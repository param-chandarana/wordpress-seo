/* global YoastSEO */
import { dispatch } from "@wordpress/data";
import { Paper } from "yoastseo";

/**
 * Clears the active marker so the analyser recomputes marks on the next pass.
 *
 * Atomic widgets render as bare semantic tags without a reusable inner container,
 * so we cannot strip `<yoastmark>` spans by mutating innerHTML.
 *
 * @returns {void}
 */
function resetMarks() {
	dispatch( "yoast-seo/editor" ).setActiveMarker( null );
	dispatch( "yoast-seo/editor" ).setMarkerPauseStatus( false );

	YoastSEO.analysis.applyMarks( new Paper( "", {} ), [] );
}

export { resetMarks };
