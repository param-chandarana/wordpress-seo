/* global elementor */
/**
 * @file Elementor V4 atomic editor entry. Extracts content from the document JSON tree
 *       and dispatches it to the Yoast editor store.
 */

import { debounce, noop } from "lodash";
import { refreshDelay } from "../analysis/constants";
import { registerElementorUIHookAfter, registerElementorUIHookBefore } from "../elementor/helpers/hooks";
import { isFormId, isFormIdEqualToDocumentId } from "../elementor/helpers/is-form-id";
import { handleEditorChange, debouncedHandleEditorChange } from "./change-handler";
import { resetMarks } from "./marks";

/**
 * Initialises the content watcher.
 *
 * @returns {void}
 */
function initializeElementorV4() {
	let stopObserver = noop;

	registerElementorUIHookAfter(
		"editor/documents/attach-preview",
		"yoast-seo/v4/content-walker/start-observer",
		() => {
			stopObserver();
			elementor.channels.editor.on( "change", debouncedHandleEditorChange );
			elementor.settings.page.model.on( "change", debouncedHandleEditorChange );
			stopObserver = () => {
				elementor.channels.editor.off( "change", debouncedHandleEditorChange );
				elementor.settings.page.model.off( "change", debouncedHandleEditorChange );
			};
		},
		isFormIdEqualToDocumentId
	);

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
			stopObserver();
			stopObserver = noop;
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
