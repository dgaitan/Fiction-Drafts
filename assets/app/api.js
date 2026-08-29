/**
 * The REST surface, as functions.
 *
 * One module so that every request carries the nonce and every URL is composed
 * from the root the server handed down. A component that built its own URL
 * would be the second place that knows the namespace.
 */

import apiFetch from '@wordpress/api-fetch';

import { bootstrap } from './bootstrap';

if ( bootstrap.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( bootstrap.nonce ) );
}

/**
 * @param {string} path Route path within the plugin namespace.
 * @return {string} Absolute REST URL.
 */
function url( path ) {
	return bootstrap.restUrl + path;
}

/**
 * @param {Object} body The `POST /jobs` payload.
 * @return {Promise<Object>} The created job's uuid and status.
 */
export function startJob( body ) {
	return apiFetch( { url: url( 'jobs' ), method: 'POST', data: body } );
}

/**
 * @param {string} uuid Job identifier.
 * @return {Promise<Object>} The job as the dashboard sees it.
 */
export function getJob( uuid ) {
	return apiFetch( { url: url( `jobs/${ uuid }` ) } );
}

/**
 * The queued-or-running job, if there is one — at most one ever is.
 *
 * @return {Promise<Object>} `{ job: null | {...} }`.
 */
export function getActiveJob() {
	return apiFetch( { url: url( 'jobs/active' ) } );
}

/**
 * @param {string} uuid Job identifier.
 * @return {Promise<Object>} The cancelled job.
 */
export function cancelJob( uuid ) {
	return apiFetch( { url: url( `jobs/${ uuid }` ), method: 'DELETE' } );
}

/**
 * @return {Promise<Object>} `{ backups: [] }`.
 */
export function getBackups() {
	return apiFetch( { url: url( 'backups' ) } );
}

/**
 * @param {string} uuid Backup identifier.
 * @return {Promise<Object>} `{ uuid, deleted }`.
 */
export function deleteBackup( uuid ) {
	return apiFetch( { url: url( `backups/${ uuid }` ), method: 'DELETE' } );
}

/**
 * Ask for permission to download one volume.
 *
 * The server composes the whole URL, nonce included. Building it here would be
 * a second place that knows the action name, the parameter names, and the nonce
 * action — and the first time those drift, the symptom is a 403 on a download
 * that used to work with nothing pointing at the cause.
 *
 * @param {string} uuid     Backup identifier.
 * @param {number} sequence Volume number, starting at 1.
 * @return {Promise<Object>} `{ url, expires_at, filename, bytes }`.
 */
export function requestDownload( uuid, sequence ) {
	return apiFetch( {
		url: url( `backups/${ uuid }/download-token` ),
		method: 'POST',
		data: { volume: sequence },
	} );
}

/**
 * @return {Promise<Object>} The stored settings.
 */
export function getSettings() {
	return apiFetch( { url: url( 'settings' ) } );
}

/**
 * @param {Object} body Fields to change; anything omitted is left alone.
 * @return {Promise<Object>} The settings as stored, after clamping.
 */
export function saveSettings( body ) {
	return apiFetch( { url: url( 'settings' ), method: 'PUT', data: body } );
}

/**
 * @param {Object} body `{ name, email, type, message }` from the Feature
 *                      Request form. `name` and `type` are optional.
 * @return {Promise<Object>} `{ sent: true }`.
 */
export function sendFeatureRequest( body ) {
	return apiFetch( {
		url: url( 'feature-request' ),
		method: 'POST',
		data: body,
	} );
}
