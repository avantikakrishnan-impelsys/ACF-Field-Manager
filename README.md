# ACF Field Manager (v2 — fully dynamic, no setup)

(Folder/file names still say "blog-slot-manager" internally — this is a
display-name-only rename, see the plugin header for details.)

Browse **Page → ACF Field Group → Field → Slot**, see the current live
values, and replace them — without ever opening ACF's field editor or
typing an ACF field name. Nothing is pre-configured or stored; every
screen reads your site's actual ACF structure at the moment you view it.

## Requirements

- WordPress
- Advanced Custom Fields (free or Pro) — **active**

## Install

1. Upload the `blog-slot-manager` folder to `wp-content/plugins/` (or
   **Plugins → Add New → Upload Plugin** with the zip).
2. Activate **ACF Field Manager**.
3. Open **ACF Field Manager** in the left admin menu — that's the only
   screen; there's no separate settings page anymore.

## How it works — the 5-step flow

1. **Page** — pick a content type (Page, Post, or any other public post
   type on your site) and search for the specific item, e.g. "Healthcare."
2. **Field Group** — every ACF field group actually attached to that page
   is listed, e.g. "Healthcare Page Template."
3. **Field** — every top-level field inside that group is listed:
   repeaters, groups, flexible content, and plain fields, with a live
   count (e.g. "Repeater — 4 rows"). Field types this tool can't edit yet
   are still shown, just greyed out, so you always see the full picture.
4. **Slot** — for a repeater or flexible content field, every current row
   is listed with a thumbnail and title pulled live from the data (plus
   "+ Add New"). For a group or plain field, there's just one slot, since
   it doesn't repeat.
5. **Edit** — the current values of every sub-field are shown, pre-filled
   and editable. There's an optional **"fill from a published blog
   post"** search box: pick a post and it overwrites only the fields it
   can confidently map (title, excerpt, featured image, permalink) —
   everything else (like a "Type" or "Button Text" field) is left exactly
   as it was, for you to review or edit by hand. Image fields have a
   **Choose Image** button that opens the normal WordPress media library.

   **Featured images pulled via "fill from post" are never reused
   as-is.** The plugin makes an independent Media Library copy first:
   resized/cropped to match whatever image is already in that slot (if
   one is set — otherwise it's used at its original size), and named
   `sitename_pagename_sectionname_sourceposttitle` so it's identifiable in
   the Media Library — always based on the post you just picked, not
   whatever title happened to be saved in that slot before.
   That copy is tagged internally as plugin-generated, so if it's later
   replaced — by filling from a different post, or by manually picking a
   different image — the old copy is deleted automatically instead of
   piling up unused. A copy that's fetched but never actually saved (you
   cancel, or fill again before saving) is cleaned up the same way. Manual
   **Choose Image** picks are never touched by any of this — they're used
   exactly as selected. (If a copy can't be created — no image-processing
   library available — the original image is used untouched instead, and
   it won't be auto-deleted later.)

   If a sub-field is itself a repeater, group, or flexible content field
   (nested inside the row you're editing), it shows a **Browse →** button
   instead of an input — click it to drill into that nested container,
   pick or add a row, and edit it, with a **← Back** button to return to
   the parent row. This works at any nesting depth (a repeater inside a
   group inside a repeater, etc.) and saving at any depth only ever
   touches that exact nested slot — everything else on the page is left
   untouched.

Click **Save Changes** and it writes back through ACF's own API
(`update_field()`), directly to that specific row/field — nothing else on
the page is touched.

## What it can edit directly

`text`, `textarea`, `wysiwyg` (as raw HTML/text), `url`, `email`,
`number`, `date_picker` (as plain text — match your existing format),
`select`, `true_false`, `image`.

## What it lists but doesn't edit (yet)

Anything else — `gallery`, `relationship`, `post_object`, `taxonomy`,
`file`, `google_map`, etc. These are shown in the field/sub-field list so
you always see everything that exists, just marked as not editable here.

(A `repeater`/`group`/`flexible_content` nested inside another
repeater/group row *is* supported — see the **Browse →** note in step 5
above, not this list.)

**Nothing unsupported is ever overwritten.** When saving a repeater row or
group, the plugin starts from the row's *existing* data and only replaces
the specific sub-fields it rendered as inputs — any sub-field type it
doesn't support keeps its current value untouched, even though it isn't
shown as an editable input.

## Safety notes

- **This tool has broad reach on purpose** — once installed, any user who
  can `edit_pages` can browse to and edit *any* ACF field on *any* page,
  not just one pre-approved section. Consider restricting the plugin
  page's capability (`edit_pages` in `blog-slot-manager.php` →
  `register_menu()`) if you want to limit it to specific roles.
- **Test on staging first.** It writes live ACF data directly.
- The "fill from blog post" search defaults to searching `post` type
  content but you can switch the type dropdown next to it.
- Flexible Content is supported per-layout: editing an existing row
  auto-detects its layout; adding a new row asks you to pick which layout
  first, since different layouts can have completely different fields.

## Extending later

- **Bulk/auto-distribution**: hook into `publish_post` to suggest (or
  auto-fill) a slot based on category/tags the moment a blog goes live.
- **Per-role restriction**: swap the `edit_pages` capability check for a
  custom capability if only certain editors should have access.
- **Canva integration**: once this is solid, wire in auto-generated social
  graphics for each newly distributed blog.
