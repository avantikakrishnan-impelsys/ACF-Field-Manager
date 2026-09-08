(function ($) {
	'use strict';

	var state = {
		postType: 'page',
		postId: null,
		postLabel: '',
		groupKey: null,
		groupTitle: '',
		fieldsCache: [],   // last Step-3 response, reused so Step-4 doesn't need a refetch
		path: [],          // chain of hops from the top-level field down to whatever slot is open now:
		                   // [{ name, label, type, row, layout, layoutLabel, count, preview, layouts }, ...]
		editBaseDepth: 0,  // state.path.length at the moment the edit panel was freshly opened from Step 4
		currentSchema: [], // schema array behind the fields currently rendered, so nested-browse clicks can look sf up by index
		pendingGeneratedImageIds: [], // "fill from post" image copies not yet saved — discarded if superseded or the editor closes without saving
	};

	/** Deletes any not-yet-saved "fill from post" image copies (server re-checks before actually deleting anything). */
	function discardPendingImages() {
		if ( ! state.pendingGeneratedImageIds.length ) return;
		ajax( 'bsm_discard_generated_images', { ids: state.pendingGeneratedImageIds }, function () {}, function () {} );
		state.pendingGeneratedImageIds = [];
	}

	/* ---------------- helpers ---------------- */

	function ajax( action, data, done, fail ) {
		data = data || {};
		data.action = action;
		data.nonce = BSM.nonce;
		$.post( BSM.ajaxUrl, data )
			.done( function ( resp ) {
				if ( resp && resp.success ) {
					done( resp.data );
				} else {
					( fail || defaultFail )( resp && resp.data ? resp.data.message : 'Unknown error' );
				}
			} )
			.fail( function () {
				( fail || defaultFail )( 'Request failed. Check your connection.' );
			} );
	}

	function defaultFail( msg ) {
		alert( 'Error: ' + msg );
	}

	function escapeHtml( str ) {
		return $( '<div>' ).text( str === null || str === undefined ? '' : str ).html();
	}

	function setBreadcrumb( step ) {
		$( '#bsm-breadcrumb li' ).removeClass( 'is-active is-done' );
		$( '#bsm-breadcrumb li' ).each( function () {
			var s = parseInt( $( this ).data( 'step' ), 10 );
			if ( s < step ) $( this ).addClass( 'is-done' );
			if ( s === step ) $( this ).addClass( 'is-active' );
		} );
	}

	/** Hide this panel and everything after it, e.g. resetFrom(2) hides steps 2,3,4 and the editor. */
	function resetFrom( step ) {
		if ( step <= 2 ) $( '#bsm-step-2' ).hide();
		if ( step <= 3 ) $( '#bsm-step-3' ).hide();
		if ( step <= 4 ) $( '#bsm-step-4' ).hide();
		$( '#bsm-edit-panel' ).hide();
	}

	/** The path as sent to the server — just the fields resolve_path() needs. */
	function pathForServer() {
		return state.path.map( function ( h ) {
			return { name: h.name, row: h.row, layout: h.layout };
		} );
	}

	function pathHeading() {
		var parts = [ state.postLabel, state.groupTitle ];
		state.path.forEach( function ( hop ) {
			parts.push( hop.label + ( hop.layoutLabel ? ' (' + hop.layoutLabel + ')' : '' ) );
			if ( hop.row !== null && hop.row !== undefined ) {
				parts.push( 'Slot ' + ( hop.row + 1 ) );
			}
		} );
		return parts.join( ' → ' );
	}

	function describeContainer( f ) {
		if ( 'repeater' === f.type ) return 'Repeater — ' + f.count + ' row' + ( f.count === 1 ? '' : 's' );
		if ( 'group' === f.type ) return 'Group — single entry';
		if ( 'flexible_content' === f.type ) return 'Flexible Content — ' + f.count + ' item' + ( f.count === 1 ? '' : 's' );
		return f.type;
	}

	/* ---------------- generic searchable select2 (used twice: item picker, fill-from-post picker) ---------------- */

	function initSearchSelect( $el, getPostType, placeholder, onChange ) {
		$el.html( '' );
		if ( $el.data( 'select2' ) ) $el.select2( 'destroy' );

		$el.select2( {
			width: '100%',
			placeholder: placeholder,
			allowClear: true,
			ajax: {
				transport: function ( params, success, failure ) {
					ajax( 'bsm_search_items', { post_type: getPostType(), term: params.data.term || '' }, function ( data ) {
						success( { results: data.results } );
					}, failure );
				},
				delay: 250,
			},
			minimumInputLength: 0,
		} );

		$el.off( 'change.bsm' ).on( 'change.bsm', function () {
			var id = $( this ).val();
			if ( id ) onChange( id, $( this ).find( ':selected' ).text() );
		} );
	}

	/* ---------------- STEP 1: post type + item ---------------- */

	function loadPostTypes() {
		ajax( 'bsm_get_post_types', {}, function ( data ) {
			var $sel = $( '#bsm-post-type-select' ).html( '' );
			var $fillSel = $( '#bsm-fill-post-type' ).html( '' );
			data.types.forEach( function ( t ) {
				$sel.append( '<option value="' + t.slug + '">' + escapeHtml( t.label ) + '</option>' );
				$fillSel.append( '<option value="' + t.slug + '">' + escapeHtml( t.label ) + '</option>' );
			} );
			$fillSel.val( 'post' );
			state.postType = $sel.val();
			bindItemSelect();
		} );
	}

	function bindItemSelect() {
		initSearchSelect(
			$( '#bsm-item-select' ),
			function () { return state.postType; },
			'Type to search…',
			function ( id, label ) {
				state.postId = id;
				state.postLabel = label;
				resetFrom( 2 );
				$( '#bsm-step-2' ).show();
				setBreadcrumb( 2 );
				loadGroups();
			}
		);
	}

	$( document ).on( 'change', '#bsm-post-type-select', function () {
		state.postType = $( this ).val();
		state.postId = null;
		resetFrom( 2 );
		bindItemSelect();
	} );

	/* ---------------- STEP 2: field groups ---------------- */

	function loadGroups() {
		var $area = $( '#bsm-groups-area' ).html( '<p>Loading…</p>' );
		ajax( 'bsm_get_field_groups', { post_id: state.postId }, function ( data ) {
			if ( ! data.groups.length ) {
				$area.html( '<p class="bsm-error">No ACF field groups are attached to this page.</p>' );
				return;
			}
			var html = '<div class="bsm-card-grid">';
			data.groups.forEach( function ( g ) {
				html += '<button type="button" class="bsm-card bsm-pick-group" data-key="' + escapeHtml( g.key ) + '" data-title="' + escapeHtml( g.title ) + '">' + escapeHtml( g.title ) + '</button>';
			} );
			html += '</div>';
			$area.html( html );
		} );
	}

	$( document ).on( 'click', '.bsm-pick-group', function () {
		$( '.bsm-pick-group' ).removeClass( 'is-selected' );
		$( this ).addClass( 'is-selected' );
		state.groupKey = $( this ).data( 'key' );
		state.groupTitle = $( this ).data( 'title' );
		resetFrom( 3 );
		$( '#bsm-step-3' ).show();
		setBreadcrumb( 3 );
		loadFields();
	} );

	/* ---------------- STEP 3: fields within the group ---------------- */

	function loadFields() {
		var $area = $( '#bsm-fields-area' ).html( '<p>Loading…</p>' );
		ajax( 'bsm_get_fields', { post_id: state.postId, group_key: state.groupKey }, function ( data ) {
			state.fieldsCache = data.fields;
			if ( ! data.fields.length ) {
				$area.html( '<p class="bsm-error">No usable fields found in this group.</p>' );
				return;
			}
			var html = '<div class="bsm-card-grid">';
			data.fields.forEach( function ( f, i ) {
				var sub = f.is_container ? describeContainer( f ) : ( f.supported ? f.type : f.type + ' (not supported yet)' );
				var disabled = ( ! f.is_container && ! f.supported ) ? ' disabled' : '';
				html += '<button type="button" class="bsm-card bsm-pick-field" data-index="' + i + '"' + disabled + '>';
				if ( f.ancestry ) html += '<span class="bsm-card-ancestry">inside ' + escapeHtml( f.ancestry ) + '</span>';
				html += '<span class="bsm-card-title">' + escapeHtml( f.label ) + '</span>';
				html += '<span class="bsm-card-sub">' + escapeHtml( sub ) + '</span>';
				html += '</button>';
			} );
			html += '</div>';
			$area.html( html );
		} );
	}

	$( document ).on( 'click', '.bsm-pick-field', function () {
		if ( $( this ).is( ':disabled' ) ) return;
		$( '.bsm-pick-field' ).removeClass( 'is-selected' );
		$( this ).addClass( 'is-selected' );

		var f = state.fieldsCache[ $( this ).data( 'index' ) ];
		state.path = [ { name: f.name, label: f.label, type: f.type, row: null, layout: null } ];

		resetFrom( 4 );
		$( '#bsm-step-4' ).show();
		setBreadcrumb( 4 );
		renderSlots( f );
	} );

	/* ---------------- STEP 4: slot list (repeater rows / flex layouts / single entries) ---------------- */
	/* Reused for BOTH the top-level Step-4 panel and for browsing into a container nested inside
	   a row while editing (rendered inside the edit panel instead, with a Back button). */

	function slotCardHtml( row, index, layoutLabel ) {
		var html = '<button type="button" class="bsm-card bsm-pick-slot" data-row="' + index + '">';
		if ( row.thumb ) {
			html += '<img src="' + row.thumb + '" alt="">';
		} else {
			html += '<div class="bsm-slot-noimg">No image</div>';
		}
		html += '<span class="bsm-card-title">Slot ' + ( index + 1 ) + '</span>';
		html += '<span class="bsm-card-sub">' + escapeHtml( row.title ) + '</span>';
		if ( layoutLabel ) html += '<span class="bsm-card-tag">' + escapeHtml( layoutLabel ) + '</span>';
		html += '</button>';
		return html;
	}

	function renderSlotsHtml( f ) {
		var html = '<div class="bsm-slot-picker">';

		if ( 'group' === f.type ) {
			html += '<div class="bsm-card-grid">';
			html += '<button type="button" class="bsm-card bsm-pick-slot" data-row="">' +
				'<span class="bsm-card-title">Single entry</span>' +
				'<span class="bsm-card-sub">This section does not repeat</span></button>';
			html += '</div>';
		} else if ( 'repeater' === f.type ) {
			html += '<div class="bsm-card-grid">';
			f.preview.forEach( function ( row ) {
				html += slotCardHtml( row, row.index );
			} );
			html += '<button type="button" class="bsm-card bsm-add-slot" data-row="' + f.count + '">+ Add New Row</button>';
			html += '</div>';
		} else if ( 'flexible_content' === f.type ) {
			html += '<div class="bsm-card-grid">';
			f.preview.forEach( function ( row ) {
				html += slotCardHtml( row, row.index, row.layout );
			} );
			html += '</div>';
			if ( f.layouts && f.layouts.length ) {
				html += '<div class="bsm-add-flex-row">';
				html += '<select class="bsm-new-layout-select">';
				f.layouts.forEach( function ( l ) {
					html += '<option value="' + escapeHtml( l.name ) + '">' + escapeHtml( l.label ) + '</option>';
				} );
				html += '</select> <button type="button" class="button bsm-add-flex-slot" data-row="' + f.count + '">+ Add New</button>';
				html += '</div>';
			}
		} else {
			// Plain scalar field — one trivial "slot".
			html += '<div class="bsm-card-grid">';
			html += '<button type="button" class="bsm-card bsm-pick-slot" data-row="">' +
				'<span class="bsm-card-title">' + escapeHtml( f.label ) + '</span>' +
				'<span class="bsm-card-sub">' + escapeHtml( f.type ) + '</span></button>';
			html += '</div>';
		}

		html += '</div>';
		return html;
	}

	function renderSlots( f ) {
		$( '#bsm-slots-area' ).html( renderSlotsHtml( f ) );
	}

	$( document ).on( 'click', '.bsm-pick-slot, .bsm-add-slot', function () {
		var rowVal = $( this ).data( 'row' );
		var nested = $( this ).closest( '#bsm-edit-panel' ).length > 0;
		var hop = state.path[ state.path.length - 1 ];
		hop.row = ( rowVal === '' || rowVal === undefined ) ? null : parseInt( rowVal, 10 );
		hop.layout = null;
		openEditor( ! nested );
	} );

	$( document ).on( 'click', '.bsm-add-flex-slot', function () {
		var nested = $( this ).closest( '#bsm-edit-panel' ).length > 0;
		var $scope = $( this ).closest( '.bsm-slot-picker' );
		var hop = state.path[ state.path.length - 1 ];
		hop.row = parseInt( $( this ).data( 'row' ), 10 );
		hop.layout = $scope.find( '.bsm-new-layout-select' ).val();
		openEditor( ! nested );
	} );

	/* ---------------- STEP 5: edit form (recursive — a row's fields can themselves contain
	   nested repeaters/groups/flexible content, browsed the same way as Step 4) ---------------- */

	function backButtonHtml() {
		if ( state.path.length <= state.editBaseDepth ) return '';
		return '<p><button type="button" class="button bsm-nested-back">&larr; Back</button></p>';
	}

	$( document ).on( 'click', '.bsm-nested-back', function () {
		discardPendingImages(); // any unsaved "fill from post" image in the view we're leaving is now abandoned
		state.path.pop();
		openEditor( false );
	} );

	/**
	 * Fetches and renders whatever state.path currently points to.
	 * isTop=true means "this is a fresh open from Step 4" — it (re)opens the
	 * edit panel and resets the base depth used to decide when Back should show.
	 */
	function openEditor( isTop ) {
		if ( isTop ) {
			discardPendingImages(); // leftovers from a previous slot that was never saved
			state.editBaseDepth = state.path.length;
			$( '#bsm-result-msg' ).empty();
			$( '#bsm-edit-panel' ).show();
			$( 'html, body' ).animate( { scrollTop: $( '#bsm-edit-panel' ).offset().top - 40 }, 200 );
		}

		$( '#bsm-edit-fields' ).html( '<p>Loading current values…</p>' );

		ajax( 'bsm_get_slot', { post_id: state.postId, path: JSON.stringify( pathForServer() ) }, function ( data ) {
			var hop = state.path[ state.path.length - 1 ];

			if ( 'rows' === data.mode ) {
				hop.count = data.count;
				hop.preview = data.preview;
				hop.layouts = data.layouts;
				$( '#bsm-edit-heading' ).text( pathHeading() );
				$( '#bsm-edit-fields' ).html( backButtonHtml() + renderSlotsHtml( hop ) );
				return;
			}

			if ( data.layout_label ) hop.layoutLabel = data.layout_label;

			$( '#bsm-edit-heading' ).text( pathHeading() );
			$( '#bsm-edit-fields' ).html( backButtonHtml() );
			renderEditFields( data.schema, data.values );
			bindFillFromPost();
		} );
	}

	function renderEditFields( schema, values ) {
		state.currentSchema = schema;
		var html = '';
		schema.forEach( function ( sf, i ) {
			html += '<div class="bsm-field-row">';
			html += '<label>' + escapeHtml( sf.label ) + ' <code>' + escapeHtml( sf.name ) + '</code></label>';

			if ( sf.is_container ) {
				html += '<button type="button" class="button bsm-browse-nested" data-index="' + i + '">Browse ' + escapeHtml( describeContainer( sf ) ) + ' &rarr;</button>';
				html += '</div>';
				return;
			}

			if ( ! sf.supported ) {
				html += '<p class="bsm-unsupported">Field type "' + escapeHtml( sf.type ) + '" isn\'t editable in this tool yet — its current value will be left untouched.</p>';
				html += '</div>';
				return;
			}

			var val = values[ sf.name ] || {};

			if ( 'image' === sf.type ) {
				html += '<div class="bsm-image-field">';
				html += '<img class="bsm-image-preview" src="' + escapeHtml( val.url || '' ) + '" style="' + ( val.url ? '' : 'display:none;' ) + '">';
				html += '<div class="bsm-image-none" style="' + ( val.url ? 'display:none;' : '' ) + '">No image set</div>';
				html += '<input type="hidden" data-field="' + escapeHtml( sf.name ) + '" value="' + escapeHtml( val.id || '' ) + '">';
				html += '<div class="bsm-image-buttons"><button type="button" class="button bsm-choose-image">Choose Image</button> <button type="button" class="button bsm-remove-image">Remove</button></div>';
				html += '</div>';
			} else if ( 'select' === sf.type && sf.choices ) {
				html += '<select data-field="' + escapeHtml( sf.name ) + '">';
				$.each( sf.choices, function ( choiceVal, choiceLabel ) {
					html += '<option value="' + escapeHtml( choiceVal ) + '"' + ( String( val.raw ) === String( choiceVal ) ? ' selected' : '' ) + '>' + escapeHtml( choiceLabel ) + '</option>';
				} );
				html += '</select>';
			} else if ( 'true_false' === sf.type ) {
				html += '<input type="checkbox" data-field="' + escapeHtml( sf.name ) + '"' + ( val.raw ? ' checked' : '' ) + '>';
			} else if ( 'textarea' === sf.type || 'wysiwyg' === sf.type ) {
				html += '<textarea data-field="' + escapeHtml( sf.name ) + '" rows="4">' + escapeHtml( val.raw || '' ) + '</textarea>';
				if ( 'wysiwyg' === sf.type ) html += '<p class="description">Rich text field — edited here as raw text/HTML.</p>';
			} else {
				var inputType = { number: 'number', url: 'url', email: 'email' }[ sf.type ] || 'text';
				var stepAttr = 'number' === sf.type ? ' step="any"' : '';
				html += '<input type="' + inputType + '"' + stepAttr + ' data-field="' + escapeHtml( sf.name ) + '" value="' + escapeHtml( val.raw || '' ) + '">';
				if ( 'date_picker' === sf.type ) html += '<p class="description">Plain text — match whatever date format is already used in this field.</p>';
			}

			html += '</div>';
		} );
		$( '#bsm-edit-fields' ).append( html );
	}

	$( document ).on( 'click', '.bsm-browse-nested', function () {
		var sf = state.currentSchema[ $( this ).data( 'index' ) ];
		var hop = { name: sf.name, label: sf.label, type: sf.type, row: null, layout: null, count: sf.count, preview: sf.preview, layouts: sf.layouts };
		state.path.push( hop );

		if ( 'group' === sf.type ) {
			// A Group is always a single entry — skip straight to its own fields.
			openEditor( false );
		} else {
			// Repeater / flexible content — use the count/preview already returned inline, no extra round trip.
			$( '#bsm-edit-heading' ).text( pathHeading() );
			$( '#bsm-edit-fields' ).html( backButtonHtml() + renderSlotsHtml( hop ) );
		}
	} );

	function bindFillFromPost() {
		initSearchSelect(
			$( '#bsm-fill-post-search' ),
			function () { return $( '#bsm-fill-post-type' ).val(); },
			'Search posts to pull values from…',
			function ( postId ) {
				var payload = {
					post_id: state.postId,
					path: JSON.stringify( pathForServer() ),
					source_post_id: postId,
				};

				ajax( 'bsm_fill_from_post', payload, function ( data ) {
					discardPendingImages(); // this fill supersedes anything an earlier, unsaved fill generated
					$.each( data.fills, function ( key, fill ) {
						var $el = $( '[data-field="' + key + '"]' );
						if ( ! $el.length ) return;
						if ( $el.closest( '.bsm-image-field' ).length ) {
							$el.val( fill.id );
							$el.closest( '.bsm-image-field' ).find( '.bsm-image-preview' ).attr( 'src', fill.url ).show();
							$el.closest( '.bsm-image-field' ).find( '.bsm-image-none' ).hide();
							if ( fill.generated && fill.id ) state.pendingGeneratedImageIds.push( fill.id );
						} else {
							$el.val( fill.value );
						}
					} );
				} );
			}
		);
	}

	/* ---------------- image picker (WP media library) ---------------- */

	var mediaFrame = null;

	$( document ).on( 'click', '.bsm-choose-image', function () {
		var $wrap = $( this ).closest( '.bsm-image-field' );

		mediaFrame = wp.media( { title: 'Choose Image', button: { text: 'Use this image' }, multiple: false } );
		mediaFrame.off( 'select' ).on( 'select', function () {
			var att = mediaFrame.state().get( 'selection' ).first().toJSON();
			var url = ( att.sizes && att.sizes.medium ) ? att.sizes.medium.url : att.url;
			$wrap.find( '[data-field]' ).val( att.id );
			$wrap.find( '.bsm-image-preview' ).attr( 'src', url ).show();
			$wrap.find( '.bsm-image-none' ).hide();
		} );
		mediaFrame.open();
	} );

	$( document ).on( 'click', '.bsm-remove-image', function () {
		var $wrap = $( this ).closest( '.bsm-image-field' );
		$wrap.find( '[data-field]' ).val( '' );
		$wrap.find( '.bsm-image-preview' ).hide();
		$wrap.find( '.bsm-image-none' ).show();
	} );

	/* ---------------- save / cancel ---------------- */

	function gatherValues() {
		var values = {};
		$( '#bsm-edit-fields [data-field]' ).each( function () {
			var $el = $( this );
			var key = $el.data( 'field' );
			values[ key ] = $el.is( ':checkbox' ) ? ( $el.is( ':checked' ) ? 1 : 0 ) : $el.val();
		} );
		return values;
	}

	/** True if any key in the submitted values equals this attachment id (i.e. it actually got saved). */
	function valuesContainId( values, id ) {
		for ( var key in values ) {
			if ( values.hasOwnProperty( key ) && String( values[ key ] ) === String( id ) ) return true;
		}
		return false;
	}

	$( document ).on( 'click', '#bsm-edit-cancel', function () {
		discardPendingImages();
		state.path = state.path.slice( 0, 1 );
		$( '#bsm-edit-panel' ).hide();
	} );

	$( document ).on( 'click', '#bsm-edit-save', function () {
		var $btn = $( this ).prop( 'disabled', true ).text( 'Saving…' );

		var payload = {
			post_id: state.postId,
			path: JSON.stringify( pathForServer() ),
			values: gatherValues(),
		};

		ajax( 'bsm_save_slot', payload, function ( data ) {
			// A generated image that made it into the submitted values is now genuinely in use —
			// leave it alone (a future edit that replaces it is handled server-side instead). Any
			// OTHER pending one was fetched by "fill from post" but ultimately not used in this
			// save (removed, or overridden by a manual pick) — discard it now instead of never.
			var stillPending = state.pendingGeneratedImageIds.filter( function ( id ) {
				return ! valuesContainId( payload.values, id );
			} );
			state.pendingGeneratedImageIds = [];
			if ( stillPending.length ) {
				ajax( 'bsm_discard_generated_images', { ids: stillPending }, function () {}, function () {} );
			}

			$( '#bsm-result-msg' ).html( '<p class="bsm-success">' + escapeHtml( data.message ) + '</p>' );
			$btn.text( 'Save Changes' ).prop( 'disabled', false );

			if ( state.path.length > state.editBaseDepth ) {
				// Saved a nested slot — pop back to its parent so the surrounding row is visibly intact.
				setTimeout( function () {
					state.path.pop();
					$( '#bsm-result-msg' ).empty();
					openEditor( false );
				}, 900 );
			} else {
				state.path = state.path.slice( 0, 1 );
				loadFields(); // Refresh counts/previews so the slot list reflects the change.
				setTimeout( function () { $( '#bsm-edit-panel' ).hide(); }, 1200 );
			}
		}, function ( msg ) {
			$( '#bsm-result-msg' ).html( '<p class="bsm-error">' + escapeHtml( msg ) + '</p>' );
			$btn.text( 'Save Changes' ).prop( 'disabled', false );
		} );
	} );

	/* ---------------- boot ---------------- */

	$( function () {
		if ( $( '#bsm-post-type-select' ).length ) {
			loadPostTypes();
		}
	} );

})( jQuery );
