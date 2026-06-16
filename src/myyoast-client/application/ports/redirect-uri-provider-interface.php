<?php
// phpcs:disable Yoast.NamingConventions.NamespaceName.TooLong -- Needed in the folder structure.

namespace Yoast\WP\SEO\MyYoast_Client\Application\Ports;

use Yoast\WP\SEO\MyYoast_Client\Domain\Registered_Client;
use Yoast\WP\SEO\MyYoast_Client\Domain\Resource_Indicator;

/**
 * Port for resolving the OAuth redirect URIs for this site.
 *
 * Splits the two distinct redirect-URI concerns: the full set to register with the
 * authorization server (Dynamic Client Registration), and the single URI to embed in a
 * given authorization request (which OAuth requires to match one of the registered URIs
 * exactly).
 */
interface Redirect_URI_Provider_Interface {

	/**
	 * Returns the redirect URIs to register this client with.
	 *
	 * This is the full allow-list sent to the authorization server during registration. It
	 * defaults to the site's canonical admin callback URL; site owners or plugins may extend,
	 * remove, or replace it for non-standard setups (reverse proxies, alternate admin URLs,
	 * headless, ...).
	 *
	 * @return string[] The redirect URIs to register. Never empty.
	 */
	public function get_redirect_uris(): array;

	/**
	 * Returns the single redirect URI to embed in an authorization request.
	 *
	 * The returned value is guaranteed to be one of the URIs in the given client's registered
	 * redirect_uris, so the authorization server's exact-match check on `redirect_uri` cannot
	 * fail because of this provider.
	 *
	 * @param Registered_Client  $client             The registered client whose redirect_uris bound the result.
	 * @param int                $user_id            The WordPress user ID starting the flow.
	 * @param string[]           $scopes             The scopes being requested.
	 * @param Resource_Indicator $resource_indicator The RFC 8707 resource indicator the token will be bound to.
	 * @param string|null        $return_url         The URL the user returns to after authorization, or null.
	 *
	 * @return string A redirect URI guaranteed to be present in $client's registered redirect_uris.
	 */
	public function get_authorization_redirect_uri(
		Registered_Client $client,
		int $user_id,
		array $scopes,
		Resource_Indicator $resource_indicator,
		?string $return_url = null
	): string;
}
