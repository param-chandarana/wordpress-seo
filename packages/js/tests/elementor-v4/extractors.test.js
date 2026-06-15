import { describe, expect, it } from "@jest/globals";

import { __testables__ } from "../../src/elementor-v4/content-walker";
import { htmlV3Prop, headingNode, paragraphNode } from "./__fixtures__/nodes";

const { EXTRACTORS } = __testables__;

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

	describe( "e-image", () => {
		// In Elementor V4 the image URL is resolved from a WP attachment ID server-side;
		// the JSON settings hold only the ID and url: null. The pre-rendered htmlCache is
		// the only client-side source of the resolved src and alt values.
		it( "extracts src and alt from htmlCache (real V4 shape — url is null in settings)", () => {
			const node = {
				widgetType: "e-image",
				settings: {
					image: {
						$$type: "image",
						value: {
							src: { $$type: "image-src", value: { id: { $$type: "image-attachment-id", value: 9 }, url: null } },
						},
					},
				},
				htmlCache: "<img class=\"e-image-base\" data-interaction-id=\"abc\" src=\"http://example.com/photo.jpg\" width=\"600\" height=\"421\" alt=\"A descriptive alt text\"/>",
				elements: [],
			};
			expect( EXTRACTORS[ "e-image" ]( node ) ).toBe(
				"<img src=\"http://example.com/photo.jpg\" alt=\"A descriptive alt text\">"
			);
		} );

		it( "emits an empty-alt img when alt is missing in htmlCache (still flagged by Yoast)", () => {
			const node = {
				widgetType: "e-image",
				settings: {},
				htmlCache: "<img class=\"e-image-base\" src=\"http://example.com/decor.png\" alt=\"\"/>",
				elements: [],
			};
			expect( EXTRACTORS[ "e-image" ]( node ) ).toBe( "<img src=\"http://example.com/decor.png\" alt=\"\">" );
		} );

		it( "escapes special characters in src and alt read from htmlCache", () => {
			const node = {
				widgetType: "e-image",
				settings: {},
				htmlCache: "<img src=\"http://example.com/?a=1&b=2\" alt=\"Quote &quot;test&quot;\"/>",
				elements: [],
			};
			// DOMParser already unescapes HTML entities; escapeAttribute re-escapes them for safe output.
			expect( EXTRACTORS[ "e-image" ]( node ) ).toBe(
				"<img src=\"http://example.com/?a=1&amp;b=2\" alt=\"Quote &quot;test&quot;\">"
			);
		} );

		it( "returns empty when htmlCache is absent and settings carry no usable data", () => {
			expect( EXTRACTORS[ "e-image" ]( {
				widgetType: "e-image",
				settings: {},
				elements: [],
			} ) ).toBe( "" );
		} );

		it( "returns empty when htmlCache contains no img tag", () => {
			expect( EXTRACTORS[ "e-image" ]( {
				widgetType: "e-image",
				settings: {},
				htmlCache: "<div>not an image</div>",
				elements: [],
			} ) ).toBe( "" );
		} );
	} );

	describe( "text-editor", () => {
		it( "passes HTML through unchanged and preserves both external and internal anchor tags", () => {
			const node = {
				widgetType: "text-editor",
				settings: {
					editor: "<p>Visit <a href=\"https://example.com\">example.com</a> or read our <a href=\"/about/\">about page</a>.</p>",
				},
			};
			const result = EXTRACTORS[ "text-editor" ]( node );
			expect( result ).toContain( "<a href=\"https://example.com\">example.com</a>" );
			expect( result ).toContain( "<a href=\"/about/\">about page</a>" );
		} );

		it( "returns empty when editor content is absent or empty", () => {
			expect( EXTRACTORS[ "text-editor" ]( { widgetType: "text-editor", settings: {} } ) ).toBe( "" );
			expect( EXTRACTORS[ "text-editor" ]( { widgetType: "text-editor", settings: { editor: "" } } ) ).toBe( "" );
		} );
	} );

	describe( "e-youtube", () => {
		it( "emits a labelled anchor for the video URL", () => {
			const node = {
				widgetType: "e-youtube",
				settings: { source: { $$type: "string", value: "https://www.youtube.com/watch?v=abc123" } },
			};
			expect( EXTRACTORS[ "e-youtube" ]( node ) ).toBe(
				"<a href=\"https://www.youtube.com/watch?v=abc123\">YouTube video</a>"
			);
		} );

		it( "returns empty when the source URL is absent", () => {
			expect( EXTRACTORS[ "e-youtube" ]( { widgetType: "e-youtube", settings: {} } ) ).toBe( "" );
		} );
	} );
} );
