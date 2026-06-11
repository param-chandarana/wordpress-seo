/* global elementor */
import { get } from "lodash";
import firstImageUrlInContent from "../helpers/firstImageUrlInContent";
import { excerptFromContent } from "../helpers/replacementVariableHelpers";
import getContentLocale from "../analysis/getContentLocale";
import { walkAtomicTree } from "./content-walker";
import { getDocumentTree } from "./document-tree";

/**
 * Finds the rendered `<img>` element for a node in the live preview DOM.
 * In Elementor V4 the `data-interaction-id` attribute is placed directly on
 * the `<img>` element, so we target it with a type-qualified selector.
 *
 * @param {Object} node           A document tree node with an `id`.
 * @param {Object} editorDocument The current Elementor document.
 * @returns {HTMLImageElement|undefined} The img element, or undefined if not found.
 */
function getPreviewImgElement( node, editorDocument ) {
	// data-interaction-id is on the <img> element itself in V4, so target it directly.
	return editorDocument.$element?.find( `img[data-interaction-id="${ node.id }"]` ).get( 0 );
}

/**
 * Walks the JSON tree and fills in `htmlCache` for any `e-image` node that is
 * missing it, reading the rendered `<img>` outerHTML from the live preview DOM.
 *
 * Elementor V4 only populates `htmlCache` in the model snapshot on initial page
 * load (from server-rendered data). When a new image widget is added mid-session,
 * the async server render updates the preview DOM but never writes back to the
 * model snapshot. Reading from the preview DOM keeps image detection current
 * without waiting for a full page reload.
 *
 * @param {Object[]} nodes           The document tree nodes (from model.toJSON()).
 * @param {Object}   editorDocument  The current Elementor document.
 * @returns {void}
 */
function enrichImageNodes( nodes, editorDocument ) {
	if ( ! Array.isArray( nodes ) ) {
		return;
	}
	nodes.forEach( ( node ) => {
		if ( ! node || typeof node !== "object" ) {
			return;
		}
		if ( node.widgetType === "e-image" ) {
			// Always prefer the live DOM over the model snapshot — the image URL and
			// alt text are resolved server-side, so htmlCache may be stale or absent
			// when the image or its alt text changes mid-session.
			const imgEl = getPreviewImgElement( node, editorDocument );
			if ( imgEl ) {
				node.htmlCache = imgEl.outerHTML;
			}
		}
		enrichImageNodes( node.elements, editorDocument );
	} );
}

/**
 * Computes the SEO excerpt with a content fallback.
 *
 * @param {string}  content     The full extracted content HTML.
 * @param {boolean} onlyExcerpt When true, returns only the post excerpt (no fallback).
 * @returns {string} The excerpt string.
 */
function getExcerpt( content, onlyExcerpt = false ) {
	let excerpt = elementor.settings.page.model.get( "post_excerpt" );

	if ( onlyExcerpt ) {
		return excerpt || "";
	}

	if ( ! excerpt ) {
		const limit = ( getContentLocale() === "ja" ) ? 80 : 156;
		excerpt = excerptFromContent( content, limit );
	}

	return excerpt;
}

/**
 * Builds the editor data snapshot from the current Elementor document.
 *
 * @param {Object} editorDocument The current document.
 * @returns {Object} The editor data.
 */
export const getEditorData = ( editorDocument ) => {
	const tree = getDocumentTree( editorDocument );
	enrichImageNodes( tree, editorDocument );
	const content = walkAtomicTree( tree );
	const featuredImageUrl = get( elementor.settings.page.model.get( "post_featured_image" ), "url", "" );
	const contentImageUrl = firstImageUrlInContent( content );

	return {
		content,
		title: elementor.settings.page.model.get( "post_title" ),
		excerpt: getExcerpt( content ),
		excerptOnly: getExcerpt( content, true ),
		imageUrl: featuredImageUrl || contentImageUrl,
		featuredImage: featuredImageUrl,
		contentImage: contentImageUrl,
		status: elementor.settings.page.model.get( "post_status" ),
	};
};
