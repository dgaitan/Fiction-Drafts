/**
 * One running job, polled until it stops.
 */

import { Button, Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';

import { bootstrap } from '../bootstrap';
import { cancelJob, getJob } from '../api';

/**
 * Statuses after which nothing further will happen.
 *
 * Polling a finished job forever is a request every two seconds for as long as
 * the tab stays open.
 */
const TERMINAL = [ 'completed', 'failed', 'cancelled' ];

/**
 * @param {Object} query The react-query Query object.
 * @return {number|false} Milliseconds until the next poll, or false to stop.
 */
function pollInterval( query ) {
	const job = query.state.data;

	if ( job && TERMINAL.includes( job.status ) ) {
		return false;
	}

	// A long backup polls hundreds of times. If the REST nonce goes stale part
	// way through — a logout in another tab, a cookie refresh — every one of
	// those becomes a 403, and a flat interval turns a dead session into a
	// request storm against an authenticated endpoint. Stop, and let the view
	// say why.
	const status = query.state.error?.data?.status;

	if ( 401 === status || 403 === status ) {
		return false;
	}

	return bootstrap.pollMs;
}

/**
 * @return {Element} The progress view.
 */
export default function Progress() {
	const { uuid } = useParams();
	const queryClient = useQueryClient();

	// In v5 refetchInterval is handed the Query, not the data. A v4-shaped
	// callback reads `undefined.status`, throws inside the scheduler, and the
	// bar polls forever.
	const {
		data: job,
		isPending,
		error,
	} = useQuery( {
		queryKey: [ 'job', uuid ],
		queryFn: () => getJob( uuid ),
		refetchInterval: pollInterval,
	} );

	const cancel = useMutation( {
		mutationFn: () => cancelJob( uuid ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'job', uuid ] } );
			queryClient.invalidateQueries( { queryKey: [ 'backups' ] } );
		},
	} );

	const authFailed =
		401 === error?.data?.status || 403 === error?.data?.status;

	if ( authFailed ) {
		return (
			<Notice
				className="fd-notice"
				status="error"
				isDismissible={ false }
			>
				{ __(
					'Your session has expired, so progress can no longer be read. Reload the page to sign in again — the backup itself keeps running.',
					'fiction-drafts'
				) }
			</Notice>
		);
	}

	if ( isPending || ! job ) {
		return <Spinner />;
	}

	const percent =
		typeof job.overall_percent === 'number' ? job.overall_percent : 0;
	const isRunning = ! TERMINAL.includes( job.status );

	return (
		<section className="fd-progress">
			<h2 className="fd-progress__heading">
				{ __( 'Backup in progress', 'fiction-drafts' ) }
			</h2>

			{ 'failed' === job.status && (
				<Notice
					className="fd-notice"
					status="error"
					isDismissible={ false }
				>
					{ /* Verbatim. A failed preflight names the space required, the
					     space free, and what to do about it; a generic string
					     throws all three away. */ }
					{ job.error ||
						__( 'The backup failed.', 'fiction-drafts' ) }
				</Notice>
			) }

			{ 'completed' === job.status && (
				<Notice
					className="fd-notice"
					status="success"
					isDismissible={ false }
				>
					{ __( 'Backup complete.', 'fiction-drafts' ) }
				</Notice>
			) }

			{ 'cancelled' === job.status && (
				<Notice
					className="fd-notice"
					status="warning"
					isDismissible={ false }
				>
					{ __( 'Backup cancelled.', 'fiction-drafts' ) }
				</Notice>
			) }

			<p className="fd-progress__stage">
				{ job.stage_label ||
					__( 'Waiting to start', 'fiction-drafts' ) }
			</p>

			{ /* A native <progress>, not a styled div. A percentage rendered as a
			     div needs its width set inline, and an inline style is the one
			     thing this app is not allowed — spec section 11. The element also
			     carries its own role and value to assistive technology, which the
			     div version would have had to restate as ARIA. */ }
			<progress
				className="fd-bar"
				value={ percent }
				max={ 100 }
				aria-label={ __( 'Backup progress', 'fiction-drafts' ) }
			/>

			<p className="fd-progress__percent">{ `${ percent }%` }</p>

			<p className="fd-progress__counts">
				{ job.stage_total > 0
					? `${ job.stage_processed } / ${ job.stage_total }`
					: __( 'Counting…', 'fiction-drafts' ) }
			</p>

			{ isRunning && (
				<Button
					className="fd-progress__cancel"
					variant="secondary"
					isDestructive
					onClick={ () => cancel.mutate() }
					disabled={ cancel.isPending }
				>
					{ __( 'Cancel', 'fiction-drafts' ) }
				</Button>
			) }

			{ ! isRunning && (
				<Link className="fd-progress__done" to="/backups">
					{ __( 'View backups', 'fiction-drafts' ) }
				</Link>
			) }
		</section>
	);
}
