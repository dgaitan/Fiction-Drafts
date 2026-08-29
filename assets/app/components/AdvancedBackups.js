/**
 * Where finished backups will be able to go, once there's somewhere to send
 * them.
 */

import { __ } from '@wordpress/i18n';
import { Link } from 'react-router-dom';

import { CloudIcon } from './icons';

/**
 * One destination card. Both of today's destinations are disabled, so this
 * takes no props worth varying yet — a `name`/`description` pair would be
 * the first thing to add if a third destination arrives before the first
 * two ship.
 *
 * @param {Object} props             Component props.
 * @param {string} props.name        The service's name.
 * @param {string} props.description What it will do, once connected.
 * @return {Element} One "coming soon" destination card.
 */
function DestinationCard( { name, description } ) {
	return (
		<div className="fd-dest">
			<div className="fd-dest__top">
				<span className="fd-dest__icon">
					<CloudIcon className="fd-icon fd-icon--xl" />
				</span>
				<span className="fd-badge fd-badge--warning">
					{ __( 'Coming soon', 'fiction-drafts' ) }
				</span>
			</div>

			<div>
				<div className="fd-dest__name">{ name }</div>
				<div className="fd-dest__desc">{ description }</div>
			</div>

			<button
				className="fd-button fd-button--disabled"
				disabled
				type="button"
			>
				{ __( 'Connect', 'fiction-drafts' ) }
			</button>
		</div>
	);
}

/**
 * @return {Element} The Advanced Backups tab.
 */
export default function AdvancedBackups() {
	return (
		<section className="fd-card">
			<h2 className="fd-card__title">
				{ __( 'Advanced Backups', 'fiction-drafts' ) }
			</h2>
			<p className="fd-card__subtitle">
				{ __(
					'Send finished backups straight to cloud storage — no more manual downloads.',
					'fiction-drafts'
				) }
			</p>

			<div className="fd-destinations">
				<DestinationCard
					name={ __( 'Google Drive', 'fiction-drafts' ) }
					description={ __(
						'Automatically upload every completed backup to your Google Drive account.',
						'fiction-drafts'
					) }
				/>
				<DestinationCard
					name={ __( 'Dropbox', 'fiction-drafts' ) }
					description={ __(
						'Automatically upload every completed backup to your Dropbox account.',
						'fiction-drafts'
					) }
				/>
			</div>

			<p className="fd-destinations__footnote">
				{ __( 'Have another destination in mind?', 'fiction-drafts' ) }{ ' ' }
				<Link to="/feature-request">
					{ __(
						'Tell us on the Feature Request tab →',
						'fiction-drafts'
					) }
				</Link>
			</p>
		</section>
	);
}
