<?php
/**
 * wp-admin page: registration status, timeline, and Sponsored Content info.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Admin;

use Flytedesk\SponsoredContent\PostType;
use Flytedesk\SponsoredContent\Registration\ApiCredential;
use Flytedesk\SponsoredContent\Registration\Client;
use Flytedesk\SponsoredContent\Registration\StatePresenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submenu page under the Sponsored Content post type: a live-updating
 * timeline (Pending -> Awaiting Response -> Accepted/Rejected), a Status
 * tab with the Register Now / Re-register button, a Technical Details tab
 * showing the last registration request/response, and an About tab
 * explaining the Sponsored Content Network to the publisher. All dynamic
 * rendering after the first paint is done client-side by
 * `assets/js/registration-admin.js`, polling
 * {@see RegistrationAjaxController} every few seconds; this class's PHP
 * only needs to render the initial shell + state once per page load.
 *
 * Also fires the one-time automatic registration attempt that
 * {@see \Flytedesk\SponsoredContent\Plugin::activate()} schedules - deferred
 * to the next `admin_init` after activation rather than run inside the
 * activation hook itself, so a slow or failing registration request can
 * never block plugin activation.
 */
class RegistrationSettingsPage {

	public const NONCE_ACTION = 'flytedesk_registration_ajax';

	private const PAGE_SLUG = 'flytedesk-registration';

	private Client $client;

	private ApiCredential $api_credential;

	private StatePresenter $state_presenter;

	private string $hook_suffix = '';

	public function __construct( Client $client, ?ApiCredential $api_credential = null, ?StatePresenter $state_presenter = null ) {
		$this->client          = $client;
		$this->api_credential  = $api_credential ?? new ApiCredential();
		$this->state_presenter = $state_presenter ?? new StatePresenter( $this->client, $this->api_credential );
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_run_automatic_registration' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu_page(): void {
		$hook_suffix = add_submenu_page(
			'edit.php?post_type=' . PostType::POST_TYPE,
			__( 'Flytedesk Registration', 'flytedesk-sponsored-content' ),
			__( 'Registration', 'flytedesk-sponsored-content' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		if ( is_string( $hook_suffix ) ) {
			$this->hook_suffix = $hook_suffix;
		}
	}

	/**
	 * Consumes the one-shot flag `Plugin::activate()` sets, so the automatic
	 * registration attempt fires exactly once per activation, on the first
	 * admin page load afterward - not on every `admin_init`, and not
	 * competing with the manual "Register Now" button's own attempts.
	 */
	public function maybe_run_automatic_registration(): void {
		if ( '1' !== get_option( Client::OPTION_NEEDS_REGISTRATION, '' ) ) {
			return;
		}

		delete_option( Client::OPTION_NEEDS_REGISTRATION );
		$this->client->register();
	}

	public function enqueue_assets( string $hook ): void {
		if ( '' === $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'flytedesk-registration-admin',
			FLYTEDESK_SPONSORED_CONTENT_URL . 'assets/css/registration-admin.css',
			array(),
			FLYTEDESK_SPONSORED_CONTENT_VERSION
		);

		wp_enqueue_script(
			'flytedesk-registration-admin',
			FLYTEDESK_SPONSORED_CONTENT_URL . 'assets/js/registration-admin.js',
			array(),
			FLYTEDESK_SPONSORED_CONTENT_VERSION,
			true
		);

		wp_localize_script(
			'flytedesk-registration-admin',
			'flytedeskRegistration',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
				'initialState' => $this->state_presenter->to_array(),
				'strings'      => array(
					'registerNow'       => __( 'Register Now', 'flytedesk-sponsored-content' ),
					'reregister'        => __( 'Re-register', 'flytedesk-sponsored-content' ),
					'sending'           => __( 'Sending…', 'flytedesk-sponsored-content' ),
					'genericError'      => __( 'Something went wrong. Please try again.', 'flytedesk-sponsored-content' ),
					'acceptedRejected'  => __( 'Accepted / Rejected', 'flytedesk-sponsored-content' ),
					'rejected'          => __( 'Rejected', 'flytedesk-sponsored-content' ),
					'noAttemptsYet'     => __( 'No attempts yet.', 'flytedesk-sponsored-content' ),
					'networkError'      => __( 'Network error (no response received)', 'flytedesk-sponsored-content' ),
					'emptyResponseBody' => __( '(empty response body)', 'flytedesk-sponsored-content' ),
					'statusLabels'      => array(
						Client::STATUS_PENDING  => __( 'Pending', 'flytedesk-sponsored-content' ),
						Client::STATUS_SENT     => __( 'Registration Sent', 'flytedesk-sponsored-content' ),
						Client::STATUS_ACCEPTED => __( 'Accepted', 'flytedesk-sponsored-content' ),
						Client::STATUS_REJECTED => __( 'Rejected', 'flytedesk-sponsored-content' ),
					),
				),
			)
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state = $this->state_presenter->to_array();
		?>
		<div class="wrap flytedesk-registration-page">
			<noscript>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'This page requires JavaScript to show live registration status.', 'flytedesk-sponsored-content' ); ?></p>
				</div>
			</noscript>

			<div class="flytedesk-header">
				<img
					src="<?php echo esc_url( FLYTEDESK_SPONSORED_CONTENT_URL . 'assets/images/flytedesk-logo-dark-bg.png' ); ?>"
					alt="flytedesk"
					class="flytedesk-header-logo"
				/>
				<div class="flytedesk-header-text">
					<h1><?php esc_html_e( 'Sponsored Content Registration', 'flytedesk-sponsored-content' ); ?></h1>
					<p><?php esc_html_e( 'Connect this site to the flytedesk Sponsored Content Network.', 'flytedesk-sponsored-content' ); ?></p>
				</div>
			</div>

			<?php $this->render_timeline_shell(); ?>

			<div class="flytedesk-tabs">
				<div class="flytedesk-tab-nav" role="tablist">
					<button type="button" class="flytedesk-tab-button is-active" data-tab="status"><?php esc_html_e( 'Status', 'flytedesk-sponsored-content' ); ?></button>
					<button type="button" class="flytedesk-tab-button" data-tab="technical"><?php esc_html_e( 'Technical Details', 'flytedesk-sponsored-content' ); ?></button>
					<button type="button" class="flytedesk-tab-button" data-tab="about"><?php esc_html_e( 'About Sponsored Content', 'flytedesk-sponsored-content' ); ?></button>
				</div>

				<?php $this->render_status_panel( $state ); ?>
				<?php $this->render_technical_panel(); ?>
				<?php $this->render_about_panel(); ?>
			</div>
		</div>
		<?php
	}

	private function render_timeline_shell(): void {
		?>
		<div class="flytedesk-timeline" id="flytedesk-timeline">
			<div class="flytedesk-timeline-step" data-step="pending">
				<div class="flytedesk-timeline-icon"></div>
				<div class="flytedesk-timeline-label"><?php esc_html_e( 'Pending', 'flytedesk-sponsored-content' ); ?></div>
				<div class="flytedesk-timeline-timestamp"></div>
			</div>
			<div class="flytedesk-timeline-connector"></div>
			<div class="flytedesk-timeline-step" data-step="sent">
				<div class="flytedesk-timeline-icon"></div>
				<div class="flytedesk-timeline-label"><?php esc_html_e( 'Awaiting Response', 'flytedesk-sponsored-content' ); ?></div>
				<div class="flytedesk-timeline-timestamp"></div>
			</div>
			<div class="flytedesk-timeline-connector"></div>
			<div class="flytedesk-timeline-step" data-step="resolved">
				<div class="flytedesk-timeline-icon"></div>
				<div class="flytedesk-timeline-label"><?php esc_html_e( 'Accepted / Rejected', 'flytedesk-sponsored-content' ); ?></div>
				<div class="flytedesk-timeline-timestamp"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_status_panel( array $state ): void {
		?>
		<div class="flytedesk-tab-panel" data-tab-panel="status" id="flytedesk-status-panel">
			<span class="flytedesk-status-badge is-<?php echo esc_attr( $state['status'] ); ?>">
				<span class="flytedesk-status-badge-dot"></span>
				<span class="flytedesk-status-badge-label"><?php echo esc_html( $this->status_label( $state['status'] ) ); ?></span>
			</span>

			<div class="flytedesk-notice is-error" data-role="last-error" <?php echo '' === $state['last_error'] ? 'hidden' : ''; ?>>
				<span class="flytedesk-notice-text"><?php echo esc_html( $state['last_error'] ); ?></span>
			</div>

			<div class="flytedesk-field-grid">
				<div class="flytedesk-field-label"><?php esc_html_e( 'Site domain', 'flytedesk-sponsored-content' ); ?></div>
				<div class="flytedesk-field-value"><code data-field="site_domain"><?php echo esc_html( $state['site_domain'] ); ?></code></div>

				<div class="flytedesk-field-label"><?php esc_html_e( 'Verification token', 'flytedesk-sponsored-content' ); ?></div>
				<div class="flytedesk-field-value"><code data-field="verification_token"><?php echo esc_html( $state['verification_token'] ); ?></code></div>

				<div class="flytedesk-field-label"><?php esc_html_e( 'API user', 'flytedesk-sponsored-content' ); ?></div>
				<div class="flytedesk-field-value">
					<code data-field="api_username"><?php echo esc_html( $state['api_username'] ); ?></code>
					<p class="flytedesk-field-description"><?php esc_html_e( 'A dedicated, low-privilege user this plugin creates automatically to hold the Application Password sent with each registration - it can only use this plugin\'s own REST API, nothing else on this site.', 'flytedesk-sponsored-content' ); ?></p>
				</div>
			</div>

			<button type="button" class="flytedesk-button" id="flytedesk-register-button">
				<span class="flytedesk-spinner"></span>
				<span class="flytedesk-button-label"><?php echo esc_html( $this->button_label( $state['status'] ) ); ?></span>
			</button>

			<div class="flytedesk-poll-indicator" id="flytedesk-poll-indicator" hidden>
				<span class="flytedesk-poll-dot"></span>
				<?php esc_html_e( 'Checking for updates every few seconds…', 'flytedesk-sponsored-content' ); ?>
			</div>
		</div>
		<?php
	}

	private function render_technical_panel(): void {
		?>
		<div class="flytedesk-tab-panel" data-tab-panel="technical" id="flytedesk-technical-panel" hidden>
			<div class="flytedesk-tech-block">
				<h3><?php esc_html_e( 'Last attempt', 'flytedesk-sponsored-content' ); ?></h3>
				<p class="flytedesk-empty-state" data-field="last_attempt_at"></p>
			</div>
			<div class="flytedesk-tech-block">
				<h3>
					<?php esc_html_e( 'Response', 'flytedesk-sponsored-content' ); ?>
					<span class="flytedesk-tech-http-status" data-field="last_http_status" hidden></span>
				</h3>
				<pre class="flytedesk-code-block" data-field="last_response_body"></pre>
			</div>
			<div class="flytedesk-tech-block">
				<h3><?php esc_html_e( 'Request sent', 'flytedesk-sponsored-content' ); ?></h3>
				<pre class="flytedesk-code-block" data-field="last_request"></pre>
				<p class="flytedesk-field-description"><?php esc_html_e( 'The Application Password itself is never shown here or stored anywhere after being sent - WordPress only reveals it once, at creation.', 'flytedesk-sponsored-content' ); ?></p>
			</div>
		</div>
		<?php
	}

	private function render_about_panel(): void {
		?>
		<div class="flytedesk-tab-panel" data-tab-panel="about" hidden>
			<p class="flytedesk-about-intro">
				<?php esc_html_e( 'Flytedesk connects brands who want to reach college students with a network of premium college news and student media sites. This plugin is how your site receives that content automatically once your registration is accepted.', 'flytedesk-sponsored-content' ); ?>
			</p>

			<div class="flytedesk-about-stats">
				<div class="flytedesk-about-stat"><strong>2,000+</strong><span><?php esc_html_e( 'Campuses', 'flytedesk-sponsored-content' ); ?></span></div>
				<div class="flytedesk-about-stat"><strong>600+</strong><span><?php esc_html_e( 'Student media orgs', 'flytedesk-sponsored-content' ); ?></span></div>
				<div class="flytedesk-about-stat"><strong>20M</strong><span><?php esc_html_e( 'Students reached', 'flytedesk-sponsored-content' ); ?></span></div>
				<div class="flytedesk-about-stat"><strong>30+</strong><span><?php esc_html_e( 'Premium websites', 'flytedesk-sponsored-content' ); ?></span></div>
			</div>

			<p class="flytedesk-about-section-title"><?php esc_html_e( 'How it works for your site', 'flytedesk-sponsored-content' ); ?></p>
			<ol class="flytedesk-how-it-works">
				<li>
					<div>
						<strong><?php esc_html_e( 'You registered automatically.', 'flytedesk-sponsored-content' ); ?></strong>
						<?php esc_html_e( 'Activating this plugin generated a secure credential for your site and sent it to flytedesk - nothing to copy or configure by hand.', 'flytedesk-sponsored-content' ); ?>
					</div>
				</li>
				<li>
					<div>
						<strong><?php esc_html_e( 'A human reviews your site.', 'flytedesk-sponsored-content' ); ?></strong>
						<?php esc_html_e( 'The flytedesk team checks your registration before anything is published - nothing goes live without this review.', 'flytedesk-sponsored-content' ); ?>
					</div>
				</li>
				<li>
					<div>
						<strong><?php esc_html_e( 'Once accepted, articles publish automatically.', 'flytedesk-sponsored-content' ); ?></strong>
						<?php esc_html_e( 'Flytedesk sends sponsored articles directly to this site, correctly tagged for whichever SEO plugin you run, and published immediately under Sponsored Content.', 'flytedesk-sponsored-content' ); ?>
					</div>
				</li>
				<li>
					<div>
						<strong><?php esc_html_e( 'You can re-register any time.', 'flytedesk-sponsored-content' ); ?></strong>
						<?php esc_html_e( 'If a registration is rejected, or an attempt fails to reach flytedesk, use the Register Now button on the Status tab to try again.', 'flytedesk-sponsored-content' ); ?>
					</div>
				</li>
			</ol>

			<p class="flytedesk-about-section-title"><?php esc_html_e( 'Frequently asked questions', 'flytedesk-sponsored-content' ); ?></p>
			<div class="flytedesk-faq">
				<?php foreach ( $this->faq_items() as $item ) : ?>
					<details>
						<summary><?php echo esc_html( $item['question'] ); ?></summary>
						<div class="flytedesk-faq-answer"><?php echo esc_html( $item['answer'] ); ?></div>
					</details>
				<?php endforeach; ?>
			</div>

			<p class="flytedesk-field-description">
				<?php
				printf(
					/* translators: %s: link to sponsored.flytedesk.com/faq */
					esc_html__( 'Source: flytedesk Sponsored Content Network (%s).', 'flytedesk-sponsored-content' ),
					'<a href="https://sponsored.flytedesk.com/faq" target="_blank" rel="noopener noreferrer">sponsored.flytedesk.com/faq</a>' // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction -- the translators comment above documents this is a fixed, hardcoded link markup, not user input; printf's own %s substitution is what phpcs is flagging, and the literal HTML here is authored by this plugin, not derived from any request.
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * @return array<int, array{question: string, answer: string}>
	 */
	private function faq_items(): array {
		return array(
			array(
				'question' => __( 'Is the content labeled as sponsored?', 'flytedesk-sponsored-content' ),
				'answer'   => __( 'Yes. Every placement is clearly disclosed per FTC guidelines and your own publisher disclosure policy.', 'flytedesk-sponsored-content' ),
			),
			array(
				'question' => __( 'Do I keep editorial control over what gets published?', 'flytedesk-sponsored-content' ),
				'answer'   => __( 'Yes. You set your own topic policy, and every article goes through light editorial review. Flytedesk also independently rejects certain categories outright - content promoting academic dishonesty, offshore gambling, or anything illegal under applicable law - and refunds those orders in full.', 'flytedesk-sponsored-content' ),
			),
			array(
				'question' => __( 'What does a typical article look like?', 'flytedesk-sponsored-content' ),
				'answer'   => __( 'Original, 500-1,000 word articles written to spec, with up to three dofollow links embedded naturally in the body - fully indexable and crawlable, and correctly tagged for whichever SEO plugin your site runs (or none at all).', 'flytedesk-sponsored-content' ),
			),
			array(
				'question' => __( 'How long does content stay on my site?', 'flytedesk-sponsored-content' ),
				'answer'   => __( 'At least one calendar year.', 'flytedesk-sponsored-content' ),
			),
			array(
				'question' => __( 'What if my registration is rejected?', 'flytedesk-sponsored-content' ),
				'answer'   => __( 'Nothing is published and no content is sent to your site. You can review the reason (if provided) and use the Register Now button to submit again at any time.', 'flytedesk-sponsored-content' ),
			),
		);
	}

	private function status_label( string $status ): string {
		switch ( $status ) {
			case Client::STATUS_SENT:
				return __( 'Registration Sent', 'flytedesk-sponsored-content' );
			case Client::STATUS_ACCEPTED:
				return __( 'Accepted', 'flytedesk-sponsored-content' );
			case Client::STATUS_REJECTED:
				return __( 'Rejected', 'flytedesk-sponsored-content' );
			default:
				return __( 'Pending', 'flytedesk-sponsored-content' );
		}
	}

	private function button_label( string $status ): string {
		return Client::STATUS_PENDING === $status
			? __( 'Register Now', 'flytedesk-sponsored-content' )
			: __( 'Re-register', 'flytedesk-sponsored-content' );
	}
}
