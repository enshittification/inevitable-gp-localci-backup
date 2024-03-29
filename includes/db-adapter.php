<?php
class GP_LocalCI_DB_Adapter {
	public function get_string_coverage( $po_obj_or_file, $project_id  ) {
		$po = localci_load_po( $po_obj_or_file );
		return $this->get_cross_locale_translated_status( $po->entries, $project_id );
	}

	private function get_cross_locale_translated_status( $po_entries, $project_id ) {
		$translations = $existing_originals = $new_originals = array();

		foreach ( $po_entries as $entry ) {
			$original = GP::$original->by_project_id_and_entry( $project_id, $entry );
			if ( $original ) {
				$original_translations = $this->get_translations_for_original( $original->id );
				if ( empty( $original_translations ) && '-obsolete' === $original->status ) {
					$new_originals[] = $original->fields();
				} else {
					$existing_originals[] = $this->existing_original_object( $original, $original_translations );
				}
			} else {
				$data = array(
					'project_id' => $project_id,
					'context'    => $entry->context,
					'singular'   => $entry->singular,
					'plural'     => $entry->plural,
					'comment'    => $entry->extracted_comments,
					'references' => implode( ' ', $entry->references ),
				);

				$suggested_replacements = $this->get_suggested_replacements( $entry );
				if ( $suggested_replacements ) {
					$data['suggestions'] = $suggested_replacements;
				}

				$new_originals[] = $data;
			}
		}

		$coverage = array(
			'new_strings' => $new_originals,
			'existing_strings' => $existing_originals,
			'translations' => $this->filter_cross_locale_translated_status( array_merge( $new_originals, $existing_originals ) ),
		);

		return $coverage;
	}

	private function get_suggested_replacements( $entry ) {
		if ( ! function_exists( 'gp_es_find_similar' ) ) {
			return false;
		}

		$hits = gp_es_find_similar( $entry );
		if ( ! $hits ) {
			return false;
		}

		$suggestions = array();
		foreach ( $hits as $hit ) {
			$original = GP::$original->get( $hit['_id'] );
			$original_translations = $this->get_translations_for_original( $original->id );
			$original = $this->existing_original_object( $original, $original_translations );
			$original['score'] = $hit['_score'];

			// discard obsolete strings with no translations
			// TODO: maybe implement in ES query
			if ( '-obsolete' === $original['status'] && empty( $original['locales'] ) ) {
				continue;
			}

			$suggestions[] = $original;
		}

		return $suggestions;
	}

	private function existing_original_object( $original, $original_translations ) {
		$original = $original->fields();
		$original['locales']  = array();
		foreach ( $original_translations as $translation ) {
			$translations[] = (object) array(
				'original_id'    => $original['id'],
				'context'        => $original['context'],
				'singular'       => $original['singular'],
				'plural'         => $original['plural'],
				'translation_id' => $translation->id,
				'locale'         => $translation->locale,
			);
			$original['locales'][] = $translation->locale;
		}
		return $original;
	}

	private function filter_cross_locale_translated_status( $strings ) {
		$rows = array();
		foreach ( $strings as $string ) {
			if ( ! isset( $string['locales'] ) ) {
				continue;
			}
			foreach ( $string['locales'] as $_locale ) {
				if ( in_array( $_locale, LOCALCI_DESIRED_LOCALES ) ) {
					$rows[ $_locale ] = $rows[ $_locale ] + 1;
				}
			}
		}
		return $rows;
	}

	private function get_translations_for_original( $original_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, tset.locale FROM gp_translations as t
				 JOIN gp_translation_sets as tset on t.translation_set_id = tset.id
				 WHERE t.original_id = %d AND t.status = 'current'", $original_id
			)
		);
	}
}
