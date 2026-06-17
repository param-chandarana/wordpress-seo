/* global elementor, YoastSEO */
import { dispatch } from "@wordpress/data";
import { Paper } from "yoastseo";
import { buildContentAndMap } from "./content-walker";
import { getDocumentTree } from "./document-tree";

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

/**
 * Returns per-widget position metadata for the current Elementor document.
 *
 * Each entry maps a widget node ID to its range in the normalised analysis
 * content string, so premium's mark applicator can apply marks at the correct
 * local offset without re-implementing the tree walk.
 *
 * @param {Object} [currentDocument] The Elementor document (defaults to current).
 * @returns {import("./content-walker").WidgetEntry[]} Array of widget entries.
 */
export function getWidgetMap( currentDocument = elementor.documents.getCurrent() ) {
	const tree = getDocumentTree( currentDocument );
	return buildContentAndMap( tree, currentDocument.$element ).widgets;
}

export { resetMarks };
