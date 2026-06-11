const stringProp = ( value ) => ( { $$type: "string", value } );

const htmlV3Prop = ( content ) => ( {
	$$type: "html-v3",
	value: { content: stringProp( content ), children: [] },
} );

const headingNode = ( content, tag = "h2" ) => ( {
	id: "abc",
	elType: "widget",
	widgetType: "e-heading",
	settings: { title: htmlV3Prop( content ), tag: stringProp( tag ) },
	elements: [],
} );

const paragraphNode = ( content, tag = "p" ) => ( {
	id: "def",
	elType: "widget",
	widgetType: "e-paragraph",
	settings: { paragraph: htmlV3Prop( content ), tag: stringProp( tag ) },
	elements: [],
} );

const textEditorNode = ( html ) => ( {
	id: "ted",
	elType: "widget",
	widgetType: "text-editor",
	settings: { editor: html },
	elements: [],
} );

const flexboxContainer = ( children ) => ( {
	id: "container-1",
	elType: "e-flexbox",
	settings: {},
	elements: children,
} );

export { stringProp, htmlV3Prop, headingNode, paragraphNode, textEditorNode, flexboxContainer };
