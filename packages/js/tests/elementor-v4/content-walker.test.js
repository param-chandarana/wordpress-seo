import { describe, expect, it } from "@jest/globals";

import { walkAtomicTree, __testables__ } from "../../src/elementor-v4/content-walker";

const { readStringProp, readHtmlV3Prop, readNestedStringProp, escapeAttribute, EXTRACTORS } = __testables__;

// ─── Test fixtures ──────────────────────────────────────────────────────────────────

/**
 * Builds a `{ $$type: "string", value: ... }` envelope.
 *
 * @param {string} value The value.
 * @returns {Object} The envelope.
 */
const stringProp = ( value ) => ( { $$type: "string", value } );

/**
 * Builds a `Html_V3_Prop_Type` envelope with `content` and an empty `children` array.
 *
 * @param {string} content The inner string content.
 * @returns {Object} The envelope.
 */
const htmlV3Prop = ( content ) => ( {
	$$type: "html-v3",
	value: { content: stringProp( content ), children: [] },
} );

/**
 * Builds an atomic heading node.
 *
 * @param {string} content The heading text.
 * @param {string} tag     h1..h6.
 * @returns {Object} The node.
 */
const headingNode = ( content, tag = "h2" ) => ( {
	id: "abc",
	elType: "widget",
	widgetType: "e-heading",
	settings: { title: htmlV3Prop( content ), tag: stringProp( tag ) },
	elements: [],
} );

/**
 * Builds an atomic paragraph node.
 *
 * @param {string} content The paragraph text.
 * @param {string} tag     p or span.
 * @returns {Object} The node.
 */
const paragraphNode = ( content, tag = "p" ) => ( {
	id: "def",
	elType: "widget",
	widgetType: "e-paragraph",
	settings: { paragraph: htmlV3Prop( content ), tag: stringProp( tag ) },
	elements: [],
} );

/**
 * Builds an atomic flexbox container with the given children.
 *
 * @param {Object[]} children The child nodes.
 * @returns {Object} The container.
 */
const flexboxContainer = ( children ) => ( {
	id: "container-1",
	elType: "e-flexbox",
	settings: {},
	elements: children,
} );

// ─── Tests ──────────────────────────────────────────────────────────────────────────

describe( "content-walker prop unwrappers", () => {
	describe( readStringProp, () => {
		it( "unwraps a string prop envelope", () => {
			expect( readStringProp( stringProp( "hello" ) ) ).toBe( "hello" );
		} );

		it( "accepts a bare string fallback (older schemas)", () => {
			expect( readStringProp( "raw string" ) ).toBe( "raw string" );
		} );

		it( "returns empty for null, undefined, or malformed input", () => {
			expect( readStringProp( null ) ).toBe( "" );
			expect( readStringProp( undefined ) ).toBe( "" );
			expect( readStringProp( {} ) ).toBe( "" );
			expect( readStringProp( { value: 123 } ) ).toBe( "" );
		} );
	} );

	describe( readHtmlV3Prop, () => {
		it( "unwraps an Html_V3 envelope to its inner content text", () => {
			expect( readHtmlV3Prop( htmlV3Prop( "Body text" ) ) ).toBe( "Body text" );
		} );

		it( "returns empty when the envelope is missing or malformed", () => {
			expect( readHtmlV3Prop( null ) ).toBe( "" );
			expect( readHtmlV3Prop( { value: null } ) ).toBe( "" );
			expect( readHtmlV3Prop( { value: { content: null } } ) ).toBe( "" );
		} );
	} );

	describe( readNestedStringProp, () => {
		it( "reads a string sub-key from a prop's value object", () => {
			const link = { $$type: "link", value: { href: "https://example.com", label: "Click" } };
			expect( readNestedStringProp( link, "href" ) ).toBe( "https://example.com" );
			expect( readNestedStringProp( link, "label" ) ).toBe( "Click" );
		} );

		it( "reads a string sub-key wrapped in its own string prop envelope", () => {
			const image = { $$type: "image", value: { alt: stringProp( "An image" ) } };
			expect( readNestedStringProp( image, "alt" ) ).toBe( "An image" );
		} );

		it( "returns empty when the path is absent", () => {
			expect( readNestedStringProp( null, "href" ) ).toBe( "" );
			expect( readNestedStringProp( {}, "href" ) ).toBe( "" );
			expect( readNestedStringProp( { value: null }, "href" ) ).toBe( "" );
		} );
	} );

	describe( escapeAttribute, () => {
		it( "escapes &, <, >, and double-quote characters", () => {
			expect( escapeAttribute( "a&b<c>d\"e" ) ).toBe( "a&amp;b&lt;c&gt;d&quot;e" );
		} );
	} );
} );

describe( "content-walker per-widget extractors", () => {
	describe( "e-heading", () => {
		it( "emits the heading with its semantic tag preserved", () => {
			expect( EXTRACTORS[ "e-heading" ]( headingNode( "Welcome", "h1" ) ) ).toBe( "<h1>Welcome</h1>" );
			expect( EXTRACTORS[ "e-heading" ]( headingNode( "Subtitle", "h3" ) ) ).toBe( "<h3>Subtitle</h3>" );
		} );

		it( "defaults to h2 when the tag is missing or invalid", () => {
			expect( EXTRACTORS[ "e-heading" ]( headingNode( "X", "h99" ) ) ).toBe( "<h2>X</h2>" );
			expect( EXTRACTORS[ "e-heading" ]( {
				widgetType: "e-heading",
				settings: { title: htmlV3Prop( "Y" ) },
			} ) ).toBe( "<h2>Y</h2>" );
		} );

		it( "returns empty for an empty title", () => {
			expect( EXTRACTORS[ "e-heading" ]( headingNode( "" ) ) ).toBe( "" );
		} );
	} );

	describe( "e-paragraph", () => {
		it( "emits the paragraph with the configured tag (p or span)", () => {
			expect( EXTRACTORS[ "e-paragraph" ]( paragraphNode( "Body", "p" ) ) ).toBe( "<p>Body</p>" );
			expect( EXTRACTORS[ "e-paragraph" ]( paragraphNode( "Inline", "span" ) ) ).toBe( "<span>Inline</span>" );
		} );

		it( "defaults to p when the tag is missing or invalid", () => {
			expect( EXTRACTORS[ "e-paragraph" ]( paragraphNode( "X", "div" ) ) ).toBe( "<p>X</p>" );
		} );
	} );

	describe( "e-button", () => {
		it( "emits an anchor when a link href is present", () => {
			const node = {
				widgetType: "e-button",
				settings: {
					text: htmlV3Prop( "Get started" ),
					link: { $$type: "link", value: { href: "https://example.com/start" } },
				},
			};
			expect( EXTRACTORS[ "e-button" ]( node ) ).toBe( "<a href=\"https://example.com/start\">Get started</a>" );
		} );

		it( "emits a button when no link is present", () => {
			const node = { widgetType: "e-button", settings: { text: htmlV3Prop( "Submit" ) } };
			expect( EXTRACTORS[ "e-button" ]( node ) ).toBe( "<button>Submit</button>" );
		} );

		it( "escapes ampersands and quotes in the link URL", () => {
			const node = {
				widgetType: "e-button",
				settings: {
					text: htmlV3Prop( "Search" ),
					link: { $$type: "link", value: { href: "https://example.com/?q=a&b=\"c\"" } },
				},
			};
			expect( EXTRACTORS[ "e-button" ]( node ) ).toBe(
				"<a href=\"https://example.com/?q=a&amp;b=&quot;c&quot;\">Search</a>"
			);
		} );
	} );

	describe( "e-image", () => {
		it( "emits an img with src and alt for SEO analysis", () => {
			const node = {
				widgetType: "e-image",
				settings: {
					image: { $$type: "image", value: { src: "hero.jpg", alt: "Hero banner showing the product" } },
				},
			};
			expect( EXTRACTORS[ "e-image" ]( node ) ).toBe(
				"<img src=\"hero.jpg\" alt=\"Hero banner showing the product\">"
			);
		} );

		it( "emits an empty-alt img when alt is missing (still flagged by Yoast)", () => {
			const node = {
				widgetType: "e-image",
				settings: { image: { $$type: "image", value: { src: "decor.png" } } },
			};
			expect( EXTRACTORS[ "e-image" ]( node ) ).toBe( "<img src=\"decor.png\" alt=\"\">" );
		} );

		it( "returns empty when both src and alt are missing", () => {
			expect( EXTRACTORS[ "e-image" ]( {
				widgetType: "e-image",
				settings: { image: { $$type: "image", value: {} } },
			} ) ).toBe( "" );
		} );
	} );

	describe( "e-tab", () => {
		it( "emits the tab label as a button so it is part of analysable text", () => {
			const node = {
				widgetType: "e-tab",
				settings: { title: htmlV3Prop( "Pricing" ) },
			};
			expect( EXTRACTORS[ "e-tab" ]( node ) ).toBe( "<button>Pricing</button>" );
		} );
	} );
} );

describe( walkAtomicTree, () => {
	it( "returns empty for null, undefined, or non-array input", () => {
		expect( walkAtomicTree( null ) ).toBe( "" );
		expect( walkAtomicTree( undefined ) ).toBe( "" );
		expect( walkAtomicTree( {} ) ).toBe( "" );
		expect( walkAtomicTree( "string" ) ).toBe( "" );
	} );

	it( "handles a flat list of atomic widgets at the root", () => {
		const tree = [
			headingNode( "Title", "h1" ),
			paragraphNode( "First body paragraph." ),
			paragraphNode( "Second body paragraph." ),
		];

		expect( walkAtomicTree( tree ) ).toBe(
			"<h1>Title</h1><p>First body paragraph.</p><p>Second body paragraph.</p>"
		);
	} );

	it( "recurses into atomic containers and bubbles child HTML up", () => {
		const tree = [
			flexboxContainer( [
				headingNode( "Inside flexbox", "h2" ),
				paragraphNode( "Wrapped paragraph." ),
			] ),
		];

		expect( walkAtomicTree( tree ) ).toBe( "<h2>Inside flexbox</h2><p>Wrapped paragraph.</p>" );
	} );

	it( "handles nested containers (flexbox > div-block > heading + paragraph)", () => {
		const innerContainer = { id: "div-1", elType: "e-div-block", settings: {}, elements: [
			headingNode( "Nested heading", "h3" ),
			paragraphNode( "Nested body." ),
		] };

		expect( walkAtomicTree( [ flexboxContainer( [ innerContainer ] ) ] ) ).toBe(
			"<h3>Nested heading</h3><p>Nested body.</p>"
		);
	} );

	it( "skips unknown widget types but still walks their children", () => {
		const tree = [ {
			id: "x",
			elType: "widget",
			widgetType: "third-party-widget-we-do-not-know-about",
			settings: { someUnknownField: "ignored" },
			elements: [ headingNode( "Wrapped in unknown widget", "h2" ) ],
		} ];

		expect( walkAtomicTree( tree ) ).toBe( "<h2>Wrapped in unknown widget</h2>" );
	} );

	it( "ignores classic widget nodes (non-atomic settings shape)", () => {
		// Classic widgets use a flat settings object rather than the typed atomic prop
		// envelope, so no extractor matches them.
		const classicHeading = {
			id: "classic-1",
			elType: "widget",
			widgetType: "heading",
			settings: { title: "Classic heading text", headerSize: "h2" },
			elements: [],
		};

		expect( walkAtomicTree( [ classicHeading ] ) ).toBe( "" );
	} );

	it( "preserves heading hierarchy and paragraph order in a realistic page tree", () => {
		const tree = [
			flexboxContainer( [
				headingNode( "Main page title", "h1" ),
				paragraphNode( "First body paragraph." ),
				paragraphNode( "Second body paragraph." ),
				headingNode( "A subsection", "h2" ),
				paragraphNode( "Third body paragraph." ),
			] ),
		];

		const html = walkAtomicTree( tree );

		expect( html ).toContain( "<h1>Main page title</h1>" );
		expect( html ).toContain( "<h2>A subsection</h2>" );
		expect( ( html.match( /<p>/g ) || [] ) ).toHaveLength( 3 );
		expect( html.indexOf( "<h1>" ) ).toBeLessThan( html.indexOf( "<h2>" ) );
	} );

	it( "is defensive against malformed nodes (null entries, missing settings)", () => {
		// Node with no widgetType, no elements.
		const emptyNode = {};
		// e-heading node with no settings.
		const headingNoSettings = { widgetType: "e-heading" };
		const tree = [
			null,
			undefined,
			"a string",
			emptyNode,
			headingNoSettings,
			headingNode( "Survivor", "h1" ),
		];

		expect( walkAtomicTree( tree ) ).toBe( "<h1>Survivor</h1>" );
	} );
} );
