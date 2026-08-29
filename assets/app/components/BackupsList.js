/**
 * The finished backups.
 */

import { Button, Notice, Spinner } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';

import { deleteBackup, getBackups } from '../api';
import DownloadVolumes from './DownloadVolumes';
import InProgressBackups from './InProgressBackups';
import { NewBackupIcon, TrashIcon } from './icons';

/**
 * A dot colour for a profile, derived from what it copies rather than from
 * its slug — the slugs belong to the server, and restating one here is a
 * second answer to a question that already has one (see
 * `AdminBoundaryTest::testTheClientRestatesNoProfileSlugOrStageId`).
 *
 * @param {Object} profile One entry's `profile` field, as `GET /backups`
 *                         returns it — `{ custom, includes: { database,
 *                         core, uploads } }`.
 * @return {string} A `fd-dot--*` modifier class.
 */
function profileDotClass( profile ) {
	if ( profile.custom ) {
		return 'fd-dot--custom';
	}

	const { database, core, uploads } = profile.includes;

	if ( database && core && uploads ) {
		return 'fd-dot--all';
	}

	if ( database && ! core ) {
		return 'fd-dot--db';
	}

	if ( uploads ) {
		return 'fd-dot--files';
	}

	return 'fd-dot--files-lean';
}

/**
 * @return {Element} The backups table.
 */
export default function BackupsList() {
	const queryClient = useQueryClient();

	// Which row has asked to be deleted and is waiting for a second click.
	//
	// An in-page confirmation rather than window.confirm(): a native dialog
	// blocks the whole page, cannot be styled or translated by us, and is the
	// one interaction an admin cannot undo. Two clicks in the interface is the
	// same protection without leaving it.
	const [ confirming, setConfirming ] = useState( null );

	const { data, isPending, isError } = useQuery( {
		queryKey: [ 'backups' ],
		queryFn: getBackups,
	} );

	// Invalidate rather than splice the row out of a local array: the server
	// decides what a backup list contains, and a client that edits its own copy
	// is asserting the delete succeeded before it knows.
	const remove = useMutation( {
		mutationFn: deleteBackup,
		onSuccess: () =>
			queryClient.invalidateQueries( { queryKey: [ 'backups' ] } ),
	} );

	if ( isPending ) {
		return (
			<>
				<InProgressBackups />
				<Spinner />
			</>
		);
	}

	if ( isError ) {
		return (
			<>
				<InProgressBackups />
				<Notice
					className="fd-notice"
					status="error"
					isDismissible={ false }
				>
					{ __(
						'The backups could not be loaded.',
						'fiction-drafts'
					) }
				</Notice>
			</>
		);
	}

	const backups = data && data.backups ? data.backups : [];

	const countLabel = sprintf(
		// translators: %d: number of finished backups.
		_n( '%d backup', '%d backups', backups.length, 'fiction-drafts' ),
		backups.length
	);

	return (
		<>
			<InProgressBackups />

			<section className="fd-card">
				<div className="fd-card__header">
					<div>
						<h2 className="fd-card__title">
							{ __( 'Backups', 'fiction-drafts' ) }
						</h2>
						{ backups.length > 0 && (
							<p className="fd-card__subtitle">{ countLabel }</p>
						) }
					</div>
					<Link className="fd-button fd-button--secondary" to="/">
						<NewBackupIcon className="fd-icon" />
						{ __( 'New backup', 'fiction-drafts' ) }
					</Link>
				</div>

				{ remove.isError && (
					<Notice
						className="fd-notice"
						status="error"
						isDismissible={ false }
					>
						{ remove.error && remove.error.message
							? remove.error.message
							: __(
									'That backup could not be deleted.',
									'fiction-drafts'
							  ) }
					</Notice>
				) }

				{ ! backups.length ? (
					<p className="fd-empty">
						{ __( 'No backups yet.', 'fiction-drafts' ) }
					</p>
				) : (
					<table className="fd-table">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Date', 'fiction-drafts' ) }
								</th>
								<th scope="col">
									{ __( 'Profile', 'fiction-drafts' ) }
								</th>
								<th scope="col">
									{ __( 'Size', 'fiction-drafts' ) }
								</th>
								<th scope="col">
									{ __( 'Volumes', 'fiction-drafts' ) }
								</th>
								<th scope="col">
									{ __( 'Contains', 'fiction-drafts' ) }
								</th>
								<th scope="col">
									{ __( 'Download', 'fiction-drafts' ) }
								</th>
								<th scope="col">
									{ __( 'Actions', 'fiction-drafts' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ backups.map( ( backup ) => (
								<tr
									className="fd-table__row"
									key={ backup.uuid }
								>
									<td className="fd-table__cell fd-table__cell--mono">
										{ backup.created_at }
									</td>
									<td className="fd-table__cell">
										<span className="fd-profile-tag">
											<span
												className={ `fd-dot ${ profileDotClass(
													backup.profile
												) }` }
											/>
											{ backup.profile.label }
										</span>
									</td>
									<td className="fd-table__cell fd-table__cell--mono">
										{ backup.size_human }
									</td>
									<td className="fd-table__cell">
										{ backup.volume_count }
										{ ! backup.available && (
											<span className="fd-badge fd-badge--warning">
												{ __(
													'files missing',
													'fiction-drafts'
												) }
											</span>
										) }
									</td>
									<td className="fd-table__cell">
										{ backup.includes_wp_config ? (
											<span className="fd-badge fd-badge--danger">
												{ __(
													'wp-config.php',
													'fiction-drafts'
												) }
											</span>
										) : (
											<span className="fd-badge fd-badge--muted">
												{ __(
													'no credentials',
													'fiction-drafts'
												) }
											</span>
										) }
									</td>
									<td className="fd-table__cell">
										<DownloadVolumes backup={ backup } />
									</td>
									<td className="fd-table__cell">
										{ confirming === backup.uuid ? (
											<span className="fd-confirm">
												<span className="fd-confirm__question">
													{ __(
														'Delete permanently? The archive files are removed from the server and cannot be recovered.',
														'fiction-drafts'
													) }
												</span>
												<Button
													className="fd-confirm__yes"
													variant="primary"
													isDestructive
													disabled={
														remove.isPending
													}
													onClick={ () => {
														setConfirming( null );
														remove.mutate(
															backup.uuid
														);
													} }
												>
													{ __(
														'Delete',
														'fiction-drafts'
													) }
												</Button>
												<Button
													className="fd-confirm__no"
													variant="tertiary"
													onClick={ () =>
														setConfirming( null )
													}
												>
													{ __(
														'Keep',
														'fiction-drafts'
													) }
												</Button>
											</span>
										) : (
											<Button
												className="fd-table__delete"
												variant="link"
												isDestructive
												disabled={ remove.isPending }
												onClick={ () =>
													setConfirming( backup.uuid )
												}
												label={ __(
													'Delete',
													'fiction-drafts'
												) }
											>
												<TrashIcon className="fd-icon" />
											</Button>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</section>
		</>
	);
}
