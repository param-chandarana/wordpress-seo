<?php

// phpcs:disable Yoast.NamingConventions.NamespaceName.TooLong -- Needed in the folder structure.

namespace Yoast\WP\SEO\AI\Authentication\Application;

use WP_User;
use Yoast\WP\SEO\AI\Content_Planner\Domain\Content_Outline_Parameters;
use Yoast\WP\SEO\AI\Content_Planner\Domain\Content_Suggestion_Parameters;
use Yoast\WP\SEO\AI\Generator\Domain\Suggestions_Parameters;
use Yoast\WP\SEO\AI\Generator\Domain\Usage_Parameters;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\Insufficient_Scope_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\OAuth_Forbidden_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Exceptions\Remote_Request_Exception;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Request;
use Yoast\WP\SEO\AI\HTTP_Request\Domain\Response;
use YoastSEO_Vendor\Psr\Log\LoggerAwareInterface;
use YoastSEO_Vendor\Psr\Log\LoggerAwareTrait;
use YoastSEO_Vendor\Psr\Log\NullLogger;

/**
 * Sends an authenticated AI request using a primary strategy, with an optional fallback.
 *
 * Each public method maps to a single AI endpoint: it builds the HTTP Request from a typed Parameter
 * object and dispatches it. Strategies own dispatch entirely. The sender only chooses between primary
 * and fallback: it tries the primary, falls back to the secondary on Remote_Request_Exception when a
 * fallback is set, and never retries the same strategy. Insufficient_Scope_Exception and
 * OAuth_Forbidden_Exception always propagate without invoking the fallback — different token
 * semantics mean the legacy path would mask the real config bug.
 */
class AI_Request_Sender implements LoggerAwareInterface {

	use LoggerAwareTrait;

	/**
	 * The primary strategy.
	 *
	 * @var Auth_Strategy_Interface
	 */
	private $primary;

	/**
	 * The fallback strategy, or null when no fallback should be tried on persistent failure.
	 *
	 * @var Auth_Strategy_Interface|null
	 */
	private $fallback;

	/**
	 * Constructor.
	 *
	 * @param Auth_Strategy_Interface      $primary  The primary strategy.
	 * @param Auth_Strategy_Interface|null $fallback The fallback strategy, or null for no fallback.
	 */
	public function __construct( Auth_Strategy_Interface $primary, ?Auth_Strategy_Interface $fallback = null ) {
		$this->primary  = $primary;
		$this->fallback = $fallback;
		$this->logger   = new NullLogger();
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- Strategies throw typed exceptions that propagate out.

	/**
	 * Requests a content outline from the AI service.
	 *
	 * @param Content_Outline_Parameters $parameters The outline parameters.
	 *
	 * @return Response The parsed response.
	 */
	public function get_content_outline_suggestions( Content_Outline_Parameters $parameters ): Response {
		$request = new Request(
			'/content-planner/next-post-outline',
			[
				'subject' => [
					'language' => $parameters->get_language(),
					'content'  => $parameters->get_content(),
				],
			],
			[ 'X-Yst-Cohort' => $parameters->get_editor() ],
		);

		return $this->dispatch( $request, $parameters->get_user() );
	}

	/**
	 * Requests next-post content suggestions from the AI service.
	 *
	 * @param Content_Suggestion_Parameters $parameters The suggestion parameters.
	 *
	 * @return Response The parsed response.
	 */
	public function get_content_suggestions( Content_Suggestion_Parameters $parameters ): Response {
		$request = new Request(
			'/content-planner/next-post-suggestions',
			[
				'subject' => [
					'language' => $parameters->get_language(),
					'content'  => $parameters->get_content(),
				],
			],
			[ 'X-Yst-Cohort' => $parameters->get_editor() ],
		);

		return $this->dispatch( $request, $parameters->get_user() );
	}

	/**
	 * Requests suggestions for the given suggestion type.
	 *
	 * @param Suggestions_Parameters $parameters The suggestions parameters.
	 *
	 * @return Response The parsed response.
	 */
	public function get_suggestions( Suggestions_Parameters $parameters ): Response {
		$user    = $parameters->get_user();
		$request = new Request(
			'/openai/suggestions/' . $parameters->get_suggestion_type(),
			[
				'service' => 'openai',
				'user_id' => (string) $user->ID,
				'subject' => [
					'content'         => $parameters->get_prompt_content(),
					'focus_keyphrase' => $parameters->get_focus_keyphrase(),
					'language'        => $parameters->get_language(),
					'platform'        => $parameters->get_platform(),
				],
			],
			[ 'X-Yst-Cohort' => $parameters->get_editor() ],
		);

		return $this->dispatch( $request, $user );
	}

	/**
	 * Requests the user's current usage.
	 *
	 * @param Usage_Parameters $parameters The usage parameters.
	 *
	 * @return Response The parsed response.
	 */
	public function get_usage( Usage_Parameters $parameters ): Response {
		$action_path = $parameters->is_unlimited() ? '/usage/' . \gmdate( 'Y-m' ) : '/usage/free-usages';
		$request     = new Request( $action_path, [], [], false );

		return $this->dispatch( $request, $parameters->get_user() );
	}

	/**
	 * Dispatches an authenticated AI request, falling back to the secondary strategy on persistent failure.
	 *
	 * @param Request $request The base request, without auth headers.
	 * @param WP_User $user    The WP user the request is on behalf of.
	 *
	 * @return Response The parsed response.
	 */
	private function dispatch( Request $request, WP_User $user ): Response {
		try {
			return $this->primary->send( $request, $user );
		}
		catch ( Insufficient_Scope_Exception | OAuth_Forbidden_Exception $exception ) {
			// OAuth-specific 4xxs never fall back — different token semantics would mask the config bug.
			throw $exception;
		}
		catch ( Remote_Request_Exception $exception ) {
			if ( $this->fallback === null ) {
				throw $exception;
			}
			$this->logger->warning(
				'Primary AI auth strategy failed ({error_id}); falling back to the secondary strategy.',
				[ 'error_id' => $exception->get_error_identifier() ],
			);
			return $this->fallback->send( $request, $user );
		}
	}

	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing
}
