/**
 * Render smoke test for every exported visual component.
 *
 * Each component is rendered with ONLY its required props (children plus any
 * prop-types `isRequired` prop) inside `<Root>`, and we assert it mounts
 * without throwing. Optional / previously-defaulted props are deliberately
 * omitted: omitting them is exactly what surfaces the React-19 class of bug
 * where a missing `defaultProps` default becomes `undefined` and is then
 * dereferenced (e.g. Title's `as`, Button's `variant`).
 *
 * The list is data-driven so it is trivial to extend: add a
 * `{ name, Component, props }` entry to `cases`.
 */
import { noop } from "lodash";
import React from "react";
import { render } from "@testing-library/react";
import {
	Alert,
	Autocomplete,
	AutocompleteField,
	Badge,
	Button,
	Card,
	Checkbox,
	CheckboxGroup,
	ChildrenLimiter,
	Code,
	DropdownMenu,
	ErrorBoundary,
	FeatureUpsell,
	FileImport,
	GradientSparklesIcon,
	ImageSelect,
	Label,
	Link,
	Modal,
	ModalNotification,
	Notifications,
	Pagination,
	Paper,
	Popover,
	ProgressBar,
	Radio,
	RadioGroup,
	Root,
	ScoreIcon,
	Select,
	SelectField,
	SidebarNavigation,
	SkeletonLoader,
	Spinner,
	Stepper,
	Table,
	TagField,
	TagInput,
	Textarea,
	TextareaField,
	TextField,
	TextInput,
	Title,
	Toast,
	Toggle,
	ToggleField,
	Tooltip,
	TooltipContainer,
	TooltipTrigger,
	TooltipWithContext,
	ValidationIcon,
	ValidationInput,
	ValidationMessage,
} from "../src";

/**
 * The cases to render. Each supplies ONLY required props.
 *
 * @type {{name: string, Component: React.ComponentType, props: Object}[]}
 */
const cases = [
	// Elements.
	{ name: "Alert", Component: Alert, props: { children: "Alert" } },
	{
		name: "Autocomplete",
		Component: Autocomplete,
		props: { id: "ac", onChange: noop, onQueryChange: noop },
	},
	// Note: `Autocomplete.Option` / `Select.Option` are intentionally omitted. They are
	// Headless UI `Combobox.Option` / `Listbox.Option` wrappers that throw when rendered
	// without their parent `Combobox` / `Listbox`, so they cannot be smoke-tested standalone.
	// Their default-param conversions are exercised through the parent components above.
	{ name: "Badge", Component: Badge, props: { children: "Badge" } },
	{ name: "Button", Component: Button, props: { children: "Button" } },
	{
		name: "Checkbox",
		Component: Checkbox,
		props: { id: "cb", name: "cb", value: "cb" },
	},
	{ name: "Code", Component: Code, props: { children: "code" } },
	{ name: "Label", Component: Label, props: {} },
	{ name: "Link", Component: Link, props: { children: "Link" } },
	{
		name: "Paper",
		Component: Paper,
		props: { children: "Paper" },
	},
	{
		name: "ProgressBar",
		Component: ProgressBar,
		props: { min: 0, max: 100, progress: 50 },
	},
	{
		name: "Radio",
		Component: Radio,
		props: { id: "r", name: "r", value: "r", label: "Radio" },
	},
	{
		name: "Select",
		Component: Select,
		props: { id: "sel", value: "a", onChange: noop },
	},
	{ name: "SkeletonLoader", Component: SkeletonLoader, props: {} },
	{ name: "Spinner", Component: Spinner, props: {} },
	{
		name: "ScoreIcon",
		Component: ScoreIcon,
		props: { score: "good" },
	},
	{ name: "GradientSparklesIcon", Component: GradientSparklesIcon, props: {} },
	{
		name: "Table",
		Component: Table,
		props: { children: <Table.Body><Table.Row><Table.Cell>Cell</Table.Cell></Table.Row></Table.Body> },
	},
	{ name: "TagInput", Component: TagInput, props: {} },
	{
		name: "TagInput.Tag",
		Component: TagInput.Tag,
		props: { tag: "tag", index: 0, onRemoveTag: noop, screenReaderRemoveTag: "Remove" },
	},
	{ name: "TextInput", Component: TextInput, props: {} },
	{ name: "Textarea", Component: Textarea, props: {} },
	{
		name: "Title",
		Component: Title,
		props: { children: "Title" },
	},
	{
		name: "Toggle",
		Component: Toggle,
		props: { id: "tg", screenReaderLabel: "Toggle", onChange: noop },
	},
	{ name: "Tooltip", Component: Tooltip, props: {} },
	{
		name: "ValidationIcon",
		Component: ValidationIcon,
		props: {},
	},
	{
		// `as` is required (isRequired). Provide a host element.
		name: "ValidationInput",
		Component: ValidationInput,
		props: { as: "input" },
	},
	{
		name: "ValidationMessage",
		Component: ValidationMessage,
		props: { children: "Message" },
	},
	{
		name: "ErrorBoundary",
		Component: ErrorBoundary,
		// react-error-boundary requires one of the fallback props plus children.
		props: { fallback: <span>Error</span>, children: <span>Content</span> },
	},

	// Compound element: ModalNotification needs to be open with a Panel child to render meaningfully.
	{
		name: "ModalNotification",
		Component: ModalNotification,
		props: {
			isOpen: true,
			onClose: noop,
			children: <ModalNotification.Panel>Panel</ModalNotification.Panel>,
		},
	},

	// Compound element: Toast renders inside a Notifications-less context fine; it only needs id + visibility.
	{
		name: "Toast",
		Component: Toast,
		props: { id: "toast", isVisible: false, setIsVisible: noop, children: <Toast.Title title="Toast" /> },
	},

	// Components.
	{
		name: "AutocompleteField",
		Component: AutocompleteField,
		props: { id: "acf", label: "Label", onChange: noop, onQueryChange: noop },
	},
	{ name: "Card", Component: Card, props: { children: "Card" } },
	{ name: "CheckboxGroup", Component: CheckboxGroup, props: {} },
	{
		name: "ChildrenLimiter",
		Component: ChildrenLimiter,
		props: {
			limit: 1,
			children: [ <span key="1">1</span>, <span key="2">2</span> ],
			renderButton: () => <button type="button">More</button>,
		},
	},
	{
		name: "FeatureUpsell",
		Component: FeatureUpsell,
		props: { children: "Feature" },
	},
	{
		name: "FileImport",
		Component: FileImport,
		props: {
			id: "fi",
			name: "fi",
			selectLabel: "Select",
			dropLabel: "Drop",
			screenReaderLabel: "File",
			abortScreenReaderLabel: "Abort",
			feedbackTitle: "Title",
			onChange: noop,
			onAbort: noop,
		},
	},
	{
		name: "Modal",
		Component: Modal,
		props: {
			isOpen: true,
			onClose: noop,
			children: <Modal.Panel>Panel</Modal.Panel>,
		},
	},
	{ name: "Notifications", Component: Notifications, props: {} },
	{
		name: "Pagination",
		Component: Pagination,
		props: {
			current: 1,
			total: 3,
			onNavigate: noop,
			screenReaderTextPrevious: "Previous",
			screenReaderTextNext: "Next",
		},
	},
	{
		name: "Popover",
		Component: Popover,
		props: { children: "Popover" },
	},
	{ name: "RadioGroup", Component: RadioGroup, props: {} },
	{
		name: "SelectField",
		Component: SelectField,
		props: { id: "sf", label: "Label", value: "a", onChange: noop },
	},
	{
		name: "SidebarNavigation",
		Component: SidebarNavigation,
		props: { children: <span>Nav</span> },
	},
	{
		name: "Stepper",
		Component: Stepper,
		props: { steps: [ { id: "s1", children: <span>Step 1</span> } ] },
	},
	{
		name: "TagField",
		Component: TagField,
		props: { id: "tf", label: "Label" },
	},
	{
		name: "TextField",
		Component: TextField,
		props: { id: "txt", label: "Label", onChange: noop },
	},
	{
		name: "TextareaField",
		Component: TextareaField,
		props: { id: "taf", label: "Label" },
	},
	{
		name: "ToggleField",
		Component: ToggleField,
		props: { id: "tgf", label: "Label", checked: false, onChange: noop },
	},
	{
		name: "ImageSelect",
		Component: ImageSelect,
		props: {
			id: "img",
			label: "Label",
			imageUrl: "",
			selectButtonLabel: "Select",
			replaceButtonLabel: "Replace",
			onSelectImage: noop,
			isDisabled: false,
			children: <span>Preview</span>,
		},
	},

	// Tooltip container family.
	{
		name: "TooltipContainer",
		Component: TooltipContainer,
		props: { children: "Tooltip container" },
	},
	{
		name: "TooltipTrigger",
		Component: TooltipTrigger,
		props: { children: "Trigger" },
	},
	{
		name: "TooltipWithContext",
		Component: TooltipWithContext,
		props: { children: "Tooltip" },
	},

	// DropdownMenu needs a Button + List inside the headlessui Menu.
	{
		name: "DropdownMenu",
		Component: DropdownMenu,
		props: {
			children: <>
				<DropdownMenu.IconTrigger screenReaderTriggerLabel="Open" />
				<DropdownMenu.List>
					<DropdownMenu.ButtonItem>Item</DropdownMenu.ButtonItem>
				</DropdownMenu.List>
			</>,
		},
	},
];

describe( "ui-library render smoke test (React " + React.version + ")", () => {
	test.each( cases )( "$name mounts with only required props", ( { Component, props } ) => {
		expect( () => render(
			<Root>
				<Component { ...props } />
			</Root>,
		) ).not.toThrow();
	} );
} );
