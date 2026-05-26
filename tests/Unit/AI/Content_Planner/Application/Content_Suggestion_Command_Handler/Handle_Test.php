<?php

// phpcs:disable Yoast.NamingConventions.NamespaceName.TooLong -- Needed in the folder structure.
// phpcs:disable Yoast.NamingConventions.NamespaceName.MaxExceeded
namespace Yoast\WP\SEO\Tests\Unit\AI\Content_Planner\Application\Content_Suggestion_Command_Handler;

use Mockery;
use WP_User;
use Yoast\WP\SEO\AI\Content_Planner\Application\Content_Suggestion_Command;
use Yoast\WP\SEO\AI\Content_Planner\Domain\Content_Suggestion_List;
use Yoast\WP\SEO\AI\Content_Planner\Domain\Content_Suggestion_Parameters;
use Yoast\WP\SEO\AI\Content_Planner\Domain\Post_List;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\Forbidden_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\OAuth_Forbidden_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Response;

/**
 * Tests the Content_Suggestion_Command_Handler handle method.
 *
 * @group ai-content-planner
 *
 * @covers \Yoast\WP\SEO\AI\Content_Planner\Application\Content_Suggestion_Command_Handler::handle
 *
 * @phpcs:disable Yoast.NamingConventions.ObjectNameDepth.MaxExceeded
 */
final class Handle_Test extends Abstract_Content_Suggestion_Command_Handler_Test {

	/**
	 * The recent content array returned by the Post_List mock.
	 *
	 * @var array<array<string, string>>
	 */
	private const RECENT_CONTENT = [
		[
			'title'       => 'Existing post',
			'description' => 'Existing description',
		],
	];

	/**
	 * Builds a command with a WP_User mock whose ID is 1.
	 *
	 * @return Content_Suggestion_Command The command.
	 */
	private function build_command(): Content_Suggestion_Command {
		$user     = Mockery::mock( WP_User::class );
		$user->ID = 1;

		return new Content_Suggestion_Command( $user, 'post', 'en_US', 'gutenberg' );
	}

	/**
	 * Tests the handle method on the happy path, including the about_page being merged into the content payload.
	 *
	 * @return void
	 */
	public function test_handle_happy_path_with_about_page() {
		$command = $this->build_command();

		$post_list = Mockery::mock( Post_List::class );
		$post_list->expects( 'to_array' )->once()->andReturn( self::RECENT_CONTENT );

		$about_page = [
			'title'       => 'About us',
			'description' => 'All about us',
		];

		$this->recent_content_collector->expects( 'collect' )->once()->with( 'post' )->andReturn( $post_list );
		$this->recent_content_collector->expects( 'collect_about_page' )->once()->with( 'post' )->andReturn( $about_page );

		$this->ai_request_sender
			->expects( 'get_content_suggestions' )
			->once()
			->with(
				Mockery::on(
					function ( $parameters ) use ( $command, $about_page ) {
						return self::parameters_match_expected_shape( $parameters, $command->get_user(), $about_page );
					},
				),
			)
			->andReturn( new Response( '{"choices":[]}', 200, '' ) );

		$result = $this->instance->handle( $command );

		$this->assertInstanceOf( Content_Suggestion_List::class, $result );
		$this->assertSame( [ 'suggestions' => [] ], $result->to_array() );
	}

	/**
	 * Tests the handle method without an about_page; the key should be absent from the content payload.
	 *
	 * @return void
	 */
	public function test_handle_happy_path_without_about_page() {
		$command = $this->build_command();

		$post_list = Mockery::mock( Post_List::class );
		$post_list->expects( 'to_array' )->once()->andReturn( self::RECENT_CONTENT );

		$this->recent_content_collector->expects( 'collect' )->once()->andReturn( $post_list );
		$this->recent_content_collector->expects( 'collect_about_page' )->once()->andReturn( false );

		$this->ai_request_sender
			->expects( 'get_content_suggestions' )
			->once()
			->with(
				Mockery::on(
					static function ( $parameters ) {
						if ( ! $parameters instanceof Content_Suggestion_Parameters ) {
							return false;
						}
						return ! \array_key_exists( 'about_page', $parameters->get_content() );
					},
				),
			)
			->andReturn( new Response( '{"choices":[]}', 200, '' ) );

		$result = $this->instance->handle( $command );

		$this->assertInstanceOf( Content_Suggestion_List::class, $result );
	}

	/**
	 * Tests the handle method revokes consent and rethrows on Forbidden_Exception.
	 *
	 * @return void
	 */
	public function test_handle_revokes_consent_on_forbidden() {
		$command = $this->build_command();

		$post_list = Mockery::mock( Post_List::class );
		$post_list->expects( 'to_array' )->once()->andReturn( [] );

		$this->recent_content_collector->expects( 'collect' )->once()->andReturn( $post_list );
		$this->recent_content_collector->expects( 'collect_about_page' )->once()->andReturn( false );

		$this->ai_request_sender
			->expects( 'get_content_suggestions' )
			->once()
			->andThrow( new Forbidden_Exception( 'NOPE', 403 ) );

		$this->consent_handler->expects( 'revoke_consent' )->once()->with( 1 );

		$this->expectException( Forbidden_Exception::class );
		$this->expectExceptionMessage( 'CONSENT_REVOKED' );

		$this->instance->handle( $command );
	}

	/**
	 * An OAuth_Forbidden_Exception is propagated unchanged — no consent revoke (OAuth-wire 403s
	 * aren't consent revocations).
	 *
	 * @return void
	 */
	public function test_handle_propagates_oauth_forbidden_without_consent_revoke() {
		$command = $this->build_command();

		$post_list = Mockery::mock( Post_List::class );
		$post_list->expects( 'to_array' )->once()->andReturn( [] );

		$this->recent_content_collector->expects( 'collect' )->once()->andReturn( $post_list );
		$this->recent_content_collector->expects( 'collect_about_page' )->once()->andReturn( false );

		$this->ai_request_sender
			->expects( 'get_content_suggestions' )
			->once()
			->andThrow( new OAuth_Forbidden_Exception( 'policy', 403, 'policy' ) );

		$this->consent_handler->shouldNotReceive( 'revoke_consent' );

		$this->expectException( OAuth_Forbidden_Exception::class );

		$this->instance->handle( $command );
	}

	/**
	 * Asserts that the given parameters match the expected shape produced by the handler.
	 *
	 * @param mixed                $parameters The parameters to inspect.
	 * @param WP_User              $user       The expected user.
	 * @param array<string, mixed> $about_page The expected about_page payload.
	 *
	 * @return bool True when the parameters match the expected shape.
	 */
	private static function parameters_match_expected_shape( $parameters, WP_User $user, array $about_page ): bool {
		if ( ! $parameters instanceof Content_Suggestion_Parameters ) {
			return false;
		}
		if ( $parameters->get_user() !== $user ) {
			return false;
		}
		if ( $parameters->get_language() !== 'en_US' ) {
			return false;
		}
		if ( $parameters->get_editor() !== 'gutenberg' ) {
			return false;
		}

		$content = $parameters->get_content();
		if ( ( $content['posts'] ?? null ) !== self::RECENT_CONTENT ) {
			return false;
		}

		return ( $content['about_page'] ?? null ) === $about_page;
	}
}
