<?php

declare( strict_types=1 );

namespace FictionDrafts\Admin;

use FictionDrafts\Domain\BackupProfile;

/**
 * The profiles as a human sees them.
 *
 * `BackupProfile` deliberately carries no user-facing text — it is spec §6.1's
 * table and nothing else, and a translated string in an enum case is a
 * presentation decision hiding inside a domain type.  This class is where that
 * decision lives, and it is a class rather than an array in `AdminPage` because
 * two callers need it: the bootstrap payload the picker renders from, and the
 * backups list, which shows the profile a finished archive was taken with.
 *
 * The catalogue is built by iterating `BackupProfile::cases()`, not by listing
 * the five profiles again.  A sixth profile therefore appears in the picker the
 * moment it is added to the enum, and the failure mode of forgetting to add it
 * here is a missing label — visible — rather than a missing option, which is
 * not.
 */
final class ProfileCatalogue {

	/**
	 * Every profile, in the order the picker should offer them.
	 *
	 * @return array<int, array{slug: string, label: string, description: string, includes: array{database: bool, core: bool, uploads: bool}, custom: bool}>
	 */
	public function all(): array {
		return array_map(
			fn ( BackupProfile $profile ): array => $this->describe( $profile ),
			BackupProfile::cases()
		);
	}

	/**
	 * @return array{slug: string, label: string, description: string, includes: array{database: bool, core: bool, uploads: bool}, custom: bool}
	 */
	public function describe( BackupProfile $profile ): array {
		return [
			'slug'        => $profile->value,
			'label'       => $this->label( $profile ),
			'description' => $this->description( $profile ),
			// The three predicates, shipped rather than re-derived, so the
			// picker can show what a profile covers without a copy of §6.1
			// in JavaScript.
			'includes'    => [
				'database' => $profile->includesDatabase(),
				'core'     => $profile->includesCore(),
				'uploads'  => $profile->includesUploads(),
			],
			// The one profile whose columns the job's options answer rather
			// than the profile itself, which is exactly when the client should
			// reveal per-area checkboxes.  Named as a property of the profile
			// so the client tests a flag instead of a slug.
			'custom'      => BackupProfile::Custom === $profile,
		];
	}

	public function label( BackupProfile $profile ): string {
		return match ( $profile ) {
			BackupProfile::Full         => __( 'Everything', 'fiction-drafts' ),
			BackupProfile::DatabaseOnly => __( 'Database only', 'fiction-drafts' ),
			BackupProfile::FilesOnly    => __( 'Files only', 'fiction-drafts' ),
			BackupProfile::FilesNoMedia => __( 'Files without media', 'fiction-drafts' ),
			BackupProfile::Custom       => __( 'Custom', 'fiction-drafts' ),
		};
	}

	private function description( BackupProfile $profile ): string {
		return match ( $profile ) {
			BackupProfile::Full         => __( 'The database and every file, including uploads.', 'fiction-drafts' ),
			BackupProfile::DatabaseOnly => __( 'The database only. No files at all.', 'fiction-drafts' ),
			BackupProfile::FilesOnly    => __( 'Every file, including uploads. No database.', 'fiction-drafts' ),
			BackupProfile::FilesNoMedia => __( 'The database and every file except uploads — the smallest copy that still carries your code and content.', 'fiction-drafts' ),
			BackupProfile::Custom       => __( 'Choose the areas yourself. Anything you do not tick is left out.', 'fiction-drafts' ),
		};
	}
}
