<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * All the live ACF reading/writing. Nothing here is cached or stored —
 * every call reflects the current state of ACF and post content.
 *
 * Field types this tool can render/edit directly:
 *   text, textarea, wysiwyg, url, email, number, date_picker, select, true_false, image
 * Container types it can browse into:
 *   repeater, group, flexible_content — at ANY nesting depth, including a
 *   repeater/group/flexible_content nested inside a repeater row or a
 *   flexible content layout instance. Steps 4/5 use a "path" (a chain of
 *   {name, row} hops from the top-level field down to whatever slot is
 *   currently open) to address these, since only the top-level field name
 *   is ever directly gettable via ACF's get_field_object() — anything
 *   living inside a repeater/flex row is addressed by walking that row's
 *   own array data instead.
 * Anything else is still LISTED (so nothing is hidden from you) but marked
 * unsupported, and is never written to — existing data in unsupported
 * sub-fields is always preserved untouched on save.
 */
class BSM_Ajax {

	const EDITABLE_TYPES  = array( 'text', 'textarea', 'wysiwyg', 'url', 'email', 'number', 'date_picker', 'select', 'true_false', 'image' );
	const CONTAINER_TYPES = array( 'repeater', 'group', 'flexible_content' );
	const SKIP_TYPES      = array( 'tab', 'accordion', 'message' ); // Visual-only, never real data.
	const FILL_ROLES      = array( 'title', 'description', 'image', 'link' ); // What "fill from post" can map into.

	public static function init() {
		add_action( 'wp_ajax_bsm_get_post_types', array( __CLASS__, 'get_post_types' ) );
		add_action( 'wp_ajax_bsm_search_items', array( __CLASS__, 'search_items' ) );
		add_action( 'wp_ajax_bsm_get_field_groups', array( __CLASS__, 'get_field_groups' ) );
		add_action( 'wp_ajax_bsm_get_fields', array( __CLASS__, 'get_fields' ) );
		add_action( 'wp_ajax_bsm_get_slot', array( __CLASS__, 'get_slot' ) );
		add_action( 'wp_ajax_bsm_fill_from_post', array( __CLASS__, 'fill_from_post' ) );
		add_action( 'wp_ajax_bsm_save_slot', array( __CLASS__, 'save_slot' ) );
		add_action( 'wp_ajax_bsm_discard_generated_images', array( __CLASS__, 'discard_generated_images' ) );
		add_action( 'wp_ajax_bsm_get_source_mapping', array( __CLASS__, 'get_source_mapping' ) );
		add_action( 'wp_ajax_bsm_save_source_mapping', array( __CLASS__, 'save_source_mapping' ) );
	}

	private static function check() {
		check_ajax_referer( 'bsm_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed' ), 403 );
		}
	}

	/* =========================================================
	 * STEP 1 — content types + item search
	 * ========================================================= */

	public static function get_post_types() {
		self::check();

		$types = get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' );
		unset( $types['attachment'] );

		$out = array();
		// Pages first, then Posts, then everything else — matches how most sites actually use ACF.
		foreach ( array( 'page', 'post' ) as $priority ) {
			if ( isset( $types[ $priority ] ) ) {
				$out[] = array( 'slug' => $priority, 'label' => $types[ $priority ]->labels->singular_name );
				unset( $types[ $priority ] );
			}
		}
		foreach ( $types as $slug => $obj ) {
			$out[] = array( 'slug' => $slug, 'label' => $obj->labels->singular_name );
		}

		wp_send_json_success( array( 'types' => $out ) );
	}

	public static function search_items() {
		self::check();

		$post_type = sanitize_key( $_POST['post_type'] ?? 'page' );
		$term      = sanitize_text_field( $_POST['term'] ?? '' );

		$q = new WP_Query( array(
			's'              => $term,
			'post_type'      => $post_type,
			'post_status'    => array( 'publish', 'draft', 'private', 'future' ),
			'posts_per_page' => 20,
			'orderby'        => $term ? 'relevance' : 'date',
			'order'          => 'DESC',
		) );

		$results = array();
		foreach ( $q->posts as $p ) {
			$label = $p->post_title ?: '(no title)';
			if ( 'publish' !== $p->post_status ) {
				$label .= ' — ' . ucfirst( $p->post_status );
			}
			$results[] = array( 'id' => $p->ID, 'text' => $label );
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/* =========================================================
	 * STEP 2 — ACF field groups on the chosen item
	 * ========================================================= */

	public static function get_field_groups() {
		self::check();

		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			wp_send_json_error( array( 'message' => 'ACF is not active.' ) );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) wp_send_json_error( array( 'message' => 'No page selected.' ) );

		$groups = acf_get_field_groups( array( 'post_id' => $post_id ) );

		$out = array();
		foreach ( $groups as $g ) {
			$out[] = array( 'key' => $g['key'], 'title' => $g['title'] );
		}

		wp_send_json_success( array( 'groups' => $out ) );
	}

	/* =========================================================
	 * STEP 3 — fields within the chosen group (with live preview)
	 * ========================================================= */

	public static function get_fields() {
		self::check();

		if ( ! function_exists( 'acf_get_fields' ) ) {
			wp_send_json_error( array( 'message' => 'ACF is not active.' ) );
		}

		$post_id   = absint( $_POST['post_id'] ?? 0 );
		$group_key = sanitize_text_field( $_POST['group_key'] ?? '' );
		if ( ! $post_id || ! $group_key ) wp_send_json_error( array( 'message' => 'Missing page or group.' ) );

		$top_fields = acf_get_fields( $group_key );
		if ( ! $top_fields ) wp_send_json_success( array( 'fields' => array() ) );

		$out = self::collect_field_rows( $top_fields, $post_id );

		wp_send_json_success( array( 'fields' => $out ) );
	}

	/**
	 * Builds the Step-3 list — top-level fields only. A Group field shows as a single
	 * card, same as a Repeater or Flexible Content field; its own sub-fields (including
	 * any container nested inside it) only appear once you actually pick it and open its
	 * form, via Browse → for anything that's itself a container. Nothing here is
	 * flattened in ahead of time, so the list a page shows always matches its real
	 * top-level structure, no matter how many fields a Group holds.
	 */
	private static function collect_field_rows( $fields, $post_id ) {
		$out = array();

		foreach ( $fields as $f ) {
			if ( in_array( $f['type'], self::SKIP_TYPES, true ) ) continue;

			// $f is already this field's real definition (from acf_get_fields()), so pass it
			// straight in as the known-good fallback — no need to re-discover it if
			// get_field_object() can't resolve it on its own (see get_field_data()).
			$obj = self::get_field_data( $f['name'], $post_id, $f );
			if ( ! $obj ) continue; // Only happens if the field has genuinely vanished from ACF entirely.

			$row = array(
				'name'  => $f['name'],
				'label' => $f['label'],
				'type'  => $f['type'],
			);

			if ( in_array( $f['type'], self::CONTAINER_TYPES, true ) ) {
				$row['is_container'] = true;

				if ( 'repeater' === $f['type'] ) {
					$rows           = is_array( $obj['value'] ) ? $obj['value'] : array();
					$row['count']   = count( $rows );
					$row['preview'] = self::build_preview( $rows, $obj['sub_fields'] );
				} elseif ( 'flexible_content' === $f['type'] ) {
					$rows           = is_array( $obj['value'] ) ? $obj['value'] : array();
					$row['count']   = count( $rows );
					$row['preview'] = self::build_preview_flex( $rows, $obj['layouts'] );
					$row['layouts'] = array_map( function ( $l ) {
						return array( 'name' => $l['name'], 'label' => $l['label'] );
					}, $obj['layouts'] );
				} elseif ( 'group' === $f['type'] ) {
					$row['count'] = 1;
				}
				$out[] = $row;
			} else {
				$row['is_container'] = false;
				$row['supported']    = in_array( $f['type'], self::EDITABLE_TYPES, true );
				$out[] = $row;
			}
		}

		return $out;
	}

	/** For a list of repeater rows, guess a display title + thumbnail per row (cosmetic only). */
	public static function build_preview( $rows, $sub_fields ) {
		list( $title_key, $image_key ) = self::guess_display_keys( $sub_fields );
		$out = array();
		foreach ( $rows as $i => $row ) {
			$out[] = array(
				'index' => $i,
				'title' => ( $title_key && ! empty( $row[ $title_key ] ) ) ? wp_strip_all_tags( is_array( $row[ $title_key ] ) ? '' : (string) $row[ $title_key ] ) : '(empty)',
				'thumb' => $image_key ? self::extract_image_url( $row[ $image_key ] ?? null ) : '',
			);
		}
		return $out;
	}

	public static function build_preview_flex( $rows, $layouts ) {
		$out = array();
		foreach ( $rows as $i => $row ) {
			$layout_name = $row['acf_fc_layout'] ?? '';
			$layout_def  = self::find_layout_def( $layouts, $layout_name );
			$title = '(' . ( $layout_def['label'] ?? $layout_name ) . ')';
			$thumb = '';
			if ( $layout_def ) {
				list( $title_key, $image_key ) = self::guess_display_keys( $layout_def['sub_fields'] );
				if ( $title_key && ! empty( $row[ $title_key ] ) ) {
					$title = wp_strip_all_tags( (string) $row[ $title_key ] );
				}
				if ( $image_key ) {
					$thumb = self::extract_image_url( $row[ $image_key ] ?? null );
				}
			}
			$out[] = array( 'index' => $i, 'title' => $title, 'thumb' => $thumb, 'layout' => $layout_name );
		}
		return $out;
	}

	public static function guess_display_keys( $sub_fields ) {
		$title_key = null;
		$image_key = null;
		foreach ( $sub_fields as $sf ) {
			$name = strtolower( $sf['name'] . ' ' . $sf['label'] );
			if ( ! $title_key && preg_match( '/title|heading|name/', $name ) ) $title_key = $sf['name'];
			if ( ! $image_key && ( 'image' === $sf['type'] || preg_match( '/image|photo|thumb/', $name ) ) ) $image_key = $sf['name'];
		}
		return array( $title_key, $image_key );
	}

	private static function extract_image_url( $val ) {
		if ( empty( $val ) ) return '';
		if ( is_array( $val ) ) return $val['sizes']['thumbnail'] ?? ( $val['url'] ?? '' );
		if ( is_numeric( $val ) ) return wp_get_attachment_image_url( $val, 'thumbnail' ) ?: '';
		if ( is_string( $val ) ) return $val; // Already a URL (return format = URL).
		return '';
	}

	public static function find_layout_def( $layouts, $layout_name ) {
		foreach ( $layouts as $l ) {
			if ( $l['name'] === $layout_name ) return $l;
		}
		return null;
	}

	/**
	 * Resolves a field's live data the same shape get_field_object() would, but tolerates
	 * get_field_object() itself failing to resolve a field that genuinely belongs to a field
	 * group attached to this post — seen in practice with a field added moments ago (the field
	 * group technically saved, but ACF's own post-context lookup hasn't caught up), regardless of
	 * whether the field currently holds a value or is completely empty. $known_def, if passed, is
	 * trusted as-is (the caller already has it from acf_get_fields()) instead of being re-found.
	 */
	public static function get_field_data( $field_name, $post_id, $known_def = null ) {
		$obj = get_field_object( $field_name, $post_id, true, true );
		if ( $obj ) return $obj;

		$def = $known_def ?: self::find_field_definition( $field_name, $post_id );
		if ( ! $def ) return null; // Genuinely doesn't exist on this post's field groups at all.

		$def['value'] = get_field( $field_name, $post_id, true );
		return $def;
	}

	/** Finds a field's own definition by scanning this post's field groups directly (see get_field_data()). */
	private static function find_field_definition( $field_name, $post_id ) {
		if ( ! function_exists( 'acf_get_field_groups' ) ) return null;
		foreach ( acf_get_field_groups( array( 'post_id' => $post_id ) ) as $group ) {
			$found = self::find_in_fields( acf_get_fields( $group['key'] ), $field_name );
			if ( $found ) return $found;
		}
		return null;
	}

	/** Recurses into Group fields only — see collect_field_rows() for why that's the correct scope. */
	private static function find_in_fields( $fields, $field_name ) {
		foreach ( (array) $fields as $f ) {
			if ( $f['name'] === $field_name ) return $f;
			if ( 'group' === $f['type'] && ! empty( $f['sub_fields'] ) ) {
				$found = self::find_in_fields( $f['sub_fields'], $field_name );
				if ( $found ) return $found;
			}
		}
		return null;
	}

	/* =========================================================
	 * PATH RESOLUTION — walks from a top-level ACF field down through
	 * any number of nested repeater / group / flexible_content hops.
	 * ========================================================= */

	/**
	 * Reads $_POST['path'], a JSON-encoded array of hops:
	 *   [ { "name": "testimonials", "row": 2 }, { "name": "sub_items", "row": 0 }, ... ]
	 * The first hop's name is always a real top-level ACF field name.
	 * Every later hop names a sub-field of whatever container the previous
	 * hop resolved to. "row" is the chosen repeater/flexible-content row
	 * index, or null/omitted for a Group hop (or a container not yet
	 * drilled into a specific row).
	 */
	private static function parse_path( $raw ) {
		$decoded = is_array( $raw ) ? $raw : json_decode( wp_unslash( (string) $raw ), true );
		if ( ! is_array( $decoded ) || empty( $decoded ) ) return null;

		$path = array();
		foreach ( $decoded as $hop ) {
			if ( ! is_array( $hop ) || empty( $hop['name'] ) ) return null;
			$path[] = array(
				'name'   => sanitize_text_field( $hop['name'] ),
				'row'    => ( isset( $hop['row'] ) && '' !== $hop['row'] && null !== $hop['row'] ) ? absint( $hop['row'] ) : null,
				'layout' => ! empty( $hop['layout'] ) ? sanitize_text_field( $hop['layout'] ) : null,
			);
		}
		return $path ?: null;
	}

	/**
	 * Walks $path and returns the resolved node, or null if any hop can't
	 * be matched against the page's current ACF structure/data (e.g. a
	 * field or row that no longer exists).
	 *
	 * Returned array:
	 *   type       - 'repeater'/'flexible_content' (no row chosen yet — a
	 *                container waiting for a slot pick), '__row__' (a
	 *                specific repeater row), '__flexrow__' (a specific
	 *                flexible-content layout instance), 'group', or a
	 *                plain ACF field type (a leaf scalar field).
	 *   sub_fields - field definitions available at this point.
	 *   layouts    - flexible_content layout definitions at this point.
	 *   value      - current value at this point (row array, group array,
	 *                or scalar).
	 *   label      - display label for whatever this hop landed on.
	 *   choices    - choices list, for a leaf select field.
	 *   is_new     - true if the final hop's row index doesn't exist yet
	 *                (i.e. this is a not-yet-saved "add new" slot).
	 *   root_name  - the top-level field name (what update_field() targets).
	 *   root_value - the full current value of the root field, untouched
	 *                siblings included.
	 *   key_path   - ordered array keys from root_value down to `value`,
	 *                used to splice an edited value back into the whole
	 *                structure before saving.
	 */
	public static function resolve_path( $post_id, $path ) {
		if ( empty( $path ) ) return null;

		$root_name = $path[0]['name'];
		$obj = self::get_field_data( $root_name, $post_id );
		if ( ! $obj ) return null;

		$root_value = $obj['value'];

		$type       = $obj['type'];
		$sub_fields = $obj['sub_fields'] ?? array();
		$layouts    = $obj['layouts'] ?? array();
		$label      = $obj['label'];
		$choices    = $obj['choices'] ?? null;
		$value      = $root_value;
		$key_path   = array();
		$is_new     = false;

		foreach ( $path as $i => $hop ) {
			if ( 0 !== $i ) {
				$name    = $hop['name'];
				$sub_def = null;
				foreach ( $sub_fields as $sf ) {
					if ( $sf['name'] === $name ) { $sub_def = $sf; break; }
				}
				if ( ! $sub_def ) return null;

				$key_path[] = $name;
				$value      = is_array( $value ) ? ( $value[ $name ] ?? null ) : null;
				$type       = $sub_def['type'];
				$sub_fields = $sub_def['sub_fields'] ?? array();
				$layouts    = $sub_def['layouts'] ?? array();
				$label      = $sub_def['label'];
				$choices    = $sub_def['choices'] ?? null;
			}

			$is_new = false;
			$row    = $hop['row'] ?? null;
			if ( null === $row ) continue;

			if ( 'repeater' === $type ) {
				$rows       = is_array( $value ) ? $value : array();
				$is_new     = ! isset( $rows[ $row ] );
				$key_path[] = $row;
				$value      = $rows[ $row ] ?? array();
				$type       = '__row__';
			} elseif ( 'flexible_content' === $type ) {
				$rows        = is_array( $value ) ? $value : array();
				$is_new      = ! isset( $rows[ $row ] );
				$existing    = $rows[ $row ] ?? array();
				$layout_name = $existing['acf_fc_layout'] ?? ( $hop['layout'] ?? '' );
				$layout_def  = self::find_layout_def( $layouts, $layout_name );
				if ( ! $layout_def ) return null;

				$key_path[] = $row;
				$value      = $existing;
				$sub_fields = $layout_def['sub_fields'];
				$type       = '__flexrow__';
				$label      = $layout_def['label'];
			}
			// A "row" on a Group or a leaf field doesn't mean anything — ignored.
		}

		return array(
			'type'       => $type,
			'sub_fields' => $sub_fields,
			'layouts'    => $layouts,
			'value'      => $value,
			'label'      => $label,
			'choices'    => $choices,
			'is_new'     => $is_new,
			'root_name'  => $root_name,
			'root_value' => $root_value,
			'key_path'   => $key_path,
		);
	}

	/** Splices $new_value into $root at the position named by $key_path, returning the updated root. */
	public static function set_by_key_path( $root, $key_path, $new_value ) {
		if ( empty( $key_path ) ) return $new_value;
		if ( ! is_array( $root ) ) $root = array();

		$key   = array_shift( $key_path );
		$child = $root[ $key ] ?? array();
		$root[ $key ] = self::set_by_key_path( $child, $key_path, $new_value );
		return $root;
	}

	/* =========================================================
	 * STEP 4/5 — a specific slot: schema + current values, at any
	 * nesting depth described by $path.
	 * ========================================================= */

	public static function get_slot() {
		self::check();

		$post_id = absint( $_POST['post_id'] ?? 0 );
		$path    = self::parse_path( $_POST['path'] ?? '' );
		if ( ! $post_id || ! $path ) wp_send_json_error( array( 'message' => 'Missing page or field path.' ) );

		$node = self::resolve_path( $post_id, $path );
		if ( ! $node ) wp_send_json_error( array( 'message' => 'Field not found on this page — it may have changed since you opened it.' ) );

		$result = array( 'field_type' => $node['type'], 'field_label' => $node['label'] );

		if ( in_array( $node['type'], array( 'repeater', 'flexible_content' ), true ) ) {
			// No row chosen yet for this container — list rows to pick from (mirrors Step 4).
			$rows = is_array( $node['value'] ) ? $node['value'] : array();
			$result['mode']  = 'rows';
			$result['count'] = count( $rows );
			if ( 'repeater' === $node['type'] ) {
				$result['preview'] = self::build_preview( $rows, $node['sub_fields'] );
			} else {
				$result['preview'] = self::build_preview_flex( $rows, $node['layouts'] );
				$result['layouts'] = array_map( function ( $l ) {
					return array( 'name' => $l['name'], 'label' => $l['label'] );
				}, $node['layouts'] );
			}
		} elseif ( in_array( $node['type'], array( '__row__', '__flexrow__', 'group' ), true ) ) {
			$existing = is_array( $node['value'] ) ? $node['value'] : array();
			$schema   = self::describe_subfields( $node['sub_fields'], $existing );
			$result['mode']   = 'fields';
			$result['schema'] = $schema;
			$result['values'] = self::values_for_schema( $schema, $existing );
			$result['is_new'] = $node['is_new'];
			if ( '__flexrow__' === $node['type'] ) {
				$last = end( $path );
				$result['layout']       = $existing['acf_fc_layout'] ?? ( $last['layout'] ?? '' );
				$result['layout_label'] = $node['label'];
			}
		} else {
			// A leaf scalar field — the top-level field itself, or one reached via Group nesting.
			$leaf_name = end( $path )['name'];
			$schema = array( array(
				'name'         => $leaf_name,
				'label'        => $node['label'],
				'type'         => $node['type'],
				'supported'    => in_array( $node['type'], self::EDITABLE_TYPES, true ),
				'choices'      => $node['choices'],
				'is_container' => false,
			) );
			$result['mode']   = 'fields';
			$result['schema'] = $schema;
			$result['values'] = self::values_for_schema( $schema, array( $leaf_name => $node['value'] ) );
			$result['is_new'] = false;
		}

		wp_send_json_success( $result );
	}

	/**
	 * Describes a container's editable sub-fields. Sub-fields that are
	 * themselves containers (repeater/group/flexible_content — nested at
	 * any depth) are flagged is_container=true with their own live
	 * count/preview attached, so the UI can offer to browse straight into
	 * them without another round trip.
	 */
	public static function describe_subfields( $sub_fields, $context_value = array() ) {
		$out = array();
		foreach ( $sub_fields as $sf ) {
			if ( in_array( $sf['type'], self::SKIP_TYPES, true ) ) continue;

			$row = array(
				'name'      => $sf['name'],
				'label'     => $sf['label'],
				'type'      => $sf['type'],
				'supported' => in_array( $sf['type'], self::EDITABLE_TYPES, true ),
				'choices'   => $sf['choices'] ?? null,
			);

			if ( in_array( $sf['type'], self::CONTAINER_TYPES, true ) ) {
				$row['is_container'] = true;
				$row['supported']    = false; // Never a direct scalar input — browse into it instead.
				$raw = is_array( $context_value ) ? ( $context_value[ $sf['name'] ] ?? array() ) : array();

				if ( 'repeater' === $sf['type'] ) {
					$rows = is_array( $raw ) ? $raw : array();
					$row['count']   = count( $rows );
					$row['preview'] = self::build_preview( $rows, $sf['sub_fields'] );
				} elseif ( 'flexible_content' === $sf['type'] ) {
					$rows = is_array( $raw ) ? $raw : array();
					$row['count']   = count( $rows );
					$row['preview'] = self::build_preview_flex( $rows, $sf['layouts'] );
					$row['layouts'] = array_map( function ( $l ) {
						return array( 'name' => $l['name'], 'label' => $l['label'] );
					}, $sf['layouts'] );
				} else { // group
					$row['count'] = 1;
				}
			} else {
				$row['is_container'] = false;
			}

			$out[] = $row;
		}
		return $out;
	}

	private static function values_for_schema( $schema, $existing_row ) {
		$out = array();
		foreach ( $schema as $sf ) {
			if ( ! empty( $sf['is_container'] ) ) continue; // No scalar value to show — it's browsed, not edited directly.
			$raw = $existing_row[ $sf['name'] ] ?? '';
			if ( 'image' === $sf['type'] ) {
				$out[ $sf['name'] ] = self::normalize_image( $raw );
			} else {
				$out[ $sf['name'] ] = array( 'raw' => is_scalar( $raw ) ? $raw : '', 'display' => is_scalar( $raw ) ? $raw : '' );
			}
		}
		return $out;
	}

	private static function normalize_image( $val ) {
		if ( empty( $val ) ) return array( 'id' => '', 'url' => '' );
		if ( is_array( $val ) ) return array( 'id' => $val['ID'] ?? ( $val['id'] ?? '' ), 'url' => $val['sizes']['medium'] ?? ( $val['url'] ?? '' ) );
		if ( is_numeric( $val ) ) return array( 'id' => $val, 'url' => wp_get_attachment_image_url( $val, 'medium' ) ?: '' );
		if ( is_string( $val ) ) return array( 'id' => '', 'url' => $val ); // URL-only return format, can't recover an ID.
		return array( 'id' => '', 'url' => '' );
	}

	/* =========================================================
	 * "Fill from blog post" convenience, and the source-mapping system
	 * behind it — see class docblock section below for how this scans.
	 * ========================================================= */

	public static function fill_from_post() {
		self::check();

		$path           = self::parse_path( $_POST['path'] ?? '' );
		$source_post_id = absint( $_POST['source_post_id'] ?? 0 );
		$root_post_id   = absint( $_POST['post_id'] ?? 0 );

		$post = get_post( $source_post_id );
		if ( ! $post ) wp_send_json_error( array( 'message' => 'Source post not found.' ) );
		if ( ! $path || ! $root_post_id ) wp_send_json_error( array( 'message' => 'Missing field path.' ) );

		// Re-resolve the schema server-side (never trust the client for this) using the same
		// path get_slot() used, so guesses are always based on real, current field definitions.
		$node = self::resolve_path( $root_post_id, $path );
		if ( ! $node ) wp_send_json_error( array( 'message' => 'Could not resolve field schema.' ) );

		if ( in_array( $node['type'], array( '__row__', '__flexrow__', 'group' ), true ) ) {
			$schema       = self::describe_subfields( $node['sub_fields'] );
			$existing_row = is_array( $node['value'] ) ? $node['value'] : array();
		} else {
			$leaf_name    = end( $path )['name'];
			$schema       = array( array( 'name' => $leaf_name, 'label' => $node['label'], 'type' => $node['type'], 'supported' => true, 'is_container' => false ) );
			$existing_row = array( $leaf_name => $node['value'] );
		}

		// Where each role's value actually comes from on THIS source post — a custom ACF field on
		// its own post type if one resolves (auto-discovered or manually set), otherwise exactly
		// today's original behavior (WordPress's own title/excerpt/featured image/permalink).
		$title_field = self::resolve_source_field( $post->post_type, 'title' );
		$desc_field  = self::resolve_source_field( $post->post_type, 'description' );
		$image_field = self::resolve_source_field( $post->post_type, 'image' );
		$link_field  = self::resolve_source_field( $post->post_type, 'link' );

		$title   = $title_field ? (string) self::get_source_value( $post->ID, $title_field ) : $post->post_title;
		$excerpt = $desc_field ? (string) self::get_source_value( $post->ID, $desc_field )
			: ( has_excerpt( $post->ID ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 ) );
		$link    = $link_field ? (string) self::get_source_value( $post->ID, $link_field ) : get_permalink( $post->ID );
		$thumb_id = $image_field
			? self::extract_attachment_id( self::get_source_value( $post->ID, $image_field, false ) )
			: get_post_thumbnail_id( $post->ID );

		$out = array();
		foreach ( $schema as $sf ) {
			if ( ! empty( $sf['is_container'] ) || ! $sf['supported'] ) continue;
			$name = strtolower( $sf['name'] . ' ' . $sf['label'] );

			if ( 'image' === $sf['type'] || preg_match( '/image|photo|thumb/', $name ) ) {
				if ( $thumb_id ) {
					$out[ $sf['name'] ] = self::build_image_fill( $thumb_id, $existing_row[ $sf['name'] ] ?? null, $root_post_id, $node['label'], $post );
				}
			} elseif ( preg_match( '/title|heading/', $name ) ) {
				$out[ $sf['name'] ] = array( 'applies' => true, 'value' => $title );
			} elseif ( preg_match( '/desc|excerpt|summary|content/', $name ) && 'url' !== $sf['type'] ) {
				$out[ $sf['name'] ] = array( 'applies' => true, 'value' => $excerpt );
			} elseif ( 'url' === $sf['type'] || preg_match( '/link|url|permalink/', $name ) ) {
				$out[ $sf['name'] ] = array( 'applies' => true, 'value' => $link );
			}
			// Anything else (type/category/button text/select/etc.) is intentionally left
			// alone — we don't guess values we can't be confident about.
		}

		wp_send_json_success( array( 'fills' => $out ) );
	}

	private static function extract_attachment_id( $val ) {
		if ( is_array( $val ) ) return (int) ( $val['ID'] ?? ( $val['id'] ?? 0 ) );
		if ( is_numeric( $val ) ) return (int) $val;
		if ( is_string( $val ) && $val ) {
			$id = attachment_url_to_postid( $val );
			if ( $id ) return $id;
		}
		return 0;
	}

	/**
	 * Scans a post type's OWN ACF field groups — found via ACF's post-type location rule, not
	 * tied to any specific existing post — and classifies each field into a role (title/
	 * description/image/link) using the exact same name/type heuristics fill_from_post() has
	 * always used for the target page's fields. This is what lets "fill from post" adapt to a
	 * different content type (a whitepaper's own "abstract"/"pdf_file" fields, say) with no code
	 * change and no manual setup — same "read the live ACF structure fresh" philosophy as
	 * everything else in this plugin, just pointed at the SOURCE post type this time. A post type
	 * with no matching custom field (a plain blog post, typically) resolves every role to null
	 * here, which is exactly what tells fill_from_post() to keep using WordPress's own defaults.
	 */
	private static function discover_source_mapping( $post_type ) {
		$mapping = array_fill_keys( self::FILL_ROLES, null );

		foreach ( self::collect_mappable_fields( $post_type ) as $f ) {
			$name = strtolower( $f['name'] . ' ' . $f['label'] );

			if ( ! $mapping['image'] && ( 'image' === $f['type'] || preg_match( '/image|photo|thumb/', $name ) ) ) {
				$mapping['image'] = $f['name'];
			} elseif ( ! $mapping['title'] && preg_match( '/title|heading/', $name ) ) {
				$mapping['title'] = $f['name'];
			} elseif ( ! $mapping['description'] && 'url' !== $f['type'] && preg_match( '/desc|excerpt|summary|abstract|content/', $name ) ) {
				$mapping['description'] = $f['name'];
			} elseif ( ! $mapping['link'] && ( 'url' === $f['type'] || preg_match( '/link|url|permalink|download/', $name ) ) ) {
				$mapping['link'] = $f['name'];
			}
		}

		return $mapping;
	}

	/**
	 * Every field on a post type that "fill from post" could plausibly map to a role — recurses
	 * into Group fields (storage-transparent, addressable directly by name no matter how deep,
	 * same reasoning as collect_field_rows()) so a field organized inside a Group isn't invisible
	 * to mapping just because of how it's arranged. Repeater/Flexible Content sub-fields are NOT
	 * included — those only exist per-row, there's no single value to read without picking a row
	 * first, so they can't be used as a one-shot "fill from post" source.
	 */
	private static function collect_mappable_fields( $post_type ) {
		if ( ! function_exists( 'acf_get_field_groups' ) ) return array();
		$out = array();
		foreach ( acf_get_field_groups( array( 'post_type' => $post_type ) ) as $group ) {
			$out = array_merge( $out, self::collect_mappable_fields_from( acf_get_fields( $group['key'] ) ) );
		}
		return $out;
	}

	private static function collect_mappable_fields_from( $fields ) {
		$out = array();
		foreach ( (array) $fields as $f ) {
			if ( in_array( $f['type'], self::SKIP_TYPES, true ) ) continue;
			if ( 'group' === $f['type'] ) {
				if ( ! empty( $f['sub_fields'] ) ) {
					$out = array_merge( $out, self::collect_mappable_fields_from( $f['sub_fields'] ) );
				}
				continue;
			}
			// A Repeater's rows all share the same sub-field schema (unlike Flexible Content,
			// where each row can be a different layout) — so its FIRST row is a safe, predictable
			// thing to offer as a source, e.g. a Whitepaper's "Content Sections" repeater where the
			// first block's body text is the de-facto summary. Encoded as "repeater::0::subfield"
			// and unpacked by get_source_value() when a fill actually reads it.
			if ( 'repeater' === $f['type'] ) {
				foreach ( (array) ( $f['sub_fields'] ?? array() ) as $sub ) {
					if ( in_array( $sub['type'], self::SKIP_TYPES, true ) ) continue;
					if ( in_array( $sub['type'], self::CONTAINER_TYPES, true ) ) continue; // nested container inside the row — skip, same reasoning as top level.
					$out[] = array(
						'name'  => $f['name'] . '::0::' . $sub['name'],
						'label' => $f['label'] . ' → ' . $sub['label'] . ' (first row)',
						'type'  => $sub['type'],
					);
				}
				continue;
			}
			if ( in_array( $f['type'], self::CONTAINER_TYPES, true ) ) continue; // flexible_content — layouts vary per row, no safe single value.
			$out[] = $f;
		}
		return $out;
	}

	/**
	 * Reads a field selector that may be a plain ACF field name, or a
	 * "repeater_name::0::subfield_name" selector produced by collect_mappable_fields_from() for a
	 * Repeater's first row. Falls back to a plain get_field() for everything else.
	 */
	private static function get_source_value( $post_id, $selector, $format = true ) {
		if ( false !== strpos( $selector, '::' ) ) {
			list( $repeater_name, $row_index, $sub_name ) = explode( '::', $selector, 3 );
			$rows = get_field( $repeater_name, $post_id, $format );
			return is_array( $rows ) && isset( $rows[ (int) $row_index ][ $sub_name ] ) ? $rows[ (int) $row_index ][ $sub_name ] : '';
		}
		return get_field( $selector, $post_id, $format );
	}

	/** Manual overrides, saved via the mapping settings box — stored as a WordPress option, never in code. */
	private static function get_mapping_overrides( $post_type ) {
		$all = get_option( 'bsm_source_mappings', array() );
		return isset( $all[ $post_type ] ) && is_array( $all[ $post_type ] ) ? $all[ $post_type ] : array();
	}

	/**
	 * Resolves the one field to actually use for a role on a source post type: a manual override
	 * first, then the auto-discovered custom field, otherwise null — meaning "no custom field
	 * found or set, use WordPress's own core field for this role" (fill_from_post()'s original,
	 * unchanged behavior for ordinary blog posts). "__core__" is a deliberate, explicit override —
	 * "always use WordPress's own field here," even if auto-discovery would have found something —
	 * as distinct from no override at all (which keeps auto-discovering going forward).
	 */
	private static function resolve_source_field( $post_type, $role ) {
		$overrides = self::get_mapping_overrides( $post_type );
		if ( array_key_exists( $role, $overrides ) ) {
			return '__core__' === $overrides[ $role ] ? null : $overrides[ $role ];
		}
		$discovered = self::discover_source_mapping( $post_type );
		return $discovered[ $role ] ?? null;
	}

	/** Powers the mapping settings box: what's currently mapped for this post type, plus every field it could be set to. */
	public static function get_source_mapping() {
		self::check();

		$post_type = sanitize_key( $_POST['post_type'] ?? 'post' );

		$discovered = self::discover_source_mapping( $post_type );
		$overrides  = self::get_mapping_overrides( $post_type );

		$core_labels = array(
			'title'       => "Post Title (WordPress's own)",
			'description' => "Excerpt / auto-summary (WordPress's own)",
			'image'       => "Featured Image (WordPress's own)",
			'link'        => "Permalink — this post's own link (WordPress's own)",
		);

		$roles = array();
		foreach ( self::FILL_ROLES as $role ) {
			$roles[ $role ] = array(
				'override'   => $overrides[ $role ] ?? '',
				'discovered' => $discovered[ $role ],
				'core_label' => $core_labels[ $role ],
			);
		}

		$fields = array_map( function ( $f ) {
			return array( 'name' => $f['name'], 'label' => $f['label'], 'type' => $f['type'] );
		}, self::collect_mappable_fields( $post_type ) );

		wp_send_json_success( array( 'roles' => $roles, 'fields' => $fields ) );
	}

	/** Saves a manual mapping override for one post type. An empty value for a role clears its override (back to automatic). */
	public static function save_source_mapping() {
		self::check();

		$post_type = sanitize_key( $_POST['post_type'] ?? '' );
		$values    = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? $_POST['mapping'] : array();
		if ( ! $post_type ) wp_send_json_error( array( 'message' => 'Missing content type.' ) );

		// Only roles with a real value get stored. This matters, not just tidiness: resolve_source_field()
		// uses array_key_exists() to decide whether a role has been overridden at all — a blank role
		// stored as '' would short-circuit straight to "always use WordPress's default" instead of
		// falling through to auto-discovery, silently breaking auto-discovery for every OTHER role the
		// moment just one role gets a manual override.
		$clean = array();
		foreach ( self::FILL_ROLES as $role ) {
			$val = sanitize_text_field( $values[ $role ] ?? '' );
			if ( '' !== $val ) $clean[ $role ] = $val;
		}

		$all = get_option( 'bsm_source_mappings', array() );
		if ( $clean ) {
			$all[ $post_type ] = $clean;
		} else {
			unset( $all[ $post_type ] ); // Everything cleared back to automatic — don't keep an empty entry around.
		}
		update_option( 'bsm_source_mappings', $all );

		wp_send_json_success( array( 'message' => 'Mapping saved.' ) );
	}

	/**
	 * Builds the fill result for one image sub-field: generates an independent, resized,
	 * meaningfully-named Media Library copy of the source post's featured image rather than
	 * reusing that attachment ID directly, so:
	 *   - the copy matches whatever size is already expected in this exact slot (the current
	 *     image's own dimensions, if one is set — otherwise the source image is used as-is), and
	 *   - it's tagged as plugin-generated (`_bsm_generated` meta) so it can be safely auto-deleted
	 *     later, either when this slot's image is replaced (see merge_row()) or if this fill is
	 *     abandoned without being saved (see discard_generated_images()) — WITHOUT ever risking
	 *     deletion of the original blog post's own featured image, which is never tagged this way.
	 * Falls back to the original attachment untouched (generated=false) if image processing
	 * isn't available on this server (e.g. no GD/Imagick) or the copy fails for any reason.
	 */
	private static function build_image_fill( $source_attachment_id, $current_raw, $target_post_id, $section_label, $source_post ) {
		$target_w = 0;
		$target_h = 0;
		if ( is_array( $current_raw ) ) {
			$target_w = (int) ( $current_raw['width'] ?? 0 );
			$target_h = (int) ( $current_raw['height'] ?? 0 );
		}

		$filename_base = self::build_generated_filename( $target_post_id, $section_label, $source_post );
		$new_id        = self::create_resized_copy( $source_attachment_id, $target_w, $target_h, $filename_base );

		if ( $new_id ) {
			return array( 'applies' => true, 'id' => $new_id, 'url' => wp_get_attachment_image_url( $new_id, 'medium' ) ?: '', 'generated' => true );
		}

		// Processing failed — fall back to the original image, unmodified and un-tagged.
		return array( 'applies' => true, 'id' => $source_attachment_id, 'url' => wp_get_attachment_image_url( $source_attachment_id, 'medium' ) ?: '', 'generated' => false );
	}

	/**
	 * Builds the "sitename_pagename_sectionname_sourceposttitle" filename base (each part
	 * slugified, no extension). Deliberately uses the SOURCE post's own title every time — not
	 * whatever title happens to already be saved in this slot — so re-filling from a different
	 * post always renames the image to match the post you just picked, not a stale leftover
	 * title from an earlier fill/save.
	 */
	private static function build_generated_filename( $target_post_id, $section_label, $source_post ) {
		$parts = array_filter( array(
			sanitize_title( get_bloginfo( 'name' ) ),
			sanitize_title( get_the_title( $target_post_id ) ),
			sanitize_title( $section_label ),
			sanitize_title( $source_post->post_title ),
		) );

		return $parts ? implode( '_', $parts ) : 'bsm-image';
	}

	/**
	 * Makes an independent copy of $source_attachment_id's file — resized/cropped to
	 * $target_w x $target_h if both are given, left at its original size otherwise — saved
	 * under a new, uniquely-disambiguated filename, and registered as a proper Media Library
	 * attachment (with full size metadata) tagged `_bsm_generated`. Returns the new attachment
	 * ID, or 0 if image processing isn't available or the copy/insert fails at any step.
	 */
	private static function create_resized_copy( $source_attachment_id, $target_w, $target_h, $filename_base ) {
		$source_path = get_attached_file( $source_attachment_id );
		if ( ! $source_path || ! file_exists( $source_path ) ) return 0;

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$editor = wp_get_image_editor( $source_path );
		if ( is_wp_error( $editor ) ) return 0;

		if ( $target_w && $target_h ) {
			$resized = $editor->resize( $target_w, $target_h, true ); // true = hard crop to the exact size.
			if ( is_wp_error( $resized ) ) return 0;
		}

		$ext         = pathinfo( $source_path, PATHINFO_EXTENSION ) ?: 'jpg';
		$upload_dir  = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) return 0;

		$unique_name = wp_unique_filename( $upload_dir['path'], sanitize_file_name( $filename_base ) . '.' . $ext );
		$new_path    = trailingslashit( $upload_dir['path'] ) . $unique_name;

		$saved = $editor->save( $new_path );
		if ( is_wp_error( $saved ) ) return 0;

		$attach_id = wp_insert_attachment( array(
			'post_mime_type' => $saved['mime-type'],
			'post_title'     => sanitize_text_field( str_replace( array( '-', '_' ), ' ', $filename_base ) ),
			'post_status'    => 'inherit',
			'post_content'   => '',
		), $saved['path'] );

		if ( ! $attach_id || is_wp_error( $attach_id ) ) return 0;

		$metadata = wp_generate_attachment_metadata( $attach_id, $saved['path'] );
		wp_update_attachment_metadata( $attach_id, $metadata );
		update_post_meta( $attach_id, '_bsm_generated', 1 );

		return $attach_id;
	}

	/**
	 * Deletes attachments the browser is discarding because a "fill from post" image was
	 * superseded (re-filled, or the editor was cancelled) before ever being saved. Only ever
	 * deletes attachments carrying the `_bsm_generated` marker — a real blog post's own featured
	 * image is never tagged that way, so it can never be removed through this endpoint.
	 */
	public static function discard_generated_images() {
		self::check();

		$ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', $_POST['ids'] ) : array();
		foreach ( $ids as $id ) {
			if ( $id && get_post_meta( $id, '_bsm_generated', true ) ) {
				wp_delete_attachment( $id, true );
			}
		}

		wp_send_json_success();
	}

	/**
	 * If $old_raw (an image sub-field's previous formatted value) is being replaced by a
	 * different attachment ID, and the OLD one is a plugin-generated copy (`_bsm_generated`),
	 * delete it — it was made solely for this slot, so once it's no longer referenced here it
	 * would otherwise sit in the Media Library unused forever. Never touches an attachment that
	 * isn't tagged this way (e.g. a real blog post's own featured image, or anything picked
	 * manually via the media library).
	 */
	private static function maybe_delete_replaced_generated_image( $old_raw, $new_id ) {
		$old_id = 0;
		if ( is_array( $old_raw ) ) {
			$old_id = (int) ( $old_raw['ID'] ?? ( $old_raw['id'] ?? 0 ) );
		} elseif ( is_numeric( $old_raw ) ) {
			$old_id = (int) $old_raw;
		}

		if ( $old_id && $old_id !== (int) $new_id && get_post_meta( $old_id, '_bsm_generated', true ) ) {
			wp_delete_attachment( $old_id, true );
		}
	}

	/* =========================================================
	 * SAVE — merge over the existing row so unsupported/unknown
	 * sub-fields (including nested containers) are never wiped out,
	 * then splice the result back into the full root field value.
	 * ========================================================= */

	public static function save_slot() {
		self::check();

		$post_id = absint( $_POST['post_id'] ?? 0 );
		$path    = self::parse_path( $_POST['path'] ?? '' );
		$values  = isset( $_POST['values'] ) && is_array( $_POST['values'] ) ? $_POST['values'] : array();

		if ( ! $post_id || ! $path ) wp_send_json_error( array( 'message' => 'Missing page or field path.' ) );

		$node = self::resolve_path( $post_id, $path );
		if ( ! $node ) wp_send_json_error( array( 'message' => 'Field not found — it may have changed since you opened this page.' ) );

		if ( in_array( $node['type'], array( '__row__', '__flexrow__', 'group' ), true ) ) {
			$existing  = is_array( $node['value'] ) ? $node['value'] : array();
			$schema    = self::describe_subfields( $node['sub_fields'], $existing );
			$new_value = self::merge_row( $existing, $schema, $values );

			if ( '__flexrow__' === $node['type'] ) {
				$last = end( $path );
				$new_value['acf_fc_layout'] = $existing['acf_fc_layout'] ?? ( $last['layout'] ?? '' );
			}
		} elseif ( in_array( $node['type'], array( 'repeater', 'flexible_content' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Choose a specific row before saving.' ) );
			return;
		} else {
			// Leaf scalar field.
			$leaf_name = end( $path )['name'];
			$new_value = self::cast_value( $node['type'], $values[ $leaf_name ] ?? '' );
			if ( 'image' === $node['type'] ) {
				self::maybe_delete_replaced_generated_image( $node['value'], $new_value );
			}
		}

		$root_value = self::set_by_key_path( $node['root_value'], $node['key_path'], $new_value );
		update_field( $node['root_name'], $root_value, $post_id );

		// NOTE: update_field()'s return value is deliberately ignored here. For a repeater or
		// flexible_content field, ACF's own update_value() writes each sub-field's data via its
		// own separate acf_update_value() call, then reports success/failure based on a totally
		// unrelated bookkeeping write: the row COUNT stored under the top-level field's own meta
		// key (see ACF core: pro/fields/class-acf-field-repeater.php update_value(), which
		// returns the row count, not a boolean). WordPress's update_metadata() returns false
		// whenever a meta value is unchanged — and editing an existing row's contents, without
		// adding or removing a row, is exactly that case. So update_field() reliably reports
		// "false" on the single most common edit (editing an existing row) even though the row's
		// data was written correctly. Since resolve_path() above already confirmed the field and
		// post exist, there's nothing meaningful left to gate on here.
		wp_send_json_success( array( 'message' => 'Saved successfully.' ) );
	}

	/**
	 * Start from the EXISTING row/group data and only overwrite keys we
	 * actually rendered as editable inputs. Anything not in $schema, any
	 * sub-field marked unsupported, and any nested container (browsed and
	 * saved separately, one level at a time) keeps its current value
	 * untouched.
	 */
	public static function merge_row( $existing, $schema, $incoming_values ) {
		$row = is_array( $existing ) ? $existing : array();
		foreach ( $schema as $sf ) {
			if ( ! $sf['supported'] ) continue; // Preserve whatever was already there.
			$key     = $sf['name'];
			$new_val = self::cast_value( $sf['type'], $incoming_values[ $key ] ?? '' );

			if ( 'image' === $sf['type'] ) {
				self::maybe_delete_replaced_generated_image( $existing[ $key ] ?? null, $new_val );
			}

			$row[ $key ] = $new_val;
		}
		return $row;
	}

	public static function cast_value( $type, $val ) {
		switch ( $type ) {
			case 'image':
				return $val === '' ? '' : absint( $val );
			case 'number':
				return is_numeric( $val ) ? $val + 0 : '';
			case 'true_false':
				return $val ? 1 : 0;
			case 'url':
				return esc_url_raw( wp_unslash( (string) $val ) );
			case 'email':
				return sanitize_email( wp_unslash( (string) $val ) );
			case 'wysiwyg':
				return wp_kses_post( wp_unslash( (string) $val ) );
			case 'textarea':
				return sanitize_textarea_field( wp_unslash( (string) $val ) );
			case 'text':
			case 'select':
			case 'date_picker':
			default:
				return sanitize_text_field( wp_unslash( (string) $val ) );
		}
	}
}
