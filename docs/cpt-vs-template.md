# CPT vs Template Decision Guide

When to use a Custom Post Type (CPT) vs a Page Template in MetaManager.

## Use a CPT When:

- The content type is created and managed as **discrete entries** (multiple items)
- Each entry has its **own structured data fields** that need a dedicated metabox
- The content type has a **meaningful archive page** (listing of all entries)
- **Multiple entries** of the same type exist on a site
- The content represents **things the business offers** (trips, services, events, courses)

**Examples:**
- `mm_event` — multiple events with dates, locations, pricing
- `mm_service` — multiple services with types, areas, pricing
- `mm_trip` — multiple bookable trips with departure, destination, capacity
- `mm_vessel` — multiple vessels with specs
- `mm_course` — multiple courses with credentials, duration
- `mm_destination` — multiple destinations with coordinates, attractions
- `mm_how_to` — multiple guides with steps
- `mm_faq_page` — multiple FAQ pages with Q&A pairs

## Use a Page Template When:

- The page is a **singleton** — one per site
- The schema data is **auto-generated from Business Profile settings** or from other CPTs
- The page serves an **aggregating role** (pulls from other CPTs)
- The content represents **site-level pages** (about, contact, calendar)

**Examples:**
- `mm-about` (AboutPage) — one about page, auto-generated from business profile
- `mm-contact` (ContactPage) — one contact page, auto-generated from business profile
- `mm-calendar` (Calendar) — one calendar page, pulls from mm_event CPT
- `mm-area-guide` (TouristDestination) — one area guide page, pulls from mm_destination CPT
- `mm-venue` (Place) — one venue page, pulls from mm_event CPT

## Use Post Type Override When:

- The content maps to **BlogPosting, WebPage, or other types** that don't need custom fields
- The **Schema admin tab** "Default Schema Type per Post Type" setting handles this
- The content uses **standard WordPress fields** (title, editor, excerpt, featured image)

**Examples:**
- Blog posts → `BlogPosting` (default)
- Generic pages → `WebPage` (default)
- Any post type → override via Schema admin tab dropdown

## Decision Flowchart

```
Is this a one-per-site page (about, contact, calendar)?
  → Yes → Use Page Template
  → No  → Does it need custom structured data fields?
            → Yes → Use CPT
            → No  → Use Post Type Override (Schema admin tab)
```

## CPT Registration Pattern

All CPTs follow the same pattern in `MM_Schema_Post_Types`:

```php
private const TYPES = [
    'mm_slug' => [ 'SchemaType', [ 'title', 'editor', 'thumbnail', 'excerpt' ], 'dashicon' ],
];
```

- `mm_slug` — WordPress post type slug (prefixed with `mm_`)
- `SchemaType` — schema.org type label
- Supports array — WordPress features (title, editor, thumbnail, excerpt)
- Dashicon — admin menu icon (without `dashicons-` prefix)

The `register_post_types()` method loops TYPES and registers each with:
- `public => true`
- `has_archive => true`
- `rewrite => slug` (strips `mm_` prefix)
- `show_in_rest => true` (Gutenberg compatible)

## Page Template Registration Pattern

All page templates follow the same pattern:

```php
public const TEMPLATES = [
    'mm-slug' => 'SchemaType',
];
```

Templates are loaded via the `template_include` filter in `load_page_template()`. The plugin provides its own template files — no theme dependency.
