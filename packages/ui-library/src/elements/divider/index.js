import classNames from "classnames";
import React, { forwardRef } from "react";

/**
 * The decorative line.
 */
const LINE_CLASS_NAME = "yst-border-0 yst-border-t yst-border-slate-200";

/**
 * @param {React.ReactNode} [children] Optional content centered on the line, e.g. a label or a toggle button.
 * @param {string} [className] The HTML class.
 * @returns {JSX.Element} The divider.
 */
const Divider = forwardRef( ( {
	children = null,
	className = "",
	...props
}, ref ) => {
	// Without content the divider is a single horizontal line.
	if ( ! children ) {
		return <hr ref={ ref } className={ classNames( LINE_CLASS_NAME, className ) } { ...props } />;
	}

	// With content the line is split so the children sit centered between the two halves.
	return (
		<div ref={ ref } className={ classNames( "yst-flex yst-items-center", className ) } { ...props }>
			<hr aria-hidden="true" className={ classNames( "yst-grow", LINE_CLASS_NAME ) } />
			{ children }
			<hr aria-hidden="true" className={ classNames( "yst-grow", LINE_CLASS_NAME ) } />
		</div>
	);
} );

Divider.displayName = "Divider";

export default Divider;
