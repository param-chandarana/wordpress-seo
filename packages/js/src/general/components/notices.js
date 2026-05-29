import WebinarPromoNotification from "../../components/WebinarPromoNotification";
import { Notice } from ".";
import classNames from "classnames";
import { STORE_NAME } from "../constants";

/**
 * The notices component that renders the notices on the general page.
 * @param {object} props
 * @param {object[]} props.notices The notices to render.
 * @param {string} props.webinarIntroSettingsUrl The URL to the webinar intro settings page.
 * @returns {JSX.Element} The notices component.
 */
export const Notices = ( { notices, webinarIntroSettingsUrl } ) => ( <div>
	<WebinarPromoNotification
		store={ STORE_NAME }
		url={ webinarIntroSettingsUrl }
		image={ null }
		className={
			classNames(
				notices.filter( notice => ! notice.isDismissed ).length > 0 ? "yst-mb-3" : "yst-mb-8",
				"yoast-webinar-dashboard"
			) }
	/>
	{ notices.length > 0 && <div className={ notices.filter( notice => ! notice.isDismissed ).length > 0 ? "yst-mb-8" : "" }> {
		notices.map( ( notice, index ) =>
			<Notice
				key={ index }
				id={ notice.id || "yoast-general-page-notice-" + index }
				title={ notice.header }
				isDismissable={ notice.isDismissable }
				className={ notice.isDismissed ? "yst-hidden" : "yst-mb-3" }
			>
				{ notice.content }
			</Notice>
		)
	}
	</div> }
</div> );
