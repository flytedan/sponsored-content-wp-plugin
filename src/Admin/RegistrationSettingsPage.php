<?php
/**
 * wp-admin page showing registration status, with a manual retry button.
 *
 * @package Flytedesk\SponsoredContent
 */

declare( strict_types=1 );

namespace Flytedesk\SponsoredContent\Admin;

use Flytedesk\SponsoredContent\PostType;
use Flytedesk\SponsoredContent\Registration\ApiCredential;
use Flytedesk\SponsoredContent\Registration\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submenu page under the Sponsored Content post type showing this site's
 * registration status with sponsored.flytedesk.com (Pending / Registration
 * Sent / Accepted / Rejected) and a button to (re-)send the registration
 * request on demand.
 *
 * Also fires the one-time automatic registration attempt that
 * {@see \Flytedesk\SponsoredContent\Plugin::activate()} schedules - deferred
 * to the next `admin_init` after activation rather than run inside the
 * activation hook itself, so a slow or failing registration request can
 * never block plugin activation.
 */
class RegistrationSettingsPage {

	private const PAGE_SLUG    = 'flytedesk-registration';
	private const ACTION       = 'flytedesk_register';
	private const NONCE_ACTION = 'flytedesk_register_nonce';

	private Client $client;

	private ApiCredential $api_credential;

	public function __construct( Client $client, ?ApiCredential $api_credential = null ) {
		$this->client         = $client;
		$this->api_credential = $api_credential ?? new ApiCredential();
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_run_automatic_registration' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_manual_registration' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . PostType::POST_TYPE,
			__( 'Flytedesk Registration', 'flytedesk-sponsored-content' ),
			__( 'Registration', 'flytedesk-sponsored-content' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
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

	public function handle_manual_registration(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'flytedesk-sponsored-content' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$this->client->register();

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'  => PostType::POST_TYPE,
					'page'       => self::PAGE_SLUG,
					'registered' => '1',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = $this->client->get_status();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Flytedesk Registration', 'flytedesk-sponsored-content' ); ?></h1>

			<?php if ( isset( $_GET['registered'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag from our own redirect, not a state-changing action. ?>
				<div class="notice notice-info is-dismissible">
					<p><?php esc_html_e( 'Registration request sent.', 'flytedesk-sponsored-content' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="notice <?php echo esc_attr( $this->status_notice_class( $status ) ); ?> inline">
				<p>
					<strong><?php esc_html_e( 'Status:', 'flytedesk-sponsored-content' ); ?></strong>
					<?php echo esc_html( $this->status_label( $status ) ); ?>
				</p>
			</div>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Site domain', 'flytedesk-sponsored-content' ); ?></th>
						<td><?php echo esc_html( $this->client->get_site_domain() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Verification token', 'flytedesk-sponsored-content' ); ?></th>
						<td><code><?php echo esc_html( $this->client->get_token() ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'API user', 'flytedesk-sponsored-content' ); ?></th>
						<td>
							<code><?php echo esc_html( $this->api_credential->get_username() ); ?></code>
							<p class="description"><?php esc_html_e( 'A dedicated, low-privilege user this plugin creates automatically to hold the Application Password sent with each registration - it can only use this plugin\'s own REST API, nothing else on this site.', 'flytedesk-sponsored-content' ); ?></p>
						</td>
					</tr>
					<?php if ( '' !== $this->client->get_sent_at() ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last sent', 'flytedesk-sponsored-content' ); ?></th>
							<td><?php echo esc_html( $this->client->get_sent_at() ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( '' !== $this->client->get_last_error() ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last error', 'flytedesk-sponsored-content' ); ?></th>
							<td><?php echo esc_html( $this->client->get_last_error() ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<?php submit_button( $this->button_label( $status ) ); ?>
			</form>
		</div>
		<?php
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

	private function status_notice_class( string $status ): string {
		switch ( $status ) {
			case Client::STATUS_SENT:
				return 'notice-info';
			case Client::STATUS_ACCEPTED:
				return 'notice-success';
			case Client::STATUS_REJECTED:
				return 'notice-error';
			default:
				return 'notice-warning';
		}
	}

	private function button_label( string $status ): string {
		return Client::STATUS_PENDING === $status
			? __( 'Register Now', 'flytedesk-sponsored-content' )
			: __( 'Re-register', 'flytedesk-sponsored-content' );
	}
}
