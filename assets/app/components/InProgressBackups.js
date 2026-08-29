/**
 * The Backups tab's own view of the job still running, if there is one.
 */

import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';

import { bootstrap } from '../bootstrap';
import { getActiveJob } from '../api';
import { TERMINAL } from './Progress';
import { ClockIcon } from './icons';

/**
 * @param {Object} query The react-query Query object.
 * @return {number|false} Milliseconds until the next poll, or false to stop.
 */
function pollInterval( query ) {
	const job = query.state.data ? query.state.data.job : null;

	if ( ! job || TERMINAL.includes( job.status ) ) {
		return false;
	}

	// Same reasoning as Progress.js: a stale nonce must not turn into a
	// request every `pollMs` for as long as the Backups tab stays open.
	const status = query.state.error?.data?.status;

	if ( 401 === status || 403 === status ) {
		return false;
	}

	return bootstrap.pollMs;
}

/**
 * The label the bootstrap gave a profile slug — never a literal here, since
 * the slugs themselves belong to the server (see
 * `AdminBoundaryTest::testTheClientRestatesNoProfileSlugOrStageId`).
 *
 * @param {string} slug A profile slug, as `GET /jobs/active` returns it.
 * @return {string} The matching label, or the slug itself if the profile has
 *                   since been removed.
 */
function profileLabel( slug ) {
	const profile = bootstrap.profiles.find( ( entry ) => entry.slug === slug );

	return profile ? profile.label : slug;
}

/**
 * @return {Element|null} A card for the queued-or-running job, or nothing
 *                        when there isn't one.
 */
export default function InProgressBackups() {
	const queryClient = useQueryClient();
	const wasActive = useRef( false );

	const { data } = useQuery( {
		queryKey: [ 'activeJob' ],
		queryFn: getActiveJob,
		refetchInterval: pollInterval,
	} );

	const job = data && data.job ? data.job : null;
	const isRunning = Boolean( job ) && ! TERMINAL.includes( job.status );

	// The job just left this card — bring the now-finished backup into the
	// list below without waiting for whatever next happens to refetch it.
	useEffect( () => {
		if ( wasActive.current && ! isRunning ) {
			queryClient.invalidateQueries( { queryKey: [ 'backups' ] } );
		}

		wasActive.current = isRunning;
	}, [ isRunning, queryClient ] );

	if ( ! isRunning ) {
		return null;
	}

	const percent =
		typeof job.overall_percent === 'number' ? job.overall_percent : 0;

	return (
		<section className="fd-card fd-active-jobs">
			<h2 className="fd-card__title">
				{ __( 'In progress', 'fiction-drafts' ) }
			</h2>
			<p className="fd-card__subtitle">
				{ __(
					'Updates automatically until it finishes.',
					'fiction-drafts'
				) }
			</p>

			<ul className="fd-active-jobs__list">
				<li className="fd-active-job">
					<div className="fd-active-job__top">
						<span className="fd-active-job__label">
							<ClockIcon className="fd-icon fd-active-job__icon" />
							<span className="fd-active-job__name">
								{ profileLabel( job.profile ) }
							</span>
							<span className="fd-active-job__stage">
								{ job.stage_label ||
									__( 'Waiting to start', 'fiction-drafts' ) }
							</span>
						</span>
						<span className="fd-active-job__percent">
							{ `${ percent }%` }
						</span>
					</div>

					<progress
						className="fd-bar fd-bar--sm"
						value={ percent }
						max={ 100 }
						aria-label={ __( 'Backup progress', 'fiction-drafts' ) }
					/>

					<Link
						className="fd-button fd-button--secondary fd-active-job__link"
						to={ `/progress/${ job.uuid }` }
					>
						{ __( 'View progress', 'fiction-drafts' ) }
					</Link>
				</li>
			</ul>
		</section>
	);
}
