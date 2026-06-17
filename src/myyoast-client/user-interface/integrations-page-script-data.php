<?php
// phpcs:disable Yoast.NamingConventions.NamespaceName.MaxExceeded
// phpcs:disable Yoast.NamingConventions.NamespaceName.TooLong -- Needed in the folder structure.

namespace Yoast\WP\SEO\MyYoast_Client\User_Interface;

use Yoast\WP\SEO\Conditionals\MyYoast_Connection_Conditional;

/**
 * Builds the MyYoast connection payload exposed to the Integrations page's
 * `wpseoIntegrationsData` global so the React app has the initial status and
 * the user-profile pointer without an extra fetch.
 *
 * Consumed by `Integrations_Page` through constructor injection.
 */
class Integrations_Page_Script_Data {

	/**
	 * The status presenter.
	 *
	 * @var Status_Presenter
	 */
	private $status_presenter;

	/**
	 * The MyYoast connection feature-flag conditional.
	 *
	 * @var MyYoast_Connection_Conditional
	 */
	private $myyoast_connection_conditional;

	/**
	 * Integrations_Page_Script_Data constructor.
	 *
	 * @param Status_Presenter               $status_presenter               The status presenter.
	 * @param MyYoast_Connection_Conditional $myyoast_connection_conditional The MyYoast connection feature-flag conditional.
	 */
	public function __construct(
		Status_Presenter $status_presenter,
		MyYoast_Connection_Conditional $myyoast_connection_conditional
	) {
		$this->status_presenter               = $status_presenter;
		$this->myyoast_connection_conditional = $myyoast_connection_conditional;
	}

	/**
	 * Returns the MyYoast connection payload, or `null` when the feature flag
	 * is disabled so the Integrations page can omit the key entirely.
	 *
	 * The `callbackOutcome` slot is populated (and consumed) when an OAuth
	 * callback finished for this user since the last time the Integrations page
	 * was rendered, so the React app can surface a one-shot notification.
	 *
	 * @return array{initialStatus: array{is_provisioned: bool, is_registered: bool, registered_at: int|null, registered_at_iso: string|null, redirect_uris: array<int, array{uri: string, origin: string, is_verified: bool}>, redirect_uris_match: bool}, profileUrl: string, callbackOutcome: array{kind: string, key: string}|null}|null
	 */
	public function present(): ?array {
		if ( ! $this->myyoast_connection_conditional->is_met() ) {
			return null;
		}

		return [
			'initialStatus'   => $this->status_presenter->present(),
			'profileUrl'      => \admin_url( 'profile.php' ),
			'callbackOutcome' => $this->consume_callback_outcome(),
		];
	}

	/**
	 * Reads and deletes the per-user OAuth callback outcome transient.
	 *
	 * Consumed-on-read so the notification only fires once.
	 *
	 * @return array{kind: string, key: string}|null The outcome, or null when none is pending.
	 */
	private function consume_callback_outcome(): ?array {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return null;
		}

		$transient_key = OAuth_Callback_Integration::TRANSIENT_PREFIX . $user_id;
		$stored        = \get_transient( $transient_key );
		if ( ! \is_array( $stored ) ) {
			return null;
		}
		\delete_transient( $transient_key );

		$kind = ( $stored['kind'] ?? '' );
		$key  = ( $stored['key'] ?? '' );
		if ( ! \is_string( $kind ) || ! \is_string( $key ) || $kind === '' || $key === '' ) {
			return null;
		}

		return [
			'kind' => $kind,
			'key'  => $key,
		];
	}
}
