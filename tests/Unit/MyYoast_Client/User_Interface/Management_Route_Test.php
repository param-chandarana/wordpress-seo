<?php

namespace Yoast\WP\SEO\Tests\Unit\MyYoast_Client\User_Interface;

use Brain\Monkey;
use Exception;
use Mockery;
use WP_REST_Request;
use WP_REST_Response;
use Yoast\WP\SEO\Conditionals\MyYoast_Connection_Conditional;
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
use Yoast\WP\SEO\MyYoast_Client\Domain\Registered_Client;
use Yoast\WP\SEO\MyYoast_Client\Infrastructure\OIDC\Issuer_Config;
use Yoast\WP\SEO\MyYoast_Client\User_Interface\Management_Route;
use Yoast\WP\SEO\MyYoast_Client\User_Interface\OAuth_Redirect_Uri;
use Yoast\WP\SEO\MyYoast_Client\User_Interface\Status_Presenter;
use Yoast\WP\SEO\Tests\Unit\TestCase;

/**
 * Tests the Management_Route class.
 *
 * @group routes
 *
 * @coversDefaultClass \Yoast\WP\SEO\MyYoast_Client\User_Interface\Management_Route
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class Management_Route_Test extends TestCase {

	/**
	 * The MyYoast client facade mock.
	 *
	 * @var MyYoast_Client|Mockery\MockInterface
	 */
	private $myyoast_client;

	/**
	 * The status presenter mock.
	 *
	 * @var Status_Presenter|Mockery\MockInterface
	 */
	private $status_presenter;

	/**
	 * The issuer config mock.
	 *
	 * @var Issuer_Config|Mockery\MockInterface
	 */
	private $issuer_config;

	/**
	 * The OAuth redirect URI builder mock.
	 *
	 * @var OAuth_Redirect_Uri|Mockery\MockInterface
	 */
	private $redirect_uri;

	/**
	 * The client registration port mock.
	 *
	 * @var Client_Registration_Interface|Mockery\MockInterface
	 */
	private $client_registration;

	/**
	 * The instance under test.
	 *
	 * @var Management_Route
	 */
	private $instance;

	/**
	 * Sets up the test fixtures.
	 *
	 * @return void
	 */
	protected function set_up() {
		parent::set_up();

		$this->myyoast_client      = Mockery::mock( MyYoast_Client::class );
		$this->status_presenter    = Mockery::mock( Status_Presenter::class );
		$this->issuer_config       = Mockery::mock( Issuer_Config::class );
		$this->redirect_uri        = Mockery::mock( OAuth_Redirect_Uri::class );
		$this->client_registration = Mockery::mock( Client_Registration_Interface::class );

		$this->instance = new Management_Route(
			$this->myyoast_client,
			$this->status_presenter,
			$this->issuer_config,
			$this->redirect_uri,
			$this->client_registration,
		);
	}

	/**
	 * Marks the config as provisioned (default in most tests).
	 *
	 * @return void
	 */
	private function provision(): void {
		$this->issuer_config->shouldReceive( 'get_software_statement' )->andReturn( 'jwt' );
		$this->issuer_config->shouldReceive( 'get_initial_access_token' )->andReturn( 'iat' );
	}

	/**
	 * Marks the config as unprovisioned.
	 *
	 * @return void
	 */
	private function unprovision(): void {
		$this->issuer_config->shouldReceive( 'get_software_statement' )->andReturn( '' );
		$this->issuer_config->shouldReceive( 'get_initial_access_token' )->andReturn( '' );
	}

	/**
	 * Returns the default status payload used by mocked presenter calls.
	 *
	 * @return array<string, mixed>
	 */
	private function status_payload(): array {
		return [
			'is_provisioned'      => true,
			'is_registered'       => true,
			'registered_at'       => 1_731_369_600,
			'registered_at_iso'   => '2025-11-12T00:00:00+00:00',
			'redirect_uris'       => [
				[
					'uri'         => 'https://example.com/wp-admin/admin.php?page=wpseo_dashboard&yoast_myyoast_oauth_callback=1',
					'origin'      => 'https://example.com',
					'is_verified' => false,
				],
			],
			'redirect_uris_match' => true,
		];
	}

	/**
	 * Tests the conditionals.
	 *
	 * @covers ::get_conditionals
	 *
	 * @return void
	 */
	public function test_get_conditionals() {
		$this->assertSame(
			[ MyYoast_Connection_Conditional::class ],
			Management_Route::get_conditionals(),
		);
	}

	/**
	 * Tests that all routes are registered. Five register_rest_route calls
	 * are made: /status (GET), /verify (POST), /register (POST),
	 * /registration (PUT + DELETE on the same path), and /authorize (POST).
	 *
	 * @covers ::register_routes
	 *
	 * @return void
	 */
	public function test_register_routes() {
		Monkey\Functions\expect( 'register_rest_route' )->times( 5 );

		$this->instance->register_routes();
	}

	/**
	 * Tests that the permission callback checks `wpseo_manage_options`.
	 *
	 * @covers ::can_manage
	 *
	 * @return void
	 */
	public function test_can_manage() {
		Monkey\Functions\expect( 'current_user_can' )
			->with( 'wpseo_manage_options' )
			->once()
			->andReturn( true );

		$this->assertTrue( $this->instance->can_manage() );
	}

	/**
	 * Tests GET /myyoast/status.
	 *
	 * @covers ::get_status
	 * @covers ::respond_with_status
	 *
	 * @return void
	 */
	public function test_get_status_returns_payload() {
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$response = $this->instance->get_status();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Tests that verify dispatches to the facade and returns a success payload.
	 *
	 * @covers ::verify
	 *
	 * @return void
	 */
	public function test_verify_success() {
		$this->provision();
		$this->myyoast_client->shouldReceive( 'verify_registration' )->once()->andReturn( [] );
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$response = $this->instance->verify();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Tests that verify hitting Registration_Not_Found_Exception surfaces registration_gone.
	 *
	 * @covers ::verify
	 * @covers ::handle_exception
	 *
	 * @return void
	 */
	public function test_verify_with_registration_gone() {
		$this->provision();
		$this->myyoast_client->shouldReceive( 'verify_registration' )
			->once()
			->andThrow( new Registration_Not_Found_Exception( 'gone' ) );
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$response = $this->instance->verify();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Tests that a Rate_Limited_Exception carrying a Retry-After value
	 * surfaces it on the response body under `details.retry_after_seconds`.
	 *
	 * @covers ::handle_exception
	 * @covers ::error_response
	 *
	 * @return void
	 */
	public function test_rate_limited_response_includes_retry_after_details() {
		$this->provision();
		$this->myyoast_client->shouldReceive( 'verify_registration' )
			->once()
			->andThrow( new Rate_Limited_Exception( 'slow down', 240 ) );
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		$captured = null;
		$mock     = Mockery::mock( 'overload:' . WP_REST_Response::class );
		$mock->shouldReceive( '__construct' )->andReturnUsing(
			static function ( $body ) use ( &$captured ): void {
				$captured = $body;
			},
		);

		$this->instance->verify();

		$this->assertIsArray( $captured );
		$this->assertSame( 'rate_limited', $captured['error_code'] );
		$this->assertArrayHasKey( 'details', $captured );
		$this->assertSame( [ 'retry_after_seconds' => 240 ], $captured['details'] );
	}

	/**
	 * Tests that a Rate_Limited_Exception without a Retry-After value omits
	 * the `details` field entirely.
	 *
	 * @covers ::handle_exception
	 * @covers ::error_response
	 *
	 * @return void
	 */
	public function test_rate_limited_response_omits_details_when_retry_after_missing() {
		$this->provision();
		$this->myyoast_client->shouldReceive( 'verify_registration' )
			->once()
			->andThrow( new Rate_Limited_Exception( 'slow down' ) );
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		$captured = null;
		$mock     = Mockery::mock( 'overload:' . WP_REST_Response::class );
		$mock->shouldReceive( '__construct' )->andReturnUsing(
			static function ( $body ) use ( &$captured ): void {
				$captured = $body;
			},
		);

		$this->instance->verify();

		$this->assertIsArray( $captured );
		$this->assertSame( 'rate_limited', $captured['error_code'] );
		$this->assertArrayNotHasKey( 'details', $captured );
	}

	/**
	 * Tests that verify maps each domain exception to a response (smoke test
	 * — exception-mapping coverage is per-branch but groups into one test).
	 *
	 * @covers ::handle_exception
	 *
	 * @return void
	 */
	public function test_handle_exception_branches() {
		$this->provision();
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$exceptions = [
			new Rate_Limited_Exception( 'rate' ),
			new Server_Capability_Exception( 'cap' ),
			new Discovery_Failed_Exception( 'discovery' ),
			new Token_Request_Failed_Exception( 'invalid_grant', 'bad', 400 ),
			new Token_Request_Failed_Exception( 'invalid_client', 'bad', 400 ),
			new Token_Storage_Exception( 'storage' ),
			new Registration_Failed_Exception( 'other' ),
			new Exception( 'boom' ),
		];

		foreach ( $exceptions as $exception ) {
			$this->myyoast_client->shouldReceive( 'verify_registration' )
				->once()
				->andThrow( $exception );

			$response = $this->instance->verify();

			$this->assertInstanceOf( WP_REST_Response::class, $response );
		}
	}

	/**
	 * Tests POST /myyoast/register delegates to ensure_registered with the current redirect URI.
	 *
	 * @covers ::register
	 *
	 * @return void
	 */
	public function test_register_delegates_to_ensure_registered() {
		$this->provision();

		$this->redirect_uri->shouldReceive( 'get' )->andReturn( 'https://example.com/wp-admin/admin.php?page=wpseo_dashboard' );
		$this->myyoast_client->shouldNotReceive( 'deregister' );
		$this->myyoast_client->shouldReceive( 'ensure_registered' )
			->once()
			->with( [ 'https://example.com/wp-admin/admin.php?page=wpseo_dashboard' ] );
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$response = $this->instance->register();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Tests PUT /myyoast/registration calls update_redirect_uris with the current URI.
	 *
	 * @covers ::update_registration
	 *
	 * @return void
	 */
	public function test_update_registration() {
		$this->provision();

		$this->redirect_uri->shouldReceive( 'get' )->andReturn( 'https://example.com/wp-admin/admin.php?page=wpseo_dashboard' );
		$this->myyoast_client->shouldReceive( 'update_redirect_uris' )
			->once()
			->with( [ 'https://example.com/wp-admin/admin.php?page=wpseo_dashboard' ] );
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$response = $this->instance->update_registration();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Tests update_registration surfaces Registration_Not_Found as a normal error response.
	 *
	 * @covers ::update_registration
	 * @covers ::handle_exception
	 *
	 * @return void
	 */
	public function test_update_registration_handles_registration_gone() {
		$this->provision();

		$this->redirect_uri->shouldReceive( 'get' )->andReturn( 'https://example.com/uri' );
		$this->myyoast_client->shouldReceive( 'update_redirect_uris' )
			->once()
			->andThrow( new Registration_Not_Found_Exception( 'gone' ) );
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$response = $this->instance->update_registration();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Tests deregister dispatches to the facade and also clears all site tokens.
	 *
	 * @covers ::deregister
	 *
	 * @return void
	 */
	public function test_deregister_success() {
		$this->provision();

		$this->myyoast_client->shouldReceive( 'deregister' )->once()->andReturn( true );
		$this->myyoast_client->shouldReceive( 'clear_all_site_tokens' )->once();
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$response = $this->instance->deregister();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Tests deregister still clears site tokens and reports success when the
	 * server-side teardown could not be confirmed (transport failure).
	 *
	 * @covers ::deregister
	 *
	 * @return void
	 */
	public function test_deregister_succeeds_locally_when_remote_unconfirmed() {
		$this->provision();

		$this->myyoast_client->shouldReceive( 'deregister' )->once()->andReturn( false );
		$this->myyoast_client->shouldReceive( 'clear_all_site_tokens' )->once();
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$response = $this->instance->deregister();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Tests deregister still clears site tokens when the remote call throws
	 * unexpectedly, so the site is never left half-connected.
	 *
	 * @covers ::deregister
	 *
	 * @return void
	 */
	public function test_deregister_clears_tokens_when_remote_throws() {
		$this->provision();

		$this->myyoast_client->shouldReceive( 'deregister' )->once()->andThrow( new Exception( 'boom' ) );
		$this->myyoast_client->shouldReceive( 'clear_all_site_tokens' )->once();
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$response = $this->instance->deregister();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Tests the registration-mutating endpoints short-circuit when the plugin is
	 * not provisioned.
	 *
	 * Only register/update/authorize require the software statement and initial
	 * access token; verify and deregister work without them and are covered by
	 * their own tests.
	 *
	 * @covers ::require_provisioned
	 *
	 * @return void
	 */
	public function test_registration_actions_blocked_when_not_provisioned() {
		$this->unprovision();
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		$this->myyoast_client->shouldNotReceive( 'ensure_registered' );
		$this->myyoast_client->shouldNotReceive( 'update_redirect_uris' );
		$this->myyoast_client->shouldNotReceive( 'get_authorization_url' );

		$request = Mockery::mock( WP_REST_Request::class );

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$this->assertInstanceOf( WP_REST_Response::class, $this->instance->register() );
		$this->assertInstanceOf( WP_REST_Response::class, $this->instance->update_registration() );
		$this->assertInstanceOf( WP_REST_Response::class, $this->instance->authorize( $request ) );
	}

	/**
	 * Tests verify works regardless of provisioning — it talks to MyYoast with
	 * the stored registration access token, not the software statement.
	 *
	 * @covers ::verify
	 *
	 * @return void
	 */
	public function test_verify_works_when_not_provisioned() {
		$this->unprovision();
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );
		$this->myyoast_client->shouldReceive( 'verify_registration' )->once();

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$this->assertInstanceOf( WP_REST_Response::class, $this->instance->verify() );
	}

	/**
	 * Tests deregister works regardless of provisioning — disconnecting only
	 * needs the stored registration, not the software statement.
	 *
	 * @covers ::deregister
	 *
	 * @return void
	 */
	public function test_deregister_works_when_not_provisioned() {
		$this->unprovision();
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );
		$this->myyoast_client->shouldReceive( 'deregister' )->once()->andReturn( true );
		$this->myyoast_client->shouldReceive( 'clear_all_site_tokens' )->once();

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$this->assertInstanceOf( WP_REST_Response::class, $this->instance->deregister() );
	}

	/**
	 * Tests authorize returns the authorization URL for a known redirect URI.
	 *
	 * @covers ::authorize
	 * @covers ::is_known_redirect_uri
	 *
	 * @return void
	 */
	public function test_authorize_returns_authorization_url() {
		$this->provision();

		$target_uri    = 'https://example.com/wp-admin/admin-post.php?action=yoast_myyoast_oauth_callback';
		$return_url    = 'https://example.com/wp-admin/admin.php?page=wpseo_integrations';
		$authorize_url = 'https://my.yoast.com/auth?code_challenge=abc';

		$this->client_registration->shouldReceive( 'get_registered_client' )
			->andReturn(
				new Registered_Client(
					'client-123',
					'rat',
					'https://my.yoast.com/clients/client-123',
					[ 'redirect_uris' => [ $target_uri ] ],
				),
			);

		Monkey\Functions\expect( 'get_current_user_id' )->andReturn( 42 );

		Monkey\Functions\expect( 'admin_url' )
			->with( 'admin.php?page=wpseo_integrations' )
			->andReturn( 'https://example.com/wp-admin/admin.php?page=wpseo_integrations' );

		$this->myyoast_client->shouldReceive( 'get_authorization_url' )
			->once()
			->with( 42, $target_uri, [ 'openid' ], null, $return_url )
			->andReturn( $authorize_url );

		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		$request = Mockery::mock( WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->with( 'redirect_uri' )->andReturn( $target_uri );

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$response = $this->instance->authorize( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
	}

	/**
	 * Tests authorize rejects an unknown redirect URI.
	 *
	 * @covers ::authorize
	 * @covers ::is_known_redirect_uri
	 *
	 * @return void
	 */
	public function test_authorize_rejects_unknown_redirect_uri() {
		$this->provision();

		$this->client_registration->shouldReceive( 'get_registered_client' )
			->andReturn(
				new Registered_Client(
					'client-123',
					'rat',
					'https://my.yoast.com/clients/client-123',
					[ 'redirect_uris' => [ 'https://example.com/known' ] ],
				),
			);

		$this->myyoast_client->shouldNotReceive( 'get_authorization_url' );

		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		$request = Mockery::mock( WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->with( 'redirect_uri' )->andReturn( 'https://attacker.example/evil' );

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$this->assertInstanceOf( WP_REST_Response::class, $this->instance->authorize( $request ) );
	}

	/**
	 * Tests authorize surfaces an error when the site is not registered.
	 *
	 * @covers ::authorize
	 *
	 * @return void
	 */
	public function test_authorize_when_not_registered() {
		$this->provision();

		$this->client_registration->shouldReceive( 'get_registered_client' )->andReturn( null );
		$this->myyoast_client->shouldNotReceive( 'get_authorization_url' );
		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		$request = Mockery::mock( WP_REST_Request::class );

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$this->assertInstanceOf( WP_REST_Response::class, $this->instance->authorize( $request ) );
	}

	/**
	 * Tests authorize maps Authorization_Flow_Exception to a registration_failed error.
	 *
	 * @covers ::authorize
	 *
	 * @return void
	 */
	public function test_authorize_handles_flow_exception() {
		$this->provision();

		$target_uri = 'https://example.com/known';

		$this->client_registration->shouldReceive( 'get_registered_client' )
			->andReturn(
				new Registered_Client(
					'client-123',
					'rat',
					'https://my.yoast.com/clients/client-123',
					[ 'redirect_uris' => [ $target_uri ] ],
				),
			);

		Monkey\Functions\expect( 'get_current_user_id' )->andReturn( 42 );

		Monkey\Functions\expect( 'admin_url' )
			->with( 'admin.php?page=wpseo_integrations' )
			->andReturn( 'https://example.com/wp-admin/admin.php?page=wpseo_integrations' );

		$this->myyoast_client->shouldReceive( 'get_authorization_url' )
			->andThrow( new Authorization_Flow_Exception( 'registration_failed', 'boom' ) );

		$this->status_presenter->shouldReceive( 'present' )->andReturn( $this->status_payload() );

		$request = Mockery::mock( WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->with( 'redirect_uri' )->andReturn( $target_uri );

		Mockery::mock( 'overload:' . WP_REST_Response::class );

		$this->assertInstanceOf( WP_REST_Response::class, $this->instance->authorize( $request ) );
	}
}
