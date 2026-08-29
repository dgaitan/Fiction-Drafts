/**
 * Everything the server told us at boot.
 *
 * `AdminPage` prints this global with `wp_add_inline_script( …, 'before' )`, so
 * it exists by the time this module runs. The fallback is not defensive
 * politeness — it keeps `wp-scripts lint-js` and any future unit runner able to
 * import this file outside WordPress.
 */

const raw =
	typeof window !== 'undefined' && window.fictionDrafts
		? window.fictionDrafts
		: {};

export const bootstrap = {
	restUrl: raw.restUrl || '',
	nonce: raw.nonce || '',
	canManage: Boolean( raw.canManage ),
	version: raw.version || '',
	pollMs: raw.pollMs || 2000,
	profiles: raw.profiles || [],
	stages: raw.stages || [],
	areas: raw.areas || [],
	defaults: raw.defaults || {},
	wpConfig: raw.wpConfig || { label: '', warning: '' },
};

/**
 * The profile the settings screen nominated, or the first one offered.
 *
 * Never a literal: which profiles exist is spec section 6.1, and section 6.1
 * lives in PHP.
 *
 * @return {string} A profile slug.
 */
export function defaultProfileSlug() {
	if ( bootstrap.defaults.profile ) {
		return bootstrap.defaults.profile;
	}

	return bootstrap.profiles.length ? bootstrap.profiles[ 0 ].slug : '';
}
