import { describe, expect, it } from "@jest/globals";

import { walkAtomicTree } from "../../src/elementor-v4/content-walker";
import { headingNode, paragraphNode, textEditorNode, flexboxContainer } from "./__fixtures__/nodes";

const linkProp = ( url ) => ( {
	$$type: "link",
	value: { destination: { $$type: "url", value: url }, isTargetBlank: null },
} );

const withLink = ( node, url ) => ( {
	...node,
	settings: { ...node.settings, link: linkProp( url ) },
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

	it( "emits internal links from a text-editor widget so InternalLinksAssessment can count them", () => {
		const tree = [
			headingNode( "Our story", "h2" ),
			textEditorNode( "<p>Learn more on our <a href=\"/about/\">about page</a> or <a href=\"/contact/\">contact us</a>.</p>" ),
		];

		const html = walkAtomicTree( tree );

		expect( html ).toContain( "<a href=\"/about/\">" );
		expect( html ).toContain( "<a href=\"/contact/\">" );
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

describe( "widget-level link wrapping", () => {
	it( "wraps paragraph output in an anchor when the widget has a link", () => {
		expect( walkAtomicTree( [ withLink( paragraphNode( "Click here." ), "https://example.com/page/" ) ] ) ).toBe(
			"<a href=\"https://example.com/page/\"><p>Click here.</p></a>"
		);
	} );

	it( "wraps heading output in an anchor when the widget has a link", () => {
		expect( walkAtomicTree( [ withLink( headingNode( "Our story", "h2" ), "/about/" ) ] ) ).toBe(
			"<a href=\"/about/\"><h2>Our story</h2></a>"
		);
	} );

	it( "escapes special characters in the widget link URL", () => {
		expect( walkAtomicTree( [ withLink( paragraphNode( "Search" ), "https://example.com/?q=a&b=c" ) ] ) ).toBe(
			"<a href=\"https://example.com/?q=a&amp;b=c\"><p>Search</p></a>"
		);
	} );

	it( "does not wrap when the widget has no link", () => {
		expect( walkAtomicTree( [ paragraphNode( "No link here." ) ] ) ).toBe( "<p>No link here.</p>" );
	} );
} );

describe( "excluded widget types", () => {
	it( "excludes e-button from analysis, matching the classic elementor-button-wrapper filter", () => {
		const buttonNode = {
			id: "btn-1",
			elType: "widget",
			widgetType: "e-button",
			settings: { text: { $$type: "html-v3", value: { content: { $$type: "string", value: "Buy now" }, children: [] } } },
			elements: [],
		};
		expect( walkAtomicTree( [ buttonNode ] ) ).toBe( "" );
	} );

	it( "excludes e-rating from analysis, matching the classic e-rating filter", () => {
		const ratingNode = {
			id: "rat-1",
			elType: "widget",
			widgetType: "e-rating",
			settings: {},
			elements: [],
		};
		expect( walkAtomicTree( [ ratingNode ] ) ).toBe( "" );
	} );

	it( "does not walk children of an excluded widget", () => {
		const buttonWithChild = {
			id: "btn-2",
			elType: "widget",
			widgetType: "e-button",
			settings: {},
			elements: [ headingNode( "Should not appear", "h2" ) ],
		};
		expect( walkAtomicTree( [ buttonWithChild ] ) ).toBe( "" );
	} );

	it( "still processes siblings of excluded widgets", () => {
		const tree = [
			headingNode( "Before", "h1" ),
			{ id: "rat-2", elType: "widget", widgetType: "e-rating", settings: {}, elements: [] },
			paragraphNode( "After." ),
		];
		expect( walkAtomicTree( tree ) ).toBe( "<h1>Before</h1><p>After.</p>" );
	} );
} );
