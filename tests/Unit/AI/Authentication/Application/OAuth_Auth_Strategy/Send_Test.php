<?php

// phpcs:disable Yoast.NamingConventions.NamespaceName.TooLong -- Needed in the folder structure.
// phpcs:disable Yoast.NamingConventions.NamespaceName.MaxExceeded
namespace Yoast\WP\SEO\Tests\Unit\AI\Authentication\Application\OAuth_Auth_Strategy;

use Brain\Monkey\Functions;
use Mockery;
use WP_User;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\Bad_Request_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\Insufficient_Scope_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\Internal_Server_Error_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\OAuth_Forbidden_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\Unauthorized_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Request;
use Yoast\WP\SEO\MyYoast_Client\Application\Exceptions\Token_Request_Failed_Exception;
use Yoast\WP\SEO\MyYoast_Client\Domain\HTTP_Response;

/**
 * Tests for OAuth_Auth_Strategy::send().
 *
 * @coversDefaultClass \Yoast\WP\SEO\AI\Authentication\Application\OAuth_Auth_Strategy
 */
final class Send_Test extends Abstract_OAuth_Auth_Strategy_Test {

	/**
	 * Stubs the WPSEO_Utils::format_json_encode call (it's a static on a real class — we don't
	 * need to mock it, but we do need to be aware that POST bodies are JSON-encoded through it).
	 *
	 * Brain Monkey's add_query_arg stub appends ?key=value naively which is fine for our assertions.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\stubs(
			[
				'add_query_arg' => static function ( array $args, string $url ): string {
					$separator = ( \strpos( $url, '?' ) === false ) ? '?' : '&';
					return $url . $separator . \http_build_query( $args );
				},
			],
		);
	}

	/**
	 * Happy path POST: user_id merged into body, JSON-encoded, Content-Type set, authenticated_request invoked.
	 *
	 * @covers ::__construct
	 * @covers ::send
	 * @covers ::to_response
	 *
	 * @return void
	 */
	public function test_send_posts_user_id_in_body(): void {
		$this->myyoast_client->expects( 'get_site_token' )->with( [ 'service:ai:consume' ], 'https://ai.yoa.st' )->andReturn( $this->token_set );

		$this->myyoast_client->expects( 'authenticated_request' )
			->with(
				'POST',
				'https://ai.yoa.st/api/v1/openai/suggestions/seo-title',
				$this->token_set,
				Mockery::on(
					function ( array $options ): bool {
						$this->assertSame( 'application/json', ( $options['headers']['Content-Type'] ?? null ) );
						$this->assertSame( 60, ( $options['timeout'] ?? null ) );
						$this->assertJson( (string) ( $options['body'] ?? '' ) );
						$body = \json_decode( $options['body'], true );
						$this->assertSame( 'hello', ( $body['prompt'] ?? null ) );
						$this->assertSame( '42', ( $body['user_id'] ?? null ) );
						return true;
					},
				),
			)
			->andReturn( new HTTP_Response( 200, [], '{"result":"ok"}' ) );

		$response = $this->instance->send(
			new Request( '/openai/suggestions/seo-title', [ 'prompt' => 'hello' ] ),
			$this->user,
		);

		$this->assertSame( 200, $response->get_response_code() );
	}

	/**
	 * Happy path GET: user_id appended as a query parameter; no body is sent.
	 *
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_appends_user_id_to_url_for_get(): void {
		$this->myyoast_client->expects( 'get_site_token' )->andReturn( $this->token_set );

		$this->myyoast_client->expects( 'authenticated_request' )
			->with(
				'GET',
				'https://ai.yoa.st/api/v1/usage/free-usages?user_id=42',
				$this->token_set,
				Mockery::on(
					function ( array $options ): bool {
						$this->assertArrayNotHasKey( 'body', $options );
						$this->assertSame( 'application/json', ( $options['headers']['Content-Type'] ?? null ) );
						return true;
					},
				),
			)
			->andReturn( new HTTP_Response( 200, [], '' ) );

		$this->instance->send( new Request( '/usage/free-usages', [], [], false ), $this->user );
	}

	/**
	 * GET with a pre-existing query string: user_id is appended without clobbering existing params.
	 *
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_preserves_existing_query_string_on_get(): void {
		$this->myyoast_client->expects( 'get_site_token' )->andReturn( $this->token_set );
		$this->myyoast_client->expects( 'authenticated_request' )
			->with(
				'GET',
				Mockery::on(
					function ( string $url ): bool {
						$this->assertStringContainsString( 'user_id=42', $url );
						$this->assertStringContainsString( 'plan=free', $url );
						return true;
					},
				),
				$this->token_set,
				Mockery::any(),
			)
			->andReturn( new HTTP_Response( 200, [], '' ) );

		$this->instance->send( new Request( '/usage/free-usages?plan=free', [], [], false ), $this->user );
	}

	/**
	 * Two consecutive sends share the cached site token; each carries that call's user id.
	 *
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_shares_site_token_across_users(): void {
		$user_b     = new WP_User();
		$user_b->ID = 99;

		$this->myyoast_client->expects( 'get_site_token' )->twice()->andReturn( $this->token_set, $this->token_set );

		$this->myyoast_client->shouldReceive( 'authenticated_request' )
			->twice()
			->andReturn( new HTTP_Response( 200, [], '{}' ), new HTTP_Response( 200, [], '{}' ) );

		$this->instance->send( new Request( '/openai/suggestions/seo-title', [ 'prompt' => 'x' ] ), $this->user );
		$this->instance->send( new Request( '/openai/suggestions/seo-title', [ 'prompt' => 'y' ] ), $user_b );
	}

	/**
	 * Token issuance failure is translated into Bad_Request_Exception('OAUTH_TOKEN_UNAVAILABLE').
	 *
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_translates_token_request_failure(): void {
		$this->myyoast_client->expects( 'get_site_token' )->andThrow( new Token_Request_Failed_Exception( 'invalid_client', 'Client auth failed.' ) );
		$this->myyoast_client->shouldNotReceive( 'authenticated_request' );

		try {
			$this->instance->send( new Request( '/openai/suggestions/seo-title' ), $this->user );
			$this->fail( 'Expected Bad_Request_Exception.' );
		}
		catch ( Bad_Request_Exception $exception ) {
			$this->assertSame( 'OAUTH_TOKEN_UNAVAILABLE', $exception->get_error_identifier() );
		}
	}

	/**
	 * 401 from yoast-ai clears the cached site token and rethrows Unauthorized_Exception.
	 *
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_clears_site_token_on_401(): void {
		$this->myyoast_client->expects( 'get_site_token' )->andReturn( $this->token_set );
		$this->myyoast_client->expects( 'authenticated_request' )
			->andReturn(
				new HTTP_Response(
					401,
					[],
					[
						'message'    => 'token expired',
						'error_code' => 'invalid_token',
					],
				),
			);
		$this->myyoast_client->expects( 'clear_site_token' )->with( 'https://ai.yoa.st' )->once();

		$this->expectException( Unauthorized_Exception::class );
		$this->instance->send( new Request( '/openai/suggestions/seo-title' ), $this->user );
	}

	/**
	 * The resource indicator passed to MyYoast tracks API_Client::get_resource_url(), so a filter
	 * override flows through to both the token request and the 401 cache clear in lockstep.
	 *
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_binds_token_to_api_client_resource_url(): void {
		$this->api_client->shouldReceive( 'get_resource_url' )->andReturn( 'https://ai-dev.yoa.st' );

		$this->myyoast_client->expects( 'get_site_token' )->with( [ 'service:ai:consume' ], 'https://ai-dev.yoa.st' )->andReturn( $this->token_set );
		$this->myyoast_client->expects( 'authenticated_request' )
			->andReturn(
				new HTTP_Response(
					401,
					[],
					[
						'message'    => 'token expired',
						'error_code' => 'invalid_token',
					],
				),
			);
		$this->myyoast_client->expects( 'clear_site_token' )->with( 'https://ai-dev.yoa.st' )->once();

		$this->expectException( Unauthorized_Exception::class );
		$this->instance->send( new Request( '/openai/suggestions/seo-title' ), $this->user );
	}

	/**
	 * 403 insufficient_scope is translated into Insufficient_Scope_Exception (sender bypasses fallback).
	 * The cached site token is NOT cleared — it's still valid, just under-scoped.
	 *
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_throws_insufficient_scope(): void {
		$this->myyoast_client->expects( 'get_site_token' )->andReturn( $this->token_set );
		$this->myyoast_client->expects( 'authenticated_request' )
			->andReturn(
				new HTTP_Response(
					403,
					[ 'www-authenticate' => 'Bearer error="insufficient_scope", scope="service:ai:consume"' ],
					[
						'message'    => 'forbidden',
						'error_code' => 'insufficient_scope',
					],
				),
			);
		$this->myyoast_client->shouldNotReceive( 'clear_site_token' );

		try {
			$this->instance->send( new Request( '/openai/suggestions/seo-title' ), $this->user );
			$this->fail( 'Expected Insufficient_Scope_Exception.' );
		}
		catch ( Insufficient_Scope_Exception $exception ) {
			$this->assertSame( 'INSUFFICIENT_SCOPE', $exception->get_error_identifier() );
		}
	}

	/**
	 * Plain 403 (no insufficient_scope marker) is translated into OAuth_Forbidden_Exception.
	 *
	 * @covers ::send
	 *
	 * @return void
	 */
	public function test_send_throws_oauth_forbidden_on_plain_403(): void {
		$this->myyoast_client->expects( 'get_site_token' )->andReturn( $this->token_set );
		$this->myyoast_client->expects( 'authenticated_request' )
			->andReturn(
				new HTTP_Response(
					403,
					[],
					[
						'message'    => 'no',
						'error_code' => 'policy_failure',
					],
				),
			);
		$this->myyoast_client->shouldNotReceive( 'clear_site_token' );

		try {
			$this->instance->send( new Request( '/openai/suggestions/seo-title' ), $this->user );
			$this->fail( 'Expected OAuth_Forbidden_Exception.' );
		}
		catch ( OAuth_Forbidden_Exception $exception ) {
			$this->assertSame( 'policy_failure', $exception->get_error_identifier() );
		}
	}

	/**
	 * Non-200 status with a JSON body surfaces the matching typed exception with message + error_code.
	 *
	 * @covers ::send
	 * @covers ::to_response
	 *
	 * @return void
	 */
	public function test_send_maps_500_to_internal_server_error(): void {
		$this->myyoast_client->expects( 'get_site_token' )->andReturn( $this->token_set );
		$this->myyoast_client->expects( 'authenticated_request' )
			->andReturn(
				new HTTP_Response(
					500,
					[],
					[
						'message'    => 'boom',
						'error_code' => 'upstream_failure',
					],
				),
			);

		try {
			$this->instance->send( new Request( '/openai/suggestions/seo-title' ), $this->user );
			$this->fail( 'Expected Internal_Server_Error_Exception.' );
		}
		catch ( Internal_Server_Error_Exception $exception ) {
			$this->assertSame( 500, $exception->getCode() );
			$this->assertSame( 'boom', $exception->getMessage() );
			$this->assertSame( 'upstream_failure', $exception->get_error_identifier() );
		}
	}

	/**
	 * Transport failure (status 0) is mapped to Bad_Request_Exception by the validator.
	 *
	 * @covers ::send
	 * @covers ::to_response
	 *
	 * @return void
	 */
	public function test_send_maps_transport_failure_to_bad_request(): void {
		$this->myyoast_client->expects( 'get_site_token' )->andReturn( $this->token_set );
		$this->myyoast_client->expects( 'authenticated_request' )
			->andReturn(
				new HTTP_Response(
					0,
					[],
					[
						'error'             => 'network_error',
						'error_description' => 'DNS lookup failed',
					],
				),
			);

		$this->expectException( Bad_Request_Exception::class );
		$this->instance->send( new Request( '/openai/suggestions/seo-title' ), $this->user );
	}
}
