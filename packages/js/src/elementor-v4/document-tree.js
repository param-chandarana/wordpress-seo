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

export { getContainerElements, getDocumentTree };
