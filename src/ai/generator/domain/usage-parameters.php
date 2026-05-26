<?php

// phpcs:disable Yoast.NamingConventions.NamespaceName.TooLong -- Needed in the folder structure.

namespace Yoast\WP\SEO\AI\Generator\Domain;

use WP_User;

/**
 * Parameters for a usage request.
 */
class Usage_Parameters {

	/**
	 * The user the request is on behalf of.
	 *
	 * @var WP_User
	 */
	private $user;

	/**
	 * Whether the user has unlimited usage (paid subscription) for this request type.
	 *
	 * @var bool
	 */
	private $is_unlimited;

	/**
	 * The constructor.
	 *
	 * @param WP_User $user         The user.
	 * @param bool    $is_unlimited Whether the user has unlimited usage.
	 */
	public function __construct( WP_User $user, bool $is_unlimited ) {
		$this->user         = $user;
		$this->is_unlimited = $is_unlimited;
	}

	/**
	 * Returns the user.
	 *
	 * @return WP_User The user.
	 */
	public function get_user(): WP_User {
		return $this->user;
	}

	/**
	 * Returns whether the user has unlimited usage.
	 *
	 * @return bool True when the user has unlimited usage.
	 */
	public function is_unlimited(): bool {
		return $this->is_unlimited;
	}
}
