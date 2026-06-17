<?php

namespace Yoast\WP\SEO\Tests\Unit\MyYoast_Client\User_Interface;

use Brain\Monkey;
use Exception;
use Mockery;
use Yoast\WP\SEO\Conditionals\MyYoast_Connection_Conditional;
use Yoast\WP\SEO\Helpers\Redirect_Helper;
use Yoast\WP\SEO\MyYoast_Client\Application\Authorization_Code_Handler;
use Yoast\WP\SEO\MyYoast_Client\Application\Exceptions\Token_Request_Failed_Exception;
use Yoast\WP\SEO\MyYoast_Client\Application\MyYoast_Client;
use Yoast\WP\SEO\MyYoast_Client\User_Interface\OAuth_Callback_Integration;
use Yoast\WP\SEO\Tests\Unit\TestCase;

/**
 * Tests the OAuth_Callback_Integration class.
 *
 * @coversDefaultClass \Yoast\WP\SEO\MyYoast_Client\User_Interface\OAuth_Callback_Integration
 */
final class OAuth_Callback_Integration_Test extends TestCase {

	private const FALLBACK_URL = 'https://example.com/wp-admin/admin.php?page=wpseo_integrations';
	private const RETURN_URL   = 'https://example.com/wp-admin/admin.php?page=wpseo_dashboard';

	/**
	 * The MyYoast client mock.
	 *
	 * @var MyYoast_Client|Mockery\MockInterface
	 */
	private $myyoast_client;

	/**
	 * The authorization code handler mock.
	 *
	 * @var Authorization_Code_Handler|Mockery\MockInterface
	 */
	private $auth_code_handler;

	/**
	 * The redirect helper mock.
	 *
	 * @var Redirect_Helper|Mockery\MockInterface
	 */
	private $redirect_helper;

	/**
	 * The instance under test.
	 *
	 * @var OAuth_Callback_Integration
	 */
	private $instance;

	/**
	 * Sets up the test fixtures.
	 *
	 * @return void
	 */
	protected function set_up() {
		parent::set_up();

		$this->myyoast_client    = Mockery::mock( MyYoast_Client::class );
		$this->auth_code_handler = Mockery::mock( Authorization_Code_Handler::class );
		$this->redirect_helper   = Mockery::mock( Redirect_Helper::class );

		$this->instance = new OAuth_Callback_Integration(
			$this->myyoast_client,
			$this->auth_code_handler,
			$this->redirect_helper,
		);

		// resolve_return_url always builds the fallback and validates any stored
		// URL against it; stub both so every test path has them available.
		Monkey\Functions\stubs(
			[
				'admin_url' => static function ( $path ) {
					return 'https://example.com/wp-admin/' . $path;
				},
				// Valid URLs pass through; a fallback would only be returned for an
				// off-host URL, which is covered explicitly where it matters.
				'wp_validate_redirect' => static function ( $location ) {
					return $location;
				},
			],
		);

		$_GET = [];
	}

	/**
	 * Tears down test fixtures.
	 *
	 * @return void
	 */
	protected function tear_down() {
		$_GET = [];
		parent::tear_down();
	}

	/**
	 * Tests the conditional list.
	 *
	 * @covers ::get_conditionals
	 *
	 * @return void
	 */
	public function test_get_conditionals() {
		$this->assertSame(
			[ MyYoast_Connection_Conditional::class ],
			OAuth_Callback_Integration::get_conditionals(),
		);
	}

	/**
	 * Tests the admin-post hook is registered and the redirect URI is pointed at the callback endpoint.
	 *
	 * @covers ::register_hooks
	 *
	 * @return void
	 */
	public function test_register_hooks() {
		Monkey\Actions\expectAdded( 'admin_post_yoast_myyoast_oauth_callback' )
			->once()
			->with( [ $this->instance, 'handle' ] );

		Monkey\Filters\expectAdded( 'wpseo_myyoast_redirect_uris' )
			->once()
			->with( [ $this->instance, 'filter_redirect_uris' ] );

		Monkey\Filters\expectAdded( 'wpseo_myyoast_authorization_redirect_uri' )
			->once()
			->with( [ $this->instance, 'filter_authorization_redirect_uri' ] );

		$this->instance->register_hooks();
	}

	/**
	 * Tests the redirect-URI filters replace the defaults with the dedicated callback endpoint.
	 *
	 * @covers ::filter_redirect_uris
	 * @covers ::filter_authorization_redirect_uri
	 * @covers ::get_callback_url
	 *
	 * @return void
	 */
	public function test_redirect_uri_filters_use_the_callback_endpoint() {
		$callback_url = 'https://example.com/wp-admin/admin-post.php?action=yoast_myyoast_oauth_callback';

		Monkey\Functions\expect( 'get_admin_url' )
			->with( null, 'admin-post.php?action=yoast_myyoast_oauth_callback' )
			->andReturn( $callback_url );

		$this->assertSame( [ $callback_url ], $this->instance->filter_redirect_uris( [ 'https://default/cb' ] ) );
		$this->assertSame( $callback_url, $this->instance->filter_authorization_redirect_uri( 'https://default/cb' ) );
	}

	/**
	 * Tests the happy path: code + state exchange succeeds, success transient is set.
	 *
	 * @covers ::__construct
	 * @covers ::handle
	 * @covers ::resolve_return_url
	 * @covers ::read_query_arg
	 * @covers ::set_outcome
	 *
	 * @return void
	 */
	public function test_handle_exchanges_code_on_success() {
		$_GET = [
			'code'  => 'abc',
			'state' => 'xyz',
		];

		$this->expect_user( 42 );
		$this->expect_return_url_lookup( 42, self::RETURN_URL );

		$this->myyoast_client->shouldReceive( 'exchange_authorization_code' )
			->once()
			->with( 42, 'abc', 'xyz' );

		$this->expect_transient_set(
			42,
			[
				'kind' => 'success',
				'key'  => 'verify_success',
			],
		);

		$this->expect_redirect( self::RETURN_URL );

		$this->instance->handle();
	}

	/**
	 * Tests `error=access_denied` translates to a `connection_cancelled` transient and discards flow state.
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_access_denied_sets_cancelled_outcome() {
		$_GET = [ 'error' => 'access_denied' ];

		$this->expect_user( 7 );
		$this->expect_return_url_lookup( 7, self::RETURN_URL );

		$this->auth_code_handler->shouldReceive( 'discard_flow_state' )->once()->with( 7 );

		$this->myyoast_client->shouldNotReceive( 'exchange_authorization_code' );

		$this->expect_transient_set(
			7,
			[
				'kind' => 'error',
				'key'  => 'connection_cancelled',
			],
		);

		$this->expect_redirect( self::RETURN_URL );

		$this->instance->handle();
	}

	/**
	 * Tests other OAuth provider errors map to a generic unexpected_error outcome.
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_generic_oauth_error_maps_to_unexpected() {
		$_GET = [ 'error' => 'server_error' ];

		$this->expect_user( 7 );
		$this->expect_return_url_lookup( 7, self::RETURN_URL );

		$this->auth_code_handler->shouldReceive( 'discard_flow_state' )->once()->with( 7 );

		$this->expect_transient_set(
			7,
			[
				'kind' => 'error',
				'key'  => 'unexpected_error',
			],
		);

		$this->expect_redirect( self::RETURN_URL );

		$this->instance->handle();
	}

	/**
	 * Tests Token_Request_Failed_Exception with `invalid_grant` maps to its dedicated message key.
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_invalid_grant_exception_maps_to_dedicated_key() {
		$_GET = [
			'code'  => 'abc',
			'state' => 'xyz',
		];

		$this->expect_user( 11 );
		$this->expect_return_url_lookup( 11, self::RETURN_URL );

		$this->myyoast_client->shouldReceive( 'exchange_authorization_code' )
			->once()
			->andThrow( new Token_Request_Failed_Exception( 'invalid_grant', 'expired' ) );

		$this->expect_transient_set(
			11,
			[
				'kind' => 'error',
				'key'  => 'token_request_failed_invalid_grant',
			],
		);

		$this->expect_redirect( self::RETURN_URL );

		$this->instance->handle();
	}

	/**
	 * Tests other Token_Request_Failed_Exception codes map to the generic token failure key.
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_token_request_failure_maps_to_generic_key() {
		$_GET = [
			'code'  => 'abc',
			'state' => 'xyz',
		];

		$this->expect_user( 11 );
		$this->expect_return_url_lookup( 11, self::RETURN_URL );

		$this->myyoast_client->shouldReceive( 'exchange_authorization_code' )
			->once()
			->andThrow( new Token_Request_Failed_Exception( 'invalid_request', 'state mismatch' ) );

		$this->expect_transient_set(
			11,
			[
				'kind' => 'error',
				'key'  => 'token_request_failed',
			],
		);

		$this->expect_redirect( self::RETURN_URL );

		$this->instance->handle();
	}

	/**
	 * Tests an unexpected exception sets an unexpected_error outcome.
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_unexpected_exception() {
		$_GET = [
			'code'  => 'abc',
			'state' => 'xyz',
		];

		$this->expect_user( 11 );
		$this->expect_return_url_lookup( 11, self::RETURN_URL );

		$this->myyoast_client->shouldReceive( 'exchange_authorization_code' )
			->once()
			->andThrow( new Exception( 'boom' ) );

		$this->expect_transient_set(
			11,
			[
				'kind' => 'error',
				'key'  => 'unexpected_error',
			],
		);

		$this->expect_redirect( self::RETURN_URL );

		$this->instance->handle();
	}

	/**
	 * Tests visiting the callback URL without code/state/error just redirects to the return URL.
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_missing_params_redirects_without_outcome() {
		$_GET = [];

		$this->expect_user( 42 );
		$this->expect_return_url_lookup( 42, self::RETURN_URL );

		$this->myyoast_client->shouldNotReceive( 'exchange_authorization_code' );
		Monkey\Functions\expect( 'set_transient' )->never();

		$this->expect_redirect( self::RETURN_URL );

		$this->instance->handle();
	}

	/**
	 * Tests the handler falls back to the integrations page when no return URL is stored.
	 *
	 * @covers ::resolve_return_url
	 *
	 * @return void
	 */
	public function test_handle_falls_back_to_integrations_page_when_no_return_url_stored() {
		$_GET = [];

		$this->expect_user( 42 );

		$this->auth_code_handler->shouldReceive( 'get_return_url' )->once()->with( 42 )->andReturn( null );

		$this->expect_redirect( self::FALLBACK_URL );

		$this->instance->handle();
	}

	/**
	 * Tests anonymous requests are redirected to the fallback without any transient activity.
	 *
	 * @covers ::handle
	 *
	 * @return void
	 */
	public function test_handle_anonymous_request_redirects_to_fallback() {
		$_GET = [
			'code'  => 'abc',
			'state' => 'xyz',
		];

		$this->expect_user( 0 );

		$this->myyoast_client->shouldNotReceive( 'exchange_authorization_code' );
		Monkey\Functions\expect( 'set_transient' )->never();

		$this->expect_redirect( self::FALLBACK_URL );

		$this->instance->handle();
	}

	/**
	 * Configures the current user id stub.
	 *
	 * @param int $user_id The user id to return.
	 *
	 * @return void
	 */
	private function expect_user( int $user_id ): void {
		Monkey\Functions\expect( 'get_current_user_id' )->andReturn( $user_id );
	}

	/**
	 * Configures the stored return URL lookup.
	 *
	 * @param int    $user_id The user id.
	 * @param string $url     The URL to return.
	 *
	 * @return void
	 */
	private function expect_return_url_lookup( int $user_id, string $url ): void {
		$this->auth_code_handler->shouldReceive( 'get_return_url' )->once()->with( $user_id )->andReturn( $url );
	}

	/**
	 * Configures the expected transient write.
	 *
	 * @param int                              $user_id The user id.
	 * @param array{kind: string, key: string} $value   The expected stored value.
	 *
	 * @return void
	 */
	private function expect_transient_set( int $user_id, array $value ): void {
		Monkey\Functions\expect( 'set_transient' )
			->once()
			->with( 'wpseo_myyoast_oauth_outcome_' . $user_id, $value, \MINUTE_IN_SECONDS );
	}

	/**
	 * Configures the expected redirect.
	 *
	 * @param string $url The expected redirect target.
	 *
	 * @return void
	 */
	private function expect_redirect( string $url ): void {
		$this->redirect_helper->shouldReceive( 'do_safe_redirect' )->once()->with( $url );
	}
}
