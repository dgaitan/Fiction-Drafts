/**
 * Pick a profile, decide about wp-config.php, start the job.
 */

import { Button, CheckboxControl, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';

import { bootstrap, defaultProfileSlug } from '../bootstrap';
import { startJob } from '../api';

/**
 * @return {Element} The new-backup form.
 */
export default function NewBackup() {
	const navigate = useNavigate();
	const queryClient = useQueryClient();

	const [ profile, setProfile ] = useState( defaultProfileSlug );

	// The wp-config opt-in, and the only place its value comes from.
	//
	// Spec section 6.3: the choice is per job and never sticky. That is not
	// enforced by remembering to clear it — it is enforced by there being
	// nowhere else it could come from. The settings payload carries no such
	// field, the profile carries no such property, and this state is created
	// fresh every time the route mounts.
	const [ includeWpConfig, setIncludeWpConfig ] = useState( false );

	const [ areas, setAreas ] = useState( {} );

	const selected = bootstrap.profiles.find(
		( entry ) => entry.slug === profile
	);
	const isCustom = Boolean( selected && selected.custom );

	const mutation = useMutation( {
		mutationFn: startJob,
		onSuccess: ( job ) => {
			queryClient.invalidateQueries( { queryKey: [ 'backups' ] } );
			navigate( `/progress/${ job.uuid }` );
		},
	} );

	/**
	 * @param {Event} event Form submission.
	 */
	function submit( event ) {
		event.preventDefault();

		mutation.mutate( {
			profile,
			include_wp_config: includeWpConfig,
			...areas,
		} );
	}

	return (
		<section className="fd-card">
			<h2 className="fd-card__title">
				{ __( 'New backup', 'fiction-drafts' ) }
			</h2>
			<p className="fd-card__subtitle">
				{ __(
					"Choose what to copy, then start a background job that resumes automatically if it's interrupted.",
					'fiction-drafts'
				) }
			</p>

			<form className="fd-new" onSubmit={ submit }>
				<fieldset className="fd-new__profiles">
					<legend className="fd-new__legend">
						{ __( 'What to copy', 'fiction-drafts' ) }
					</legend>

					<div className="fd-profiles">
						{ bootstrap.profiles.map( ( entry ) => (
							<div
								className={
									profile === entry.slug
										? 'fd-profile fd-profile--selected'
										: 'fd-profile'
								}
								key={ entry.slug }
							>
								<input
									className="fd-profile__radio"
									id={ `fd-profile-${ entry.slug }` }
									type="radio"
									name="fd-profile"
									value={ entry.slug }
									checked={ profile === entry.slug }
									onChange={ () => setProfile( entry.slug ) }
								/>
								<label
									className="fd-profile__body"
									htmlFor={ `fd-profile-${ entry.slug }` }
								>
									<span className="fd-profile__label">
										{ entry.label }
									</span>
									<span className="fd-profile__description">
										{ entry.description }
									</span>
								</label>
							</div>
						) ) }
					</div>
				</fieldset>

				{ isCustom && (
					<fieldset className="fd-new__areas">
						<legend className="fd-new__legend">
							{ __( 'Areas to include', 'fiction-drafts' ) }
						</legend>

						{ bootstrap.areas.map( ( area ) => (
							<CheckboxControl
								key={ area.key }
								className="fd-area"
								label={ area.label }
								checked={ Boolean( areas[ area.key ] ) }
								onChange={ ( value ) =>
									setAreas( {
										...areas,
										[ area.key ]: value,
									} )
								}
							/>
						) ) }
					</fieldset>
				) }

				<div className="fd-callout">
					<CheckboxControl
						className="fd-wp-config"
						label={ bootstrap.wpConfig.label }
						help={ bootstrap.wpConfig.warning }
						checked={ includeWpConfig }
						onChange={ setIncludeWpConfig }
					/>
				</div>

				{ mutation.isError && (
					<Notice
						className="fd-notice"
						status="error"
						isDismissible={ false }
					>
						{ mutation.error && mutation.error.message
							? mutation.error.message
							: __(
									'The backup could not be started.',
									'fiction-drafts'
							  ) }
					</Notice>
				) }

				<Button
					className="fd-new__submit"
					variant="primary"
					type="submit"
					isBusy={ mutation.isPending }
					disabled={ mutation.isPending }
				>
					{ __( 'Start backup', 'fiction-drafts' ) }
				</Button>
			</form>
		</section>
	);
}
