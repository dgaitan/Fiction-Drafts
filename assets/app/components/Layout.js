/**
 * The chrome around every route: the banner, the tabs, and the sidebar.
 */

import { __ } from '@wordpress/i18n';
import { NavLink, Outlet } from 'react-router-dom';

import DonateWidget from './DonateWidget';
import logo from '../images/icon.png';
import { bootstrap } from '../bootstrap';
import {
	BackupsIcon,
	CloudIcon,
	ExternalLinkIcon,
	MailIcon,
	NewBackupIcon,
	SettingsIcon,
	ShieldCheckIcon,
} from './icons';

/**
 * The plugin author's own site — not a claim about the plugin, so it lives
 * here rather than in the bootstrap payload the server owns.
 */
const AUTHOR_URL = 'https://dgaitan.dev';

/**
 * @param {Object}  status          `NavLink`'s render prop.
 * @param {boolean} status.isActive Whether this link matches the current route.
 * @return {string} The class names for one tab.
 */
function tabClassName( { isActive } ) {
	return isActive ? 'fd-tab fd-tab--active' : 'fd-tab';
}

/**
 * @return {Element} Header, navigation, and the active route.
 */
export default function Layout() {
	return (
		<div className="fd-app">
			<header className="fd-hero">
				<div className="fd-hero__brand">
					<img className="fd-hero__logo" src={ logo } alt="" />

					<div className="fd-hero__intro">
						<div className="fd-hero__titlerow">
							<h1 className="fd-hero__title">
								{ __( 'Fiction Drafts', 'fiction-drafts' ) }
							</h1>
							{ bootstrap.version && (
								<span className="fd-pill">
									{ `v${ bootstrap.version }` }
								</span>
							) }
						</div>

						<p className="fd-hero__tagline">
							{ __(
								'Back up your site to downloadable zip volumes as resumable background jobs that never time out.',
								'fiction-drafts'
							) }
						</p>

						<div className="fd-hero__note">
							<ShieldCheckIcon className="fd-icon fd-icon--sm" />
							<span>
								{ __(
									'Export only — nothing here can ever write back to your site.',
									'fiction-drafts'
								) }
							</span>
						</div>
					</div>
				</div>

				<div className="fd-hero__author">
					<span className="fd-avatar">DG</span>
					<span className="fd-hero__authortext">
						<span>{ __( 'Built by', 'fiction-drafts' ) }</span>
						<a
							className="fd-hero__authorlink"
							href={ AUTHOR_URL }
							target="_blank"
							rel="noopener noreferrer"
						>
							David Gaitan
							<ExternalLinkIcon className="fd-icon fd-icon--xs" />
						</a>
					</span>
				</div>
			</header>

			<nav
				className="fd-nav"
				aria-label={ __( 'Fiction Drafts sections', 'fiction-drafts' ) }
			>
				<NavLink className={ tabClassName } to="/" end>
					<NewBackupIcon className="fd-icon" />
					{ __( 'New backup', 'fiction-drafts' ) }
				</NavLink>
				<NavLink className={ tabClassName } to="/backups">
					<BackupsIcon className="fd-icon" />
					{ __( 'Backups', 'fiction-drafts' ) }
				</NavLink>
				<NavLink className={ tabClassName } to="/advanced-backups">
					<CloudIcon className="fd-icon" />
					{ __( 'Advanced Backups', 'fiction-drafts' ) }
				</NavLink>
				<NavLink className={ tabClassName } to="/feature-request">
					<MailIcon className="fd-icon" />
					{ __( 'Feature Request', 'fiction-drafts' ) }
				</NavLink>
				<NavLink className={ tabClassName } to="/settings">
					<SettingsIcon className="fd-icon" />
					{ __( 'Settings', 'fiction-drafts' ) }
				</NavLink>
			</nav>

			<div className="fd-layout">
				<main className="fd-main">
					<Outlet />
				</main>

				<aside className="fd-sidebar">
					<DonateWidget />
				</aside>
			</div>
		</div>
	);
}
