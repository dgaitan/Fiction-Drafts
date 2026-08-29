/**
 * Fiction Drafts admin SPA.
 *
 * Mounted into the single div `AdminPage::render()` prints. Everything the app
 * knows about profiles, stages, and warning text was handed down by PHP at
 * boot — see `bootstrap.js`.
 */

import { createRoot } from '@wordpress/element';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { HashRouter, Navigate, Route, Routes } from 'react-router-dom';

import AdvancedBackups from './components/AdvancedBackups';
import BackupsList from './components/BackupsList';
import FeatureRequest from './components/FeatureRequest';
import Layout from './components/Layout';
import NewBackup from './components/NewBackup';
import Progress from './components/Progress';
import SettingsForm from './components/SettingsForm';

import './styles/index.scss';

const queryClient = new QueryClient( {
	defaultOptions: {
		queries: {
			// A backup list is not worth re-fetching on every window focus, and
			// the one view that must stay fresh asks for it explicitly with
			// refetchInterval.
			refetchOnWindowFocus: false,
			retry: 1,
		},
	},
} );

/**
 * @return {Element} The whole application.
 */
const App = () => (
	<QueryClientProvider client={ queryClient }>
		{ /* Hash routing, not browser routing. The page is
		     `admin.php?page=fiction-drafts` with no rewrite behind it, so a
		     pushed path like /backups would 404 on the next reload. */ }
		<HashRouter>
			<Routes>
				<Route element={ <Layout /> } path="/">
					<Route element={ <NewBackup /> } index />
					<Route element={ <Progress /> } path="progress/:uuid" />
					<Route element={ <BackupsList /> } path="backups" />
					<Route
						element={ <AdvancedBackups /> }
						path="advanced-backups"
					/>
					<Route
						element={ <FeatureRequest /> }
						path="feature-request"
					/>
					<Route element={ <SettingsForm /> } path="settings" />
					{ /* Any hash the app does not own lands here rather than on a
				     blank screen — a bare href="#" anywhere in WordPress admin
				     chrome is enough to route this app to nothing. */ }
					<Route element={ <Navigate replace to="/" /> } path="*" />
				</Route>
			</Routes>
		</HashRouter>
	</QueryClientProvider>
);

document.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'fiction-drafts-root' );

	if ( container ) {
		createRoot( container ).render( <App /> );
	}
} );
