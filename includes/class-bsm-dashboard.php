<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Single screen, fully dynamic. Flow:
 *   1. Pick a content type + item (e.g. Page: "Healthcare")
 *   2. Pick an ACF Field Group attached to it (e.g. "Healthcare Page Template")
 *   3. Pick a Field within that group (e.g. "Insights & Impact")
 *   4. Pick a Slot (repeater row / flexible layout instance / the field itself if not repeating)
 *   5. See current values, optionally pull fresh values from a published blog post,
 *      hand-edit anything, save.
 *
 * Nothing here is stored — every step reads ACF's live structure via AJAX.
 */
class BSM_Dashboard {

	public static function init() {}

	public static function render() {
		if ( ! current_user_can( 'edit_pages' ) ) return;
		?>
		<div class="wrap bsm-wrap">
			<h1>ACF Field Manager <span class="bsm-version">v<?php echo esc_html( BSM_VERSION ); ?></span></h1>
			<p class="description">Browse to any ACF field on any page and replace its contents — live, with no setup.</p>

			<ol class="bsm-breadcrumb" id="bsm-breadcrumb">
				<li class="is-active" data-step="1">1. Page</li>
				<li data-step="2">2. Field Group</li>
				<li data-step="3">3. Field</li>
				<li data-step="4">4. Slot</li>
			</ol>

			<!-- Step 1: content type + item -->
			<div class="bsm-panel" id="bsm-step-1">
				<h2>Step 1 — Choose a page</h2>
				<label>Content type</label>
				<select id="bsm-post-type-select"></select>

				<label>Page / item</label>
				<select id="bsm-item-select" style="width:100%;"></select>
			</div>

			<!-- Step 2: field group -->
			<div class="bsm-panel" id="bsm-step-2" style="display:none;">
				<h2>Step 2 — Choose an ACF Field Group</h2>
				<div id="bsm-groups-area"><p class="description">Loading…</p></div>
			</div>

			<!-- Step 3: field within group -->
			<div class="bsm-panel" id="bsm-step-3" style="display:none;">
				<h2>Step 3 — Choose a Field</h2>
				<div id="bsm-fields-area"><p class="description">Loading…</p></div>
			</div>

			<!-- Step 4: slot list -->
			<div class="bsm-panel" id="bsm-step-4" style="display:none;">
				<h2>Step 4 — Choose a Slot</h2>
				<div id="bsm-slots-area"><p class="description">Loading…</p></div>
			</div>

			<!-- Step 5: edit form -->
			<div class="bsm-panel" id="bsm-edit-panel" style="display:none;">
				<h2 id="bsm-edit-heading">Edit</h2>

				<div class="bsm-fill-box">
					<strong>Optional — fill from a published blog post, or insert a form:</strong>
					<div class="bsm-fill-row">
						<select id="bsm-fill-post-type"></select>
						<select id="bsm-fill-post-search" style="width:60%;"></select>
					</div>
					<p class="description">For a post/page: overwrites only the fields it can confidently map (title, excerpt, featured image, link). For a form: drops its <code>[wpforms id="…"]</code> shortcode into any field whose name mentions "form" or "shortcode". Everything else is left as-is — edit it by hand below.</p>

					<p><button type="button" class="button-link" id="bsm-mapping-toggle">⚙ Adjust which fields get pulled in for this content type</button></p>
					<div class="bsm-mapping-box" id="bsm-mapping-box" style="display:none;">
						<p class="description">Automatic by default — this reads the content type's own fields and guesses. Only set something below if a guess is wrong; leave "(Automatic — …)" selected otherwise.</p>
						<div id="bsm-mapping-fields"><p class="description">Loading…</p></div>
						<p class="bsm-actions"><button type="button" class="button button-primary" id="bsm-mapping-save">Save Mapping</button></p>
						<div id="bsm-mapping-result"></div>
					</div>
				</div>

				<div id="bsm-edit-fields"></div>

				<p class="bsm-actions">
					<button type="button" class="button" id="bsm-edit-cancel">Cancel</button>
					<button type="button" class="button button-primary" id="bsm-edit-save">Save Changes</button>
				</p>
				<div id="bsm-result-msg"></div>
			</div>
		</div>
		<?php
	}
}
