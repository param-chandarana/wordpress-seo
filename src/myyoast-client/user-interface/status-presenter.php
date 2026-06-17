<?php
// phpcs:disable Yoast.NamingConventions.NamespaceName.MaxExceeded
// phpcs:disable Yoast.NamingConventions.NamespaceName.TooLong -- Needed in the folder structure.

namespace Yoast\WP\SEO\MyYoast_Client\User_Interface;

use Yoast\WP\SEO\MyYoast_Client\Application\Ports\Client_Registration_Interface;
use Yoast\WP\SEO\MyYoast_Client\Domain\Registered_Client;
use Yoast\WP\SEO\MyYoast_Client\Infrastructure\OIDC\Issuer_Config;

/**
 * Builds the status payload surfaced on the "MyYoast connection" settings page.
 *
 * Intentionally narrow: registration state, registration date, and the
 * stored redirect URIs with their verification state. Software statement,
 * IAT, RAT, client ID, scopes, and token data are never included.
 */
class Status_Presenter {

	/**
	 * The client registration port.
	 *
	 * @var Client_Registration_Interface
	 */
	private $client_registration;

	/**
	 * The issuer configuration.
	 *
	 * @var Issuer_Config
	 */
	private $issuer_config;

	/**
	 * The OAuth redirect URI builder.
	 *
	 * @var OAuth_Redirect_Uri
	 */
	private $redirect_uri;

	/**
	 * Status_Presenter constructor.
	 *
	 * @param Client_Registration_Interface $client_registration The client registration port.
	 * @param Issuer_Config                 $issuer_config       The issuer configuration.
	 * @param OAuth_Redirect_Uri            $redirect_uri        The OAuth redirect URI builder.
	 */
	public function __construct(
		Client_Registration_Interface $client_registration,
		Issuer_Config $issuer_config,
		OAuth_Redirect_Uri $redirect_uri
	) {
		$this->client_registration = $client_registration;
		$this->issuer_config       = $issuer_config;
		$this->redirect_uri        = $redirect_uri;
	}

	/**
	 * Returns the current status payload.
	 *
	 * @return array{is_provisioned: bool, is_registered: bool, registered_at: int|null, registered_at_iso: string|null, redirect_uris: array<int, array{uri: string, origin: string, is_verified: bool}>, redirect_uris_match: bool}
	 */
	public function present(): array {
		$is_provisioned = ( $this->issuer_config->get_software_statement() !== '' )
			&& ( $this->issuer_config->get_initial_access_token() !== '' );

		$registered_client = $this->client_registration->get_registered_client();
		$is_registered     = ( $registered_client !== null );

		$registered_at       = null;
		$registered_at_iso   = null;
		$redirect_uris       = [];
		$redirect_uris_match = true;

		if ( $registered_client !== null ) {
			$registered_at       = $this->extract_registered_at( $registered_client );
			$registered_at_iso   = ( $registered_at !== null ) ? \gmdate( 'c', $registered_at ) : null;
			$redirect_uris       = $this->extract_redirect_uris( $registered_client );
			$redirect_uris_match = $this->redirect_uris_match( $registered_client );
		}

		return [
			'is_provisioned'      => $is_provisioned,
			'is_registered'       => $is_registered,
			'registered_at'       => $registered_at,
			'registered_at_iso'   => $registered_at_iso,
			'redirect_uris'       => $redirect_uris,
			'redirect_uris_match' => $redirect_uris_match,
		];
	}

	/**
	 * Extracts the registration timestamp (RFC 7591 `client_id_issued_at`).
	 *
	 * @param Registered_Client $client The registered client.
	 *
	 * @return int|null Unix timestamp, or null if absent or not coercible.
	 */
	private function extract_registered_at( Registered_Client $client ): ?int {
		$metadata = $client->get_metadata();
		if ( ! isset( $metadata['client_id_issued_at'] ) ) {
			return null;
		}

		$value = $metadata['client_id_issued_at'];
		if ( ! \is_numeric( $value ) ) {
			return null;
		}

		$timestamp = (int) $value;
		return ( $timestamp > 0 ) ? $timestamp : null;
	}

	/**
	 * Whether the currently-computed redirect URI is among the stored ones.
	 *
	 * Compares the full URI — a mismatch means the site's URL has changed
	 * since it was connected, and the registration needs updating.
	 *
	 * @param Registered_Client $client The registered client.
	 *
	 * @return bool
	 */
	private function redirect_uris_match( Registered_Client $client ): bool {
		$metadata = $client->get_metadata();
		$stored   = ( $metadata['redirect_uris'] ?? [] );
		if ( ! \is_array( $stored ) ) {
			return false;
		}

		return \in_array( $this->redirect_uri->get(), $stored, true );
	}

	/**
	 * Returns the stored redirect URIs annotated with their origin (scheme +
	 * host + optional port) and their verification state.
	 *
	 * Verification state is hardcoded to `false` for now: issue #1207 introduces
	 * the verified-site concept along with the helpers that decide which URIs
	 * have completed an authorization code flow. Replace this once #1207 lands
	 * on trunk.
	 *
	 * @param Registered_Client $client The registered client.
	 *
	 * @return array<int, array{uri: string, origin: string, is_verified: bool}>
	 */
	private function extract_redirect_uris( Registered_Client $client ): array {
		$metadata = $client->get_metadata();
		$uris     = ( $metadata['redirect_uris'] ?? [] );
		if ( ! \is_array( $uris ) ) {
			return [];
		}

		$result = [];
		foreach ( $uris as $uri ) {
			if ( ! \is_string( $uri ) || $uri === '' ) {
				continue;
			}

			$origin = $this->extract_origin( $uri );
			if ( $origin === null ) {
				continue;
			}

			$result[] = [
				'uri'         => $uri,
				'origin'      => $origin,
				'is_verified' => $this->is_uri_verified( $uri ),
			];
		}

		return $result;
	}

	/**
	 * Extracts the origin (scheme + host + optional port) from a URI.
	 *
	 * @param string $uri The URI to parse.
	 *
	 * @return string|null The origin, or null if the URI couldn't be parsed.
	 */
	private function extract_origin( string $uri ): ?string {
		$parts = \wp_parse_url( $uri );
		if ( ! \is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}

	/**
	 * Whether the given redirect URI has been verified by completing an
	 * authorization code flow.
	 *
	 * TODO: replace stub once #1207 (Yoast AI requests authenticated via
	 * MyYoast OAuth tokens) lands on trunk — it introduces the verified-site
	 * concept and the helper to check it.
	 *
	 * @param string $uri The redirect URI to check.
	 *
	 * @return bool
	 */
	private function is_uri_verified( string $uri ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found,VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Stub ignores $uri until #1207 adds the verified-site helper.
		return false;
	}
}
