/* global elementor, YoastSEO */
/**
 * @file Elementor V4 atomic editor entry. Extracts content from the document JSON tree
 *       and dispatches it to the Yoast editor store.
 */

import { dispatch, select } from "@wordpress/data";
import { debounce, get } from "lodash";
import { Paper } from "yoastseo";
import { refreshDelay } from "../analysis/constants";
import firstImageUrlInContent from "../helpers/firstImageUrlInContent";
import { excerptFromContent } from "../helpers/replacementVariableHelpers";
import getContentLocale from "../analysis/getContentLocale";
import { registerElementorUIHookAfter, registerElementorUIHookBefore } from "../elementor/helpers/hooks";
import { isFormId, isFormIdEqualToDocumentId } from "../elementor/helpers/is-form-id";

import { walkAtomicTree } from "./content-walker";

const editorData = {
	content: "",
	title: "",
	excerpt: "",
	slug: "",
	imageUrl: "",
	featuredImage: "",
	contentImage: "",
	excerptOnly: "",
};

/**
 * Tries to extract the elements array from the live container model.
 *
 * @param {Object} currentDocument The Elementor document.
 * @returns {Array|null} The elements array, or null if not accessible.
 */
function getContainerElements( currentDocument ) {
	const model = currentDocument.container?.model;
	if ( ! model || typeof model.toJSON !== "function" ) {
		return null;
	}
	const json = model.toJSON();
	return Array.isArray( json?.elements ) ? json.elements : null;
}

/**
 * Reads the atomic widget JSON tree from an Elementor document, trying the live
 * container model first and falling back to the initial config.
 *
 * @param {Object} currentDocument The Elementor document.
 * @returns {Array} The top-level elements array, or empty if not accessible.
 */
function getDocumentTree( currentDocument ) {
	if ( ! currentDocument ) {
		return [];
	}
	const fromContainer = getContainerElements( currentDocument );
	if ( fromContainer ) {
		return fromContainer;
	}
	return Array.isArray( currentDocument.config?.elements ) ? currentDocument.config.elements : [];
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
function getEditorData( editorDocument ) {
	const content = walkAtomicTree( getDocumentTree( editorDocument ) );
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
}

/* eslint-disable complexity */
/**
 * Dispatches updated editor data when the document changes.
 *
 * @returns {void}
 */
function handleEditorChange() {
	const currentDocument = elementor.documents.getCurrent();

	if ( ! isFormIdEqualToDocumentId() ) {
		return;
	}

	if ( ! [ "wp-post", "wp-page" ].includes( currentDocument.config.type ) ) {
		return;
	}

	if ( select( "yoast-seo/editor" ).getActiveMarker() ) {
		return;
	}

	const data = getEditorData( currentDocument );

	if ( data.content !== editorData.content ) {
		editorData.content = data.content;
		dispatch( "yoast-seo/editor" ).setEditorDataContent( editorData.content );
	}

	if ( data.title !== editorData.title ) {
		editorData.title = data.title;
		dispatch( "yoast-seo/editor" ).setEditorDataTitle( editorData.title );
	}

	if ( data.excerpt !== editorData.excerpt ) {
		editorData.excerpt = data.excerpt;
		editorData.excerptOnly = data.excerptOnly;
		dispatch( "yoast-seo/editor" ).setEditorDataExcerpt( editorData.excerpt );
		dispatch( "yoast-seo/editor" ).updateReplacementVariable( "excerpt", editorData.excerpt );
		dispatch( "yoast-seo/editor" ).updateReplacementVariable( "excerpt_only", editorData.excerptOnly );
	}

	if ( data.imageUrl !== editorData.imageUrl ) {
		editorData.imageUrl = data.imageUrl;
		dispatch( "yoast-seo/editor" ).setEditorDataImageUrl( editorData.imageUrl );
	}

	if ( data.contentImage !== editorData.contentImage ) {
		editorData.contentImage = data.contentImage;
		dispatch( "yoast-seo/editor" ).setContentImage( editorData.contentImage );
	}

	if ( data.featuredImage !== editorData.featuredImage ) {
		editorData.featuredImage = data.featuredImage;
		dispatch( "yoast-seo/editor" ).updateData( { snippetPreviewImageURL: editorData.featuredImage } );
	}
}
/* eslint-enable complexity */

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

const debouncedHandleEditorChange = debounce( handleEditorChange, refreshDelay );

/**
 * Initialises the content watcher.
 *
 * @returns {void}
 */
function initializeElementorV4() {
	registerElementorUIHookBefore(
		"panel/editor/open",
		"yoast-seo/v4/marks/reset-on-edit",
		debounce( resetMarks, refreshDelay ),
		isFormIdEqualToDocumentId
	);

	registerElementorUIHookBefore(
		"document/save/save",
		"yoast-seo/v4/marks/reset-on-save",
		resetMarks,
		( { document } ) => isFormId( document?.id || elementor.documents.getCurrent().id )
	);

	registerElementorUIHookAfter(
		"editor/documents/close",
		"yoast-seo/v4/content-walker/stop",
		() => {
			debouncedHandleEditorChange.cancel();
		},
		( { id } ) => isFormId( id )
	);

	registerElementorUIHookAfter(
		"document/save/set-is-modified",
		"yoast-seo/v4/content-walker/on-modified",
		debouncedHandleEditorChange,
		( { document } ) => isFormId( document?.id || elementor.documents.getCurrent().id )
	);

	handleEditorChange();
}

jQuery( window ).on( "elementor:init", () => {
	window.elementor.on( "panel:init", () => {
		setTimeout( initializeElementorV4 );
	} );
} );
