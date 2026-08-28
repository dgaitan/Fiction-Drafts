/**
 * The chrome around the three routes.
 */

import { __ } from '@wordpress/i18n';
import { NavLink, Outlet } from 'react-router-dom';

/**
 * @return {Element} Header, navigation, and the active route.
 */
export default function Layout() {
	return (
		<div className="fd-app">
			<h1 className="fd-app__title">
				{ __( 'Fiction Drafts', 'fiction-drafts' ) }
			</h1>

			<nav
				className="fd-nav"
				aria-label={ __( 'Fiction Drafts sections', 'fiction-drafts' ) }
			>
				<NavLink
					className={ ( { isActive } ) =>
						isActive
							? 'fd-nav__link fd-nav__link--active'
							: 'fd-nav__link'
					}
					to="/"
					end
				>
					{ __( 'New backup', 'fiction-drafts' ) }
				</NavLink>
				<NavLink
					className={ ( { isActive } ) =>
						isActive
							? 'fd-nav__link fd-nav__link--active'
							: 'fd-nav__link'
					}
					to="/backups"
				>
					{ __( 'Backups', 'fiction-drafts' ) }
				</NavLink>
				<NavLink
					className={ ( { isActive } ) =>
						isActive
							? 'fd-nav__link fd-nav__link--active'
							: 'fd-nav__link'
					}
					to="/settings"
				>
					{ __( 'Settings', 'fiction-drafts' ) }
				</NavLink>
			</nav>

			<main className="fd-app__body">
				<Outlet />
			</main>
		</div>
	);
}
