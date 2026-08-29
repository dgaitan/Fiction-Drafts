/**
 * Line icons, drawn from primitives only.
 *
 * Every glyph is `line` / `polyline` / `polygon` / `circle` / `rect`, plus
 * straight-segment `path` data where a shape needs one edge a primitive can't
 * give it — never a hand-typed Bézier curve. That is a deliberate limit, not
 * an aesthetic one: a curve command with a mistyped argument still parses and
 * renders *something*, silently, so there is nothing here worth getting
 * wrong in a way SVG would not immediately show.
 */

/**
 * @param {Object} props             Component props.
 * @param {string} [props.className] Class names to apply to the `svg`.
 * @return {Element} A plus-in-circle glyph.
 */
export function NewBackupIcon( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<circle cx="12" cy="12" r="9" />
			<line x1="12" y1="8" x2="12" y2="16" />
			<line x1="8" y1="12" x2="16" y2="12" />
		</svg>
	);
}

/**
 * @param {Object} props             Component props.
 * @param {string} [props.className] Class names to apply to the `svg`.
 * @return {Element} An archive-box glyph.
 */
export function BackupsIcon( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<rect x="3" y="4" width="18" height="4" rx="1" />
			<path d="M5 8 L5 18 A2 2 0 0 0 7 20 L17 20 A2 2 0 0 0 19 18 L19 8" />
			<line x1="10" y1="12" x2="14" y2="12" />
		</svg>
	);
}

/**
 * @param {Object} props             Component props.
 * @param {string} [props.className] Class names to apply to the `svg`.
 * @return {Element} A clustered-cloud glyph.
 */
export function CloudIcon( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<circle cx="8" cy="14" r="4" />
			<circle cx="13" cy="10" r="5" />
			<circle cx="17" cy="14" r="3.5" />
			<rect x="5" y="14" width="14" height="5" rx="2.5" />
		</svg>
	);
}

/**
 * @param {Object} props             Component props.
 * @param {string} [props.className] Class names to apply to the `svg`.
 * @return {Element} An envelope glyph, used for both mail and messaging.
 */
export function MailIcon( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<rect x="3" y="5" width="18" height="14" rx="2" />
			<polyline points="3,7 12,13 21,7" />
		</svg>
	);
}

/**
 * @param {Object} props             Component props.
 * @param {string} [props.className] Class names to apply to the `svg`.
 * @return {Element} A gear-like glyph.
 */
export function SettingsIcon( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<circle cx="12" cy="12" r="3" />
			<circle cx="12" cy="12" r="8" strokeDasharray="2 3" />
		</svg>
	);
}

/**
 * @param {Object} props             Component props.
 * @param {string} [props.className] Class names to apply to the `svg`.
 * @return {Element} A shield-with-checkmark glyph.
 */
export function ShieldCheckIcon( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<polygon points="12,3 19,6 19,11 12,20 5,11 5,6" />
			<polyline points="9,11 11,13 15,9" />
		</svg>
	);
}

/**
 * @param {Object} props             Component props.
 * @param {string} [props.className] Class names to apply to the `svg`.
 * @return {Element} An arrow-out-of-a-box glyph.
 */
export function ExternalLinkIcon( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<polyline points="5,19 5,7 12,7" />
			<polyline points="19,12 19,19 8,19" />
			<polyline points="13,4 20,4 20,11" />
			<line x1="20" y1="4" x2="10" y2="14" />
		</svg>
	);
}

/**
 * @param {Object} props             Component props.
 * @param {string} [props.className] Class names to apply to the `svg`.
 * @return {Element} A download-tray glyph.
 */
export function DownloadIcon( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<line x1="12" y1="3" x2="12" y2="14" />
			<polyline points="7,10 12,15 17,10" />
			<line x1="5" y1="20" x2="19" y2="20" />
		</svg>
	);
}

/**
 * @param {Object} props             Component props.
 * @param {string} [props.className] Class names to apply to the `svg`.
 * @return {Element} A wastebasket glyph.
 */
export function TrashIcon( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<polyline points="6,7 7,21 17,21 18,7" />
			<line x1="9" y1="7" x2="9" y2="4" />
			<line x1="15" y1="7" x2="15" y2="4" />
			<line x1="9" y1="4" x2="15" y2="4" />
			<line x1="10" y1="11" x2="10" y2="17" />
			<line x1="14" y1="11" x2="14" y2="17" />
		</svg>
	);
}

/**
 * @param {Object} props             Component props.
 * @param {string} [props.className] Class names to apply to the `svg`.
 * @return {Element} A clock-face glyph, used for a job still under way.
 */
export function ClockIcon( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<circle cx="12" cy="12" r="9" />
			<polyline points="12,7 12,12 16,14" />
		</svg>
	);
}

/**
 * @param {Object} props             Component props.
 * @param {string} [props.className] Class names to apply to the `svg`.
 * @return {Element} A filled heart, built from two circles and a triangle.
 */
export function HeartIcon( { className } ) {
	return (
		<svg
			className={ className }
			viewBox="0 0 24 24"
			fill="currentColor"
			stroke="none"
		>
			<circle cx="8.5" cy="9" r="4.5" />
			<circle cx="15.5" cy="9" r="4.5" />
			<polygon points="4,11 12,21 20,11" />
		</svg>
	);
}
