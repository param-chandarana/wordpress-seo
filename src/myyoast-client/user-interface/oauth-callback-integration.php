<?php
// phpcs:disable Yoast.NamingConventions.NamespaceName.MaxExceeded
// phpcs:disable Yoast.NamingConventions.NamespaceName.TooLong -- Needed in the folder structure.

namespace Yoast\WP\SEO\MyYoast_Client\User_Interface;

use Throwable;
use Yoast\WP\SEO\Conditionals\MyYoast_Connection_Conditional;
use Yoast\WP\SEO\General\User_Interface\General_Page_Integration;
use Yoast\WP\SEO\Helpers\Redirect_Helper;
use Yoast\WP\SEO\Integrations\Integration_Interface;
use Yoast\WP\SEO\MyYoast_Client\Application\Authorization_Code_Handler;
use Yoast\WP\SEO\MyYoast_Client\Application\Exceptions\Token_Request_Failed_Exception;
use Yoast\WP\SEO\MyYoast_Client\Application\MyYoast_Client;
use YoastSEO_Vendor\Psr\Log\LoggerAwareInterface;
use YoastSEO_Vendor\Psr\Log\LoggerAwareTrait;
use YoastSEO_Vendor\Psr\Log\NullLogger;

/**
 * Handles the OAuth authorization-code callback redirect.
 *
 * Registers a dedicated callback endpoint on `admin-post.php` (reachable for
 * any logged-in user, regardless of which admin page the flow started from) as
 * the site's OAuth redirect URI, hooks the matching `admin_post_*` action,
 * exchanges the returning code, surfaces a notification via a short-lived
 * per-user transient, and redirects the user to the `return_url` they were
 * sent off from.
 *
 * The base client defaults its redirect URI to an admin page; we replace that
 * with this endpoint through the redirect-URI provider's filters so the
 * callback never depends on a specific page being loaded.
 */
class OAuth_Callback_Integration implements Integration_Interface, LoggerAwareInterface {

	use LoggerAwareTrait;

	public const CALLBACK_ACTION = 'yoast_myyoast_oauth_callback';

	public const TRANSIENT_PREFIX = 'wpseo_myyoast_oauth_outcome_';
	private const TRANSIENT_TTL   = \MINUTE_IN_SECONDS;

	/**
	 * The MyYoast client facade.
	 *
	 * @var MyYoast_Client
	 */
	private $myyoast_client;

	/**
	 * The authorization code handler — used to read the stored return URL and
	 * to discard pending state when the provider returns an error.
	 *
	 * @var Authorization_Code_Handler
	 */
	private $auth_code_handler;

	/**
	 * The redirect helper — kept behind an injectable seam to keep the `exit`
	 * out of the unit tests.
	 *
	 * @var Redirect_Helper
	 */
	private $redirect_helper;

	/**
	 * Constructor.
	 *
	 * @param MyYoast_Client             $myyoast_client    The MyYoast client facade.
	 * @param Authorization_Code_Handler $auth_code_handler The authorization code handler.
	 * @param Redirect_Helper            $redirect_helper   The redirect helper.
	 */
	public function __construct(
		MyYoast_Client $myyoast_client,
		Authorization_Code_Handler $auth_code_handler,
		Redirect_Helper $redirect_helper
	) {
		$this->myyoast_client    = $myyoast_client;
		$this->auth_code_handler = $auth_code_handler;
		$this->redirect_helper   = $redirect_helper;
		$this->logger            = new NullLogger();
	}

	/**
	 * Returns the conditionals on which this integration should be loaded.
	 *
	 * @return array<string>
	 */
	public static function get_conditionals() {
		return [ MyYoast_Connection_Conditional::class ];
	}

	/**
	 * Registers the callback endpoint and points the site's OAuth redirect URI at it.
	 *
	 * @return void
	 */
	public function register_hooks() {
		\add_action( 'admin_post_' . self::CALLBACK_ACTION, [ $this, 'handle' ] );
	}


	/**
	 * Returns this site's dedicated OAuth callback endpoint URL.
	 *
	 * @return string The callback URL.
	 */
	public static function get_callback_url(): string {
		return \get_admin_url( null, 'admin-post.php?action=' . self::CALLBACK_ACTION );
	}

	/**
	 * Handles the OAuth callback request.
	 *
	 * @return void
	 */
	public function handle(): void {
		$user_id    = \get_current_user_id();
		$return_url = $this->resolve_return_url( $user_id );

		// admin_post_* (no _nopriv variant) only fires for logged-in users, so $user_id should
		// always be > 0 here. Defensive check in case the hook is dispatched manually.
		if ( $user_id <= 0 ) {
			$this->redirect_helper->do_safe_redirect( $return_url );
			return;
		}

		$error = $this->read_query_arg( 'error' );
		if ( $error !== '' ) {
			$this->auth_code_handler->discard_flow_state( $user_id );
			$key = ( $error === 'access_denied' ) ? 'connection_cancelled' : 'unexpected_error';
			$this->set_outcome( $user_id, 'error', $key );
			$this->redirect_helper->do_safe_redirect( $return_url );
			return;
		}

		$code  = $this->read_query_arg( 'code' );
		$state = $this->read_query_arg( 'state' );

		if ( $code === '' || $state === '' ) {
			// Stale bookmark or someone hitting the callback URL directly. No notification.
			$this->redirect_helper->do_safe_redirect( $return_url );
			return;
		}

		try {
			$this->myyoast_client->exchange_authorization_code( $user_id, $code, $state );
		} catch ( Token_Request_Failed_Exception $e ) {
			$is_invalid_grant = ( $e->get_error_code() === 'invalid_grant' );
			$key              = ( $is_invalid_grant ) ? 'token_request_failed_invalid_grant' : 'token_request_failed';
			$this->set_outcome( $user_id, 'error', $key );
			$this->redirect_helper->do_safe_redirect( $return_url );
			return;
		} catch ( Throwable $e ) {
			$this->logger->error(
				'Unexpected error during MyYoast OAuth callback exchange for user {user_id}: {error}',
				[
					'user_id' => $user_id,
					'error'   => $e->getMessage(),
				],
			);
			$this->set_outcome( $user_id, 'error', 'unexpected_error' );
			$this->redirect_helper->do_safe_redirect( $return_url );
			return;
		}

		// The authorization-code handler marks the redirect URI validated as part of
		// exchanging the code, so the refreshed status already reflects the verified state.
		$this->set_outcome( $user_id, 'success', 'verify_success' );
		$this->redirect_helper->do_safe_redirect( $return_url );
	}

	/**
	 * Resolves the URL to send the browser back to after the callback runs.
	 *
	 * Falls back to the integrations page when no return URL is stored
	 * (stale bookmark, no pending flow).
	 *
	 * @param int $user_id The WordPress user ID.
	 *
	 * @return string The return URL.
	 */
	private function resolve_return_url( int $user_id ): string {
		$fallback = \admin_url( 'admin.php?page=' . General_Page_Integration::PAGE );

		if ( $user_id > 0 ) {
			$stored = $this->auth_code_handler->get_return_url( $user_id );
			if ( \is_string( $stored ) && $stored !== '' ) {
				// Defense in depth: the stored URL is only ever written as an
				// admin_url by the management route, but validate it against the
				// site's own host before redirecting so a tampered store entry
				// can't become an open redirect.
				return \wp_validate_redirect( $stored, $fallback );
			}
		}

		return $fallback;
	}

	/**
	 * Reads a query argument, returning an empty string when missing.
	 *
	 * @param string $name The query argument name.
	 *
	 * @return string The sanitized value.
	 */
	private function read_query_arg( string $name ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- CSRF defense is OAuth `state` validated inside exchange_code.
		if ( ! isset( $_GET[ $name ] ) || ! \is_string( $_GET[ $name ] ) ) {
			return '';
		}
		return \sanitize_text_field( \wp_unslash( $_GET[ $name ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Stores the callback outcome in a short-lived per-user transient.
	 *
	 * @param int    $user_id The WordPress user ID.
	 * @param string $kind    The outcome kind, either "success" or "error".
	 * @param string $key     The message key the front-end maps to copy.
	 *
	 * @return void
	 */
	private function set_outcome( int $user_id, string $kind, string $key ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		\set_transient(
			self::TRANSIENT_PREFIX . $user_id,
			[
				'kind' => $kind,
				'key'  => $key,
			],
			self::TRANSIENT_TTL,
		);
	}
}
