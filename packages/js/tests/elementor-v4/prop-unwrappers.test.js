import { describe, expect, it } from "@jest/globals";

import { __testables__ } from "../../src/elementor-v4/content-walker";
import { stringProp, htmlV3Prop } from "./__fixtures__/nodes";

const { readStringProp, readHtmlV3Prop, readNestedStringProp, escapeAttribute } = __testables__;

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
