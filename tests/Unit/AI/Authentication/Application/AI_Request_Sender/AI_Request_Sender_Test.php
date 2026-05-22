<?php

// phpcs:disable Yoast.NamingConventions.NamespaceName.TooLong -- Needed in the folder structure.
// phpcs:disable Yoast.NamingConventions.NamespaceName.MaxExceeded
namespace Yoast\WP\SEO\Tests\Unit\AI\Authentication\Application\AI_Request_Sender;

use Mockery;
use WP_User;
use Yoast\WP\SEO\AI\Authentication\Application\AI_Request_Sender;
use Yoast\WP\SEO\AI\Authentication\Application\Auth_Strategy_Interface;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\Forbidden_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\Insufficient_Scope_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\OAuth_Forbidden_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\Unauthorized_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Request;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Response;
use Yoast\WP\SEO\Tests\Unit\TestCase;

/**
 * Tests for AI_Request_Sender.
 *
 * @group ai-authentication
 *
 * @coversDefaultClass \Yoast\WP\SEO\AI\Authentication\Application\AI_Request_Sender
 */
final class AI_Request_Sender_Test extends TestCase {

	/**
	 * The primary strategy mock.
	 *
	 * @var Mockery\MockInterface|Auth_Strategy_Interface
	 */
	private $primary;

	/**
	 * The fallback strategy mock.
	 *
	 * @var Mockery\MockInterface|Auth_Strategy_Interface
	 */
	private $fallback;

	/**
	 * The WP user.
	 *
	 * @var WP_User
	 */
	private $user;

	/**
	 * The base request fed into send().
	 *
	 * @var Request
	 */
	private $request;

	/**
	 * Sets up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->primary  = Mockery::mock( Auth_Strategy_Interface::class );
		$this->fallback = Mockery::mock( Auth_Strategy_Interface::class );

		$this->user     = new WP_User();
		$this->user->ID = 42;
		$this->request  = new Request( '/openai/suggestions/seo-title' );
	}

	/**
	 * Happy path: primary returns a response, fallback is never invoked.
	 *
	 * @covers ::__construct
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_returns_response_from_primary(): void {
		$response = new Response( '{}', 200, 'OK' );

		$this->primary->expects( 'send' )->with( $this->request, $this->user )->andReturn( $response );
		$this->fallback->shouldNotReceive( 'send' );

		$sender = new AI_Request_Sender( $this->primary, $this->fallback );

		$this->assertSame( $response, $sender->send( $this->request, $this->user ) );
	}

	/**
	 * When the primary throws a Remote_Request_Exception and a fallback is configured, the fallback dispatches.
	 *
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_falls_back_on_remote_request_exception(): void {
		$fallback_response = new Response( '{}', 200, 'OK' );

		$this->primary->expects( 'send' )->andThrow( new Unauthorized_Exception( 'oauth-failed', 401 ) );
		$this->fallback->expects( 'send' )->with( $this->request, $this->user )->andReturn( $fallback_response );

		$sender = new AI_Request_Sender( $this->primary, $this->fallback );

		$this->assertSame( $fallback_response, $sender->send( $this->request, $this->user ) );
	}

	/**
	 * Without a fallback, Remote_Request_Exception propagates.
	 *
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_rethrows_when_no_fallback(): void {
		$this->primary->expects( 'send' )->andThrow( new Unauthorized_Exception( 'no-recovery', 401 ) );

		$sender = new AI_Request_Sender( $this->primary );

		$this->expectException( Unauthorized_Exception::class );
		$sender->send( $this->request, $this->user );
	}

	/**
	 * Insufficient_Scope_Exception always propagates without invoking the fallback.
	 *
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_propagates_insufficient_scope_without_fallback(): void {
		$this->primary->expects( 'send' )->andThrow( new Insufficient_Scope_Exception( 'INSUFFICIENT_SCOPE', 403, 'INSUFFICIENT_SCOPE' ) );
		$this->fallback->shouldNotReceive( 'send' );

		$sender = new AI_Request_Sender( $this->primary, $this->fallback );

		$this->expectException( Insufficient_Scope_Exception::class );
		$sender->send( $this->request, $this->user );
	}

	/**
	 * OAuth_Forbidden_Exception always propagates without invoking the fallback.
	 *
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_propagates_oauth_forbidden_without_fallback(): void {
		$this->primary->expects( 'send' )->andThrow( new OAuth_Forbidden_Exception( 'policy', 403, 'policy' ) );
		$this->fallback->shouldNotReceive( 'send' );

		$sender = new AI_Request_Sender( $this->primary, $this->fallback );

		$this->expectException( OAuth_Forbidden_Exception::class );
		$sender->send( $this->request, $this->user );
	}

	/**
	 * A plain Forbidden_Exception (not the OAuth-specific subclasses) still falls back when a fallback exists.
	 *
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_falls_back_on_plain_forbidden(): void {
		$fallback_response = new Response( '{}', 200, 'OK' );

		$this->primary->expects( 'send' )->andThrow( new Forbidden_Exception( 'forbidden', 403, 'forbidden' ) );
		$this->fallback->expects( 'send' )->andReturn( $fallback_response );

		$sender = new AI_Request_Sender( $this->primary, $this->fallback );

		$this->assertSame( $fallback_response, $sender->send( $this->request, $this->user ) );
	}
}
