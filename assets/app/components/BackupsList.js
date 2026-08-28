/**
 * The finished backups.
 */

import { Button, Notice, Spinner } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { deleteBackup, getBackups } from '../api';
import DownloadVolumes from './DownloadVolumes';

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
		return <Spinner />;
	}

	if ( isError ) {
		return (
			<Notice
				className="fd-notice"
				status="error"
				isDismissible={ false }
			>
				{ __( 'The backups could not be loaded.', 'fiction-drafts' ) }
			</Notice>
		);
	}

	const backups = data && data.backups ? data.backups : [];

	if ( ! backups.length ) {
		return (
			<p className="fd-empty">
				{ __( 'No backups yet.', 'fiction-drafts' ) }
			</p>
		);
	}

	return (
		<section className="fd-backups">
			<h2 className="fd-backups__heading">
				{ __( 'Backups', 'fiction-drafts' ) }
			</h2>

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

			<table className="fd-table">
				<thead>
					<tr>
						<th scope="col">{ __( 'Date', 'fiction-drafts' ) }</th>
						<th scope="col">
							{ __( 'Profile', 'fiction-drafts' ) }
						</th>
						<th scope="col">{ __( 'Size', 'fiction-drafts' ) }</th>
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
						<tr className="fd-table__row" key={ backup.uuid }>
							<td className="fd-table__cell">
								{ backup.created_at }
							</td>
							<td className="fd-table__cell">
								{ backup.profile.label }
							</td>
							<td className="fd-table__cell">
								{ backup.size_human }
							</td>
							<td className="fd-table__cell">
								{ backup.volume_count }
								{ ! backup.available && (
									<span className="fd-badge fd-badge--missing">
										{ __(
											'files missing',
											'fiction-drafts'
										) }
									</span>
								) }
							</td>
							<td className="fd-table__cell">
								{ backup.includes_wp_config ? (
									<span className="fd-badge fd-badge--secret">
										{ __(
											'wp-config.php',
											'fiction-drafts'
										) }
									</span>
								) : (
									<span className="fd-muted">
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
											disabled={ remove.isPending }
											onClick={ () => {
												setConfirming( null );
												remove.mutate( backup.uuid );
											} }
										>
											{ __( 'Delete', 'fiction-drafts' ) }
										</Button>
										<Button
											className="fd-confirm__no"
											variant="tertiary"
											onClick={ () =>
												setConfirming( null )
											}
										>
											{ __( 'Keep', 'fiction-drafts' ) }
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
									>
										{ __( 'Delete', 'fiction-drafts' ) }
									</Button>
								) }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</section>
	);
}
