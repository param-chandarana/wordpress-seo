import { beforeEach, describe, expect, it, jest } from "@jest/globals";

// Local module mocks — no factory; auto-mock creates jest.fn() for each export.
// Importing after jest.mock() gives a reference to the mock function.
jest.mock( "../../src/elementor-v4/content-walker" );
jest.mock( "../../src/elementor-v4/document-tree" );
jest.mock( "../../src/helpers/firstImageUrlInContent" );
jest.mock( "../../src/helpers/replacementVariableHelpers" );
jest.mock( "../../src/analysis/getContentLocale" );

import { walkAtomicTree } from "../../src/elementor-v4/content-walker";
import { getDocumentTree } from "../../src/elementor-v4/document-tree";
import firstImageUrlInContent from "../../src/helpers/firstImageUrlInContent";
import { excerptFromContent } from "../../src/helpers/replacementVariableHelpers";
import getContentLocale from "../../src/analysis/getContentLocale";
import { getEditorData } from "../../src/elementor-v4/editor-data";

const mockPageModelGet = jest.fn();

global.elementor = {
	settings: {
		page: {
			model: { get: mockPageModelGet },
		},
	},
};

const PAGE_SETTING_KEYS = {
	TITLE: "post_title",
	EXCERPT: "post_excerpt",
	STATUS: "post_status",
	FEATURED_IMAGE: "post_featured_image",
};

const defaultSettings = {
	[ PAGE_SETTING_KEYS.TITLE ]: "My Post",
	[ PAGE_SETTING_KEYS.EXCERPT ]: "",
	[ PAGE_SETTING_KEYS.STATUS ]: "draft",
	[ PAGE_SETTING_KEYS.FEATURED_IMAGE ]: null,
};

const makeEditorDocument = ( $elementOverride = null ) => ( {
	$element: $elementOverride ?? {
		find: jest.fn().mockReturnValue( {
			find: jest.fn().mockReturnValue( { get: jest.fn().mockReturnValue( null ) } ),
		} ),
	},
} );

beforeEach( () => {
	jest.clearAllMocks();
	mockPageModelGet.mockImplementation( ( key ) => defaultSettings[ key ] ?? null );
	getDocumentTree.mockReturnValue( [] );
	walkAtomicTree.mockReturnValue( "" );
	firstImageUrlInContent.mockReturnValue( "" );
	excerptFromContent.mockReturnValue( "generated excerpt" );
	getContentLocale.mockReturnValue( "en" );
} );

describe( "getEditorData", () => {
	it( "returns content produced by walkAtomicTree", () => {
		const tree = [ { id: "h1", widgetType: "e-heading" } ];
		getDocumentTree.mockReturnValue( tree );
		walkAtomicTree.mockReturnValue( "<h1>Hello</h1>" );

		const result = getEditorData( makeEditorDocument() );

		expect( getDocumentTree ).toHaveBeenCalled();
		expect( walkAtomicTree ).toHaveBeenCalledWith( tree );
		expect( result.content ).toBe( "<h1>Hello</h1>" );
	} );

	it( "returns title and status from elementor page settings", () => {
		mockPageModelGet.mockImplementation( ( key ) => ( {
			[ PAGE_SETTING_KEYS.TITLE ]: "SEO Title",
			[ PAGE_SETTING_KEYS.STATUS ]: "publish",
			[ PAGE_SETTING_KEYS.EXCERPT ]: "",
			[ PAGE_SETTING_KEYS.FEATURED_IMAGE ]: null,
		} )[ key ] ?? null );

		const result = getEditorData( makeEditorDocument() );

		expect( result.title ).toBe( "SEO Title" );
		expect( result.status ).toBe( "publish" );
	} );

	it( "uses post_excerpt when set", () => {
		mockPageModelGet.mockImplementation( ( key ) => key === PAGE_SETTING_KEYS.EXCERPT ? "Hand-written excerpt." : null );

		const result = getEditorData( makeEditorDocument() );

		expect( result.excerpt ).toBe( "Hand-written excerpt." );
		expect( excerptFromContent ).not.toHaveBeenCalled();
	} );

	it( "falls back to excerptFromContent when post_excerpt is absent", () => {
		walkAtomicTree.mockReturnValue( "<p>Long content here.</p>" );
		excerptFromContent.mockReturnValue( "Auto-generated excerpt." );

		const result = getEditorData( makeEditorDocument() );

		expect( excerptFromContent ).toHaveBeenCalledWith( "<p>Long content here.</p>", 156 );
		expect( result.excerpt ).toBe( "Auto-generated excerpt." );
	} );

	it( "uses a character limit of 80 for the Japanese locale", () => {
		getContentLocale.mockReturnValue( "ja" );
		walkAtomicTree.mockReturnValue( "<p>Content.</p>" );

		getEditorData( makeEditorDocument() );

		expect( excerptFromContent ).toHaveBeenCalledWith( "<p>Content.</p>", 80 );
	} );

	it( "returns excerptOnly from post_excerpt with no content fallback", () => {
		walkAtomicTree.mockReturnValue( "<p>Content.</p>" );

		const resultNoExcerpt = getEditorData( makeEditorDocument() );
		expect( resultNoExcerpt.excerptOnly ).toBe( "" );

		mockPageModelGet.mockImplementation( ( key ) => key === PAGE_SETTING_KEYS.EXCERPT ? "My excerpt." : null );
		const resultWithExcerpt = getEditorData( makeEditorDocument() );
		expect( resultWithExcerpt.excerptOnly ).toBe( "My excerpt." );
	} );

	it( "prefers featuredImageUrl over contentImageUrl for imageUrl", () => {
		mockPageModelGet.mockImplementation( ( key ) => {
			if ( key === PAGE_SETTING_KEYS.FEATURED_IMAGE ) {
				return { url: "https://example.com/featured.jpg" };
			}
			return null;
		} );
		firstImageUrlInContent.mockReturnValue( "https://example.com/content.jpg" );

		const result = getEditorData( makeEditorDocument() );

		expect( result.imageUrl ).toBe( "https://example.com/featured.jpg" );
		expect( result.featuredImage ).toBe( "https://example.com/featured.jpg" );
	} );

	it( "falls back to contentImageUrl when no featured image is set", () => {
		firstImageUrlInContent.mockReturnValue( "https://example.com/content.jpg" );

		const result = getEditorData( makeEditorDocument() );

		expect( result.imageUrl ).toBe( "https://example.com/content.jpg" );
		expect( result.contentImage ).toBe( "https://example.com/content.jpg" );
		expect( result.featuredImage ).toBe( "" );
	} );
} );

describe( "enrichImageNodes (via getEditorData)", () => {
	it( "fills htmlCache for an e-image node that is missing it, reading from the preview DOM", () => {
		const imgOuterHTML = "<img src=\"https://example.com/photo.jpg\" alt=\"Photo\">";
		const imageNode = { id: "img-1", widgetType: "e-image", elements: [] };
		getDocumentTree.mockReturnValue( [ imageNode ] );

		const mockImgEl = { outerHTML: imgOuterHTML };
		const $element = {
			find: jest.fn().mockReturnValue( {
				find: jest.fn().mockReturnValue( { get: jest.fn().mockReturnValue( mockImgEl ) } ),
			} ),
		};

		getEditorData( { $element } );

		expect( walkAtomicTree ).toHaveBeenCalledWith(
			expect.arrayContaining( [
				expect.objectContaining( { htmlCache: imgOuterHTML } ),
			] )
		);
	} );

	it( "does not overwrite an existing htmlCache", () => {
		const existingCache = "<img src=\"https://example.com/existing.jpg\" alt=\"\">";
		const imageNode = { id: "img-1", widgetType: "e-image", htmlCache: existingCache, elements: [] };
		getDocumentTree.mockReturnValue( [ imageNode ] );

		const mockImgEl = { outerHTML: "<img src=\"https://example.com/new.jpg\" alt=\"\">" };
		const $element = {
			find: jest.fn().mockReturnValue( {
				find: jest.fn().mockReturnValue( { get: jest.fn().mockReturnValue( mockImgEl ) } ),
			} ),
		};

		getEditorData( { $element } );

		expect( walkAtomicTree ).toHaveBeenCalledWith(
			expect.arrayContaining( [
				expect.objectContaining( { htmlCache: existingCache } ),
			] )
		);
	} );

	it( "recurses into nested elements to enrich image nodes at any depth", () => {
		const imgOuterHTML = "<img src=\"https://example.com/deep.jpg\" alt=\"Deep\">";
		const imageNode = { id: "img-nested", widgetType: "e-image", elements: [] };
		const tree = [ { id: "container", elType: "e-flexbox", elements: [ imageNode ] } ];
		getDocumentTree.mockReturnValue( tree );

		const mockImgEl = { outerHTML: imgOuterHTML };
		const $element = {
			find: jest.fn().mockReturnValue( {
				find: jest.fn().mockReturnValue( { get: jest.fn().mockReturnValue( mockImgEl ) } ),
			} ),
		};

		getEditorData( { $element } );

		expect( imageNode.htmlCache ).toBe( imgOuterHTML );
	} );
} );
