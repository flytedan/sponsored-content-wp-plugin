<?php
/**
 * wp-admin page: registration status, timeline, and Hosted Content info.
 *
 * @package Flytedesk\HostedContent
 */

declare( strict_types=1 );

namespace Flytedesk\HostedContent\Admin;

use Flytedesk\HostedContent\PostType;
use Flytedesk\HostedContent\Registration\ApiCredential;
use Flytedesk\HostedContent\Registration\Client;
use Flytedesk\HostedContent\Registration\Consent;
use Flytedesk\HostedContent\Registration\StatePresenter;
use Flytedesk\HostedContent\Registration\VerificationTracker;
use Flytedesk\HostedContent\Seo\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submenu page under the Hosted Content post type. Before {@see Consent}
 * has been granted, renders only a plain-language explanation of what
 * registering does and a "Connect to flytedesk" button - nothing is sent
 * anywhere until a human explicitly clicks it. Afterward, renders the full
 * dashboard: a live-updating timeline (Pending -> Connected -> Awaiting
 * Response -> Accepted/Rejected -> Verified), a Status tab with the
 * Re-register button, a Technical Details tab showing the last registration
 * request/response, and an About tab explaining the Hosted Content
 * Network to the publisher. All dynamic rendering after the first paint is
 * done client-side by `assets/js/registration-admin.js`, polling
 * {@see RegistrationAjaxController} every few seconds; this class's PHP
 * only needs to render the initial shell + state once per page load.
 */
class RegistrationSettingsPage {

	public const NONCE_ACTION = 'flytedesk_registration_ajax';

	private const PAGE_SLUG = 'flytedesk-registration';

	private Client $client;

	private Resolver $seo_resolver;

	private Consent $consent;

	private ApiCredential $api_credential;

	private StatePresenter $state_presenter;

	private string $hook_suffix = '';

	public function __construct( Client $client, Resolver $seo_resolver, Consent $consent, ?ApiCredential $api_credential = null, ?StatePresenter $state_presenter = null ) {
		$this->client          = $client;
		$this->seo_resolver    = $seo_resolver;
		$this->consent         = $consent;
		$this->api_credential  = $api_credential ?? new ApiCredential();
		$this->state_presenter = $state_presenter ?? new StatePresenter( $this->client, $this->api_credential, new VerificationTracker(), $this->consent );
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu_page(): void {
		$hook_suffix = add_submenu_page(
			'edit.php?post_type=' . PostType::POST_TYPE,
			__( 'Flytedesk Registration', 'flytedesk-hosted-content' ),
			__( 'Registration', 'flytedesk-hosted-content' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		if ( is_string( $hook_suffix ) ) {
			$this->hook_suffix = $hook_suffix;
		}
	}

	public function enqueue_assets( string $hook ): void {
		if ( '' === $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}

		$css_path = FLYTEDESK_HOSTED_CONTENT_DIR . 'assets/css/registration-admin.css';
		$js_path  = FLYTEDESK_HOSTED_CONTENT_DIR . 'assets/js/registration-admin.js';

		wp_enqueue_style(
			'flytedesk-registration-admin',
			FLYTEDESK_HOSTED_CONTENT_URL . 'assets/css/registration-admin.css',
			array(),
			$this->asset_version( $css_path )
		);

		wp_enqueue_script(
			'flytedesk-registration-admin',
			FLYTEDESK_HOSTED_CONTENT_URL . 'assets/js/registration-admin.js',
			array(),
			$this->asset_version( $js_path ),
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
					'registerNow'        => __( 'Register Now', 'flytedesk-hosted-content' ),
					'reregister'         => __( 'Re-register', 'flytedesk-hosted-content' ),
					'sending'            => __( 'Sending…', 'flytedesk-hosted-content' ),
					'genericError'       => __( 'Something went wrong. Please try again.', 'flytedesk-hosted-content' ),
					'acceptedRejected'   => __( 'Accepted / Rejected', 'flytedesk-hosted-content' ),
					'accepted'           => __( 'Accepted', 'flytedesk-hosted-content' ),
					'rejected'           => __( 'Rejected', 'flytedesk-hosted-content' ),
					'noAttemptsYet'      => __( 'No attempts yet.', 'flytedesk-hosted-content' ),
					'networkError'       => __( 'Network error (no response received)', 'flytedesk-hosted-content' ),
					'emptyResponseBody'  => __( '(empty response body)', 'flytedesk-hosted-content' ),
					'notYetVerified'     => __( 'Not yet verified', 'flytedesk-hosted-content' ),
					'connectToFlytedesk' => __( 'Connect to flytedesk', 'flytedesk-hosted-content' ),
					'connecting'         => __( 'Connecting…', 'flytedesk-hosted-content' ),
					'statusLabels'       => array(
						Client::STATUS_PENDING  => __( 'Pending', 'flytedesk-hosted-content' ),
						Client::STATUS_SENT     => __( 'Registration Sent', 'flytedesk-hosted-content' ),
						Client::STATUS_ACCEPTED => __( 'Accepted', 'flytedesk-hosted-content' ),
						Client::STATUS_REJECTED => __( 'Rejected', 'flytedesk-hosted-content' ),
					),
				),
			)
		);
	}

	/**
	 * Uses the asset file's own last-modified time as the cache-busting
	 * version, rather than the plugin's static version constant - a plugin
	 * release that ships new CSS/JS without remembering to bump that
	 * constant would otherwise leave every browser serving a stale cached
	 * copy indefinitely after an update. `filemtime()` changes automatically
	 * whenever the file's actual contents change, so this can't go stale.
	 * Falls back to the plugin version if the file is somehow unreadable.
	 */
	private function asset_version( string $absolute_path ): string {
		$mtime = file_exists( $absolute_path ) ? filemtime( $absolute_path ) : false;

		return false !== $mtime ? (string) $mtime : FLYTEDESK_HOSTED_CONTENT_VERSION;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->consent->has_been_granted() ) {
			$this->render_consent_gate();
			return;
		}

		$state = $this->state_presenter->to_array();
		?>
		<div class="wrap flytedesk-registration-page">
			<noscript>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'This page requires JavaScript to show live registration status.', 'flytedesk-hosted-content' ); ?></p>
				</div>
			</noscript>

			<?php $this->render_header(); ?>

			<?php $this->render_seo_notice(); ?>

			<?php $this->render_timeline_shell(); ?>

			<?php $this->render_verification_breakdown(); ?>

			<div class="flytedesk-tabs">
				<div class="flytedesk-tab-nav" role="tablist">
					<button type="button" class="flytedesk-tab-button is-active" data-tab="status"><?php esc_html_e( 'Status', 'flytedesk-hosted-content' ); ?></button>
					<button type="button" class="flytedesk-tab-button" data-tab="technical"><?php esc_html_e( 'Technical Details', 'flytedesk-hosted-content' ); ?></button>
					<button type="button" class="flytedesk-tab-button" data-tab="about"><?php esc_html_e( 'About Hosted Content', 'flytedesk-hosted-content' ); ?></button>
				</div>

				<?php $this->render_status_panel( $state ); ?>
				<?php $this->render_technical_panel(); ?>
				<?php $this->render_about_panel(); ?>
			</div>
		</div>
		<?php
	}

	private function render_header(): void {
		?>
		<div class="flytedesk-header">
			<img
				src="<?php echo esc_url( FLYTEDESK_HOSTED_CONTENT_URL . 'assets/images/flytedesk-logo-dark-bg.png' ); ?>"
				alt="flytedesk"
				class="flytedesk-header-logo"
			/>
			<div class="flytedesk-header-text">
				<h1><?php esc_html_e( 'Hosted Content Registration', 'flytedesk-hosted-content' ); ?></h1>
				<p><?php esc_html_e( 'Connect this site to the flytedesk Hosted Content Network.', 'flytedesk-hosted-content' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Shown instead of the dashboard until {@see Consent::has_been_granted()}
	 * is true. WordPress.org's Plugin Directory guidelines require plugins
	 * not contact external servers without a human's explicit, informed
	 * opt-in - activating the plugin isn't itself that consent - so nothing
	 * is sent to sponsored.flytedesk.com until this exact button is clicked.
	 * The click both records who authorized it ({@see Consent::grant()}) and
	 * immediately sends the registration that authorization covers, so
	 * getting started is one click, not a consent step followed by a
	 * separate registration step.
	 */
	private function render_consent_gate(): void {
		?>
		<div class="wrap flytedesk-registration-page">
			<?php $this->render_header(); ?>

			<div class="flytedesk-consent-card">
				<h2><?php esc_html_e( 'Connect this site to flytedesk', 'flytedesk-hosted-content' ); ?></h2>
				<p><?php esc_html_e( 'Before this site can receive hosted content, it needs to register with flytedesk\'s platform. Clicking "Connect to flytedesk" below will:', 'flytedesk-hosted-content' ); ?></p>
				<ul class="flytedesk-consent-list">
					<li><?php esc_html_e( 'Create a dedicated, low-privilege WordPress user ("flytebot") on this site, used only by this plugin\'s own REST API - nothing else on this site.', 'flytedesk-hosted-content' ); ?></li>
					<li><?php esc_html_e( 'Issue that user an Application Password and send it, along with this site\'s domain and title, to sponsored.flytedesk.com.', 'flytedesk-hosted-content' ); ?></li>
					<li><?php esc_html_e( 'Let a human at flytedesk review this registration - nothing is published to this site unless and until they approve it.', 'flytedesk-hosted-content' ); ?></li>
				</ul>
				<p class="flytedesk-field-description"><?php esc_html_e( 'Nothing is sent anywhere, and no user is created, until you click the button below. Who clicked it and when is recorded on this site for your own records.', 'flytedesk-hosted-content' ); ?></p>

				<button type="button" class="flytedesk-button" id="flytedesk-consent-button">
					<span class="flytedesk-spinner"></span>
					<span class="flytedesk-button-label"><?php esc_html_e( 'Connect to flytedesk', 'flytedesk-hosted-content' ); ?></span>
				</button>

				<div class="flytedesk-notice is-error" data-role="consent-error" hidden>
					<span class="flytedesk-notice-text"></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Warns the publisher when no supported SEO plugin ({@see Resolver}'s
	 * Yoast/Rank Math/AIOSEO adapters) is active - content still publishes
	 * fine either way (the fallback adapter writes its own basic meta tags
	 * directly), but only a real SEO plugin also produces an XML sitemap
	 * entry and structured data for it, so it's worth flagging rather than
	 * silently degrading.
	 */
	private function render_seo_notice(): void {
		if ( $this->seo_resolver->has_recommended_plugin() ) {
			return;
		}
		?>
		<div class="flytedesk-notice is-warning flytedesk-seo-notice">
			<strong><?php esc_html_e( 'No supported SEO plugin detected.', 'flytedesk-hosted-content' ); ?></strong>
			<?php esc_html_e( "Hosted articles will still publish, but using this plugin's own basic meta tags instead of a dedicated SEO plugin's fields - with no automatic XML sitemap entry or structured data. Install one of the following for full support:", 'flytedesk-hosted-content' ); ?>
			<?php
			printf(
				/* translators: 1: Yoast SEO plugin link, 2: Rank Math plugin link, 3: All in One SEO plugin link. */
				esc_html__( '%1$s, %2$s, or %3$s.', 'flytedesk-hosted-content' ),
				'<a href="https://wordpress.org/plugins/wordpress-seo/" target="_blank" rel="noopener noreferrer">Yoast SEO</a>', // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction -- fixed, hardcoded link markup authored by this plugin, not derived from request input; see the identical pattern in render_about_panel().
				'<a href="https://wordpress.org/plugins/seo-by-rank-math/" target="_blank" rel="noopener noreferrer">Rank Math</a>', // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction -- fixed, hardcoded link markup authored by this plugin, not derived from request input; see the identical pattern in render_about_panel().
				'<a href="https://wordpress.org/plugins/all-in-one-seo-pack/" target="_blank" rel="noopener noreferrer">All in One SEO</a>' // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction -- fixed, hardcoded link markup authored by this plugin, not derived from request input; see the identical pattern in render_about_panel().
			);
			?>
		</div>
		<?php
	}

	private function render_timeline_shell(): void {
		?>
		<div class="flytedesk-timeline" id="flytedesk-timeline">
			<div class="flytedesk-timeline-step" data-step="pending">
				<div class="flytedesk-timeline-icon"></div>
				<div class="flytedesk-timeline-label"><?php esc_html_e( 'Pending', 'flytedesk-hosted-content' ); ?></div>
				<div class="flytedesk-timeline-timestamp"></div>
			</div>
			<div class="flytedesk-timeline-connector"></div>
			<div class="flytedesk-timeline-step" data-step="connected">
				<div class="flytedesk-timeline-icon"></div>
				<div class="flytedesk-timeline-label"><?php esc_html_e( 'Connected', 'flytedesk-hosted-content' ); ?></div>
				<div class="flytedesk-timeline-timestamp"></div>
			</div>
			<div class="flytedesk-timeline-connector"></div>
			<div class="flytedesk-timeline-step" data-step="awaiting">
				<div class="flytedesk-timeline-icon"></div>
				<div class="flytedesk-timeline-label"><?php esc_html_e( 'Awaiting Response', 'flytedesk-hosted-content' ); ?></div>
				<div class="flytedesk-timeline-timestamp"></div>
			</div>
			<div class="flytedesk-timeline-connector"></div>
			<div class="flytedesk-timeline-step" data-step="resolved">
				<div class="flytedesk-timeline-icon"></div>
				<div class="flytedesk-timeline-label"><?php esc_html_e( 'Accepted / Rejected', 'flytedesk-hosted-content' ); ?></div>
				<div class="flytedesk-timeline-timestamp"></div>
			</div>
			<div class="flytedesk-timeline-connector"></div>
			<div class="flytedesk-timeline-step" data-step="verified">
				<div class="flytedesk-timeline-icon"></div>
				<div class="flytedesk-timeline-label"><?php esc_html_e( 'Verified', 'flytedesk-hosted-content' ); ?></div>
				<div class="flytedesk-timeline-timestamp"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * The "Verified" timeline step's detail: individual Create/Update/Delete
	 * status, each driven by {@see \Flytedesk\HostedContent\Registration\VerificationTracker}
	 * recording the first time flytedesk's platform successfully exercised
	 * that operation against this site's REST API (real or test content -
	 * only that it worked at least once). The top-level "Verified" step only
	 * shows complete once all three here do.
	 */
	private function render_verification_breakdown(): void {
		?>
		<div class="flytedesk-verification-breakdown" id="flytedesk-verification-breakdown">
			<div class="flytedesk-verification-item" data-verification="create">
				<span class="flytedesk-verification-icon"></span>
				<span class="flytedesk-verification-label"><?php esc_html_e( 'Create', 'flytedesk-hosted-content' ); ?></span>
				<span class="flytedesk-verification-timestamp"></span>
			</div>
			<div class="flytedesk-verification-item" data-verification="update">
				<span class="flytedesk-verification-icon"></span>
				<span class="flytedesk-verification-label"><?php esc_html_e( 'Update', 'flytedesk-hosted-content' ); ?></span>
				<span class="flytedesk-verification-timestamp"></span>
			</div>
			<div class="flytedesk-verification-item" data-verification="delete">
				<span class="flytedesk-verification-icon"></span>
				<span class="flytedesk-verification-label"><?php esc_html_e( 'Delete', 'flytedesk-hosted-content' ); ?></span>
				<span class="flytedesk-verification-timestamp"></span>
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
				<div class="flytedesk-field-label"><?php esc_html_e( 'Site domain', 'flytedesk-hosted-content' ); ?></div>
				<div class="flytedesk-field-value"><code data-field="site_domain"><?php echo esc_html( $state['site_domain'] ); ?></code></div>

				<div class="flytedesk-field-label"><?php esc_html_e( 'Verification token', 'flytedesk-hosted-content' ); ?></div>
				<div class="flytedesk-field-value"><code data-field="verification_token"><?php echo esc_html( $state['verification_token'] ); ?></code></div>

				<div class="flytedesk-field-label"><?php esc_html_e( 'API user', 'flytedesk-hosted-content' ); ?></div>
				<div class="flytedesk-field-value">
					<code data-field="api_username"><?php echo esc_html( $state['api_username'] ); ?></code>
					<p class="flytedesk-field-description"><?php esc_html_e( 'A dedicated, low-privilege user this plugin creates automatically to hold the Application Password sent with each registration - it can only use this plugin\'s own REST API, nothing else on this site.', 'flytedesk-hosted-content' ); ?></p>
				</div>
			</div>

			<button type="button" class="flytedesk-button" id="flytedesk-register-button">
				<span class="flytedesk-spinner"></span>
				<span class="flytedesk-button-label"><?php echo esc_html( $this->button_label( $state['status'] ) ); ?></span>
			</button>

			<div class="flytedesk-poll-indicator" id="flytedesk-poll-indicator" hidden>
				<span class="flytedesk-poll-dot"></span>
				<?php esc_html_e( 'Checking for updates every few seconds…', 'flytedesk-hosted-content' ); ?>
			</div>
		</div>
		<?php
	}

	private function render_technical_panel(): void {
		?>
		<div class="flytedesk-tab-panel" data-tab-panel="technical" id="flytedesk-technical-panel" hidden>
			<div class="flytedesk-tech-block">
				<h3><?php esc_html_e( 'Authorized by', 'flytedesk-hosted-content' ); ?></h3>
				<p class="flytedesk-empty-state" data-field="consent_summary"></p>
			</div>
			<div class="flytedesk-tech-block">
				<h3><?php esc_html_e( 'Last attempt', 'flytedesk-hosted-content' ); ?></h3>
				<p class="flytedesk-empty-state" data-field="last_attempt_at"></p>
			</div>
			<div class="flytedesk-tech-block">
				<h3>
					<?php esc_html_e( 'Response', 'flytedesk-hosted-content' ); ?>
					<span class="flytedesk-tech-http-status" data-field="last_http_status" hidden></span>
				</h3>
				<pre class="flytedesk-code-block" data-field="last_response_body"></pre>
			</div>
			<div class="flytedesk-tech-block">
				<h3><?php esc_html_e( 'Request sent', 'flytedesk-hosted-content' ); ?></h3>
				<pre class="flytedesk-code-block" data-field="last_request"></pre>
				<p class="flytedesk-field-description"><?php esc_html_e( 'The Application Password itself is never shown here or stored anywhere after being sent - WordPress only reveals it once, at creation.', 'flytedesk-hosted-content' ); ?></p>
			</div>
		</div>
		<?php
	}

	private function render_about_panel(): void {
		?>
		<div class="flytedesk-tab-panel" data-tab-panel="about" hidden>
			<p class="flytedesk-about-intro">
				<?php esc_html_e( 'Flytedesk connects brands who want to reach college students with a network of premium college news and student media sites. This plugin is how your site receives that content automatically once your registration is accepted.', 'flytedesk-hosted-content' ); ?>
			</p>

			<div class="flytedesk-about-stats">
				<div class="flytedesk-about-stat"><strong>2,000+</strong><span><?php esc_html_e( 'Campuses', 'flytedesk-hosted-content' ); ?></span></div>
				<div class="flytedesk-about-stat"><strong>600+</strong><span><?php esc_html_e( 'Student media orgs', 'flytedesk-hosted-content' ); ?></span></div>
				<div class="flytedesk-about-stat"><strong>20M</strong><span><?php esc_html_e( 'Students reached', 'flytedesk-hosted-content' ); ?></span></div>
				<div class="flytedesk-about-stat"><strong>30+</strong><span><?php esc_html_e( 'Premium websites', 'flytedesk-hosted-content' ); ?></span></div>
			</div>

			<p class="flytedesk-about-section-title"><?php esc_html_e( 'How it works for your site', 'flytedesk-hosted-content' ); ?></p>
			<ol class="flytedesk-how-it-works">
				<li>
					<div>
						<strong><?php esc_html_e( 'You connected this site.', 'flytedesk-hosted-content' ); ?></strong>
						<?php esc_html_e( 'Clicking "Connect to flytedesk" generated a secure credential for your site and sent it to flytedesk - nothing to copy or configure by hand, and nothing sent anywhere without that explicit click.', 'flytedesk-hosted-content' ); ?>
					</div>
				</li>
				<li>
					<div>
						<strong><?php esc_html_e( 'A human reviews your site.', 'flytedesk-hosted-content' ); ?></strong>
						<?php esc_html_e( 'The flytedesk team checks your registration before anything is published - nothing goes live without this review.', 'flytedesk-hosted-content' ); ?>
					</div>
				</li>
				<li>
					<div>
						<strong><?php esc_html_e( 'Once accepted, articles publish automatically.', 'flytedesk-hosted-content' ); ?></strong>
						<?php esc_html_e( 'Flytedesk sends hosted articles directly to this site, correctly tagged for whichever SEO plugin you run, and published immediately under Hosted Content.', 'flytedesk-hosted-content' ); ?>
					</div>
				</li>
				<li>
					<div>
						<strong><?php esc_html_e( 'You can re-register any time.', 'flytedesk-hosted-content' ); ?></strong>
						<?php esc_html_e( 'If a registration is rejected, or an attempt fails to reach flytedesk, use the Register Now button on the Status tab to try again.', 'flytedesk-hosted-content' ); ?>
					</div>
				</li>
			</ol>

			<p class="flytedesk-about-section-title"><?php esc_html_e( 'Frequently asked questions', 'flytedesk-hosted-content' ); ?></p>
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
					esc_html__( 'Source: flytedesk Hosted Content Network (%s).', 'flytedesk-hosted-content' ),
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
				'question' => __( 'Is the content labeled as sponsored?', 'flytedesk-hosted-content' ),
				'answer'   => __( 'Yes. Every placement is clearly disclosed per FTC guidelines and your own publisher disclosure policy.', 'flytedesk-hosted-content' ),
			),
			array(
				'question' => __( 'Do I keep editorial control over what gets published?', 'flytedesk-hosted-content' ),
				'answer'   => __( 'Yes. You set your own topic policy, and every article goes through light editorial review. Flytedesk also independently rejects certain categories outright - content promoting academic dishonesty, offshore gambling, or anything illegal under applicable law - and refunds those orders in full.', 'flytedesk-hosted-content' ),
			),
			array(
				'question' => __( 'What does a typical article look like?', 'flytedesk-hosted-content' ),
				'answer'   => __( 'Original, 500-1,000 word articles written to spec, with up to three dofollow links embedded naturally in the body - fully indexable and crawlable, and correctly tagged for whichever SEO plugin your site runs (or none at all).', 'flytedesk-hosted-content' ),
			),
			array(
				'question' => __( 'How long does content stay on my site?', 'flytedesk-hosted-content' ),
				'answer'   => __( 'At least one calendar year.', 'flytedesk-hosted-content' ),
			),
			array(
				'question' => __( 'What if my registration is rejected?', 'flytedesk-hosted-content' ),
				'answer'   => __( 'Nothing is published and no content is sent to your site. You can review the reason (if provided) and use the Register Now button to submit again at any time.', 'flytedesk-hosted-content' ),
			),
		);
	}

	private function status_label( string $status ): string {
		switch ( $status ) {
			case Client::STATUS_SENT:
				return __( 'Registration Sent', 'flytedesk-hosted-content' );
			case Client::STATUS_ACCEPTED:
				return __( 'Accepted', 'flytedesk-hosted-content' );
			case Client::STATUS_REJECTED:
				return __( 'Rejected', 'flytedesk-hosted-content' );
			default:
				return __( 'Pending', 'flytedesk-hosted-content' );
		}
	}

	private function button_label( string $status ): string {
		return Client::STATUS_PENDING === $status
			? __( 'Register Now', 'flytedesk-hosted-content' )
			: __( 'Re-register', 'flytedesk-hosted-content' );
	}
}
