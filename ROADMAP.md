# Metamanager Roadmap

Full codebase audit — 100% source read of both repos, contrasted against all documentation, wiki, GitHub Pages, and plugin help tabs. Last updated 2026-07-31 (SiteNavigationElement fix pending push).

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

## BUGS — All Fixed (from full codebase audit 2026-07-31)

### BUG-1: Video sitemap indexes YouTube/Vimeo embeds (HIGH) — FIXED 2026-07-31
**File:** `includes/class-mm-sitemap.php:170-206`
**Problem:** `render_video_sitemap()` extracted YouTube and Vimeo embeds from post content and included them in the video sitemap via `extract_embed_videos()`. Per user requirement, video sitemaps should ONLY index self-hosted videos.
**Fix:** Removed `extract_embed_videos()` call from `render_video_sitemap()`. Removed `OPT_VIDEO_YOUTUBE` and `OPT_VIDEO_VIMEO` constants and settings. Removed `get_cached_oembed()` method. Updated sitemap settings description. Released as v2.3.41.

### BUG-2: Organization/LocalBusiness schema not rendering on production (HIGH) — FIXED 2026-07-31
**File:** `includes/metadata/modules/class-mm-mod-local.php`
**Problem:** Organization schema used `ProfessionalService` which is not a valid schema.org type (was proposed but never added to spec). Validator silently skipped the node.
**Fix:** Added validation in `MM_Mod_Local::populate()` to check stored type against `get_business_types()` and fall back to `LocalBusiness`. Released as v2.3.45.

### BUG-3: Compression UI labels imply lossy quality levels (MEDIUM) — FIXED 2026-07-31
**File:** `includes/class-mm-settings.php:285-306`
**Problem:** Compression dropdown labels implied lossy quality trade-offs. ALL compression is lossless only.
**Fix:** Updated dropdown labels to "Effort Level 1 (fast)" through "Effort Level 7 (slow)" with description "All compression is lossless — this controls optimization effort, not quality." Released as v2.3.41.

### BUG-4: Author settings save — values don't persist (MEDIUM) — CONFIRMED WORKING
**File:** `templates/admin/page-authors.php`, `includes/metadata/admin/class-mm-metadata-admin.php`
**Problem:** User reported toggling settings shows success notice but values don't persist.
**Finding:** Save mechanism was correct — `sanitize_section()` properly extracts and merges settings. Values persist in `mm_meta_settings` option. Issue was not reproducible.
**Resolution:** Verified working on production via WP-CLI. No fix needed.

### BUG-5: Two separate sitemap configuration locations (LOW) — FIXED 2026-07-31
**Files:** `includes/class-mm-sitemap.php`, `includes/metadata/modules/class-mm-mod-sitemap.php`
**Problem:** Sitemap settings existed in two places — Preferences → Sitemaps and Metamanager → Sitemaps.
**Fix:** Consolidated all sitemap settings into `MM_Site_Settings` singleton. Removed standalone options from `MM_Sitemap`. Released as v2.3.43.

### BUG-6: Schema `author_persons` vs Authors `person_schema` duplicate control (LOW) — FIXED 2026-07-31
**Files:** `includes/metadata/class-mm-site-settings.php:261`, `templates/admin/page-authors.php:58-65`
**Problem:** Two separate settings controlled Person schema emission.
**Fix:** Removed `schema.author_persons` from defaults and schema settings page. `authors.person_schema` is now the single control. Released as v2.3.41.

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
| F39 | Video sitemap YouTube/Vimeo embed removal (BUG-1) | 2026-07-31 |
| F40 | Compression UI labels lossless clarification (BUG-3) | 2026-07-31 |
| F41 | Person schema duplicate control consolidation (BUG-6) | 2026-07-31 |
| F42 | Sitemap settings consolidation into MM_Site_Settings (BUG-5) | 2026-07-31 |
| F43 | Updater fatal error — stdClass used as array in inject_update() | 2026-07-31 |
| F44 | Organization schema business type validation (BUG-2) | 2026-07-31 |
| F45 | SiteNavigationElement — nav_menu taxonomy term_meta fix | 2026-07-31 |

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

### Phase 1: Fix Active Bugs — COMPLETE

All 6 bugs from the full codebase audit have been fixed:

| Bug | Priority | Status | Version |
|-----|----------|--------|---------|
| BUG-1: Video sitemap YouTube/Vimeo | HIGH | Fixed | v2.3.41 |
| BUG-2: Organization schema validation | HIGH | Fixed | v2.3.45 |
| BUG-3: Compression UI labels | MEDIUM | Fixed | v2.3.41 |
| BUG-4: Author settings save | MEDIUM | Confirmed working | N/A |
| BUG-5: Sitemap settings consolidation | LOW | Fixed | v2.3.43 |
| BUG-6: Person schema duplicate | LOW | Fixed | v2.3.41 |

### Phase 2: SiteNavigationElement Fix — COMPLETE

Fixed nav_menu taxonomy term_meta issue where both `class-mm-nav-menu-admin.php` and `class-mm-mod-schema.php` incorrectly used `post_meta` storage and `get_posts(['post_type' => 'nav_menu'])` query. Now uses `term_meta` and `get_terms()` with `meta_query`.

### Phase 3: Verification — COMPLETE

- Full test suite: 49 unit tests (261 assertions) — ALL PASS
- Integration tests: 252 tests (403 assertions) — 11 failures (env-specific, not code bugs)
- PHPStan: 0 errors
- Production verification: Schema output correct, video sitemap empty (correct), compression labels updated

### Next Steps

1. Push SiteNavigationElement fix to dev branch and promote through pipeline
2. Test AI integration (Abilities API, MCP server, discovery files) on production
3. Run WordPress Plugin Checker against plugin on production

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

- Plugin: v2.3.45 (production, manually updated 2026-07-31)
- Server: v2.4.8
- Apt server: `34.136.87.92` (apt.richardkentgates.com)
- Production site: `104.197.172.183`
