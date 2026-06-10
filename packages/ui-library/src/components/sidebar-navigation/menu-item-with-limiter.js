import ChevronDownIcon from "@heroicons/react/solid/ChevronDownIcon";
import classNames from "classnames";
import React, { useCallback } from "react";
import { useSvgAria } from "../../hooks";
import Divider from "../../elements/divider";
import ChildrenLimiter from "../children-limiter";
import { useNavigationContext } from "./index";
import MenuItem from "./menu-item";

/**
 * The show-more / show-less toggle, a pill button centered on a divider line. Keeps the navigation
 * history in sync with the toggle so the expand state is restored when the menu remounts.
 *
 * @param {boolean} show Whether the extra children are currently shown.
 * @param {Function} toggle Toggles the extra children (from ChildrenLimiter).
 * @param {Object} ariaProps The `aria-expanded` / `aria-controls` props from ChildrenLimiter.
 * @param {string} buttonId The history key for the expand state.
 * @param {React.ReactNode} showMoreLabel The label shown while collapsed.
 * @param {React.ReactNode} showLessLabel The label shown while expanded.
 * @returns {JSX.Element} The toggle button on a divider.
 */
const LimiterToggle = ( { show, toggle, ariaProps, buttonId, showMoreLabel, showLessLabel } ) => {
	const { addToHistory, removeFromHistory } = useNavigationContext();
	const svgAriaProps = useSvgAria();

	const handleClick = useCallback( () => {
		toggle();
		if ( show ) {
			removeFromHistory( buttonId );
		} else {
			addToHistory( buttonId );
		}
	}, [ show, toggle, buttonId, addToHistory, removeFromHistory ] );

	return (
		<Divider className="yst-mt-2">
			<button
				type="button"
				className="yst-flex yst-items-center yst-gap-1.5 yst-px-2.5 yst-py-1 yst-text-xs yst-font-medium yst-text-slate-700 yst-bg-slate-50 yst-rounded-full yst-border yst-border-slate-300 hover:yst-bg-white hover:yst-text-slate-800 focus:yst-outline-none focus:yst-ring-2 focus:yst-ring-primary-500 focus:yst-ring-offset-2"
				onClick={ handleClick }
				{ ...ariaProps }
			>
				{ show ? showLessLabel : showMoreLabel }
				<ChevronDownIcon
					className={ classNames( "yst-h-4 yst-w-4 yst-flex-shrink-0 yst-text-slate-400", show && "yst-rotate-180" ) }
					{ ...svgAriaProps }
				/>
			</button>
		</Divider>
	);
};

/**
 * A `SidebarNavigation.MenuItem` with a built-in `ChildrenLimiter` and a show-more/less toggle.
 *
 * The expand state is persisted in the navigation history (keyed by `buttonId`), so it survives the
 * menu re-rendering or remounting (e.g. when navigating between routes).
 *
 * @param {string} id The menu item id.
 * @param {Function} [icon] The icon component for the menu item.
 * @param {React.ReactNode} label The menu item label.
 * @param {boolean} [defaultOpen] Whether the menu item starts open.
 * @param {number} limit The maximum children shown before the toggle appears.
 * @param {string} buttonId The id of the collapsible region the toggle controls (`aria-controls`) and the
 *                          key under which the expand state is stored in the navigation history.
 * @param {React.ReactNode} showMoreLabel The toggle label while collapsed.
 * @param {React.ReactNode} showLessLabel The toggle label while expanded.
 * @param {React.ReactNode} children The children to limit.
 * @returns {JSX.Element} The menu item with a children limiter.
 */
export const MenuItemWithLimiter = ( {
	id,
	icon = null,
	label,
	defaultOpen = false,
	limit,
	buttonId,
	showMoreLabel,
	showLessLabel,
	children,
} ) => {
	const { history } = useNavigationContext();

	const renderButton = useCallback(
		( { show, toggle, ariaProps } ) => (
			<LimiterToggle
				show={ show }
				toggle={ toggle }
				ariaProps={ ariaProps }
				buttonId={ buttonId }
				showMoreLabel={ showMoreLabel }
				showLessLabel={ showLessLabel }
			/>
		),
		[ buttonId, showMoreLabel, showLessLabel ],
	);

	return (
		<MenuItem id={ id } icon={ icon } label={ label } defaultOpen={ defaultOpen }>
			<ChildrenLimiter limit={ limit } id={ buttonId } initialShow={ history.includes( buttonId ) } renderButton={ renderButton }>
				{ children }
			</ChildrenLimiter>
		</MenuItem>
	);
};

MenuItemWithLimiter.displayName = "SidebarNavigation.MenuItemWithLimiter";

export default MenuItemWithLimiter;
