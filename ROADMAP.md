# Metamanager Roadmap

Last updated 2026-08-09.

---

## What This Is

Metamanager manages metadata for WordPress media and pages. It extracts, normalizes, and synchronizes metadata across file system, database, and frontend output. The daemon layer handles the heavy lifting (ExifTool embed, lossless compression, ffmpeg remux). The plugin layer handles WordPress integration (admin UI, job queue, structured data, frontend output).

---

## Repository Overview

| Repo | Branch | Version | Purpose |
|------|--------|---------|---------|
| `metamanager-plugin` | dev/test/main | Auto-bumped by CI | WordPress plugin: metadata sync, frontend output, admin UI, job queue |
| `metamanager` | dev/test/main | Auto-bumped by CI | Daemon scripts (meta embed, compression), apt server deployment, systemd units |

## Architecture

```
WordPress Plugin (PHP)                    OS Daemons (Bash)
┌─────────────────────────────┐          ┌─────────────────────────────┐
│ MM_Admin                    │          │ metamanager-meta-daemon.sh  │
│ MM_Metadata (field defs)    │   jobs   │  - inotifywait watcher      │
│ MM_Job_Queue (write/claim)  │ ──────> │  - ExifTool embed           │
│ MM_DB (log/query)           │ <────── │  - writes result JSON       │
│ MM_Cron (import results)    │ results │                             │
│                             │          │ metamanager-compress-daemon │
│ MM_Media_Detector           │          │  - jpegtran/optipng/cwebp   │
│  (content media scanning)   │          │  - ffmpeg remux             │
│ MM_Head_Emitter + Modules   │          │                             │
│  (page-level output)        │          │                             │
│ MM_Abilities (AI API)       │          │                             │
│ MM_MCP_Server (AI tools)    │          │                             │
│ MM_Mod_Discovery (llms.txt) │          │                             │
└─────────────────────────────┘          └─────────────────────────────┘
```

---

## Current Status — Production

| Item | Value |
|------|-------|
| Plugin version | Auto-bumped by CI on every dev push |
| Daemon version | Auto-bumped by CI on server repo dev push |
| WordPress version | 6.9 |
| Production URL | https://hyercleaning.com |
| Apt server | apt.richardkentgates.com |

---

## What's Done

### Audit #1 Bugs (2026-07-31) — All Fixed

| # | Bug | Fix | Version |
|---|-----|-----|---------|
| BUG-1 | Video sitemap indexes YouTube/Vimeo embeds | Removed embed extraction | v2.3.41 |
| BUG-2 | Organization schema uses invalid type `ProfessionalService` | Validate against `get_business_types()`, fall back to `LocalBusiness` | v2.3.45 |
| BUG-3 | Compression UI labels imply lossy quality | Updated labels to "Effort Level" with lossless clarification | v2.3.41 |
| BUG-4 | Author settings don't persist | Confirmed working — not reproducible | N/A |
| BUG-5 | Two separate sitemap configuration pages | Consolidated into `MM_Site_Settings` | v2.3.43 |
| BUG-6 | Duplicate Person schema controls | Removed `schema.author_persons`, kept `authors.person_schema` | v2.3.41 |

### Audit #2 Bugs (2026-08-01) — All Fixed

| # | Severity | Issue | Fix | Version |
|---|----------|-------|-----|---------|
| C1 | CRITICAL | `METADATA_URL` uses `http://` — MITM risk | Changed to `https://` | v2.3.58 |
| C2 | MEDIUM | `MM_Importer` dead code (328 lines) | Deleted | v2.3.58 |
| C3 | MEDIUM | `sslverify => false` in link checker | Removed | v2.3.58 |
| C4 | LOW | Deprecated `wpmu_new_blog` hook | Removed | v2.3.58 |

### Documentation Fixes — All Fixed

| # | Issue | Status |
|---|-------|--------|
| D1 | readme.txt changelog stuck at v2.1.7 | Fixed |
| D2 | GPL-2.0 vs GPL-3.0 license mismatch | Fixed (GPL-3.0-or-later) |
| D3 | CHANGELOG.md 40+ auto-increment noise entries | Cleaned |
| D4 | ROADMAP.md stale version references | Fixed |
| D5 | JOB_QUEUE_SPEC.md wrong job_type values | Fixed |
| D6 | ARCHITECTURE.md missing endpoints and files | Fixed |
| D7 | GitHub Pages broken Quick Links | Rewritten |
| D8 | Plugin URI points to server repo | Fixed |
| D9 | AGENTS.md stale compatibility map example | Fixed |
| D10 | CONTRIBUTING.md inconsistent version requirements | Fixed |
| D11 | SECURITY.md missing endpoints | Fixed |
| D12 | No issue/PR templates | Added |

### Test Coverage — Priority 1 Complete

| Class | Lines | Tests | Status |
|-------|-------|-------|--------|
| `MM_Page_Context` | 124 | 16 | Done |
| `MM_Mod_Head_Meta` | 351 | 28 | Done |
| `MM_Head_Emitter` | 106 | 11 | Done |
| `MM_Mod_Author` | 92 | 4 | Done |
| `MM_Mod_Local` | 341 | 14 | Done |
| `MM_Mod_Social` | 247 | 15 | Done |
| `MM_Memory_Manager` | 231 | 37 | Done |

**Total: 488 tests, 1173 assertions — all passing.**

### Infrastructure

- CI/CD promotion chains verified (dev→test→main)
- HTTPS on apt server (Let's Encrypt, auto-renewal)
- Branch protection removed (was blocking workflow automation)
- Promotion workflows rewritten (direct git merge, not PRs)
- Both wikis populated (server: 3 pages, plugin: 14 pages)
- GitHub Pages rewritten for both repos
- Memory manager with dynamic batch sizing
- AGENTS.md mandatory workflow rules

### Audit #3 — Parallel Logic (2026-08-04) — All Fixed

| # | Finding | Severity | Fix | Version |
|---|---------|----------|-----|---------|
| P-1 | Old metadata system (`MM_Frontend`, `MM_Sitemap`) still active alongside new module system | HIGH | Deleted both files, migrated features to new modules | v2.3.68 |
| P-2 | Two sitemap implementations register same rewrite rules | HIGH | Removed `MM_Sitemap::init()`, consolidated into `MM_Mod_Sitemap_Web` | v2.3.68 |
| P-3 | Two frontend/schema implementations emit duplicate tags | HIGH | Removed `MM_Frontend::init()`, content-based detection via `MM_Media_Detector` | v2.3.68 |
| P-4 | `$pick` closure defined twice in `MM_Metadata` | HIGH | Extracted `MM_Metadata::pick_embedded_value()` — 1 definition, 6 calls | v2.3.67 |
| P-5 | `deep_merge()` defined in two classes | MEDIUM | Unified into `MM_Site_Settings::deep_merge()` with `$merge_lists` param | v2.3.67 |
| P-6 | PostalAddress built with/without sanitization across modules | MEDIUM | Extracted `MM_Mod_Base::postal_address_node()` with consistent sanitization | v2.3.67 |

### Audit #3 — Old System Migration (2026-08-04) — Complete

Migrated unique features from deleted `MM_Frontend` and `MM_Sitemap` into new module system:

| Source | Feature | Destination |
|--------|---------|-------------|
| `MM_Frontend` | Attachment page schema (ImageObject/VideoObject/AudioObject/DigitalDocument) | `MM_Mod_Schema::add_media_schema()` |
| `MM_Frontend` | Attachment page OG tags (og:video, og:audio, og:image:secure_url) | `MM_Mod_Social::emit_og()` |
| `MM_Frontend` | License/copyright link | `MM_Mod_Head_Meta::add_license_link()` |
| `MM_Sitemap` | `/sitemap-video.xml` (Google Video Sitemap) | `MM_Mod_Sitemap_Web::render_video_sitemap()` |
| `MM_Sitemap` | `/sitemap-media.xml` (image:image + video:video) | `MM_Mod_Sitemap_Web::render_media_sitemap()` |
| `MM_Sitemap` | Video extraction from post content | `MM_Mod_Sitemap_Web::extract_selfhosted_videos()` |
| `MM_Sitemap` | Media/video robots.txt directives | `MM_Mod_Sitemap_Web::append_robots_txt()` |
| `MM_Sitemap` | Search engine pings for all sitemaps | `MM_Mod_Sitemap_Web::send_ping()` |

New class created: `MM_Media_Detector` — scans post_content for `<img>`, `<video>`, `<audio>`, `<iframe>`, `<a>` tags. Resolves URLs to WP attachment IDs. Filterable via `mm_detected_media`.

Key design change: Schema and OG tags are now only emitted for media that actually exists in the post content — no more featured-image manipulation signals.

### Audit #3 — Code Quality Fixes (2026-08-04) — Complete

| # | Item | Fix |
|---|------|-----|
| Q-1 | `strpos()` → `str_contains()`/`str_starts_with()` | 4 files updated |
| Q-2 | Missing type hints on `$default` params | Added `mixed` type hints in 3 files |
| Q-3 | Indentation in `MM_Settings::init()` | Fixed 3-tab over-indentation |
| Q-4 | Indentation for `mm_apply_bulk_meta` hook | Fixed 0/3-tab → 2-tab |
| Q-5 | Legacy test class name `Test_MM_Frontend` | Renamed to `Test_MM_Modules` |
| DUP-1 | `deep_sanitize_section()` duplicated | Consolidated into `MM_Site_Settings::deep_sanitize_section()` (public static) |
| DUP-2 | Test helpers duplicated | Updated call sites to shared helpers |
| DUP-3 | `extract_json_ld()` duplicated | Updated call sites to shared helper |
| O-7 | Orphaned doc block | Removed |
| O-8 | Empty comment separator | Removed |

### Audit #3 — Dead Code & Missing Logic Fixes (2026-08-04) — Complete

| # | Item | Fix |
|---|------|-----|
| O-1 | `calculate_batch_size()` never called | Wired into AJAX scan library for memory-aware batch sizing |
| O-2 | `estimate_job_cost()` only caller was O-1 | Kept as dependency of O-1 |
| O-5 | `check_version()` only called by tests | Made private; test updated to use `diagnose()` |
| O-6 | 5× `*_path()` public, only called internally | Changed from `public` to `private` |
| G-1 | `get_term_link()` WP_Error not checked | Added `is_string()` guards in mod-social, mod-head-meta, mod-schema |
| G-2 | LinkedIn prefix empty string | Added `https://www.linkedin.com/in/` prefix |
| G-3 | FAQ extraction only `<details>/<summary>` | Added `<dl>/<dt>/<dd>` and heading+paragraph patterns |
| G-5 | HTML sitemap hierarchical unlimited | Capped at 500 per level |
| G-6 | DNS prefetch removal kills all hints | Selective filter removes only `dns-prefetch` relation type |
| G-7 | Deactivation hook missing cron clear | Added `mm_meta_check_links` + `flush_rewrite_rules` |
| G-8 | Download methods lack `exit` | Added `exit` to vcard/json/csv sub-methods |

---

## Audit #3 — 2026-08-04

Full codebase audit covering orphans, parallel logic, security, missing logic, and WordPress coding standards.

### Orphans / Dead Code

| # | Item | File | Severity | Status |
|---|------|------|----------|--------|
| O-1 | `calculate_batch_size()` — never called in production | `class-mm-memory-manager.php:139` | MEDIUM | FIXED (v2.3.71) — wired into AJAX scan |
| O-2 | `estimate_job_cost()` — only caller is O-1 | `class-mm-memory-manager.php:92` | LOW | FIXED (v2.3.71) — dependency of O-1 |
| O-3 | `field_map()` — defined but never called anywhere | `class-mm-metadata.php:209` | MEDIUM | FIXED (v2.3.72) — single source of truth for import/verify |
| O-4 | `drop_table()` — only called by tests, uninstall.php inlines its own | `class-mm-db.php:34` | MEDIUM | KEEP — correct for tests; uninstall.php cannot load classes |
| O-5 | `check_version()` — only called by tests | `class-mm-daemon-updater.php:197` | LOW | FIXED (v2.3.71) — made private |
| O-6 | 5× `*_path()` methods — public, only called internally | `class-mm-status.php` | LOW | FIXED (v2.3.71) — made private |
| O-7 | Orphaned doc block ("AJAX: Save a single row's metadata fields") | `class-mm-admin.php:1564` | LOW | FIXED (v2.3.70) |
| O-8 | Empty comment separator block | `class-mm-metadata.php:335` | LOW | FIXED (v2.3.70) |

### Parallel Logic — Fixed

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| P-1 | Old metadata system (`MM_Frontend`, `MM_Sitemap`) still active alongside new module system | HIGH | FIXED (v2.3.68) |
| P-2 | Two sitemap implementations register same rewrite rules | HIGH | FIXED (v2.3.68) |
| P-3 | Two frontend/schema implementations emit duplicate tags | HIGH | FIXED (v2.3.68) |
| P-4 | `$pick` closure defined twice in `MM_Metadata` | HIGH | FIXED (v2.3.67) |
| P-5 | `deep_merge()` defined in two classes | MEDIUM | FIXED (v2.3.67) |
| P-6 | PostalAddress built with/without sanitization across modules | MEDIUM | FIXED (v2.3.67) |
| P-7 | `enqueue_all_sizes()` guard pattern repeated 16 times | MEDIUM | CLOSED — 15 call sites are legitimately different, not extractable |

### Duplicate Code — Consolidation Needed

| # | Item | Files | Severity | Status |
|---|------|-------|----------|--------|
| DUP-1 | `deep_sanitize_section()` duplicated | `class-mm-site-settings.php:185` vs `class-mm-metadata-admin.php:~217` | MEDIUM | FIXED (v2.3.70) |
| DUP-2 | Test helpers duplicated (`make_image_attachment` etc.) | `tests/Integration/Test_MM_Frontend.php` vs `tests/Helpers/helpers.php` | LOW | FIXED (v2.3.70) |
| DUP-3 | `extract_json_ld()` duplicated | `tests/Integration/Test_MM_Frontend.php` vs `tests/Helpers/helpers.php` | LOW | FIXED (v2.3.70) |

### Security

No CRITICAL or HIGH findings. One LOW: `@exec()` suppression in daemon updater.

### Missing Logic / Gaps

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| G-1 | `get_term_link()` return not type-checked — WP_Error flows into og:url | MEDIUM | FIXED (v2.3.71) — added is_string() checks in 3 files |
| G-2 | LinkedIn prefix empty string — produces bare handle, not URL | MEDIUM | FIXED (v2.3.71) — added https://www.linkedin.com/in/ prefix |
| G-3 | FAQ extraction only supports `<details>/<summary>` — no other patterns | MEDIUM | FIXED (v2.3.71) — added dl/dt/dd and heading+paragraph patterns |
| G-4 | HTML sitemap flat queries hardcap at 500 posts, no pagination | MEDIUM | FIXED (v2.3.72) — configurable flat_limit (default 500, max 5000) |
| G-5 | HTML sitemap hierarchical queries unlimited — OOM risk on large sites | MEDIUM | FIXED (v2.3.71) — capped at 500 per level |
| G-6 | `remove_wp_dns_prefetch` setting removes ALL resource hints, not just DNS prefetch | MEDIUM | FIXED (v2.3.71) — selective filter removes only dns-prefetch |
| G-7 | No deactivation hook — cron events and tables persist after deactivation | MEDIUM | FIXED (v2.3.71) — added mm_meta_check_links + flush_rewrite_rules |
| G-8 | `exit` missing in business contact download sub-methods | LOW | FIXED (v2.3.71) — added exit to vcard/json/csv methods |

### Code Quality — PHP 8.0+ Modernization

| # | Item | Files | Severity | Status |
|---|------|-------|----------|--------|
| Q-1 | `strpos()` should be `str_contains()` / `str_starts_with()` (PHP 8.0+) | `class-mm-site-settings.php:215,217`, `class-mm-metadata-admin.php:217,219`, `class-mm-mod-links.php:161,167`, `class-mm-metadata-cli.php:334` | MEDIUM | FIXED (v2.3.70) |
| Q-2 | Missing type hints on `$default` parameters and filter callbacks | `class-mm-site-settings.php`, `class-mm-page-context.php`, `class-mm-updater.php` | MEDIUM | FIXED (v2.3.70) |
| Q-3 | Indentation inconsistency in `init()` | `class-mm-settings.php:55-60` | LOW | FIXED (v2.3.70) |
| Q-4 | Indentation inconsistency for `mm_apply_bulk_meta` hook | `class-mm-admin.php:66-67` | LOW | FIXED (v2.3.70) |
| Q-5 | Legacy test class name `Test_MM_Frontend` (MM_Frontend no longer exists) | `tests/Integration/Test_MM_Frontend.php` | LOW | FIXED (v2.3.70) |

### Test Coverage — Priority 2 — Complete

| Class | Lines | Tests | Status |
|-------|-------|-------|--------|
| `MM_Post_Meta_Panel` | 198 | 19 | Done |
| `MM_Metadata_Admin` | 366 | 15 | Done |
| `MM_Mod_Sitemap_Web` | 337 | 34 | Done |

### Validation

- [x] Run WordPress Plugin Checker on production — admin page saves fixed (ModSecurity removed)
- [x] SearchAction deprecated by Google Nov 2024 — default changed to `false` in schema.website_searchaction setting

### Schema Type Thinning — Complete

- Removed 7 types from per-post selector: LocalBusiness, Organization, Person, FAQPage, TouristAttraction, TouristTrip, RealEstateListing
- BlogPosting locked for native posts (hidden from metabox dropdown)
- Dead `extract_faq()` method removed
- WebPage node mapping updated to reflect remaining types
- Facebook Page ID field added under fb:app_id on Social settings
- fb:admins meta tag emitted in head when set

---

## Schema.org / OG Compliance — 2026-08-04

### Emitted Schema Types (all valid)

| Type | Location | Status |
|------|----------|--------|
| WebSite | `MM_Mod_Schema::add_website_node()` | ✅ Valid |
| SiteNavigationElement | `MM_Mod_Schema::add_navigation_node()` | ✅ Valid (not in Google gallery, harmless) |
| Person | `MM_Mod_Author` | ✅ Valid |
| LocalBusiness / Corporation | `MM_Mod_Schema::add_content_node()` | ✅ Valid |
| AboutPage / CollectionPage / ProfilePage / WebPage | `MM_Mod_Schema::add_webpage_node()` | ✅ Valid |
| BreadcrumbList | `MM_Mod_Schema::add_breadcrumb_node()` | ✅ Valid |
| ImageObject | `MM_Mod_Schema::add_image_schema()` | ✅ Valid |
| VideoObject | `MM_Mod_Schema::add_video_schema()` | ✅ Valid |
| AudioObject | `MM_Mod_Schema::add_audio_schema()` | ✅ Valid |
| DigitalDocument | `MM_Mod_Schema::add_document_schema()` | ✅ Valid |
| GeoCoordinates / Place | `MM_Mod_Schema::add_location_to_node()` | ✅ Valid |
| FAQPage | Removed | ❌ Removed — Google dropped rich results May 2026 |

### Emitted OG Tags (all valid)

| Tag | Source | Status |
|-----|--------|--------|
| `og:title` | `MM_Mod_Head_Meta` | ✅ Required, HTTPS |
| `og:type` | `MM_Mod_Head_Meta` | ✅ Required |
| `og:url` | `MM_Mod_Head_Meta` | ✅ Required, HTTPS |
| `og:image` | `MM_Mod_Head_Meta` | ✅ Required, content-based |
| `og:description` | `MM_Mod_Head_Meta` | ✅ Recommended |
| `og:site_name` | `MM_Mod_Head_Meta` | ✅ Recommended |
| `og:locale` | `MM_Mod_Head_Meta` | ✅ Recommended |
| `og:video` | `MM_Mod_Social` | ✅ Conditional (video present) |
| `og:audio` | `MM_Mod_Social` | ✅ Conditional (audio present) |
| `fb:app_id` | `MM_Mod_Social` | ✅ Optional (Facebook App ID) |
| `fb:admins` | `MM_Mod_Social` | ✅ Optional (Facebook Page ID) |
| `twitter:card` | `MM_Mod_Social` | ✅ Recommended |
| `twitter:site` | `MM_Mod_Social` | ✅ Recommended |
| `twitter:creator` | `MM_Mod_Social` | ✅ Recommended |

### Deprecated by Google (NOT emitted)

| Type | Deprecated | Status |
|------|------------|--------|
| HowTo | Sep 2023 | Not emitted ✅ |
| Course Info | June 2025 | Not emitted ✅ |
| ClaimReview | June 2025 | Not emitted ✅ |
| EstimatedSalary | June 2025 | Not emitted ✅ |
| LearningVideo | June 2025 | Not emitted ✅ |
| SpecialAnnouncement | June 2025 | Not emitted ✅ |
| VehicleListing | June 2025 | Not emitted ✅ |
| Book Actions | June 2025 | Not emitted ✅ |
| SearchAction (Sitelinks) | Nov 2024 | Default disabled ✅ |
| FAQPage (rich results) | May 2026 | Kept — valid schema.org, useful for AI crawlers |

---

## Pipeline

```
dev ──push──> ci.yml (lint + PHPStan + tests + build)
    │         version-bump.yml (auto version with [skip ci])
    │
    │  workflow_dispatch: promote-to-test.yml
    ▼
test ──push──> test-deploy.yml (build zip + apt server deploy)
    │
    │  workflow_dispatch: promote-to-main.yml
    ▼
main ──push──> release.yml (tag + GitHub release + apt server deploy)
```

---

## What's Left

### HIGH — WooCommerce Product Schema Integration (S-2)

Product schema must auto-populate from WooCommerce product data when WooCommerce is active. Manual fields become fallback only.

**Detection:** Check `class_exists('WooCommerce')` or `WC()` at runtime.

**Auto-populate from WooCommerce meta (when active):**
| Schema Field | WooCommerce Source | Fallback (no WooCommerce) |
|-------------|-------------------|--------------------------|
| `offers.price` | `_price` (or `_sale_price` if set) | Manual `product_price` field |
| `offers.priceCurrency` | WooCommerce currency (`get_woocommerce_currency()`) | Manual `product_currency` field |
| `offers.availability` | `_stock_status` → schema mapping (`instock`→`InStock`, `outofstock`→`OutOfStock`, `onbackorder`→`PreOrder`) | Manual `product_availability` select |
| `brand` | `_brand` meta or `pa_brand` attribute | Manual `product_brand` field |
| `sku` | `_sku` | N/A |
| `image` | Product gallery featured image | OG image fallback |
| `name` | Product post title | Post title |
| `description` | Product short description (`_short_description`) or post excerpt | Post excerpt |

**Files to change:**
- `includes/metadata/class-mm-schema-types.php` — modify `get_fields_by_type()['Product']` to add `auto_label` hints when WooCommerce is active
- `includes/metadata/modules/class-mm-mod-schema.php` — modify `add_content_node()` Product branch to pull from WC data when available
- New helper: `MM_Mod_Schema::get_woocommerce_product_data(int $post_id): array` — returns normalized price/availability/brand/sku from WC meta

**Conditional field visibility:** When WooCommerce is active, Product fields in the metabox show "Auto: from WooCommerce" labels. Manual inputs remain as overrides (user can override WC data per-post if needed).

**WooCommerce hooks:** Hook into `woocommerce_update_product` and `woocommerce_update_attribute` to trigger schema re-validation (optional — the existing `save_post` hook already fires for WC product saves).

---

### HIGH — Event as WooCommerce Product (S-4)

Simple WooCommerce integration for Event posts — make events purchasable as products with ticket generation and QR codes.

**Scope:** Utilize WooCommerce, don't replace it. Let WC handle inventory, analytics, coupons, etc.

**What we add:**
| Feature | Implementation |
|---------|----------------|
| Event ↔ Product link | Custom field `_mm_wc_product_id` on Event, `_mm_event_id` on Product |
| Ticket purchase | WC Product type "Event Ticket" with event linking |
| Ticket hash | Unique hash per order item: `md5($order_id . $item_id . $salt)` |
| QR code | `chillerlan/php-qrcode` library, payload: `site/event/{slug}?ticket={hash}` |
| Ticket download | Rewrite endpoint: `/gcm-event/{event_id}/ticket/{hash}/` |
| Order email | WC email attachment with QR code image |
| Check-in | Simple REST endpoint: `POST /wp-json/metamanager/v1/checkin` with ticket hash |

**Files to change:**
- New: `includes/class-mm-event-product.php` — WC product type registration, event linking UI
- New: `includes/class-mm-ticket-qr.php` — QR code generation and download endpoint
- Modified: `includes/metadata/class-mm-schema-types.php` — add `event_ticket_url` and `event_capacity` fields
- Modified: `includes/metadata/modules/class-mm-mod-business-contact.php` — update .ical to include ticket URL
- New: `includes/rest-api/class-mm-rest-checkin.php` — ticket validation endpoint

**WooCommerce integration points:**
- Register custom product type "Event Ticket" in WC admin
- Product data tab shows event selector dropdown
- On order complete → generate ticket hash, save to order meta
- WC email template filter → attach QR code image
- REST API endpoint for door check-in (validates hash, returns event/attendee data)

**Dependencies:** `chillerlan/php-qrcode` (composer require chillerlan/php-qrcode)

**NOT in scope:** (WooCommerce handles these)
- Inventory management
- Analytics and reporting
- Coupon/discount codes
- Refund processing
- Attendee management beyond basic check-in
- PDF ticket generation (QR code image is sufficient)

---

### HIGH — Service as WooCommerce Product (S-9)

Simple WooCommerce integration for Service posts — make services purchasable as products with booking/scheduling.

**Scope:** Utilize WooCommerce, don't replace it. Let WC handle payment processing, analytics, coupons, etc.

**What we add:**
| Feature | Implementation |
|---------|----------------|
| Service ↔ Product link | Custom field `_mm_wc_product_id` on Service, `_mm_service_id` on Product |
| Service purchase | WC Product type "Service Booking" with service linking |
| Booking confirmation | Unique booking hash: `md5($order_id . $item_id . $salt)` |
| Booking details | Custom order meta: service name, date/time, location, notes |
| Service page | Auto-generated service details from business profile + service meta |

**Files to change:**
- New: `includes/class-mm-service-product.php` — WC product type registration, service linking UI
- Modified: `includes/metadata/class-mm-schema-types.php` — add `service_booking_url` field
- Modified: `includes/metadata/modules/class-mm-mod-schema.php` — Service schema includes offers from WC when available

**WooCommerce integration points:**
- Register custom product type "Service Booking" in WC admin
- Product data tab shows service selector dropdown
- On order complete → generate booking hash, save to order meta
- WC email template filter → include booking confirmation details
- Service schema auto-populates price/availability from WC product data

**Service-specific fields:**
| Field | Purpose |
|-------|---------|
| `service_type` | Type of service (e.g., "Pressure Washing") |
| `service_area` | Area served (e.g., "Destin, FL") |
| `service_price` | Base price (fallback when no WC product) |
| `service_currency` | Currency code |
| `service_booking_url` | Link to book/purchase the service |
| `service_duration` | Estimated duration (for scheduling) |
| `service_includes` | What's included in the service |

**NOT in scope:** (WooCommerce handles these)
- Payment processing
- Analytics and reporting
- Coupon/discount codes
- Refund processing
- Appointment scheduling beyond basic booking hash
- Calendar integration (use Bookly, Amelia, etc. if needed)

---

### HIGH — Digital Media as WooCommerce Product (S-10)

Sell digital media files (photos, videos, documents) as WooCommerce products with file protection, watermarked previews, and per-customer licensing.

**Scope:** Utilize WooCommerce for payment, order management, and customer accounts. MetaManager handles file protection, watermarking, and license generation.

**File Protection Strategy:**
- Store original files **outside web root** (e.g., `/srv/media/`)
- Serve via PHP streaming endpoint with purchase validation
- Watermarked previews in public uploads folder
- Files never directly accessible via URL

**Watermarking (Imagick):**
| Element | Behavior |
|---------|----------|
| Site title | Translucent text overlay on all preview images |
| Site logo | Desaturated + translucent overlay (if logo is set) |
| Applied to | Preview/thumbnail images only, not original downloads |
| Auto-generate | On upload, before files are moved to protected storage |

**License System:**
- One license document **per customer** (not per purchase)
- Generated from business license text (configurable in settings)
- Attached to WooCommerce order confirmation email
- Accessible in WooCommerce "My Account" → Downloads
- Applies to **all** purchases by that customer
- License includes: usage rights, restrictions, attribution requirements

**CPT Structure:**
```
mm_media → Digital Media
  - media_type: photo | video | document | audio
  - media_file: original file path (outside web root)
  - media_preview: watermarked/low-res preview path
  - media_dimensions: "1920x1080" (for images/video)
  - media_file_size: "2.4 MB"
  - media_license_type: personal | commercial | editorial | custom
  - media_download_limit: 0 (unlimited) or number
  - media_expiry_days: 0 (never) or number
```

**Files to change:**
- New: `includes/class-mm-media-product.php` — WC product type registration, file protection, watermarking, license generation
- New: `includes/rest-api/class-mm-rest-media-download.php` — PHP streaming endpoint with purchase validation
- New: `includes/class-mm-watermark.php` — Imagick watermark generation (text + optional logo)
- New: `includes/class-mm-license.php` — License document generation and attachment
- Modified: `includes/metadata/class-mm-schema-post-types.php` — register mm_media CPT
- Modified: `includes/metadata/class-mm-schema-types.php` — MediaObject/CreativeWork schema fields

**WooCommerce integration points:**
- Register custom product type "Digital Download" in WC admin
- Product data tab shows media selector + upload fields
- On order complete → move file to protected storage, generate watermarked preview, create license
- WC email template filter → attach license document
- My Account page → license document accessible in Downloads section
- Download counter/limit enforcement via streaming endpoint

**Schema output:**
- MediaObject with contentUrl (protected), thumbnailUrl (watermarked preview)
- license attribute linking to license document
- width/height/fileSize/encodingFormat from metadata

**NOT in scope:** (WooCommerce handles these)
- Payment processing
- Order management
- Customer account management
- Download tracking/limits (we enforce, WC stores)
- Coupon/discount codes
- Refund processing

---

### MEDIUM — Schema Type Cleanup (S-5)

Remove redundant schema types that map to WordPress built-ins:

| Type | Maps to | Action |
|------|---------|--------|
| BlogPosting | Default `post` type | Remove CPT, schema module detects `post` type |
| WebPage | Default `page` type | Remove CPT, schema module detects `page` type |
| ProfilePage | Author archive pages | Remove CPT, schema module detects `is_author()` |
| Article | Redundant with WebPage + BlogPosting | Remove entirely |

**Final CPT list:**
| CPT | Type | Status |
|-----|------|--------|
| `mm_event` | Event | Editable, .ical, WC product integration |
| `mm_service` | Service | Editable, WC product integration |
| `mm_media` | Digital Media | Editable, WC product, file protection, watermarking |
| `mm_how_to` | HowTo | Editable |
| `mm_about_page` | AboutPage | Read-only, auto-generated |
| `mm_contact_page` | ContactPage | Read-only, auto-generated |
| `mm_calendar` | Calendar | Read-only, auto-generated |

---

### MEDIUM — ContactPage Auto-Generation (S-6)

ContactPage CPT auto-generates content from Business Profile settings.

**Content rendered:**
- Business name, logo
- HTML5 `<address>` element with geo: protocol links
- Phone (click-to-call), email, vCard download
- Opening hours
- Action buttons (controlled via Contact Page settings)

**Settings page renamed:** "Contact Card" → "Contact Page"

**Files changed:**
- `class-mm-schema-post-types.php` — ContactPage CPT with disabled editor
- `class-mm-mod-business-contact.php` — `render_contact_page()` method
- `class-mm-mod-schema.php` — ContactPage schema populated from business info
- `page-contact.php` — renamed heading and descriptions

---

### MEDIUM — AboutPage Auto-Generation (S-7)

AboutPage CPT auto-generates content from Business Profile settings.

**Content rendered:**
- Business name, logo, description
- Founding date, number of employees, price range
- Full address with geo: protocol link
- Service areas, payment methods
- Social profile links

**New Business Profile fields:**
- Description (textarea)
- Founding Date (date picker)
- Number of Employees (text)

**Files changed:**
- `class-mm-schema-post-types.php` — AboutPage CPT with disabled editor
- `class-mm-mod-business-contact.php` — `render_about_page()` method
- `class-mm-mod-schema.php` — AboutPage schema populated from business info
- `class-mm-site-settings.php` — business_defaults() updated
- `page-business.php` — new fields added

---

### MEDIUM — Calendar Auto-Generation (S-8)

Calendar CPT auto-generates a navigable month-by-month event calendar.

**Features:**
- Month navigation (forward/backward)
- Today button
- Events shown on their start date
- Click-through to individual event posts
- Full responsive CSS

**Files changed:**
- `class-mm-schema-post-types.php` — Calendar CPT with disabled editor
- `class-mm-mod-business-contact.php` — `render_calendar()` method
- `biz-contact.css` — calendar styles added

---

## Integration Tests on PEO

Integration tests require WordPress test suite at `/tmp/wordpress-tests-lib`. PEO has MySQL test database (`metamanager_test`) ready. Install test suite and run PHPUnit.

---

## Media Type Capabilities Reference

The bulk metadata form must respect these write capabilities per `MM_Metadata::WRITE_CAPABILITY`:

| Format | Write capability | Writable fields |
|--------|-----------------|-----------------|
| JPEG/PNG/WebP/AVIF/GIF/TIFF | `full` | All fields (EXIF+IPTC+XMP) |
| MP4/MOV/3GP | `full` | All fields (QuickTime+XMP) |
| AVI/WMV | `xmp_only` | XMP-only fields (Headline, Credit, Keywords, Date, Rating, City, State, Country, GPS) |
| MKV/WebM/OGG video | `read_only` | None — display only |
| MP3/M4A/FLAC/AIFF | `full` | All fields (ID3/QuickTime/Vorbis+XMP) |
| OGG audio | `vorbis_only` | Vorbis comment fields only |
| WAV/WMA audio | `xmp_only` | XMP-only fields |
| PDF | `xmp_only` | XMP-only fields |

Compression support:
| Format | Compressible |
|--------|-------------|
| JPEG | Yes (jpegtran) |
| PNG | Yes (optipng) |
| WebP | Yes (cwebp) |
| AVIF | Yes (avifenc) |
| GIF/TIFF | No |
| Video (MP4/MOV/AVI/WMV/MKV/WebM/OGV) | Yes (ffmpeg remux) |
| Audio | No |
| PDF | No |

---

## Restoration & Expansion Plan (2026-08-11)

### Restoration — Missing Functionality

| # | Item | Priority | Status |
|---|------|----------|--------|
| R-1 | `META_SYNCED` constant + registration | HIGH | Done — constant defined, registered, set after import, used by `on_upload()` and Scan Library |
| R-2 | Queue notice `queued` for metadata jobs | HIGH | Done — `write_job()` now pushes `'queued'` notice for metadata jobs behind pending |
| R-3 | Media Processing page: media-type-aware form | HIGH | Done — query includes all MIME types, filter dropdown, data-write-cap attributes, JS field filtering |
| R-4 | Media Processing page: compression checkbox per format | HIGH | Done — data-compressible attribute, JS hide/show, format-specific labels |
| R-5 | Media Processing page: GPS read-only display | MEDIUM | Done — GPS lat/lon/alt shown in grid with pin icon |
| R-6 | `phpunit.xml.dist` | MEDIUM | Exists at `tests/phpunit.xml` but requires WordPress test suite at `/tmp/wordpress-tests-lib` |
| R-7 | GPS map preview on attachment edit | LOW | Done — Leaflet/OpenStreetMap enqueued on attachment edit screen |

### Expansion — Platform Improvements

| # | Item | Priority | Status |
|---|------|----------|--------|
| E-1 | Bulk metadata: video/audio/PDF support | HIGH | Done — query includes all MIME types, grid shows icons per type |
| E-2 | Bulk metadata: per-MIME field filtering | HIGH | Done — client-side JS + server-side MIME validation in AJAX handler |
| E-3 | Bulk metadata: dynamic compression checkbox | MEDIUM | Done — JS toggles visibility per format with format-specific labels |
| E-4 | Attachment GPS map preview | LOW | Done — Leaflet/OSM map with marker |
| E-5 | `phpunit.xml.dist` | MEDIUM | Exists at `tests/phpunit.xml` |
| E-6 | Metadata versioning | LOW | Done — mm_meta_history table, snapshots on save, version history pane on attachment edit |
| E-7 | Metadata diff view | LOW | Done — compare any two versions or current values, AJAX diff with color-coded before/after table |
| S-1 | Schema type thinning | HIGH | Done — removed 7 types (LocalBusiness, Organization, Person, FAQPage, TouristAttraction, TouristTrip, RealEstateListing), locked BlogPosting for posts, removed dead FAQ extraction code |
| S-2 | WooCommerce Product schema | HIGH | Done — auto-populates Product schema from WooCommerce meta (price, availability, brand, sku), manual fields as overrides |
| S-3 | Facebook Page ID field | MEDIUM | Done — fb:admins field added under fb:app_id on Social settings, meta tag emitted in head |

---

## Conventions

- All work on `dev` only. Never checkout/edit/push `test` or `main`.
- Promote via `workflow_dispatch` triggers only.
- CI auto-bumps `MM_VERSION` on every dev push — never edit manually.
- Compression is lossless ONLY.
- All software moves to production through native update systems (apt, WordPress auto-update).
- PHP 8.4 for WP-CLI (`php8.4 /usr/local/bin/wp --path=/srv/www/wordpress`).
- SSH user: `richardkentgates` (not root); default SSH key.
- Plugin triggers daemon updates automatically — no manual intervention on success.
