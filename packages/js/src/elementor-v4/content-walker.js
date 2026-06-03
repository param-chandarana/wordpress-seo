/**
 * @file Walks an Elementor V4 atomic widget JSON tree and emits analyser-ready HTML.
 */

/**
 * Unwraps a `{ $$type: "string", value: "..." }` prop envelope.
 *
 * @param {*} prop The prop value.
 * @returns {string} The string value, or empty if missing or malformed.
 */
const readStringProp = ( prop ) => {
	if ( prop === null ) {
		return "";
	}
	if ( typeof prop === "string" ) {
		return prop;
	}
	if ( typeof prop === "object" && typeof prop.value === "string" ) {
		return prop.value;
	}
	return "";
};

/**
 * Unwraps a `Html_V3_Prop_Type` envelope of the shape:
 *   { $$type: "html-v3", value: { content: { $$type: "string", value: "..." }, children: [] } }
 *
 * @param {*} prop The Html_V3 prop value.
 * @returns {string} The inner content text, or empty if missing or malformed.
 */
const readHtmlV3Prop = ( prop ) => {
	if ( prop === null || typeof prop !== "object" ) {
		return "";
	}
	return readStringProp( prop.value?.content );
};

/**
 * Reads a named sub-property out of a prop's `value` object (used by Link and Image props).
 *
 * @param {*}      prop The prop value.
 * @param {string} key  The sub-key to read inside `prop.value`.
 * @returns {string} The string value, or empty.
 */
const readNestedStringProp = ( prop, key ) => {
	if ( prop === null || typeof prop !== "object" ) {
		return "";
	}
	if ( prop.value === null || typeof prop.value !== "object" ) {
		return "";
	}
	return readStringProp( prop.value[ key ] );
};

/**
 * Escapes &, <, >, and " for inclusion in an HTML attribute value.
 *
 * @param {string} value The raw value.
 * @returns {string} The escaped value.
 */
const escapeAttribute = ( value ) => String( value )
	.replace( /&/g, "&amp;" )
	.replace( /"/g, "&quot;" )
	.replace( /</g, "&lt;" )
	.replace( />/g, "&gt;" );

const HEADING_TAGS = new Set( [ "h1", "h2", "h3", "h4", "h5", "h6" ] );
const PARAGRAPH_TAGS = new Set( [ "p", "span" ] );

const EXTRACTORS = {
	"e-heading": ( node ) => {
		const text = readHtmlV3Prop( node.settings?.title );
		if ( text === "" ) {
			return "";
		}
		const rawTag = readStringProp( node.settings?.tag );
		const tag = HEADING_TAGS.has( rawTag ) ? rawTag : "h2";
		return `<${ tag }>${ text }</${ tag }>`;
	},

	"e-paragraph": ( node ) => {
		const text = readHtmlV3Prop( node.settings?.paragraph );
		if ( text === "" ) {
			return "";
		}
		const rawTag = readStringProp( node.settings?.tag );
		const tag = PARAGRAPH_TAGS.has( rawTag ) ? rawTag : "p";
		return `<${ tag }>${ text }</${ tag }>`;
	},

	"e-button": ( node ) => {
		const text = readHtmlV3Prop( node.settings?.text );
		if ( text === "" ) {
			return "";
		}
		const href = readNestedStringProp( node.settings?.link, "href" );
		if ( href !== "" ) {
			return `<a href="${ escapeAttribute( href ) }">${ text }</a>`;
		}
		return `<button>${ text }</button>`;
	},

	"e-image": ( node ) => {
		const alt = readNestedStringProp( node.settings?.image, "alt" );
		const src = readNestedStringProp( node.settings?.image, "src" );
		if ( alt === "" && src === "" ) {
			return "";
		}
		const attrs = [];
		if ( src !== "" ) {
			attrs.push( `src="${ escapeAttribute( src ) }"` );
		}
		attrs.push( `alt="${ escapeAttribute( alt ) }"` );
		return `<img ${ attrs.join( " " ) }>`;
	},

	"e-tab": ( node ) => {
		const text = readHtmlV3Prop( node.settings?.title );
		if ( text === "" ) {
			return "";
		}
		return `<button>${ text }</button>`;
	},
};

/**
 * Returns the HTML from the matching atomic-widget extractor, or empty string.
 *
 * @param {Object} node A document tree node.
 * @returns {string} The extracted HTML.
 */
function extractWidgetHtml( node ) {
	const extractor = EXTRACTORS[ node.widgetType ];
	return extractor ? extractor( node ) : "";
}

/**
 * Walks the document tree and concatenates the HTML produced by each known atomic
 * widget extractor. Unknown widget types contribute no HTML themselves but their
 * children are still walked, so atomic widgets nested inside a third-party widget
 * are still captured.
 *
 * @param {Object[]} nodes The `_elementor_data` array (or a sub-tree's `elements`).
 * @returns {string} The concatenated content HTML.
 */
export function walkAtomicTree( nodes ) {
	if ( ! Array.isArray( nodes ) ) {
		return "";
	}
	return nodes.map( ( node ) => {
		if ( ! node || typeof node !== "object" ) {
			return "";
		}
		return extractWidgetHtml( node ) + walkAtomicTree( node.elements );
	} ).join( "" );
}

export const __testables__ = {
	readStringProp,
	readHtmlV3Prop,
	readNestedStringProp,
	escapeAttribute,
	EXTRACTORS,
};
