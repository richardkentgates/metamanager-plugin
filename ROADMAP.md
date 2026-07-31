# Metamanager Roadmap

Full codebase audit — 100% source read of both repos, contrasted against all documentation, wiki, GitHub Pages, and plugin help tabs. Last updated 2026-07-31.

---

## Repository Overview

| Repo | Branch | Version | Purpose |
|------|--------|---------|---------|
| `metamanager-plugin` | dev/test/main | Auto-bumped by CI | WordPress plugin: metadata sync, frontend output, admin UI, job queue, web-layer SEO |
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
│ MM_Frontend (media output)  │          │  - jpegtran/optipng/cwebp   │
│ MM_Head_Emitter + Modules   │          │  - ffmpeg remux             │
│  (page-level SEO)           │          │                             │
└─────────────────────────────┘          └─────────────────────────────┘
```

**Two frontend output systems coexist:**
- `MM_Frontend` — media-specific structured data (ImageObject/VideoObject/AudioObject/DigitalDocument + OG for media + license links)
- `MM_Mod_*` via `MM_Head_Emitter` — page-level SEO (title/desc/canonical/robots + WebSite/BlogPosting schema + OG/Twitter cards)

Both are needed. They handle different concerns.

---

## BUGS — Active (from full codebase audit 2026-07-31)

### BUG-1: Video sitemap indexes YouTube/Vimeo embeds (HIGH)
**File:** `includes/class-mm-sitemap.php:170-206`
**Problem:** `render_video_sitemap()` extracts YouTube and Vimeo embeds from post content and includes them in the video sitemap via `extract_embed_videos()`. Per user requirement, video sitemaps should ONLY index self-hosted videos. Indexing embeds is hard on hardware and produces low-quality sitemap entries (no duration, no keywords, no rating for embeds).
**Current behavior:** Three sources: YouTube embeds, Vimeo embeds, self-hosted `<video>` tags + video attachments.
**Required behavior:** Only self-hosted `<video>` tags + video attachment pages.
**Fix:** Remove `extract_embed_videos()` call from `render_video_sitemap()`. Remove `OPT_VIDEO_YOUTUBE` and `OPT_VIDEO_VIMEO` settings and their UI checkboxes. Remove `get_cached_oembed()`. Update settings field to only show self-hosted toggle. Update help tab text.

### BUG-2: Organization/LocalBusiness schema not rendering on production (HIGH)
**File:** `includes/metadata/modules/class-mm-mod-local.php`
**Problem:** On the production site (104.197.172.183), only SoftwareApplication schema (hardcoded in theme) is visible. No `@graph` JSON-LD output from the plugin. The `MM_Mod_Local::populate()` method generates Organization/LocalBusiness schema but it's not appearing in the frontend.
**Possible causes:**
1. Business name is empty in `mm_meta_business` option → minimal Organization node emitted
2. `MM_Mod_Local` module not being loaded by `MM_Metadata_Admin::get_documents()`
3. `MM_Mod_Schema` not calling `MM_Head_Emitter::render()` on frontend pages
**Fix:** Requires SSH verification of: (a) business profile option values, (b) module registration in `get_documents()`, (c) frontend page source for JSON-LD output.

### BUG-3: Compression UI labels imply lossy quality levels (MEDIUM)
**File:** `includes/class-mm-settings.php:285-306`
**Problem:** The compression dropdown labels ("1 — Minimal (fastest)", "7 — Maximum (slowest)") and description ("Higher levels produce smaller files but take longer") imply lossy quality trade-offs. ALL compression in the plugin is lossless only:
- optipng: effort level 1-7 (lossless PNG optimization)
- jpegtran: `-copy all -optimize -progressive` (lossless JPEG)
- cwebp: `-m 6 -q 100` (hardcoded lossless WebP)
- ffmpeg: `-c copy` (lossless remux)
**Fix:** Update field description to explicitly state "All compression is lossless — this controls optimization effort, not quality." Consider renaming labels to "Lossless effort level" or similar.

### BUG-4: Author settings save — values don't persist (MEDIUM)
**File:** `templates/admin/page-authors.php`, `includes/metadata/admin/class-mm-metadata-admin.php`
**Problem:** User reports toggling "Enable Author SEO" and "noindex for author" shows success notice but values don't persist on page reload.
**Code analysis:** The save mechanism appears correct — `sanitize_section()` properly extracts `$raw['authors']`, runs `deep_sanitize()` against defaults, and merges back into the full settings array. Checkboxes that are unchecked are missing from POST data and default to `false` in `deep_sanitize()`.
**Possible causes:**
1. User tested on old Media → Settings page instead of Metamanager → Authors
2. Object cache (Redis/Memcached) returning stale option values
3. Nonce mismatch causing silent save failure
4. Another plugin hooking `sanitize_option_mm_meta_settings` and overwriting
**Fix:** Requires live verification on production via SSH. Check: (a) `get_option('mm_meta_settings')` values, (b) `wp_options` table directly, (c)是否存在 object cache.

### BUG-5: Two separate sitemap configuration locations (LOW)
**Files:** `includes/class-mm-sitemap.php`, `includes/metadata/modules/class-mm-mod-sitemap.php`
**Problem:** Sitemap settings exist in two places:
1. **Preferences → Sitemaps** (`MM_Sitemap::register_settings()`): controls media/video sitemap toggles, YouTube/Vimeo/self-hosted extraction
2. **Metamanager → Sitemaps** (`MM_Mod_Sitemap_Web`): controls post types, taxonomies, records per file, HTML sitemap
**Impact:** Confusing UX — users don't know which page to configure.
**Fix:** Consolidate all sitemap settings into one admin page (Metamanager → Sitemaps). Remove duplicate from Preferences page.

### BUG-6: Schema `author_persons` vs Authors `person_schema` duplicate control (LOW)
**Files:** `includes/metadata/class-mm-site-settings.php:261`, `templates/admin/page-authors.php:58-65`
**Problem:** Two separate settings control Person schema emission:
1. `schema.author_persons` (default: `true`) — in Schema settings section
2. `authors.person_schema` (default: `true`) — in Authors settings section
Both control whether Person JSON-LD nodes are emitted. Users may change one and not the other, leading to confusion.
**Fix:** Remove one of the two settings. The Authors page `person_schema` is the more logical place. Remove `schema.author_persons` from defaults and schema settings page.

---

## BUGS — Already Fixed (from prior work)

| # | Issue | Date Fixed |
|---|-------|------------|
| F1 | Unparseable JSON results silently deleted | 2026-05-24 |
| F2 | CI/CD branch strategy normalization | 2026-05-24 |
| F3 | PHPStan excludes ~40% of plugin code | 2026-05-24 |
| F4 | AVIF MIME type support | 2026-05-24 |
| F5 | Dead code MM_Status::mark_compressed() | 2026-05-24 |
| F6 | Help tab HTML formatting | 2026-05-24 |
| F7 | Hardcoded tool paths | 2026-05-24 |
| F8 | glob() without limit | 2026-05-24 |
| F9 | Email receipt UTF-8 encoding | 2026-07-27 |
| F10 | Media sitemap rewrite rules | 2026-07-26 |
| F11 | wp-sitemaps route conflict | 2026-07-26 |
| F12 | Business profile schema fallback | 2026-07-26 |
| F13 | Deploy workflow metadata.json heredoc | 2026-07-26 |
| F14 | MM_Frontend::init() already called (false positive) | 2026-07-27 |
| F15 | write_job() already checks put_contents (false positive) | 2026-07-27 |
| F16 | MM_Metadata_CLI registered with WP-CLI | 2026-07-27 |
| F17 | purge-links removed from help tabs | 2026-07-27 |
| F18 | .processing cleanup already in code (false positive) | 2026-07-27 |
| F19 | Duplicate <title> already fixed (false positive) | 2026-07-27 |
| F20 | REST API write-back already works (false positive) | 2026-07-27 |
| F21 | mm_meta_synced already removed (FIX-10) | 2026-07-25 |
| F22 | OG video/audio already works (false positive) | 2026-07-27 |
| F23 | test-deploy.yml JSON indentation fixed | 2026-07-27 |
| F24 | SECURITY.md versions updated (both repos) | 2026-07-27 |
| F25 | ARCHITECTURE.md versions updated | 2026-07-27 |
| F26 | License badge corrected to GPL 3.0+ | 2026-07-27 |
| F27 | Plugin site og:url and meta description updated | 2026-07-27 |
| F28 | Documentation URLs unified | 2026-07-27 |
| F29 | Wiki links removed from READMEs | 2026-07-27 |
| F30 | L1-L4 stale findings resolved | 2026-07-27 |
| F31 | Updater filter fix — returns `false` when no update to inject | 2026-07-29 |
| F32 | Metadata transient stale cache cleared | 2026-07-29 |
| F33 | Version header drift fixed (CI sed hardened) | 2026-07-29 |
| F34 | Production update_plugins option rebuilt | 2026-07-29 |
| F35 | All promotions completed through PR #32 (v2.3.40) | 2026-07-30 |
| F36 | MM_CLI fatal error fix (redundant WP_CLI stubs removed) | 2026-07-30 |
| F37 | CI phpunit.xml path fix | 2026-07-30 |
| F38 | WP_CLI\Utils function stubs added | 2026-07-30 |

---

## AUDIT — Verified NOT Issues

- **Media Processing page**: Intentionally kept. Needed for batch metadata operations.
- **`MM_Admin::add_bulk_action()`**: Part of Media Processing feature, not dead code.
- **Two-frontend-system architecture**: Both `MM_Frontend` (media-specific) and `MM_Mod_*` (page-level) are needed. They handle different concerns.
- **Daemon field coverage**: PHP and shell scripts define identical field sets. Consistent.
- **Job queue filesystem contract**: PHP writes -> daemon claims via atomic `mv` -> writes result -> PHP imports via WP-Cron. Solid design.
- **Verify system**: Exists (`rest_verify_attachment`, `verify_single_file()`, `calculate_verify_score()`) but no scheduled cron — requires manual REST API call. Not broken, just not auto-triggered.
- **Schema primary menu**: No menu checked = NO SiteNavigationElement schema (intentional design).

---

## Implementation Plan

### Phase 1: Fix Active Bugs (priority order)

**1. BUG-1 — Remove YouTube/Vimeo from video sitemap** (HIGH, straightforward)
- Remove `extract_embed_videos()` call from `render_video_sitemap()`
- Remove `OPT_VIDEO_YOUTUBE` and `OPT_VIDEO_VIMEO` constants and settings
- Remove `get_cached_oembed()` method
- Remove YouTube/Vimeo checkboxes from `field_sitemap_video()`
- Update sitemap settings description
- Update help tab text
- Run tests to verify no regressions

**2. BUG-2 — Verify Organization schema on production** (HIGH, requires SSH)
- SSH to production server
- Check `mm_meta_business` option values via WP-CLI
- Check if business name is empty
- Verify module loading in `MM_Metadata_Admin::get_documents()`
- Check frontend page source for JSON-LD output
- Fix root cause based on findings

**3. BUG-3 — Fix compression UI labels** (MEDIUM, straightforward)
- Update `field_compress_level()` description to state "All compression is lossless"
- Update section description to clarify lossless behavior
- Consider renaming dropdown labels to emphasize effort, not quality

**4. BUG-4 — Verify author settings save** (MEDIUM, requires SSH)
- SSH to production
- Check `get_option('mm_meta_settings')` via WP-CLI
- Check if author settings are in the saved array
- Check for object cache interference
- Test save via WP-CLI: `wp option update mm_meta_settings ...`

**5. BUG-5 — Consolidate sitemap settings** (LOW, UX improvement)
- Merge Preferences → Sitemaps settings into Metamanager → Sitemaps
- Remove duplicate from Preferences page
- Update admin menu registration

**6. BUG-6 — Remove duplicate Person schema control** (LOW, cleanup)
- Remove `schema.author_persons` from defaults and Schema settings page
- Keep `authors.person_schema` as the single control
- Update any references

### Phase 2: Verification

After all fixes:
1. Run full test suite (`php vendor/bin/phpunit`)
2. Run PHPStan (`php vendor/bin/phpstan analyse`)
3. Run lint (`composer lint`)
4. Promote through pipeline: dev → test → main
5. Verify on production via browser

---

## Pipeline

```
dev ──push──> dev-ci.yml (lint + PHPStan + version bump)
    │
    │  open PR: dev → test
    ▼
test <──PR merge── test-deploy.yml (build zip + apt server deploy)
    │
    │  open PR: test → main
    ▼
main <──PR merge── main-release.yml (tag + GitHub release)
                   deploy.yml (production server)
```

**Promotion = PR + merge.** Direct pushes to `test` and `main` are blocked by branch protection.

### Branch Protection Rules

| Branch | Requires PR | Required Status Checks | Restrict Pushes |
|--------|------------|----------------------|-----------------|
| `test` | Yes (from `dev`) | dev-ci passes (lint, PHPStan, ShellCheck) | No direct push; merge only |
| `main` | Yes (from `test`) | test-deploy passes (build, deploy) | No direct push; merge only |

---

## Current Versions

- Plugin: Auto-bumped by CI on dev push (see `MM_VERSION` in `metamanager.php`)
- Server: Auto-bumped by CI on dev push (see `debian/changelog` and `VERSION`)
- Apt server: `34.136.87.92` (apt.richardkentgates.com)
- Production site: `104.197.172.183`
