import getResearcher from "../../src/languageProcessing/getResearcher";
import EnglishResearcher from "../../src/languageProcessing/languages/en/Researcher";
import DefaultResearcher from "../../src/languageProcessing/languages/_default/Researcher";

describe( "getResearcher", () => {
	it( "resolves a supported language to its Researcher class", () => {
		const Researcher = getResearcher( "en" );

		expect( Researcher ).toBe( EnglishResearcher );
		// It returns the class, not an instance, so consumers stay in control of construction.
		expect( typeof Researcher ).toBe( "function" );
		expect( () => new Researcher() ).not.toThrow();
	} );

	it( "falls back to the default Researcher for an unsupported language", () => {
		expect( getResearcher( "xx" ) ).toBe( DefaultResearcher );
	} );

	it( "falls back to the default Researcher when no language is given", () => {
		expect( getResearcher() ).toBe( DefaultResearcher );
	} );
} );
