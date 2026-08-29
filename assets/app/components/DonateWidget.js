/**
 * The one persistent widget in the sidebar, on every tab.
 */

import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { HeartIcon } from './icons';

/**
 * Where the button sends a visitor.
 *
 * The plugin has no payment processor of its own to integrate, so this points
 * at the author's own site rather than a fabricated `/donate` path that would
 * 404. Swap it for a dedicated sponsor link (GitHub Sponsors, Buy Me a
 * Coffee, Ko-fi) once one exists — one constant, one place.
 */
const DONATE_URL = 'https://dgaitan.dev';

/**
 * @return {Element} The donate widget.
 */
export default function DonateWidget() {
	return (
		<div className="fd-widget">
			<span className="fd-widget__icon">
				<HeartIcon className="fd-icon fd-icon--lg" />
			</span>

			<h3 className="fd-widget__title">
				{ __( 'Enjoying Fiction Drafts?', 'fiction-drafts' ) }
			</h3>

			<p className="fd-widget__body">
				{ __(
					"This plugin will always be free. If it's saved you time, a small donation helps keep it that way.",
					'fiction-drafts'
				) }
			</p>

			<Button
				className="fd-widget__button"
				href={ DONATE_URL }
				target="_blank"
				rel="noopener noreferrer"
			>
				<HeartIcon className="fd-icon fd-icon--sm" />
				{ __( 'Donate', 'fiction-drafts' ) }
			</Button>
		</div>
	);
}
