<?php

// phpcs:disable Yoast.NamingConventions.NamespaceName.TooLong -- Needed in the folder structure.
// phpcs:disable Yoast.NamingConventions.NamespaceName.MaxExceeded
namespace Yoast\WP\SEO\Tests\Unit\AI\Authentication\Application\AI_Request_Sender;

use Mockery;
use WP_User;
use Yoast\WP\SEO\AI\Authentication\Application\AI_Request_Sender;
use Yoast\WP\SEO\AI\Authentication\Application\Auth_Strategy_Interface;
use Yoast\WP\SEO\AI\Content_Planner\Domain\Content_Outline_Parameters;
use Yoast\WP\SEO\AI\Content_Planner\Domain\Content_Suggestion_Parameters;
use Yoast\WP\SEO\AI\Generator\Domain\Suggestions_Parameters;
use Yoast\WP\SEO\AI\Generator\Domain\Usage_Parameters;
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
	}

	/**
	 * Builds a suggestions parameter object that is used to exercise the shared dispatch path.
	 *
	 * @return Suggestions_Parameters The parameters.
	 */
	private function suggestions_parameters(): Suggestions_Parameters {
		return new Suggestions_Parameters(
			$this->user,
			'seo-title',
			'prompt content',
			'focus keyphrase',
			'en_US',
			'web',
			'gutenberg',
		);
	}

	/**
	 * Happy path: primary returns a response, fallback is never invoked.
	 *
	 * @covers ::__construct
	 * @covers ::get_suggestions
	 * @covers ::dispatch
	 *
	 * @return void
	 */
	public function test_dispatch_returns_response_from_primary(): void {
		$response = new Response( '{}', 200, 'OK' );

		$this->primary->expects( 'send' )->with( Mockery::type( Request::class ), $this->user )->andReturn( $response );
		$this->fallback->shouldNotReceive( 'send' );

		$sender = new AI_Request_Sender( $this->primary, $this->fallback );

		$this->assertSame( $response, $sender->get_suggestions( $this->suggestions_parameters() ) );
	}

	/**
	 * When the primary throws a Remote_Request_Exception and a fallback is configured, the fallback dispatches.
	 *
	 * @covers ::dispatch
	 *
	 * @return void
	 */
	public function test_dispatch_falls_back_on_remote_request_exception(): void {
		$fallback_response = new Response( '{}', 200, 'OK' );

		$this->primary->expects( 'send' )->andThrow( new Unauthorized_Exception( 'oauth-failed', 401 ) );
		$this->fallback->expects( 'send' )->with( Mockery::type( Request::class ), $this->user )->andReturn( $fallback_response );

		$sender = new AI_Request_Sender( $this->primary, $this->fallback );

		$this->assertSame( $fallback_response, $sender->get_suggestions( $this->suggestions_parameters() ) );
	}

	/**
	 * Without a fallback, Remote_Request_Exception propagates.
	 *
	 * @covers ::dispatch
	 *
	 * @return void
	 */
	public function test_dispatch_rethrows_when_no_fallback(): void {
		$this->primary->expects( 'send' )->andThrow( new Unauthorized_Exception( 'no-recovery', 401 ) );

		$sender = new AI_Request_Sender( $this->primary );

		$this->expectException( Unauthorized_Exception::class );
		$sender->get_suggestions( $this->suggestions_parameters() );
	}

	/**
	 * Insufficient_Scope_Exception always propagates without invoking the fallback.
	 *
	 * @covers ::dispatch
	 *
	 * @return void
	 */
	public function test_dispatch_propagates_insufficient_scope_without_fallback(): void {
		$this->primary->expects( 'send' )->andThrow( new Insufficient_Scope_Exception( 'INSUFFICIENT_SCOPE', 403, 'INSUFFICIENT_SCOPE' ) );
		$this->fallback->shouldNotReceive( 'send' );

		$sender = new AI_Request_Sender( $this->primary, $this->fallback );

		$this->expectException( Insufficient_Scope_Exception::class );
		$sender->get_suggestions( $this->suggestions_parameters() );
	}

	/**
	 * OAuth_Forbidden_Exception always propagates without invoking the fallback.
	 *
	 * @covers ::dispatch
	 *
	 * @return void
	 */
	public function test_dispatch_propagates_oauth_forbidden_without_fallback(): void {
		$this->primary->expects( 'send' )->andThrow( new OAuth_Forbidden_Exception( 'policy', 403, 'policy' ) );
		$this->fallback->shouldNotReceive( 'send' );

		$sender = new AI_Request_Sender( $this->primary, $this->fallback );

		$this->expectException( OAuth_Forbidden_Exception::class );
		$sender->get_suggestions( $this->suggestions_parameters() );
	}

	/**
	 * A plain Forbidden_Exception (not the OAuth-specific subclasses) still falls back when a fallback exists.
	 *
	 * @covers ::dispatch
	 *
	 * @return void
	 */
	public function test_dispatch_falls_back_on_plain_forbidden(): void {
		$fallback_response = new Response( '{}', 200, 'OK' );

		$this->primary->expects( 'send' )->andThrow( new Forbidden_Exception( 'forbidden', 403, 'forbidden' ) );
		$this->fallback->expects( 'send' )->andReturn( $fallback_response );

		$sender = new AI_Request_Sender( $this->primary, $this->fallback );

		$this->assertSame( $fallback_response, $sender->get_suggestions( $this->suggestions_parameters() ) );
	}

	/**
	 * Builds the expected Request for the content-outline endpoint and dispatches it.
	 *
	 * @covers ::get_content_outline_suggestions
	 *
	 * @return void
	 */
	public function test_get_content_outline_suggestions_builds_request(): void {
		$content    = [
			'new_post_metadata' => [ 'title' => 'How to use AI' ],
			'existing_posts'    => [],
		];
		$parameters = new Content_Outline_Parameters( $this->user, 'en_US', $content, 'gutenberg' );
		$response   = new Response( '{}', 200, 'OK' );

		$this->primary
			->expects( 'send' )
			->with(
				Mockery::on(
					static function ( $request ) use ( $content ) {
						return $request instanceof Request
							&& $request->get_action_path() === '/content-planner/next-post-outline'
							&& $request->is_post() === true
							&& $request->get_headers() === [ 'X-Yst-Cohort' => 'gutenberg' ]
							&& $request->get_body() === [
								'subject' => [
									'language' => 'en_US',
									'content'  => $content,
								],
							];
					},
				),
				$this->user,
			)
			->andReturn( $response );

		$sender = new AI_Request_Sender( $this->primary );

		$this->assertSame( $response, $sender->get_content_outline_suggestions( $parameters ) );
	}

	/**
	 * Builds the expected Request for the content-suggestions endpoint and dispatches it.
	 *
	 * @covers ::get_content_suggestions
	 *
	 * @return void
	 */
	public function test_get_content_suggestions_builds_request(): void {
		$content    = [
			'posts' => [
				[
					'title'       => 'Existing post',
					'description' => 'Existing description',
				],
			],
		];
		$parameters = new Content_Suggestion_Parameters( $this->user, 'en_US', $content, 'gutenberg' );
		$response   = new Response( '{}', 200, 'OK' );

		$this->primary
			->expects( 'send' )
			->with(
				Mockery::on(
					static function ( $request ) use ( $content ) {
						return $request instanceof Request
							&& $request->get_action_path() === '/content-planner/next-post-suggestions'
							&& $request->is_post() === true
							&& $request->get_headers() === [ 'X-Yst-Cohort' => 'gutenberg' ]
							&& $request->get_body() === [
								'subject' => [
									'language' => 'en_US',
									'content'  => $content,
								],
							];
					},
				),
				$this->user,
			)
			->andReturn( $response );

		$sender = new AI_Request_Sender( $this->primary );

		$this->assertSame( $response, $sender->get_content_suggestions( $parameters ) );
	}

	/**
	 * Builds the expected Request for the OpenAI suggestions endpoint, interpolating the suggestion type.
	 *
	 * @covers ::get_suggestions
	 *
	 * @return void
	 */
	public function test_get_suggestions_builds_request(): void {
		$parameters = new Suggestions_Parameters(
			$this->user,
			'seo-title',
			'prompt content',
			'focus keyphrase',
			'en_US',
			'web',
			'gutenberg',
		);
		$response   = new Response( '{}', 200, 'OK' );

		$this->primary
			->expects( 'send' )
			->with(
				Mockery::on( [ self::class, 'request_matches_suggestions_shape' ] ),
				$this->user,
			)
			->andReturn( $response );

		$sender = new AI_Request_Sender( $this->primary );

		$this->assertSame( $response, $sender->get_suggestions( $parameters ) );
	}

	/**
	 * Matcher used by the suggestions test to verify the dispatched request.
	 *
	 * @param mixed $request The candidate request.
	 *
	 * @return bool True when the request matches the expected shape.
	 */
	public static function request_matches_suggestions_shape( $request ): bool {
		if ( ! $request instanceof Request ) {
			return false;
		}
		if ( $request->get_action_path() !== '/openai/suggestions/seo-title' ) {
			return false;
		}
		if ( $request->is_post() !== true ) {
			return false;
		}
		if ( $request->get_headers() !== [ 'X-Yst-Cohort' => 'gutenberg' ] ) {
			return false;
		}

		return $request->get_body() === [
			'service' => 'openai',
			'user_id' => '42',
			'subject' => [
				'content'         => 'prompt content',
				'focus_keyphrase' => 'focus keyphrase',
				'language'        => 'en_US',
				'platform'        => 'web',
			],
		];
	}

	/**
	 * Builds the expected GET Request for the usage endpoint when the user has unlimited usage.
	 *
	 * @covers ::get_usage
	 *
	 * @return void
	 */
	public function test_get_usage_builds_unlimited_request(): void {
		$parameters     = new Usage_Parameters( $this->user, true );
		$response       = new Response( '{}', 200, 'OK' );
		$expected_month = \gmdate( 'Y-m' );

		$this->primary
			->expects( 'send' )
			->with(
				Mockery::on(
					static function ( $request ) use ( $expected_month ) {
						return $request instanceof Request
							&& $request->get_action_path() === '/usage/' . $expected_month
							&& $request->is_post() === false
							&& $request->get_body() === []
							&& $request->get_headers() === [];
					},
				),
				$this->user,
			)
			->andReturn( $response );

		$sender = new AI_Request_Sender( $this->primary );

		$this->assertSame( $response, $sender->get_usage( $parameters ) );
	}

	/**
	 * Builds the expected GET Request for the free-usages endpoint when the user does not have unlimited usage.
	 *
	 * @covers ::get_usage
	 *
	 * @return void
	 */
	public function test_get_usage_builds_free_usages_request(): void {
		$parameters = new Usage_Parameters( $this->user, false );
		$response   = new Response( '{}', 200, 'OK' );

		$this->primary
			->expects( 'send' )
			->with(
				Mockery::on(
					static function ( $request ) {
						return $request instanceof Request
							&& $request->get_action_path() === '/usage/free-usages'
							&& $request->is_post() === false
							&& $request->get_body() === []
							&& $request->get_headers() === [];
					},
				),
				$this->user,
			)
			->andReturn( $response );

		$sender = new AI_Request_Sender( $this->primary );

		$this->assertSame( $response, $sender->get_usage( $parameters ) );
	}
}
