/**
 * Exclusions, volume size, retention.
 */

import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getSettings, saveSettings } from '../api';

const MIB = 1024 * 1024;

/**
 * @return {Element} The settings form.
 */
export default function SettingsForm() {
	const queryClient = useQueryClient();

	const { data: settings, isPending } = useQuery( {
		queryKey: [ 'settings' ],
		queryFn: getSettings,
	} );

	const [ form, setForm ] = useState( null );

	// The server clamps, so the response is authoritative over what was typed.
	// Re-seeding from every successful read is how a 5 MiB entry visibly
	// becomes the 10 MiB floor instead of silently disagreeing with storage.
	useEffect( () => {
		if ( settings ) {
			setForm( {
				default_profile: settings.default_profile,
				exclusions: settings.exclusions.join( '\n' ),
				max_volume_mib: Math.round( settings.max_volume_bytes / MIB ),
				retention_count: settings.retention_count,
			} );
		}
	}, [ settings ] );

	const save = useMutation( {
		mutationFn: saveSettings,
		onSuccess: ( stored ) => {
			queryClient.setQueryData( [ 'settings' ], stored );
		},
	} );

	if ( isPending || ! form || ! settings ) {
		return <Spinner />;
	}

	const minMib = Math.max( 1, Math.round( settings.min_volume_bytes / MIB ) );

	/**
	 * @param {Event} event Form submission.
	 */
	function submit( event ) {
		event.preventDefault();

		save.mutate( {
			default_profile: form.default_profile,
			exclusions: form.exclusions
				.split( '\n' )
				.map( ( line ) => line.trim() )
				.filter( Boolean ),
			max_volume_bytes: Number( form.max_volume_mib ) * MIB,
			retention_count: Number( form.retention_count ),
		} );
	}

	return (
		<section className="fd-card">
			<h2 className="fd-card__title">
				{ __( 'Settings', 'fiction-drafts' ) }
			</h2>
			<p className="fd-card__subtitle">
				{ __(
					'Defaults, exclusions, and volume behaviour for every future backup.',
					'fiction-drafts'
				) }
			</p>

			<form className="fd-settings" onSubmit={ submit }>
				<div className="fd-settings__group">
					<h3 className="fd-settings__group-title">
						{ __( 'General', 'fiction-drafts' ) }
					</h3>
					<p className="fd-settings__group-desc">
						{ __(
							'The profile pre-selected on the New Backup tab.',
							'fiction-drafts'
						) }
					</p>

					<SelectControl
						className="fd-field"
						label={ __( 'Default profile', 'fiction-drafts' ) }
						value={ form.default_profile }
						options={ settings.profiles.map( ( profile ) => ( {
							label: profile.label,
							value: profile.slug,
						} ) ) }
						onChange={ ( value ) =>
							setForm( { ...form, default_profile: value } )
						}
					/>
				</div>

				<div className="fd-settings__group">
					<h3 className="fd-settings__group-title">
						{ __( 'Exclusions', 'fiction-drafts' ) }
					</h3>
					<p className="fd-settings__group-desc">
						{ __(
							'One glob pattern per line, relative to the WordPress root. These are added to the ones every profile already excludes.',
							'fiction-drafts'
						) }
					</p>

					<TextareaControl
						className="fd-field"
						label={ __( 'Extra exclusions', 'fiction-drafts' ) }
						value={ form.exclusions }
						rows={ 8 }
						onChange={ ( value ) =>
							setForm( { ...form, exclusions: value } )
						}
					/>
				</div>

				<div className="fd-settings__group">
					<h3 className="fd-settings__group-title">
						{ __( 'Volumes & retention', 'fiction-drafts' ) }
					</h3>
					<p className="fd-settings__group-desc">
						{ __(
							'How large each archive volume can grow, and how many finished backups to keep.',
							'fiction-drafts'
						) }
					</p>

					<div className="fd-settings__row">
						<TextControl
							className="fd-field"
							type="number"
							min={ minMib }
							label={ __(
								'Maximum volume size (MiB)',
								'fiction-drafts'
							) }
							help={ sprintf(
								/* translators: %d: minimum volume size in mebibytes. */
								__(
									'Anything below %d MiB is raised to it when saved.',
									'fiction-drafts'
								),
								minMib
							) }
							value={ form.max_volume_mib }
							onChange={ ( value ) =>
								setForm( { ...form, max_volume_mib: value } )
							}
						/>

						<TextControl
							className="fd-field"
							type="number"
							min={ 0 }
							label={ __( 'Backups to keep', 'fiction-drafts' ) }
							help={ __(
								'Older backups beyond this many are deleted automatically. Set it to 0 to keep every backup and manage them yourself.',
								'fiction-drafts'
							) }
							value={ form.retention_count }
							onChange={ ( value ) =>
								setForm( { ...form, retention_count: value } )
							}
						/>
					</div>
				</div>

				{ save.isError && (
					<Notice
						className="fd-notice"
						status="error"
						isDismissible={ false }
					>
						{ save.error && save.error.message
							? save.error.message
							: __(
									'The settings could not be saved.',
									'fiction-drafts'
							  ) }
					</Notice>
				) }

				{ save.isSuccess && (
					<Notice
						className="fd-notice"
						status="success"
						isDismissible={ false }
					>
						{ __( 'Settings saved.', 'fiction-drafts' ) }
					</Notice>
				) }

				<Button
					className="fd-settings__submit"
					variant="primary"
					type="submit"
					isBusy={ save.isPending }
					disabled={ save.isPending }
				>
					{ __( 'Save settings', 'fiction-drafts' ) }
				</Button>
			</form>
		</section>
	);
}
