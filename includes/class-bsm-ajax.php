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

	public static function init() {
		add_action( 'wp_ajax_bsm_get_post_types', array( __CLASS__, 'get_post_types' ) );
		add_action( 'wp_ajax_bsm_search_items', array( __CLASS__, 'search_items' ) );
		add_action( 'wp_ajax_bsm_get_field_groups', array( __CLASS__, 'get_field_groups' ) );
		add_action( 'wp_ajax_bsm_get_fields', array( __CLASS__, 'get_fields' ) );
		add_action( 'wp_ajax_bsm_get_slot', array( __CLASS__, 'get_slot' ) );
		add_action( 'wp_ajax_bsm_fill_from_post', array( __CLASS__, 'fill_from_post' ) );
		add_action( 'wp_ajax_bsm_save_slot', array( __CLASS__, 'save_slot' ) );
		add_action( 'wp_ajax_bsm_discard_generated_images', array( __CLASS__, 'discard_generated_images' ) );
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

		$out = self::collect_field_rows( $top_fields, $post_id, '' );

		wp_send_json_success( array( 'fields' => $out ) );
	}

	/**
	 * Builds the flat Step-3 list. Recurses through Group fields (and only
	 * Group fields) because ACF Groups are storage-transparent: a field
	 * nested inside a Group is still addressable directly by its own field
	 * name via get_field()/get_field_object(), exactly like a top-level
	 * field, no matter how many Groups deep it is. Repeater and Flexible
	 * Content are NOT recursed into here — their rows have real, indexed
	 * storage and are handled one level down in Step 4/5 instead (which in
	 * turn can recurse into further-nested containers inside those rows).
	 */
	private static function collect_field_rows( $fields, $post_id, $ancestry ) {
		$out = array();

		foreach ( $fields as $f ) {
			if ( in_array( $f['type'], self::SKIP_TYPES, true ) ) continue;

			$obj = get_field_object( $f['name'], $post_id, true, true );
			if ( ! $obj ) continue;

			$row = array(
				'name'     => $f['name'],
				'label'    => $f['label'],
				'type'     => $f['type'],
				'ancestry' => $ancestry, // e.g. "Healthcare Page Template" — shown so you know where this field lives.
			);

			if ( in_array( $f['type'], self::CONTAINER_TYPES, true ) ) {
				$row['is_container'] = true;

				if ( 'repeater' === $f['type'] ) {
					$rows           = is_array( $obj['value'] ) ? $obj['value'] : array();
					$row['count']   = count( $rows );
					$row['preview'] = self::build_preview( $rows, $obj['sub_fields'] );
					$out[] = $row;
				} elseif ( 'flexible_content' === $f['type'] ) {
					$rows           = is_array( $obj['value'] ) ? $obj['value'] : array();
					$row['count']   = count( $rows );
					$row['preview'] = self::build_preview_flex( $rows, $obj['layouts'] );
					$row['layouts'] = array_map( function ( $l ) {
						return array( 'name' => $l['name'], 'label' => $l['label'] );
					}, $obj['layouts'] );
					$out[] = $row;
				} elseif ( 'group' === $f['type'] ) {
					$row['count'] = 1;
					$out[] = $row; // Still offered as "edit this group's own direct fields in one form."
					// Also surface what's inside it as separate, independently-addressable entries.
					if ( ! empty( $obj['sub_fields'] ) ) {
						$nested_label = $ancestry ? $ancestry . ' → ' . $f['label'] : $f['label'];
						$out = array_merge( $out, self::collect_field_rows( $obj['sub_fields'], $post_id, $nested_label ) );
					}
				}
			} else {
				$row['is_container'] = false;
				$row['supported']    = in_array( $f['type'], self::EDITABLE_TYPES, true );
				$out[] = $row;
			}
		}

		return $out;
	}

	/** For a list of repeater rows, guess a display title + thumbnail per row (cosmetic only). */
	private static function build_preview( $rows, $sub_fields ) {
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

	private static function build_preview_flex( $rows, $layouts ) {
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

	private static function guess_display_keys( $sub_fields ) {
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

	private static function find_layout_def( $layouts, $layout_name ) {
		foreach ( $layouts as $l ) {
			if ( $l['name'] === $layout_name ) return $l;
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
	private static function resolve_path( $post_id, $path ) {
		if ( empty( $path ) ) return null;

		$root_name = $path[0]['name'];
		$obj = get_field_object( $root_name, $post_id, true, true );
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
	private static function set_by_key_path( $root, $key_path, $new_value ) {
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
	private static function describe_subfields( $sub_fields, $context_value = array() ) {
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
	 * "Fill from blog post" convenience
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

		$excerpt = has_excerpt( $post->ID ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
		$thumb_id = get_post_thumbnail_id( $post->ID );

		$out = array();
		foreach ( $schema as $sf ) {
			if ( ! empty( $sf['is_container'] ) || ! $sf['supported'] ) continue;
			$name = strtolower( $sf['name'] . ' ' . $sf['label'] );

			if ( 'image' === $sf['type'] || preg_match( '/image|photo|thumb/', $name ) ) {
				if ( $thumb_id ) {
					$out[ $sf['name'] ] = self::build_image_fill( $thumb_id, $existing_row[ $sf['name'] ] ?? null, $root_post_id, $node['label'], $post );
				}
			} elseif ( preg_match( '/title|heading/', $name ) ) {
				$out[ $sf['name'] ] = array( 'applies' => true, 'value' => $post->post_title );
			} elseif ( preg_match( '/desc|excerpt|summary|content/', $name ) && 'url' !== $sf['type'] ) {
				$out[ $sf['name'] ] = array( 'applies' => true, 'value' => $excerpt );
			} elseif ( 'url' === $sf['type'] || preg_match( '/link|url|permalink/', $name ) ) {
				$out[ $sf['name'] ] = array( 'applies' => true, 'value' => get_permalink( $post->ID ) );
			}
			// Anything else (type/category/button text/select/etc.) is intentionally left
			// alone — we don't guess values we can't be confident about.
		}

		wp_send_json_success( array( 'fills' => $out ) );
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
	private static function merge_row( $existing, $schema, $incoming_values ) {
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

	private static function cast_value( $type, $val ) {
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
