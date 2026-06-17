<?php
// phpcs:disable Yoast.NamingConventions.NamespaceName.MaxExceeded
// phpcs:disable Yoast.NamingConventions.NamespaceName.TooLong -- Needed in the folder structure.

namespace Yoast\WP\SEO\MyYoast_Client\User_Interface;

use Throwable;
use WP_REST_Response;
use Yoast\WP\SEO\Conditionals\MyYoast_Connection_Conditional;
use Yoast\WP\SEO\Integrations\Admin\Integrations_Page;
use Yoast\WP\SEO\Main;
use Yoast\WP\SEO\MyYoast_Client\Application\Exceptions\Authorization_Flow_Exception;
use Yoast\WP\SEO\MyYoast_Client\Application\Exceptions\Discovery_Failed_Exception;
use Yoast\WP\SEO\MyYoast_Client\Application\Exceptions\Rate_Limited_Exception;
use Yoast\WP\SEO\MyYoast_Client\Application\Exceptions\Registration_Failed_Exception;
use Yoast\WP\SEO\MyYoast_Client\Application\Exceptions\Registration_Not_Found_Exception;
use Yoast\WP\SEO\MyYoast_Client\Application\Exceptions\Server_Capability_Exception;
use Yoast\WP\SEO\MyYoast_Client\Application\Exceptions\Token_Request_Failed_Exception;
use Yoast\WP\SEO\MyYoast_Client\Application\Exceptions\Token_Storage_Exception;
use Yoast\WP\SEO\MyYoast_Client\Application\MyYoast_Client;
use Yoast\WP\SEO\MyYoast_Client\Application\Ports\Client_Registration_Interface;
use Yoast\WP\SEO\MyYoast_Client\Domain\Exceptions\Invalid_Resource_Exception;
use Yoast\WP\SEO\MyYoast_Client\Infrastructure\OIDC\Issuer_Config;
use Yoast\WP\SEO\Routes\Route_Interface;
use YoastSEO_Vendor\Psr\Log\LoggerAwareInterface;
use YoastSEO_Vendor\Psr\Log\LoggerAwareTrait;
use YoastSEO_Vendor\Psr\Log\NullLogger;

/**
 * REST endpoints for managing the site's MyYoast OAuth client registration.
 *
 * UI-side counterpart to `wp yoast auth` — every endpoint dispatches to the
 * same `MyYoast_Client` facade and returns the refreshed status payload on
 * success so the client can update its local state without a follow-up GET.
 */
class Management_Route implements Route_Interface, LoggerAwareInterface {

	use LoggerAwareTrait;

	public const ROUTE_PREFIX = '/myyoast';

	public const STATUS_ROUTE       = '/status';
	public const VERIFY_ROUTE       = '/verify';
	public const REGISTER_ROUTE     = '/register';
	public const REGISTRATION_ROUTE = '/registration';
	public const AUTHORIZE_ROUTE    = '/authorize';

	/**
	 * The MyYoast client facade.
	 *
	 * @var MyYoast_Client
	 */
	private $myyoast_client;

	/**
	 * The status presenter.
	 *
	 * @var Status_Presenter
	 */
	private $status_presenter;

	/**
	 * The issuer configuration.
	 *
	 * @var Issuer_Config
	 */
	private $issuer_config;

	/**
	 * The client registration port.
	 *
	 * @var Client_Registration_Interface
	 */
	private $client_registration;

	/**
	 * Management_Route constructor.
	 *
	 * @param MyYoast_Client                $myyoast_client      The MyYoast client facade.
	 * @param Status_Presenter              $status_presenter    The status presenter.
	 * @param Issuer_Config                 $issuer_config       The issuer configuration.
	 * @param Client_Registration_Interface $client_registration The client registration port.
	 */
	public function __construct(
		MyYoast_Client $myyoast_client,
		Status_Presenter $status_presenter,
		Issuer_Config $issuer_config,
		Client_Registration_Interface $client_registration
	) {
		$this->myyoast_client      = $myyoast_client;
		$this->status_presenter    = $status_presenter;
		$this->issuer_config       = $issuer_config;
		$this->client_registration = $client_registration;
		$this->logger              = new NullLogger();
	}

	/**
	 * Returns the conditionals on which this route should be registered.
	 *
	 * @return array<string>
	 */
	public static function get_conditionals() {
		return [ MyYoast_Connection_Conditional::class ];
	}

	/**
	 * Registers the routes with WordPress.
	 *
	 * @return void
	 */
	public function register_routes() {
		$permission_callback = [ $this, 'can_manage' ];

		\register_rest_route(
			Main::API_V1_NAMESPACE,
			self::ROUTE_PREFIX . self::STATUS_ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => $permission_callback,
			],
		);

		\register_rest_route(
			Main::API_V1_NAMESPACE,
			self::ROUTE_PREFIX . self::VERIFY_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'verify' ],
				'permission_callback' => $permission_callback,
			],
		);

		\register_rest_route(
			Main::API_V1_NAMESPACE,
			self::ROUTE_PREFIX . self::REGISTER_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'register' ],
				'permission_callback' => $permission_callback,
			],
		);

		\register_rest_route(
			Main::API_V1_NAMESPACE,
			self::ROUTE_PREFIX . self::REGISTRATION_ROUTE,
			[
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_registration' ],
					'permission_callback' => $permission_callback,
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'deregister' ],
					'permission_callback' => $permission_callback,
				],
			],
		);

		\register_rest_route(
			Main::API_V1_NAMESPACE,
			self::ROUTE_PREFIX . self::AUTHORIZE_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'authorize' ],
				'permission_callback' => $permission_callback,
			],
		);
	}

	/**
	 * Permission callback for every endpoint.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return \current_user_can( 'wpseo_manage_options' );
	}

	/**
	 * GET /myyoast/status — returns the current status payload.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		return $this->respond_with_status( 200, null );
	}

	/**
	 * POST /myyoast/verify — verifies the registration with the server.
	 *
	 * @return WP_REST_Response
	 */
	public function verify() {
		try {
			$this->myyoast_client->verify_registration();
		} catch ( Throwable $e ) {
			return $this->handle_exception( $e );
		}

		return $this->respond_with_status( 200, null );
	}

	/**
	 * POST /myyoast/register — connects the site to MyYoast.
	 *
	 * @return WP_REST_Response
	 */
	public function register() {
		$gate = $this->require_provisioned();
		if ( $gate !== null ) {
			return $gate;
		}

		try {
			$this->myyoast_client->ensure_registered();
		} catch ( Throwable $e ) {
			return $this->handle_exception( $e );
		}

		return $this->respond_with_status( 200, 'connect_success' );
	}

	/**
	 * PUT /myyoast/registration — re-syncs the connection's redirect URIs.
	 *
	 * Used to recover the connection after the site's URL has changed. The client
	 * resolves the current redirect URIs itself and updates the registration in
	 * place (RFC 7592 PUT) when the set differs from what is stored.
	 *
	 * @return WP_REST_Response
	 */
	public function update_registration() {
		$gate = $this->require_provisioned();
		if ( $gate !== null ) {
			return $gate;
		}

		try {
			$this->myyoast_client->ensure_registered();
		} catch ( Throwable $e ) {
			return $this->handle_exception( $e );
		}

		return $this->respond_with_status( 200, 'update_success' );
	}

	/**
	 * POST /myyoast/authorize — starts the authorization-code flow and returns
	 * the URL the browser should be sent to.
	 *
	 * Completing the round-trip verifies that the site's redirect URI is
	 * reachable and that the user is who they claim to be. The client resolves
	 * the redirect URI itself, and the authorization-code handler marks it
	 * validated once the returning code is exchanged.
	 *
	 * @return WP_REST_Response
	 */
	public function authorize(): WP_REST_Response {
		$gate = $this->require_provisioned();
		if ( $gate !== null ) {
			return $gate;
		}

		if ( $this->client_registration->get_registered_client() === null ) {
			return $this->error_response( 'registration_gone' );
		}

		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return $this->error_response( 'invalid_user', null, 401 );
		}

		$return_url = \admin_url( 'admin.php?page=' . Integrations_Page::PAGE );

		try {
			$authorize_url = $this->myyoast_client->get_authorization_url(
				$user_id,
				[ 'openid' ],
				null,
				$return_url,
			);
		} catch ( Authorization_Flow_Exception $e ) {
			return $this->error_response( 'registration_failed', $e );
		} catch ( Throwable $e ) {
			return $this->handle_exception( $e );
		}

		$body = [
			'authorize_url' => $authorize_url,
			'status'        => $this->status_presenter->present(),
		];

		return new WP_REST_Response( $body, 200 );
	}

	/**
	 * DELETE /myyoast/registration — disconnects the site server-side and locally.
	 *
	 * @return WP_REST_Response
	 */
	public function deregister() {
		// Disconnect is best-effort on the server but always authoritative
		// locally: whatever happens with the remote RFC 7592 DELETE, the site
		// ends up disconnected here. An orphaned server-side client is cleaned up
		// automatically by MyYoast. deregister() already clears the local
		// registration and returns false (rather than throwing) on transport
		// failure.
		$remote_cleared = false;
		try {
			$remote_cleared = $this->myyoast_client->deregister();
		} catch ( Throwable $e ) {
			$this->logger->warning(
				'Unexpected error during MyYoast deregistration; disconnecting locally anyway: {error}',
				[ 'error' => $e->getMessage() ],
			);
		} finally {
			// Always clear site tokens, even when the remote call threw, so the
			// site is never left half-connected.
			$this->myyoast_client->clear_all_site_tokens();
		}

		if ( ! $remote_cleared ) {
			$this->logger->warning( 'MyYoast server-side deregistration was not confirmed; the site was disconnected locally.' );
		}

		return $this->respond_with_status( 200, 'disconnect_success' );
	}

	/**
	 * Returns a "not provisioned" response when SS or IAT is empty.
	 *
	 * @return WP_REST_Response|null Response when blocked, null otherwise.
	 */
	private function require_provisioned(): ?WP_REST_Response {
		if ( $this->is_provisioned() ) {
			return null;
		}

		return $this->error_response( 'not_provisioned' );
	}

	/**
	 * Whether the plugin is provisioned for OAuth (software statement + IAT).
	 *
	 * @return bool
	 */
	private function is_provisioned(): bool {
		return ( $this->issuer_config->get_software_statement() !== '' )
			&& ( $this->issuer_config->get_initial_access_token() !== '' );
	}

	/**
	 * Maps an exception to a REST error response.
	 *
	 * The REST endpoint itself executed correctly — what failed is an upstream
	 * call to MyYoast or a precondition. We therefore return HTTP 200 with an
	 * `error_code` in the body that the UI translates into actionable copy.
	 * Genuine request-validation failures return 4xx separately (see callers).
	 *
	 * @param Throwable $exception The exception to handle.
	 *
	 * @return WP_REST_Response
	 */
	private function handle_exception( Throwable $exception ): WP_REST_Response {
		if ( $exception instanceof Registration_Not_Found_Exception ) {
			return $this->error_response( 'registration_gone', $exception );
		}

		if ( $exception instanceof Rate_Limited_Exception ) {
			$retry_after = $exception->get_retry_after_seconds();
			$details     = ( $retry_after !== null ) ? [ 'retry_after_seconds' => $retry_after ] : [];
			return $this->error_response( 'rate_limited', $exception, 200, $details );
		}

		if ( $exception instanceof Server_Capability_Exception ) {
			return $this->error_response( 'server_capability', $exception );
		}

		if ( $exception instanceof Discovery_Failed_Exception ) {
			return $this->error_response( 'myyoast_unreachable', $exception );
		}

		if ( $exception instanceof Token_Request_Failed_Exception ) {
			$code = ( $exception->get_error_code() === 'invalid_grant' ) ? 'token_request_failed_invalid_grant' : 'token_request_failed';
			return $this->error_response( $code, $exception );
		}

		if ( $exception instanceof Token_Storage_Exception ) {
			return $this->error_response( 'token_storage_failed', $exception );
		}

		if ( $exception instanceof Invalid_Resource_Exception ) {
			return $this->error_response( 'invalid_resource', $exception );
		}

		if ( $exception instanceof Registration_Failed_Exception ) {
			return $this->error_response( 'registration_failed', $exception );
		}

		$this->logger->error(
			'Unexpected exception in MyYoast management route: {message}',
			[ 'message' => $exception->getMessage() ],
		);

		return $this->error_response( 'unexpected_error', $exception );
	}

	/**
	 * Builds a successful response carrying the refreshed status payload.
	 *
	 * @param int         $status      The HTTP status.
	 * @param string|null $message_key The key in the i18n message map for the success notice, or null when none applies.
	 *
	 * @return WP_REST_Response
	 */
	private function respond_with_status( int $status, ?string $message_key ): WP_REST_Response {
		$body = [
			'status' => $this->status_presenter->present(),
		];
		if ( $message_key !== null ) {
			$body['message_key'] = $message_key;
		}

		return new WP_REST_Response( $body, $status );
	}

	/**
	 * Builds an error response.
	 *
	 * Defaults to HTTP 200 — the REST endpoint succeeded; the failure is in
	 * an upstream call or precondition, and the UI keys off `error_code`,
	 * not the HTTP status. Genuine 4xx (e.g. validation failures) pass an
	 * explicit status.
	 *
	 * @param string                $error_code The machine-readable error code (looked up client-side in the i18n map).
	 * @param Throwable|null        $exception  Optional exception (logged when present).
	 * @param int                   $status     The HTTP status. Defaults to 200.
	 * @param array<string, scalar> $details    Optional extra fields the UI may use to enrich the error message.
	 *
	 * @return WP_REST_Response
	 */
	private function error_response( string $error_code, ?Throwable $exception = null, int $status = 200, array $details = [] ): WP_REST_Response {
		if ( $exception !== null ) {
			$this->logger->warning(
				'MyYoast management error ({code}): {message}',
				[
					'code'    => $error_code,
					'message' => $exception->getMessage(),
				],
			);
		}

		$body = [
			'error_code' => $error_code,
			'status'     => $this->status_presenter->present(),
		];
		if ( $details !== [] ) {
			$body['details'] = $details;
		}

		return new WP_REST_Response( $body, $status );
	}
}
