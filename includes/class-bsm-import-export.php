<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Export/import ACF field DATA (not structure) between pages — same page or a different one,
 * same site or a different one (e.g. test -> live). Never creates or attaches a field group;
 * a group/field must already exist on the target page (via ACF's own location rules) for
 * anything to be written into it — this only ever writes values into containers that already
 * exist, exactly like the rest of this plugin.
 *
 * File shape (always JSON — see class docblock in class-bsm-ajax.php for the "raw value, key
 * before name" principles this reuses throughout):
 *   { bsm_export: true, version: 1, exported_at, source_page: {...},
 *     groups: [ { group_key, group_title, fields: [ ...leaf or container entries... ] } ] }
 *
 *   leaf:      { name, key, label, type, value, clear }
 *              (an image leaf also carries filename — see "Image handling" below)
 *   group:     { name, key, label, type: "group", fields: [ ...recursively... ] }
 *   repeater:  { name, key, label, type: "repeater", sub_fields: [ {name,label,type}, ... ],
 *                rows: [ { subfieldname: {value, clear}, ... }, ... ] }
 *   flexible:  { name, key, label, type: "flexible_content",
 *                layouts: [ { name, label, sub_fields: [...] }, ... ],
 *                rows: [ { acf_fc_layout, subfieldname: {value, clear}, ... }, ... ] }
 *
 * Only EDITABLE_TYPES (BSM_Ajax::EDITABLE_TYPES) are ever exported or written — same scope as
 * manual editing. `clear: true` on a leaf means "make this blank" even though the incoming value
 * is empty; the import preview screen also offers a live checkbox that overrides this per field
 * before commit, so ticking it there is equivalent to hand-editing the flag in the file.
 *
 * Repeater/Flexible Content fields are matched and either fully replaced (every row) or left
 * completely untouched — there's no reliable way to line up "row 3" between two different pages,
 * so this never tries to merge individual rows. The preview screen shows the current row count
 * next to the incoming row count, plus a live preview of the incoming rows, so this is a decision
 * made with full visibility rather than a silent guess.
 *
 * Image handling: an attachment ID from a DIFFERENT site's export is meaningless here — media
 * libraries aren't shared, so the same numeric ID can point at a completely different file, or
 * none at all. Every exported image also carries its filename, and on import that filename is
 * looked up against THIS site's own Media Library first; the raw ID is only trusted as a
 * fallback (e.g. re-importing onto the very same site, where it's still meaningful). If neither
 * resolves — the image genuinely isn't in this site's library — the field is left exactly as it
 * was, never written with a broken or wrong reference. See resolve_import_image().
 */
class BSM_Import_Export {

	const MAX_FILE_BYTES = 2 * 1024 * 1024; // 2MB — generous for field data, small enough to reject abuse.

	public static function init() {
		add_action( 'wp_ajax_bsm_export_data', array( __CLASS__, 'export_data' ) );
		add_action( 'wp_ajax_bsm_export_template', array( __CLASS__, 'export_template' ) );
		add_action( 'wp_ajax_bsm_preview_import', array( __CLASS__, 'preview_import' ) );
		add_action( 'wp_ajax_bsm_commit_import', array( __CLASS__, 'commit_import' ) );
	}

	private static function check() {
		check_ajax_referer( 'bsm_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed' ), 403 );
		}
		if ( ! function_exists( 'get_field_object' ) || ! function_exists( 'acf_get_field_groups' ) ) {
			wp_send_json_error( array( 'message' => 'ACF is not active.' ) );
		}
	}

	/* =========================================================
	 * EXPORT — real current data, or a blank fill-in-by-hand template.
	 * ========================================================= */

	public static function export_data() {
		self::check();
		$post_id   = absint( $_POST['post_id'] ?? 0 );
		$group_key = sanitize_text_field( $_POST['group_key'] ?? '' );
		if ( ! $post_id ) wp_send_json_error( array( 'message' => 'No page selected.' ) );

		$payload = self::build_export( $post_id, $group_key ?: null, false );
		wp_send_json_success( array( 'json' => wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
	}

	public static function export_template() {
		self::check();
		$post_id   = absint( $_POST['post_id'] ?? 0 );
		$group_key = sanitize_text_field( $_POST['group_key'] ?? '' );
		if ( ! $post_id ) wp_send_json_error( array( 'message' => 'No page selected.' ) );

		$payload = self::build_export( $post_id, $group_key ?: null, true );
		wp_send_json_success( array( 'json' => wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
	}

	/** $group_key null = every group attached to the page. $blank = template mode (no real values). */
	private static function build_export( $post_id, $group_key, $blank ) {
		$groups = acf_get_field_groups( array( 'post_id' => $post_id ) );
		if ( $group_key ) {
			$groups = array_values( array_filter( $groups, function ( $g ) use ( $group_key ) {
				return $g['key'] === $group_key;
			} ) );
		}

		$out_groups = array();
		foreach ( $groups as $g ) {
			$out_groups[] = array(
				'group_key'   => $g['key'],
				'group_title' => $g['title'],
				'fields'      => self::export_fields( acf_get_fields( $g['key'] ), $post_id, $blank, null ),
			);
		}

		$post = get_post( $post_id );
		return array(
			'bsm_export'  => true,
			'version'     => 1,
			'exported_at' => current_time( 'c' ),
			'source_page' => array(
				'id'        => $post_id,
				'title'     => $post ? $post->post_title : '',
				'post_type' => $post ? $post->post_type : '',
			),
			'groups' => $out_groups,
		);
	}

	/**
	 * Walks one level of fields. $known_values, when given, is the already-normalized
	 * (name-keyed — see BSM_Ajax::get_field_data()) value array for THIS level (a Group's own
	 * value, or one repeater/flex row); null means "top level — fetch each field's live value
	 * directly," which only happens once per top-level field since nested levels always pass
	 * $known_values down.
	 */
	private static function export_fields( $fields, $post_id, $blank, $known_values ) {
		$out = array();

		foreach ( $fields as $f ) {
			if ( in_array( $f['type'], BSM_Ajax::SKIP_TYPES, true ) ) continue;

			if ( in_array( $f['type'], BSM_Ajax::CONTAINER_TYPES, true ) ) {
				$value = null;
				if ( ! $blank ) {
					if ( null === $known_values ) {
						$obj   = BSM_Ajax::get_field_data( $f['name'], $post_id, $f, $f['key'] ?? null );
						$value = $obj ? $obj['value'] : null;
					} else {
						$value = $known_values[ $f['name'] ] ?? null;
					}
				}
				$out[] = self::export_container( $f, $value, $post_id, $blank );
			} else {
				if ( ! BSM_Ajax::is_field_supported( $f ) ) continue; // Same scope as manual editing — never Gallery/Link/relationship/etc.

				$value = '';
				if ( ! $blank ) {
					$raw = ( null === $known_values )
						? ( ( $obj = BSM_Ajax::get_field_data( $f['name'], $post_id, $f, $f['key'] ?? null ) ) ? $obj['value'] : '' )
						: ( $known_values[ $f['name'] ] ?? '' );
					$value = self::scalar_export_value( $f['type'], $raw );
				}

				$entry = array(
					'name'    => $f['name'],
					'key'     => $f['key'] ?? '',
					'label'   => $f['label'],
					'type'    => $f['type'],
					'choices' => $f['choices'] ?? null,
					'value'   => $value,
					'clear'   => false,
				);
				if ( 'image' === $f['type'] && $value ) $entry['filename'] = self::attachment_filename( $value );
				$out[] = $entry;
			}
		}

		return $out;
	}

	private static function export_container( $f, $value, $post_id, $blank ) {
		if ( 'group' === $f['type'] ) {
			return array(
				'name'   => $f['name'],
				'key'    => $f['key'] ?? '',
				'label'  => $f['label'],
				'type'   => 'group',
				'fields' => self::export_fields( $f['sub_fields'] ?? array(), $post_id, $blank, $blank ? array() : ( is_array( $value ) ? $value : array() ) ),
			);
		}

		if ( 'repeater' === $f['type'] ) {
			$sub_fields = $f['sub_fields'] ?? array();
			$rows       = $blank ? array( array() ) : ( is_array( $value ) ? $value : array() ); // One blank example row for a template.
			$out_rows   = array();
			foreach ( $rows as $row ) {
				$out_rows[] = self::export_row( $sub_fields, is_array( $row ) ? $row : array(), $post_id, $blank );
			}
			return array(
				'name'       => $f['name'],
				'key'        => $f['key'] ?? '',
				'label'      => $f['label'],
				'type'       => 'repeater',
				'sub_fields' => self::field_summaries( $sub_fields ),
				'rows'       => $out_rows,
			);
		}

		// flexible_content
		$layouts  = $f['layouts'] ?? array();
		$out_rows = array();
		if ( $blank ) {
			// One example row per layout, so the shape of every layout is visible in the template.
			foreach ( $layouts as $layout ) {
				$row                  = self::export_row( $layout['sub_fields'] ?? array(), array(), $post_id, true );
				$row['acf_fc_layout'] = $layout['name'];
				$out_rows[]           = $row;
			}
		} else {
			foreach ( ( is_array( $value ) ? $value : array() ) as $row ) {
				$layout_name = $row['acf_fc_layout'] ?? '';
				$layout_def  = BSM_Ajax::find_layout_def( $layouts, $layout_name );
				$out_row     = $layout_def ? self::export_row( $layout_def['sub_fields'], is_array( $row ) ? $row : array(), $post_id, false ) : array();
				$out_row['acf_fc_layout'] = $layout_name;
				$out_rows[] = $out_row;
			}
		}

		return array(
			'name'    => $f['name'],
			'key'     => $f['key'] ?? '',
			'label'   => $f['label'],
			'type'    => 'flexible_content',
			'layouts' => array_map( function ( $l ) {
				return array( 'name' => $l['name'], 'label' => $l['label'], 'sub_fields' => self::field_summaries( $l['sub_fields'] ?? array() ) );
			}, $layouts ),
			'rows' => $out_rows,
		);
	}

	/** One repeater/flex row, as a flat {subfieldname: {value, clear}} map (plus any nested containers, same shape as export_fields). */
	private static function export_row( $sub_fields, $row_value, $post_id, $blank ) {
		$row = array();
		foreach ( self::export_fields( $sub_fields, $post_id, $blank, $blank ? array() : $row_value ) as $entry ) {
			$row[ $entry['name'] ] = $entry;
		}
		return $row;
	}

	/** name/label/type only — what BSM_Ajax::guess_display_keys()/build_preview() need, nothing more. */
	private static function field_summaries( $sub_fields ) {
		$out = array();
		foreach ( (array) $sub_fields as $sf ) {
			if ( in_array( $sf['type'], BSM_Ajax::SKIP_TYPES, true ) ) continue;
			$out[] = array( 'name' => $sf['name'], 'label' => $sf['label'], 'type' => $sf['type'] );
		}
		return $out;
	}

	/** Image values export as the plain attachment ID — a starting point only; see resolve_import_image() for why the filename is what actually matters on import. */
	private static function scalar_export_value( $type, $val ) {
		if ( 'image' === $type ) {
			if ( is_array( $val ) ) return $val['ID'] ?? ( $val['id'] ?? '' );
			return is_scalar( $val ) ? $val : '';
		}
		return is_scalar( $val ) ? $val : '';
	}

	private static function attachment_filename( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		return $file ? wp_basename( $file ) : '';
	}

	/* =========================================================
	 * IMPORT — parse, match against the TARGET page's live structure, preview, then commit.
	 * ========================================================= */

	private static function decode_file( $raw ) {
		if ( strlen( $raw ) > self::MAX_FILE_BYTES ) return null;
		$data = json_decode( wp_unslash( (string) $raw ), true );
		if ( ! is_array( $data ) || empty( $data['bsm_export'] ) || ! is_array( $data['groups'] ?? null ) ) return null;
		return $data;
	}

	/** Matches a file group against one of the TARGET page's currently-attached groups: key first, else exact title. */
	private static function match_group( $target_groups, $file_group ) {
		foreach ( $target_groups as $g ) {
			if ( ! empty( $file_group['group_key'] ) && $g['key'] === $file_group['group_key'] ) return $g;
		}
		foreach ( $target_groups as $g ) {
			if ( isset( $file_group['group_title'] ) && strtolower( $g['title'] ) === strtolower( $file_group['group_title'] ) ) return $g;
		}
		return null;
	}

	/** Matches a file field against one of $target_fields: key first, else exact name. */
	private static function match_field( $target_fields, $file_field ) {
		if ( ! empty( $file_field['key'] ) ) {
			foreach ( $target_fields as $tf ) {
				if ( ( $tf['key'] ?? '' ) === $file_field['key'] ) return $tf;
			}
		}
		foreach ( $target_fields as $tf ) {
			if ( $tf['name'] === $file_field['name'] ) return $tf;
		}
		return null;
	}

	/** Converts our {name,key,label} breadcrumb into the {name,key,row,layout} shape BSM_Ajax::resolve_path() expects. */
	private static function for_resolve( $path ) {
		return array_map( function ( $h ) {
			return array( 'name' => $h['name'], 'key' => $h['key'], 'row' => null, 'layout' => null );
		}, $path );
	}

	private static function path_signature( $group_key, $path ) {
		return $group_key . '::' . implode( '.', array_map( function ( $h ) { return $h['name']; }, $path ) );
	}

	public static function preview_import() {
		self::check();

		$post_id = absint( $_POST['post_id'] ?? 0 );
		$data    = self::decode_file( $_POST['file_contents'] ?? '' );
		if ( ! $post_id ) wp_send_json_error( array( 'message' => 'No page selected.' ) );
		if ( ! $data ) wp_send_json_error( array( 'message' => 'That file is not a valid export from this tool, or is too large.' ) );

		$target_groups = acf_get_field_groups( array( 'post_id' => $post_id ) );

		$groups_out = array();
		foreach ( $data['groups'] as $fg ) {
			$target_group = self::match_group( $target_groups, $fg );
			if ( ! $target_group ) {
				$groups_out[] = array( 'group_title' => $fg['group_title'] ?? '(unnamed)', 'found' => false, 'leaves' => array(), 'containers' => array() );
				continue;
			}

			$leaves     = array();
			$containers = array();
			self::diff_fields( $fg['fields'] ?? array(), acf_get_fields( $target_group['key'] ), array(), $post_id, $target_group['key'], $leaves, $containers );
			$groups_out[] = array( 'group_title' => $target_group['title'], 'found' => true, 'leaves' => $leaves, 'containers' => $containers );
		}

		wp_send_json_success( array( 'groups' => $groups_out, 'source_page' => $data['source_page'] ?? null ) );
	}

	/**
	 * Recursively diffs one level of file fields against the matching level of the TARGET's live
	 * field definitions, appending flat entries into $leaves (scalar fields, current vs incoming,
	 * computed action) and $containers (repeater/flex — row counts + incoming preview, no
	 * per-row diff — see class docblock for why).
	 */
	private static function diff_fields( $file_fields, $target_fields, $path_prefix, $post_id, $group_key, &$leaves, &$containers ) {
		foreach ( $file_fields as $ff ) {
			$target_def = self::match_field( $target_fields, $ff );
			$path       = array_merge( $path_prefix, array( array(
				'name'  => $ff['name'],
				'key'   => $target_def['key'] ?? ( $ff['key'] ?? '' ),
				'label' => $ff['label'] ?? $ff['name'],
			) ) );
			$sig = self::path_signature( $group_key, $path );

			if ( ! $target_def ) {
				$leaves[] = array( 'sig' => $sig, 'label' => $ff['label'] ?? $ff['name'], 'action' => 'skip', 'reason' => 'Not found on this page' );
				continue;
			}
			if ( $target_def['type'] !== ( $ff['type'] ?? '' ) ) {
				$leaves[] = array( 'sig' => $sig, 'label' => $ff['label'] ?? $ff['name'], 'action' => 'skip', 'reason' => 'Field type differs here (' . $target_def['type'] . ')' );
				continue;
			}

			if ( 'group' === $ff['type'] ) {
				self::diff_fields( $ff['fields'] ?? array(), $target_def['sub_fields'] ?? array(), $path, $post_id, $group_key, $leaves, $containers );
			} elseif ( in_array( $ff['type'], array( 'repeater', 'flexible_content' ), true ) ) {
				$node          = BSM_Ajax::resolve_path( $post_id, self::for_resolve( $path ) );
				$current_count = ( $node && is_array( $node['value'] ) ) ? count( $node['value'] ) : 0;
				$preview       = self::preview_incoming_rows( $ff );
				$containers[]  = array(
					'sig'            => $sig,
					'label'          => $ff['label'] ?? $ff['name'],
					'type'           => $ff['type'],
					'current_count'  => $current_count,
					'incoming_count' => count( $ff['rows'] ?? array() ),
					'preview'        => $preview,
				);
			} elseif ( 'image' === $ff['type'] ) {
				$node     = BSM_Ajax::resolve_path( $post_id, self::for_resolve( $path ) );
				$current  = $node ? $node['value'] : null;
				$clear    = ! empty( $ff['clear'] );
				$resolved = $clear ? '' : self::resolve_import_image( $ff['value'] ?? '', $ff['filename'] ?? '' );

				$action = 'leave';
				if ( $clear ) $action = 'clear';
				elseif ( '' !== (string) ( $ff['value'] ?? '' ) && '' === (string) $resolved ) $action = 'skip';
				elseif ( '' !== (string) $resolved ) $action = 'update';

				$leaves[] = array(
					'sig'      => $sig,
					'label'    => $ff['label'] ?? $ff['name'],
					'type'     => 'image',
					'current'  => is_scalar( $current ) ? $current : '',
					'incoming' => $resolved ? ( $ff['filename'] ?? $resolved ) : '',
					'clear'    => $clear,
					'action'   => $action,
					'reason'   => 'skip' === $action ? 'Not found in this site\'s Media Library' : '',
				);
			} else {
				$node    = BSM_Ajax::resolve_path( $post_id, self::for_resolve( $path ) );
				$current = $node ? $node['value'] : null;
				$clear   = ! empty( $ff['clear'] );
				$incoming = $ff['value'] ?? '';
				$has_incoming = ! $clear && '' !== (string) $incoming && null !== $incoming;

				$action = 'leave';
				if ( $clear ) $action = 'clear';
				elseif ( $has_incoming ) $action = 'update';

				$leaves[] = array(
					'sig'      => $sig,
					'label'    => $ff['label'] ?? $ff['name'],
					'type'     => $ff['type'],
					'current'  => is_scalar( $current ) ? $current : '',
					'incoming' => is_scalar( $incoming ) ? $incoming : '',
					'clear'    => $clear,
					'action'   => $action,
				);
			}
		}
	}

	private static function preview_incoming_rows( $ff ) {
		$flat_rows = array();
		foreach ( (array) ( $ff['rows'] ?? array() ) as $row ) {
			$flat = array();
			foreach ( $row as $key => $cell ) {
				$flat[ $key ] = ( 'acf_fc_layout' === $key ) ? $cell : ( is_array( $cell ) ? ( $cell['value'] ?? '' ) : $cell );
			}
			$flat_rows[] = $flat;
		}

		if ( 'repeater' === $ff['type'] ) {
			return BSM_Ajax::build_preview( $flat_rows, $ff['sub_fields'] ?? array() );
		}

		// flexible_content — build_preview_flex() wants ACF-shaped layout defs; ours already match (name/label/sub_fields).
		return BSM_Ajax::build_preview_flex( $flat_rows, $ff['layouts'] ?? array() );
	}

	public static function commit_import() {
		self::check();

		$post_id  = absint( $_POST['post_id'] ?? 0 );
		$data     = self::decode_file( $_POST['file_contents'] ?? '' );
		$included = isset( $_POST['included'] ) && is_array( $_POST['included'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['included'] ) ) : array();
		$included = array_fill_keys( $included, true ); // O(1) lookup.

		if ( ! $post_id ) wp_send_json_error( array( 'message' => 'No page selected.' ) );
		if ( ! $data ) wp_send_json_error( array( 'message' => 'That file is not a valid export from this tool, or is too large.' ) );

		$target_groups = acf_get_field_groups( array( 'post_id' => $post_id ) );
		$summary       = array();

		foreach ( $data['groups'] as $fg ) {
			$target_group = self::match_group( $target_groups, $fg );
			if ( ! $target_group ) {
				$summary[] = array( 'label' => $fg['group_title'] ?? '(unnamed)', 'status' => 'skipped', 'reason' => 'Not found on this page' );
				continue;
			}
			self::commit_fields( $fg['fields'] ?? array(), acf_get_fields( $target_group['key'] ), array(), $post_id, $target_group['key'], $included, $summary );
		}

		wp_send_json_success( array( 'summary' => $summary ) );
	}

	private static function commit_fields( $file_fields, $target_fields, $path_prefix, $post_id, $group_key, $included, &$summary ) {
		foreach ( $file_fields as $ff ) {
			$target_def = self::match_field( $target_fields, $ff );
			$path       = array_merge( $path_prefix, array( array(
				'name'  => $ff['name'],
				'key'   => $target_def['key'] ?? ( $ff['key'] ?? '' ),
				'label' => $ff['label'] ?? $ff['name'],
			) ) );
			$sig = self::path_signature( $group_key, $path );

			if ( ! $target_def || $target_def['type'] !== ( $ff['type'] ?? '' ) ) {
				continue; // Reported already at preview time — nothing to write, nothing new to say.
			}

			if ( 'group' === $ff['type'] ) {
				self::commit_fields( $ff['fields'] ?? array(), $target_def['sub_fields'] ?? array(), $path, $post_id, $group_key, $included, $summary );
				continue;
			}

			if ( ! isset( $included[ $sig ] ) ) continue; // Left unchecked in the preview — leave it alone entirely.

			if ( in_array( $ff['type'], array( 'repeater', 'flexible_content' ), true ) ) {
				$summary[] = self::write_container( $post_id, $path, $ff );
			} else {
				$summary[] = self::write_leaf( $post_id, $path, $ff );
			}
		}
	}

	/** Writes one scalar leaf (top-level, or nested inside any number of Groups) via the exact same resolve/cast/splice sequence save_slot() uses. */
	private static function write_leaf( $post_id, $path, $ff ) {
		$label    = $ff['label'] ?? $ff['name'];
		$clear    = ! empty( $ff['clear'] );
		$incoming = $ff['value'] ?? '';

		// An empty, non-cleared value is "nothing to import" — enforced here too, not just by the
		// preview screen defaulting this row's checkbox to unchecked, so a stray/forced-included
		// signature can never blank a field the file never actually meant to touch.
		if ( ! $clear && ( '' === (string) $incoming || null === $incoming ) ) {
			return array( 'label' => $label, 'status' => 'left unchanged', 'reason' => 'Nothing to import for this field' );
		}

		$node = BSM_Ajax::resolve_path( $post_id, self::for_resolve( $path ) );
		if ( ! $node ) return array( 'label' => $label, 'status' => 'skipped', 'reason' => 'Field not found — the page may have changed.' );

		if ( 'image' === $node['type'] && ! $clear ) {
			$resolved = self::resolve_import_image( $incoming, $ff['filename'] ?? '' );
			if ( '' === $resolved ) {
				return array( 'label' => $label, 'status' => 'left unchanged', 'reason' => 'Image not found in this site\'s Media Library' );
			}
			$new_value = $resolved;
		} else {
			$new_value = BSM_Ajax::cast_value( $node['type'], $clear ? '' : $incoming, $node['choices'] ?? null, $clear );
		}

		if ( null === $new_value ) {
			return array( 'label' => $label, 'status' => 'skipped', 'reason' => 'Not one of this field\'s own choices' );
		}

		$root_value = BSM_Ajax::set_by_key_path( $node['root_value'], $node['key_path'], $new_value );
		update_field( $node['root_key'] ?: $node['root_name'], $root_value, $post_id );

		return array( 'label' => $label, 'status' => $clear ? 'cleared' : 'updated' );
	}

	/**
	 * Replaces an entire repeater's/Flexible Content's rows in one write — see class docblock for
	 * why this is whole-field, not per-row. "Whole-field" still means position-preserving for
	 * anything this tool doesn't itself write, though: row N's UNSUPPORTED sub-field data
	 * (Gallery, relationship, a nested container — anything not in EDITABLE_TYPES, so never
	 * exported in the first place) is carried over from the TARGET's own existing row N, same
	 * "never wipe what you didn't render an input for" rule merge_row() already applies to a
	 * single row. A row beyond the target's current count starts with nothing to carry over
	 * (it's genuinely new); a target row beyond the incoming count is simply dropped, same as
	 * deleting a row by hand. A Flexible Content row only carries over the existing row at that
	 * position if it's the SAME layout — a different layout has a completely different sub-field
	 * shape, so there's nothing sensible to inherit.
	 */
	private static function write_container( $post_id, $path, $ff ) {
		$label = $ff['label'] ?? $ff['name'];
		$node  = BSM_Ajax::resolve_path( $post_id, self::for_resolve( $path ) );
		if ( ! $node || ! in_array( $node['type'], array( 'repeater', 'flexible_content' ), true ) ) {
			return array( 'label' => $label, 'status' => 'skipped', 'reason' => 'Field not found — the page may have changed.' );
		}

		$existing_rows = is_array( $node['value'] ) ? $node['value'] : array();
		$is_flex       = 'flexible_content' === $node['type'];
		$new_rows      = array();

		foreach ( (array) ( $ff['rows'] ?? array() ) as $i => $row ) {
			$existing_row = is_array( $existing_rows[ $i ] ?? null ) ? $existing_rows[ $i ] : array();

			if ( $is_flex ) {
				$layout_name = $row['acf_fc_layout'] ?? '';
				$layout_def  = BSM_Ajax::find_layout_def( $node['layouts'], $layout_name );
				$same_layout = ( $existing_row['acf_fc_layout'] ?? null ) === $layout_name;
				$base        = $same_layout ? $existing_row : array();
				$new_row     = $layout_def ? self::cast_row( $row, $layout_def['sub_fields'], $base ) : $base;
				$new_row['acf_fc_layout'] = $layout_name;
				$new_rows[] = $new_row;
			} else {
				$new_rows[] = self::cast_row( $row, $node['sub_fields'], $existing_row );
			}
		}

		$root_value = BSM_Ajax::set_by_key_path( $node['root_value'], $node['key_path'], $new_rows );
		update_field( $node['root_key'] ?: $node['root_name'], $root_value, $post_id );

		return array( 'label' => $label, 'status' => 'updated', 'rows' => count( $new_rows ) );
	}

	/**
	 * Casts one row's cells against the TARGET's real, live sub-field definitions — never the
	 * file's own idea of the structure, same principle as everywhere else in this plugin. A cell
	 * whose sub-field no longer exists, changed type, or isn't itself an editable type on the
	 * target is left out entirely — same effect as it never having been in the file. $base is the
	 * existing row (or layout-matched existing row) to start from, so anything not in EDITABLE_TYPES
	 * — the only thing $row could never have a value for — survives untouched.
	 */
	private static function cast_row( $row, $target_sub_fields, $base = array() ) {
		$out = is_array( $base ) ? $base : array();

		$by_name = array();
		foreach ( (array) $target_sub_fields as $sf ) { $by_name[ $sf['name'] ] = $sf; }

		foreach ( $row as $key => $cell ) {
			if ( 'acf_fc_layout' === $key ) continue; // Handled by the caller.
			if ( ! isset( $by_name[ $key ] ) ) continue; // Sub-field renamed/removed on the target since export.
			$sf = $by_name[ $key ];
			if ( ! BSM_Ajax::is_field_supported( $sf ) ) continue;
			if ( ! is_array( $cell ) || ! array_key_exists( 'value', $cell ) ) continue; // A nested container cell — not written inside a row replace in this version; left as whatever $base already had.

			$clear = ! empty( $cell['clear'] );

			if ( 'image' === $sf['type'] && ! $clear ) {
				$resolved = self::resolve_import_image( $cell['value'], $cell['filename'] ?? '' );
				if ( '' === $resolved ) continue; // Not in this site's Media Library — leave that cell as $base had it.
				$out[ $key ] = $resolved;
				continue;
			}

			$val = BSM_Ajax::cast_value( $sf['type'], $clear ? '' : $cell['value'], $sf['choices'] ?? null, $clear );
			if ( null === $val ) continue; // Not one of this select's own choices — leave that cell as $base had it.

			$out[ $key ] = $val;
		}

		return $out;
	}

	/**
	 * Resolves an imported image to an attachment ID that actually exists in THIS site's Media
	 * Library — see class docblock's "Image handling" note for why the raw exported ID can't be
	 * trusted on its own. Filename match wins when available (the portable, meaningful signal
	 * across two different sites); the raw ID is only used as a fallback, and only when it
	 * genuinely resolves to a real image here. Returns '' when neither resolves — "not present,"
	 * meaning the caller leaves the field exactly as it was rather than writing a guess.
	 */
	private static function resolve_import_image( $value, $filename ) {
		if ( $filename ) {
			$found = self::find_attachment_by_filename( $filename );
			if ( $found ) return $found;
		}
		$id = is_numeric( $value ) ? (int) $value : 0;
		if ( $id && 'attachment' === get_post_type( $id ) && wp_attachment_is_image( $id ) ) {
			return $id;
		}
		return '';
	}

	private static function find_attachment_by_filename( $filename ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $filename );
		$id   = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT 1",
			$like
		) );
		return $id ? (int) $id : 0;
	}
}
