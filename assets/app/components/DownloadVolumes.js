/**
 * The download control for one backup's volumes.
 *
 * A backup is one or more volumes and each is fetched separately, because a
 * grant authorises exactly one file. One button per volume when there are
 * several, one plain button when there is one.
 */

import { Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation } from '@tanstack/react-query';

import { requestDownload } from '../api';

/**
 * @param {Object} props        Component props.
 * @param {Object} props.backup One entry from `GET /backups`.
 * @return {Element} The download controls.
 */
export default function DownloadVolumes( { backup } ) {
	// Which volume is in flight, so only its button shows a busy state.
	const [ pending, setPending ] = useState( null );

	const grant = useMutation( {
		mutationFn: ( sequence ) => requestDownload( backup.uuid, sequence ),
		onSettled: () => setPending( null ),
		onSuccess: ( data ) => {
			// Navigate rather than fetch. A grant is single-use, so an
			// XHR that read the archive into memory would spend the grant
			// and leave the browser holding a gigabyte it cannot save —
			// and `<a download>` on a cross-document response is the one
			// thing browsers reliably stream straight to disk.
			if ( data && data.url ) {
				window.location.assign( data.url );
			}
		},
	} );

	const volumes =
		backup.volumes && backup.volumes.length ? backup.volumes : [];

	/**
	 * The label for one volume's button.
	 *
	 * Two literal calls rather than one with a chosen format string: a sprintf
	 * whose format is an expression cannot be checked by the i18n tooling, and
	 * cannot be found by the string extractor either — the translation would
	 * simply never appear in the .pot file.
	 *
	 * @param {number} sequence Volume number, starting at 1.
	 * @return {string} Button text.
	 */
	function label( sequence ) {
		if ( volumes.length < 2 ) {
			return __( 'Download', 'fiction-drafts' );
		}

		// translators: %d: volume number within a multi-part backup.
		return sprintf( __( 'Download part %d', 'fiction-drafts' ), sequence );
	}

	if ( ! backup.available || ! volumes.length ) {
		return (
			<span className="fd-muted">
				{ __( 'Unavailable', 'fiction-drafts' ) }
			</span>
		);
	}

	return (
		<span className="fd-download">
			{ grant.isError && (
				<Notice
					className="fd-notice"
					status="error"
					isDismissible={ false }
				>
					{ grant.error && grant.error.message
						? grant.error.message
						: __(
								'That download could not be prepared.',
								'fiction-drafts'
						  ) }
				</Notice>
			) }

			{ volumes.map( ( volume ) => (
				<Button
					className="fd-download__button"
					disabled={ grant.isPending }
					key={ volume.sequence }
					onClick={ () => {
						setPending( volume.sequence );
						grant.mutate( volume.sequence );
					} }
					variant="secondary"
				>
					{ pending === volume.sequence
						? __( 'Preparing…', 'fiction-drafts' )
						: label( volume.sequence ) }
				</Button>
			) ) }
		</span>
	);
}
