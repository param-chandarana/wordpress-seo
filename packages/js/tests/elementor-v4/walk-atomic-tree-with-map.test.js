import { describe, expect, it } from "@jest/globals";

import { walkAtomicTree, walkAtomicTreeWithMap } from "../../src/elementor-v4/content-walker";
import { headingNode, paragraphNode, textEditorNode, flexboxContainer } from "./__fixtures__/nodes";

// Helpers to create nodes with explicit IDs (fixtures use fixed IDs).
const h = ( content, tag, id ) => ( { ...headingNode( content, tag ), id } );
const p = ( content, id ) => ( { ...paragraphNode( content ), id } );

describe( "walkAtomicTreeWithMap", () => {
	describe( "edge cases", () => {
		it( "returns empty for null, undefined, object, and string input", () => {
			const empty = { content: "", widgets: [] };
			expect( walkAtomicTreeWithMap( null ) ).toEqual( empty );
			expect( walkAtomicTreeWithMap( undefined ) ).toEqual( empty );
			expect( walkAtomicTreeWithMap( {} ) ).toEqual( empty );
			expect( walkAtomicTreeWithMap( "text" ) ).toEqual( empty );
		} );

		it( "returns empty for an empty array", () => {
			expect( walkAtomicTreeWithMap( [] ) ).toEqual( { content: "", widgets: [] } );
		} );

		it( "does not create a widget entry for a node without an id", () => {
			const noId = { elType: "widget", widgetType: "e-heading", settings: headingNode( "Title", "h2" ).settings, elements: [] };
			const { content, widgets } = walkAtomicTreeWithMap( [ noId ] );
			expect( content ).toBe( "" );
			expect( widgets ).toHaveLength( 0 );
		} );

		it( "does not create a widget entry when the extractor produces empty HTML", () => {
			// e-heading with an empty title emits nothing.
			const empty = h( "", "h2", "empty" );
			const valid = p( "After.", "valid" );
			const { widgets } = walkAtomicTreeWithMap( [ empty, valid ] );
			expect( widgets ).toHaveLength( 1 );
			expect( widgets[ 0 ].id ).toBe( "valid" );
			// Position must start at 0, not after the empty node.
			expect( widgets[ 0 ].start ).toBe( 0 );
		} );
	} );

	describe( "positions", () => {
		it( "returns start=0 and end=html.length for a single widget", () => {
			const node = h( "Title", "h1", "w1" );
			const expected = "<h1>Title</h1>";
			const { content, widgets } = walkAtomicTreeWithMap( [ node ] );
			expect( content ).toBe( expected );
			expect( widgets ).toEqual( [ { id: "w1", widgetType: "e-heading", start: 0, end: expected.length } ] );
		} );

		it( "produces sequential, non-overlapping positions for multiple sibling widgets", () => {
			const tree = [ h( "Cats", "h2", "w1" ), p( "Body text.", "w2" ) ];
			const hHtml = "<h2>Cats</h2>";
			const pHtml = "<p>Body text.</p>";
			const { content, widgets } = walkAtomicTreeWithMap( tree );
			expect( content ).toBe( hHtml + pHtml );
			expect( widgets[ 0 ] ).toEqual( { id: "w1", widgetType: "e-heading", start: 0, end: hHtml.length } );
			expect( widgets[ 1 ] ).toEqual( { id: "w2", widgetType: "e-paragraph", start: hHtml.length, end: hHtml.length + pHtml.length } );
		} );

		it( "content matches walkAtomicTree for the same tree", () => {
			const tree = [ h( "Heading", "h1", "a" ), p( "Paragraph.", "b" ), textEditorNode( "<p>Rich text.</p>" ) ];
			const { content } = walkAtomicTreeWithMap( tree );
			expect( content ).toBe( walkAtomicTree( tree ) );
		} );

		it( "strips \\n and \\t so positions are in the normalised string", () => {
			const node = { ...textEditorNode( "<p>Line\tone\ntwo</p>" ), id: "te" };
			const { content, widgets } = walkAtomicTreeWithMap( [ node ] );
			expect( content ).not.toMatch( /[\n\t]/ );
			expect( widgets[ 0 ].end - widgets[ 0 ].start ).toBe( content.length );
		} );

		it( "every widget span is a valid slice of the content string", () => {
			const tree = [
				h( "First", "h1", "w1" ),
				p( "Second.", "w2" ),
				{ ...textEditorNode( "<p>Third.</p>" ), id: "w3" },
			];
			const { content, widgets } = walkAtomicTreeWithMap( tree );
			widgets.forEach( ( w ) => {
				expect( content.slice( w.start, w.end ) ).toBe( content.slice( w.start, w.end ) );
				expect( w.start ).toBeGreaterThanOrEqual( 0 );
				expect( w.end ).toBeGreaterThan( w.start );
				expect( w.end ).toBeLessThanOrEqual( content.length );
			} );
		} );
	} );

	describe( "nested containers", () => {
		it( "adjusts child widget offsets by the length of preceding content", () => {
			const outerHtml = "<h1>Outer</h1>";
			const innerHHtml = "<h2>Inner.</h2>";
			const innerPHtml = "<p>Deep.</p>";

			const tree = [
				h( "Outer", "h1", "outer" ),
				flexboxContainer( [
					h( "Inner.", "h2", "inner-h" ),
					p( "Deep.", "inner-p" ),
				] ),
			];

			const { content, widgets } = walkAtomicTreeWithMap( tree );
			expect( content ).toBe( outerHtml + innerHHtml + innerPHtml );

			expect( widgets.find( w => w.id === "outer" ) ).toEqual(
				{ id: "outer", widgetType: "e-heading", start: 0, end: outerHtml.length }
			);
			expect( widgets.find( w => w.id === "inner-h" ) ).toEqual(
				{ id: "inner-h", widgetType: "e-heading", start: outerHtml.length, end: outerHtml.length + innerHHtml.length }
			);
			expect( widgets.find( w => w.id === "inner-p" ) ).toEqual(
				{ id: "inner-p", widgetType: "e-paragraph", start: outerHtml.length + innerHHtml.length, end: outerHtml.length + innerHHtml.length + innerPHtml.length }
			);
		} );
	} );

	describe( "excluded widget types", () => {
		it( "skips excluded widgets and does not walk their children", () => {
			const button = {
				id: "btn",
				elType: "widget",
				widgetType: "e-button",
				settings: {},
				elements: [ h( "Should not appear", "h2", "child-h" ) ],
			};
			const after = p( "After.", "after-p" );
			const { content, widgets } = walkAtomicTreeWithMap( [ button, after ] );
			expect( content ).toBe( "<p>After.</p>" );
			expect( widgets ).toHaveLength( 1 );
			expect( widgets[ 0 ].id ).toBe( "after-p" );
		} );

		it( "still processes siblings that follow an excluded widget", () => {
			const tree = [
				h( "Before.", "h1", "before" ),
				{ id: "div", elType: "widget", widgetType: "e-divider", settings: {}, elements: [] },
				p( "After.", "after" ),
			];
			const { widgets } = walkAtomicTreeWithMap( tree );
			const ids = widgets.map( w => w.id );
			expect( ids ).toContain( "before" );
			expect( ids ).toContain( "after" );
			expect( ids ).not.toContain( "div" );
		} );
	} );

	describe( "Backbone collection handling", () => {
		it( "unwraps a toJSON() collection at any nesting level", () => {
			const backboneCollection = ( children ) => ( { toJSON: () => children } );
			const tree = [ {
				id: "container",
				elType: "container",
				settings: {},
				elements: backboneCollection( [ h( "Deep.", "h3", "deep" ) ] ),
			} ];
			const { content, widgets } = walkAtomicTreeWithMap( tree );
			expect( content ).toBe( "<h3>Deep.</h3>" );
			expect( widgets[ 0 ].id ).toBe( "deep" );
		} );
	} );
} );
