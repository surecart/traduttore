<?php
/**
 * Command for syncing a project with a CI pipeline
 */

namespace Required\Traduttore\CLI;

use GP;
use GP_Locales;
use GP_Translation_Set;
use PO;
use Required\Traduttore\Project;
use Required\Traduttore\ProjectLocator;
use Required\Traduttore\Updater;
use Translation_Entry;
use Translations;
use WP_CLI;
use WP_CLI_Command;
use function WP_CLI\Utils\get_flag_value;

/**
 * CI sync command class.
 *
 * Lets a pipeline push a POT and exchange translation sets over WP-CLI instead of
 * Traduttore cloning the repository itself.
 */
class CiCommand extends WP_CLI_Command {
	/**
	 * Share of the active originals a POT may lack before the import is refused without --force.
	 */
	protected const MAX_SHRINK = 0.1;

	/**
	 * Imports originals from a POT file built outside of Traduttore, e.g. by CI.
	 *
	 * Refuses a POT with more than 10% fewer strings than the project currently has,
	 * so a broken build cannot obsolete thousands of originals.
	 *
	 * ## OPTIONS
	 *
	 * <project>
	 * : Path or ID of the project.
	 *
	 * <file>
	 * : Path to the POT file.
	 *
	 * [--force]
	 * : Import even if the POT has more than 10% fewer strings than the project.
	 *
	 * ## EXAMPLES
	 *
	 *     # Import a POT file built by CI.
	 *     $ wp traduttore ci originals surecart /tmp/surecart.pot
	 *     Success: Imported originals for project (ID: 1): 12 added, 3 changed, 0 fuzzied, 1 obsoleted, 0 errors.
	 *
	 * @param string[] $args       Command args.
	 * @param string[] $assoc_args Associative args.
	 */
	public function originals( array $args, array $assoc_args ): void {
		$force   = get_flag_value( $assoc_args, 'force', false );
		$project = $this->get_project( $args[0] );
		$pot     = $this->parse_po_file( $args[1] );

		$incoming = \count( $pot->entries );
		$existing = (int) GP::$original->count_by_project_id( $project->get_id() );

		if ( ! $force && $incoming < $existing * ( 1 - static::MAX_SHRINK ) ) {
			WP_CLI::error(
				sprintf(
					'POT has %1$d strings but the project has %2$d active originals. Refusing to obsolete that many, use --force to import anyway.',
					$incoming,
					$existing
				)
			);
		}

		$updater = new Updater( $project );

		if ( $updater->has_lock() ) {
			WP_CLI::error( sprintf( 'An import is already running for project (ID: %d)', $project->get_id() ) );
		}

		$updater->add_lock();

		$stats       = $this->import_pot( $updater, $args[1] );
		$second_pass = false;
		$missing     = $this->get_missing_entries( $project, $pot );

		// import_for_project() can fuzzy-match two new strings to the same dropped original and keeps only the last.
		// That original is no longer dropped on a re-run, so one more pass creates the lost string.
		if ( $missing ) {
			WP_CLI::log( sprintf( '%d POT strings have no active original after the first pass, importing again.', \count( $missing ) ) );

			foreach ( $this->import_pot( $updater, $args[1] ) as $i => $count ) {
				$stats[ $i ] += $count;
			}

			$second_pass = true;
			$missing     = $this->get_missing_entries( $project, $pot );
		}

		$updater->remove_lock();

		if ( $missing ) {
			WP_CLI::warning(
				sprintf(
					'%1$d POT strings are still missing after the second pass, e.g. "%2$s".',
					\count( $missing ),
					implode( '", "', array_column( \array_slice( $missing, 0, 3 ), 'singular' ) )
				)
			);
		}

		[ $added, $changed, $fuzzied, $obsoleted, $errors ] = $stats;

		WP_CLI::success(
			sprintf(
				'Imported originals for project (ID: %1$d): %2$d added, %3$d changed, %4$d fuzzied, %5$d obsoleted, %6$d errors%7$s.',
				$project->get_id(),
				$added,
				$changed,
				$fuzzied,
				$obsoleted,
				$errors,
				$second_pass ? ' (2 passes)' : ''
			)
		);
	}

	/**
	 * Lists the translation sets of a project with their counts as JSON.
	 *
	 * ## OPTIONS
	 *
	 * <project>
	 * : Path or ID of the project.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp traduttore ci sets surecart
	 *     [{"locale":"de","wp_locale":"de_DE","set":"german","current":4100,"untranslated":120,"waiting":3}]
	 *
	 * @param string[] $args Command args.
	 */
	public function sets( array $args ): void {
		$project = $this->get_project( $args[0] );
		$sets    = [];

		/** @var \GP_Translation_Set $set */
		foreach ( (array) GP::$translation_set->by_project_id( $project->get_id() ) as $set ) {
			$locale = GP_Locales::by_slug( $set->locale );

			$sets[] = [
				'locale'       => $set->locale,
				'wp_locale'    => $locale->wp_locale ?? null,
				'set'          => $set->slug,
				'current'      => (int) $set->current_count(),
				'untranslated' => (int) $set->untranslated_count(),
				'waiting'      => (int) $set->waiting_count(),
			];
		}

		WP_CLI::line( wp_json_encode( $sets ) );
	}

	/**
	 * Imports translations into a set without touching existing current or waiting translations.
	 *
	 * GlotPress' own import replaces a differing current translation and bypasses waiting
	 * suggestions. This drops those entries first, so only untranslated and fuzzy-only
	 * originals get filled.
	 *
	 * ## OPTIONS
	 *
	 * <project>
	 * : Path or ID of the project.
	 *
	 * <locale>
	 * : GlotPress locale slug (e.g. "de", not "de_DE") as listed by `sets`.
	 *
	 * <set>
	 * : Translation set slug as listed by `sets`.
	 *
	 * <file>
	 * : Path to the PO file.
	 *
	 * [--status=<status>]
	 * : Status for the imported translations.
	 * ---
	 * default: current
	 * options:
	 *   - current
	 *   - waiting
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Fill the untranslated strings of the German set, attributed to the ai-bot user.
	 *     $ wp traduttore ci import surecart de german /tmp/de.po --user=ai-bot
	 *     Success: Imported 118 translations into de/german: 3980 skipped (already translated), 2 unknown originals, 4 held as waiting.
	 *
	 * @param string[] $args       Command args.
	 * @param string[] $assoc_args Associative args.
	 */
	public function import( array $args, array $assoc_args ): void {
		$status          = get_flag_value( $assoc_args, 'status', 'current' );
		$project         = $this->get_project( $args[0] );
		$translation_set = $this->get_translation_set( $project, $args[1], $args[2] );
		$po              = $this->parse_po_file( $args[3] );

		$original_ids = $this->get_original_ids_by_key( $project );
		$translated   = $this->get_translated_original_ids( $translation_set );

		$to_import = new Translations();
		$skipped   = 0;
		$unknown   = 0;

		foreach ( $po->entries as $entry ) {
			if ( empty( $entry->translations ) ) {
				continue;
			}

			$original_id = $original_ids[ $entry->key() ] ?? null;

			if ( null === $original_id ) {
				++$unknown;
				continue;
			}

			if ( isset( $translated[ $original_id ] ) ) {
				++$skipped;
				continue;
			}

			$to_import->add_entry( $entry );
		}

		$waiting_before = $translation_set->waiting_count();

		// Covers anything translated between the lookup above and the import.
		add_filter( 'gp_translation_set_import_over_existing', '__return_false' );
		$imported = (int) $translation_set->import( $to_import, $status );
		remove_filter( 'gp_translation_set_import_over_existing', '__return_false' );

		// import() cleared the cache, but the set memoises its counts.
		$translation_set->update_status_breakdown();

		WP_CLI::success(
			sprintf(
				'Imported %1$d translations into %2$s/%3$s: %4$d skipped (already translated), %5$d unknown originals, %6$d held as waiting.',
				$imported,
				$translation_set->locale,
				$translation_set->slug,
				$skipped,
				$unknown,
				max( 0, $translation_set->waiting_count() - $waiting_before )
			)
		);
	}

	/**
	 * Returns the project for a path or ID, or exits with an error.
	 *
	 * @param string $project Path or ID of the project.
	 * @return \Required\Traduttore\Project The project.
	 */
	protected function get_project( string $project ): Project {
		$found = ( new ProjectLocator( $project ) )->get_project();

		if ( ! $found ) {
			WP_CLI::error( 'Project not found' );
		}

		return $found;
	}

	/**
	 * Returns the translation set for a locale and set slug, or exits with an error.
	 *
	 * @param \Required\Traduttore\Project $project The project.
	 * @param string                       $locale  GlotPress locale slug.
	 * @param string                       $set     Translation set slug.
	 * @return \GP_Translation_Set The translation set.
	 */
	protected function get_translation_set( Project $project, string $locale, string $set ): GP_Translation_Set {
		$translation_set = GP::$translation_set->by_project_id_slug_and_locale( $project->get_id(), $set, $locale );

		if ( ! $translation_set ) {
			WP_CLI::error( sprintf( 'Translation set not found for locale "%1$s" and set "%2$s". Run `wp traduttore ci sets %3$d` to list them.', $locale, $set, $project->get_id() ) );
		}

		return $translation_set;
	}

	/**
	 * Parses a PO/POT file, or exits with an error.
	 *
	 * @param string $file Path to the file.
	 * @return \PO The parsed file.
	 */
	protected function parse_po_file( string $file ): PO {
		$po = new PO();

		if ( ! is_readable( $file ) || ! $po->import_from_file( $file ) ) {
			WP_CLI::error( sprintf( 'Could not read %s', $file ) );
		}

		return $po;
	}

	/**
	 * Imports a POT file into the project, or releases the update lock and exits with an error.
	 *
	 * @param \Required\Traduttore\Updater $updater The updater holding the lock.
	 * @param string                       $file    Path to the POT file.
	 * @return int[] Stats as returned by Updater::import_pot_file().
	 */
	protected function import_pot( Updater $updater, string $file ): array {
		$stats = $updater->import_pot_file( $file );

		if ( null === $stats ) {
			$updater->remove_lock();
			WP_CLI::error( sprintf( 'Could not import %s', $file ) );
		}

		return $stats;
	}

	/**
	 * Returns the POT entries that have no active original in the project.
	 *
	 * Both sides are keyed like Translation_Entry::key(), so this is a key diff.
	 *
	 * @param \Required\Traduttore\Project $project The project.
	 * @param \PO                          $pot     The parsed POT file.
	 * @return \Translation_Entry[] Missing entries keyed by entry key.
	 */
	protected function get_missing_entries( Project $project, PO $pot ): array {
		return array_diff_key( $pot->entries, $this->get_original_ids_by_key( $project ) );
	}

	/**
	 * Returns the IDs of the project's active originals keyed like Translation_Entry::key().
	 *
	 * One query instead of GP_Original::by_project_id_and_entry() per PO entry.
	 *
	 * @param \Required\Traduttore\Project $project The project.
	 * @return int[] Original IDs keyed by entry key.
	 */
	protected function get_original_ids_by_key( Project $project ): array {
		$ids = [];

		/** @var \GP_Original $original */
		foreach ( (array) GP::$original->by_project_id( $project->get_id() ) as $original ) {
			$entry = new Translation_Entry(
				[
					'singular' => $original->singular,
					'plural'   => $original->plural,
					'context'  => $original->context,
				]
			);

			$ids[ $entry->key() ] = (int) $original->id;
		}

		return $ids;
	}

	/**
	 * Returns the IDs of the originals that already have a current or waiting translation in a set.
	 *
	 * @param \GP_Translation_Set $translation_set The translation set.
	 * @return array<int,true> Original IDs as keys.
	 */
	protected function get_translated_original_ids( GP_Translation_Set $translation_set ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT original_id FROM `$wpdb->gp_translations` WHERE translation_set_id = %d AND status IN ( 'current', 'waiting' )",
				$translation_set->id
			)
		);

		return array_fill_keys( array_map( 'intval', $ids ), true );
	}
}
