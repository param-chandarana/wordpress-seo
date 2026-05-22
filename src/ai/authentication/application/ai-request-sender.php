<?php

// phpcs:disable Yoast.NamingConventions.NamespaceName.TooLong -- Needed in the folder structure.

namespace Yoast\WP\SEO\AI\Authentication\Application;

use WP_User;
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
 * Strategies own dispatch entirely. The sender only chooses between primary and fallback: it tries
 * the primary, falls back to the secondary on Remote_Request_Exception when a fallback is set, and
 * never retries the same strategy. Insufficient_Scope_Exception and OAuth_Forbidden_Exception
 * always propagate without invoking the fallback — different token semantics mean the legacy path
 * would mask the real config bug.
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
	 * Sends an authenticated AI request, falling back to the secondary strategy on persistent failure.
	 *
	 * @param Request $request The base request, without auth headers.
	 * @param WP_User $user    The WP user the request is on behalf of.
	 *
	 * @return Response The parsed response.
	 */
	public function send( Request $request, WP_User $user ): Response {
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
