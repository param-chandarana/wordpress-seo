<?php

// phpcs:disable Yoast.NamingConventions.NamespaceName.TooLong -- Needed in the folder structure.
// phpcs:disable Yoast.NamingConventions.NamespaceName.MaxExceeded
namespace Yoast\WP\SEO\Tests\Unit\AI\Authentication\Application\Token_Auth_Strategy;

use Mockery;
use WP_User;
use Yoast\WP\SEO\AI\Authentication\Application\Token_Auth_Strategy;
use Yoast\WP\SEO\AI\Authorization\Application\Token_Manager;
use Yoast\WP\SEO\AI\HTTP_Request\Application\Request_Handler;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\Bad_Request_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\Unauthorized_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Request;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Response;
use Yoast\WP\SEO\Helpers\User_Helper;
use Yoast\WP\SEO\Tests\Unit\TestCase;

/**
 * Tests for Token_Auth_Strategy.
 *
 * @group ai-authentication
 *
 * @coversDefaultClass \Yoast\WP\SEO\AI\Authentication\Application\Token_Auth_Strategy
 */
final class Token_Auth_Strategy_Test extends TestCase {

	/**
	 * The token manager mock.
	 *
	 * @var Mockery\MockInterface|Token_Manager
	 */
	private $token_manager;

	/**
	 * The user helper mock.
	 *
	 * @var Mockery\MockInterface|User_Helper
	 */
	private $user_helper;

	/**
	 * The request handler mock.
	 *
	 * @var Mockery\MockInterface|Request_Handler
	 */
	private $request_handler;

	/**
	 * The WP user.
	 *
	 * @var WP_User
	 */
	private $user;

	/**
	 * The instance under test.
	 *
	 * @var Token_Auth_Strategy
	 */
	private $instance;

	/**
	 * Sets up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->token_manager   = Mockery::mock( Token_Manager::class );
		$this->user_helper     = Mockery::mock( User_Helper::class );
		$this->request_handler = Mockery::mock( Request_Handler::class );

		$this->user     = new WP_User();
		$this->user->ID = 42;

		$this->instance = new Token_Auth_Strategy( $this->token_manager, $this->user_helper, $this->request_handler );
	}

	/**
	 * Happy path: token is fetched, Bearer header is attached, request_handler returns the response.
	 *
	 * @covers ::__construct
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_attaches_bearer_header_and_dispatches(): void {
		$response = new Response( '{}', 200, 'OK' );

		$this->token_manager->expects( 'get_or_request_access_token' )->with( $this->user )->andReturn( 'jwt-token' );
		$this->request_handler->expects( 'handle' )->with(
			Mockery::on(
				function ( Request $decorated ): bool {
					$this->assertSame( 'Bearer jwt-token', ( $decorated->get_headers()['Authorization'] ?? null ) );
					return true;
				},
			),
		)->andReturn( $response );

		$this->user_helper->shouldNotReceive( 'delete_meta' );

		$this->assertSame( $response, $this->instance->send( new Request( '/openai/suggestions/seo-title' ), $this->user ) );
	}

	/**
	 * On 401, stored access + refresh JWT meta are deleted and Unauthorized_Exception is rethrown.
	 *
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_deletes_jwts_on_401(): void {
		$this->token_manager->expects( 'get_or_request_access_token' )->andReturn( 'jwt-token' );
		$this->request_handler->expects( 'handle' )->andThrow( new Unauthorized_Exception( '401', 401 ) );

		$this->user_helper->expects( 'delete_meta' )->with( $this->user->ID, '_yoast_wpseo_ai_generator_access_jwt' )->once();
		$this->user_helper->expects( 'delete_meta' )->with( $this->user->ID, '_yoast_wpseo_ai_generator_refresh_jwt' )->once();

		$this->expectException( Unauthorized_Exception::class );
		$this->instance->send( new Request( '/openai/suggestions/seo-title' ), $this->user );
	}

	/**
	 * Non-401 errors propagate without touching stored JWTs.
	 *
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_propagates_non_401_without_clearing_jwts(): void {
		$this->token_manager->expects( 'get_or_request_access_token' )->andReturn( 'jwt-token' );
		$this->request_handler->expects( 'handle' )->andThrow( new Bad_Request_Exception( '400', 400 ) );
		$this->user_helper->shouldNotReceive( 'delete_meta' );

		$this->expectException( Bad_Request_Exception::class );
		$this->instance->send( new Request( '/openai/suggestions/seo-title' ), $this->user );
	}
}
