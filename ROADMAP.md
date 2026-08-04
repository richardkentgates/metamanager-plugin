# Metamanager Roadmap

Last updated 2026-08-04.

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
| Plugin on production | v2.3.64 (auto-updated via apt) |
| Plugin on apt server | v2.3.64 (CI deployed) |
| Daemon version | v2.4.27 |
| WordPress version | 6.9 |
| Production URL | https://thepeosolution.com |
| Production IP | 104.197.172.183 |
| Apt server IP | 34.136.87.92 (apt.richardkentgates.com) |

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

**Total: 449 tests, 1089 assertions — all passing.**

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
| O-3 | `field_map()` — defined but never called anywhere | `class-mm-metadata.php:209` | MEDIUM | OPEN — needs adapter layer |
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
| P-7 | `enqueue_all_sizes()` guard pattern repeated 16 times | MEDIUM | OPEN — different variations, not extractable |

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
| G-4 | HTML sitemap flat queries hardcap at 500 posts, no pagination | MEDIUM | OPEN — configurable limit needed |
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

### Test Coverage — Priority 2

| Class | Lines | Why |
|-------|-------|-----|
| `MM_Post_Meta_Panel` | 198 | Per-post SEO save logic |
| `MM_Metadata_Admin` | 366 | Settings, sanitization, AJAX |
| `MM_Mod_Sitemap_Web` | 337 | XML sitemap engine |

### Validation

- [ ] Run WordPress Plugin Checker on production
- [ ] Structured data validation endpoint (consider)

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

## Conventions

- All work on `dev` only. Never checkout/edit/push `test` or `main`.
- Promote via `workflow_dispatch` triggers only.
- CI auto-bumps `MM_VERSION` on every dev push — never edit manually.
- Compression is lossless ONLY.
- All software moves to production through native update systems (apt, WordPress auto-update).
- PHP 8.2 for WP-CLI (`php8.2 /usr/local/bin/wp --path=/srv/www/wordpress`).
- SSH user: `richardkentgates` (not root); default SSH key.
- Plugin triggers daemon updates automatically — no manual intervention on success.
