/**
 * Contact the developer directly: a bug, a feature, or a question.
 */

import {
	Button,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useMutation } from '@tanstack/react-query';

import { sendFeatureRequest } from '../api';

const AUTHOR_URL = 'https://dgaitan.dev';

const EMPTY_FORM = {
	name: '',
	email: '',
	type: 'feature_request',
	message: '',
};

/**
 * @return {Element} The Feature Request tab.
 */
export default function FeatureRequest() {
	const [ form, setForm ] = useState( EMPTY_FORM );

	const send = useMutation( {
		mutationFn: sendFeatureRequest,
		onSuccess: () => setForm( EMPTY_FORM ),
	} );

	/**
	 * @param {Event} event Form submission.
	 */
	function submit( event ) {
		event.preventDefault();
		send.mutate( form );
	}

	return (
		<section className="fd-card">
			<h2 className="fd-card__title">
				{ __( 'Feature Request', 'fiction-drafts' ) }
			</h2>
			<p className="fd-card__subtitle">
				{ __(
					'Contact the plugin developer directly — report a bug, request a feature, or just say hi.',
					'fiction-drafts'
				) }
			</p>

			<div className="fd-request">
				<form className="fd-request__form" onSubmit={ submit }>
					<div className="fd-request__row">
						<TextControl
							className="fd-field"
							label={ __( 'Name', 'fiction-drafts' ) }
							value={ form.name }
							onChange={ ( name ) =>
								setForm( { ...form, name } )
							}
						/>
						<TextControl
							className="fd-field"
							type="email"
							required
							label={ __( 'Email', 'fiction-drafts' ) }
							value={ form.email }
							onChange={ ( email ) =>
								setForm( { ...form, email } )
							}
						/>
					</div>

					<SelectControl
						className="fd-field"
						label={ __( "What's this about?", 'fiction-drafts' ) }
						value={ form.type }
						options={ [
							{
								label: __(
									'Feature request',
									'fiction-drafts'
								),
								value: 'feature_request',
							},
							{
								label: __( 'Bug report', 'fiction-drafts' ),
								value: 'bug_report',
							},
							{
								label: __( 'Question', 'fiction-drafts' ),
								value: 'question',
							},
							{
								label: __( 'Something else', 'fiction-drafts' ),
								value: 'other',
							},
						] }
						onChange={ ( type ) => setForm( { ...form, type } ) }
					/>

					<TextareaControl
						className="fd-field"
						required
						label={ __( 'Message', 'fiction-drafts' ) }
						help={ __(
							"Goes straight to the developer's inbox — never shared.",
							'fiction-drafts'
						) }
						rows={ 6 }
						value={ form.message }
						onChange={ ( message ) =>
							setForm( { ...form, message } )
						}
					/>

					{ send.isError && (
						<Notice
							className="fd-notice"
							status="error"
							isDismissible={ false }
						>
							{ send.error && send.error.message
								? send.error.message
								: __(
										'The message could not be sent.',
										'fiction-drafts'
								  ) }
						</Notice>
					) }

					{ send.isSuccess && (
						<Notice
							className="fd-notice"
							status="success"
							isDismissible={ false }
						>
							{ __(
								'Message sent — thanks for reaching out.',
								'fiction-drafts'
							) }
						</Notice>
					) }

					<Button
						className="fd-request__submit"
						variant="primary"
						type="submit"
						isBusy={ send.isPending }
						disabled={ send.isPending }
					>
						{ __( 'Send message', 'fiction-drafts' ) }
					</Button>
				</form>

				<aside className="fd-contact">
					<div className="fd-contact__title">
						{ __( 'Direct contact', 'fiction-drafts' ) }
					</div>
					<div className="fd-contact__person">
						<span className="fd-avatar fd-avatar--contact">DG</span>
						<div>
							<div className="fd-contact__name">David Gaitan</div>
							<a
								className="fd-contact__link"
								href={ AUTHOR_URL }
								target="_blank"
								rel="noopener noreferrer"
							>
								dgaitan.dev
							</a>
						</div>
					</div>
					<p className="fd-contact__text">
						{ __(
							'I build and maintain Fiction Drafts myself. I read every message that comes through this form.',
							'fiction-drafts'
						) }
					</p>
				</aside>
			</div>
		</section>
	);
}
