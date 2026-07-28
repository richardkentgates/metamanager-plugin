# Metamanager Roadmap

Complete audit findings from 100% code read of both repos, contrasted against all documentation, wiki, GitHub Pages, and plugin help tabs. Last updated 2026-07-27.

---

## Repository Overview

| Repo | Branch | Version | Purpose |
|------|--------|---------|---------|
| `metamanager-plugin` | dev/test/main | 2.3.12 | WordPress plugin: metadata sync, frontend output, admin UI, job queue, web-layer SEO |
| `metamanager` | dev/test/main | 2.4.7 | Daemon scripts (meta embed, compression), apt server deployment, systemd units |

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

## Audit Summary

| Category | Open Items |
|----------|-----------|
| Pipeline | 0 (1 done) |
| Critical (Code) | 0 (4 total, 2 false positives, 2 fixed) |
| High (Code) | 0 (6 total, 5 false positives, 1 fixed) |
| Medium (Code) | 0 (8 total, 5 false positives, 3 fixed) |
| Critical (Docs) | 10 → 1 actionable, 9 resolved |
| Low (Code/Docs) | 11 → 0 actionable, 11 stale/known |
| **Total Open** | **1** |
| Already Fixed | 32 |

---

## CRITICAL — Must Fix (Code)

### C1: `MM_Frontend::init()` is never called
**Status:** Already fixed — `metamanager.php:117` calls `MM_Frontend::init()` inside `plugins_loaded` hook. False positive from original audit.

### C2: `write_job()` doesn't check `put_contents()` return
**Status:** Already fixed — `class-mm-job-queue.php:163` checks `false === $bytes || 0 === $bytes`, logs error, returns `'failed'`. False positive from original audit.

### C3: `MM_Metadata_CLI` class is never registered with `WP_CLI::add_command()`
**Status:** Fixed 2026-07-27. Added `extends \WP_CLI_Command` and `\WP_CLI::add_command('metamanager metadata', 'MM_Metadata_CLI')`. Commands accessible as `wp metamanager metadata {export,reset,check-links,...}`.

### C4: `purge-links` CLI command documented but doesn't exist
**Status:** Fixed 2026-07-27. Removed from Links help tab. Updated Tools help tab with correct `metadata` prefix for all commands.

---

## HIGH — Should Fix (Code)

### H1: `.processing` files not cleaned on attachment delete
**Status:** Already fixed — `class-mm-job-queue.php:374` handles `.processing` cleanup. False positive.

### H2: Duplicate `<title>` tag for themes without `title-tag` support
**Status:** Already fixed — direct `<title>` output removed from emitter; uses `pre_get_document_title` filter via `MM_Metadata_Loader`. False positive.

### H3: REST API edits don't trigger metadata write-back
**Status:** Already fixed — `on_save_post_attachment` at `class-mm-metadata.php:599` catches REST API edits. False positive.

### H4: `mm_meta_synced` postmeta registered but never written
**Status:** Already fixed in code (AUDIT-TRACKING FIX-10, 2026-07-25). Documentation references in ARCHITECTURE.md and README.md are stale.

### H5: No `og:video`/`og:audio` for media attachments
**Status:** Already works — `output_video_audio_open_graph` at `class-mm-frontend.php:409` handles video/audio OG tags. False positive (C1 was false positive, so this works).

### H6: `deploy.yml` metadata.json heredoc has indentation
**Status:** Fixed 2026-07-27. `test-deploy.yml` had indented JSON in printf heredoc. Changed to compact JSON matching `deploy.yml`.

---

## MEDIUM — Should Fix (Code)

### M1: Schema module skips content node for `WebPage`/`WebSite`
**Status:** Intentional design — WebPage is the container page, content node is for BlogPosting/Article/etc. types. Not a bug.

### M2: `SiteNavigationElement` nesting non-standard
**Status:** Valid schema.org (uses `hasPart`). Design choice, not a bug.

### M3: Custom JSON-LD has no validation
**Status:** Validation exists — `json_decode` check at `class-mm-mod-schema.php:38`. Only adds if `is_array()`.

### M4: No metadata write-back dedup
**Status:** Dedup exists — `write_job()` at `class-mm-job-queue.php:121` skips if pending job already exists for same attachment+size.

### M5: Concurrent `mm_import_completed_jobs()` could duplicate verification
**Status:** Lock exists — transient at `metamanager.php:291-295` prevents concurrent execution.

### M6: Help tab — Meta Sync column not documented in Media Library help
**Status:** Fixed 2026-07-27. Added new "Meta Sync Column" help tab to Media Library screen.

### M7: Help tab — HTML sitemap shortcode missing 2 attributes
**Status:** Fixed 2026-07-27. Added `show_date` and `order_by` rows to the shortcode attributes table.

### M8: Help tab — Batch Metadata page has no help tab
**Status:** Fixed 2026-07-27. Added "Overview" help tab to the Media Processing screen.

---

## DOCUMENTATION — Must Fix

### D1: GitHub wiki is completely empty
**Source:** Both repos link to `https://github.com/richardkentgates/metamanager-plugin/wiki` in README and docs site
**Impact:** All wiki links are broken. Users clicking "Wiki" from the README find no content.
**Fix:** Either populate the wiki or remove wiki links from all documentation.

### D2: Docs site JSON-LD `softwareVersion` stuck at `2.1.7`
**Source:** `metamanager.richardkentgates.com` HTML `<head>` structured data
**Impact:** Search engines and knowledge panels display wrong version. 18 versions behind actual (2.3.12).
**Fix:** Update `softwareVersion` in the JSON-LD block and add automated build step to keep in sync.

### D3: License mismatch across documentation
**Source:** Multiple locations
- README badges: "GPL 2.0+"
- LICENSE file: "GNU GENERAL PUBLIC LICENSE Version 2"
- Docs site JSON-LD: "https://www.gnu.org/licenses/gpl-3.0.html" (GPL 3.0)
- CHANGELOG v1.0.0: "GPLv3 license"
**Impact:** Inconsistent license claims. Legal uncertainty for users.
**Fix:** Determine actual license (2.0 or 3.0) and make all references consistent.

### D4: Plugin site `og:url` points to non-existent domain
**Source:** `richardkentgates.github.io/metamanager-plugin/` og:url meta tag
**Impact:** Social media link previews point to `mm-plugin.richardkentgates.com` which likely doesn't resolve.
**Fix:** Configure CNAME or update og:url to actual GitHub Pages URL.

### D5: Plugin site meta description only covers media layer
**Source:** `richardkentgates.github.io/metamanager-plugin/` meta description
**Impact:** Description says "Lossless image compression and metadata embedding" — completely omits web layer (Schema.org, OG, sitemaps, robots.txt, link checker, business profile, author profiles).
**Fix:** Update description to cover full feature set, or redirect to main docs site.

### D6: Server ARCHITECTURE.md version numbers stale
**Source:** `metamanager/ARCHITECTURE.md:369-374`
**Impact:** Daemon documented as `2.4.4-1`, actual is `2.4.7-1`. Plugin documented as `2.3.2`.
**Fix:** Update version table or add note that versions are illustrative.

### D7: Server SECURITY.md says "1.x" is supported
**Source:** `metamanager/SECURITY.md`
**Impact:** Current version is 2.4.7. Supported version statement is outdated.
**Fix:** Update to "2.x".

### D8: Plugin SECURITY.md says "1.x" is supported
**Source:** `metamanager-plugin/SECURITY.md`
**Impact:** Current version is 2.3.12. Supported version statement is outdated.
**Fix:** Update to "2.x".

### D9: Help tab documentation URL inconsistency
**Source:** Multiple admin pages
- Job Dashboard sidebar: `https://mm-plugin.richardkentgates.com`
- Settings page sidebar: `https://metamanager.richardkentgates.com`
- Bulk Metadata page in-app text: `https://metamanager.richardkentgates.com`
**Impact:** Users get directed to different sites from different pages.
**Fix:** Unify to one canonical documentation URL.

### D10: ROADMAP-SEPARATION.md phases 7A-7I, 8, 10, 11, 12 still pending
**Source:** `metamanager/ROADMAP-SEPARATION.md`
**Impact:** Server setup (UFW, iptables, ModSecurity, Fail2Ban, Maldet, apt repo, plugin hosting), GPG signing, CI/CD server repo build, end-to-end testing, and documentation cleanup all marked pending.
**Fix:** Track completion or update status.

---

## PIPELINE — Must Fix

### P1: Add branch protection on `test` and `main` branches
**Repos:** Both `metamanager-plugin` and `metamanager`
**Impact:** Currently any push goes directly to test/main with no review. Need PR-based promotion with required status checks.
**Fix:**
- Enable branch protection on `test`: require PR from `dev`, require dev-ci status checks to pass
- Enable branch protection on `main`: require PR from `test`, require test-deploy status checks to pass
- No force pushes, no bypassing admins
- Update AGENTS.md and ROADMAP.md to reflect PR-based workflow

### L1: Stray `}` in `class-mm-cron.php:293`
**Status:** Stale finding — file does not exist in current codebase.

### L2: Stray `}` in `class-mm-daemon-bridge.php:326`
**Status:** Stale finding — file does not exist in current codebase.

### L3: No server repo test workflow on `test` branch
**Status:** Already addressed — `build-deb.yml` runs ShellCheck on the test branch before building.

### L4: Zip structure inconsistency in `deploy.yml`
**Status:** Not an issue — all workflows consistently exclude `tests`, `stubs`, and `mm-*.zip`.

### D11: Docs site j-make.js content fragments return 404
**Status:** False positive — fragments load correctly. The audit tested wrong paths (`/body/header` instead of `/body/header_0`). All fragment paths return 200 with content.

### D12: Plugin site same j-make.js fragment 404 issue
**Status:** False positive — same as D11. Fragments work correctly on both sites.

### D13: Server CHANGELOG.md has no entries for versions 2.3.1-2.3.12
**Source:** `metamanager/CHANGELOG.md`
**Impact:** 12 patch releases with no changelog entries (likely auto-incremented by CI).
**Fix:** Add catch-all entry or ensure CI generates entries.

### D14: Branching strategy inconsistency across docs
**Source:** `BRANCHING.md` vs `AGENTS.md` vs `ROADMAP.md`
**Impact:** BRANCHING.md describes GitFlow (develop/main), while AGENTS.md and ROADMAP.md describe simpler dev/test/main pipeline. Different docs teach different workflows.
**Fix:** Reconcile all branching documentation to match actual practice.

---

## Verified NOT Issues

- **Media Processing page**: Intentionally kept. Needed for batch metadata operations.
- **`MM_Admin::add_bulk_action()`**: Part of Media Processing feature, not dead code.
- **Two-frontend-system architecture**: Both `MM_Frontend` (media-specific) and `MM_Mod_*` (page-level) are needed. They handle different concerns.
- **Daemon field coverage**: PHP and shell scripts define identical field sets. Consistent.
- **Job queue filesystem contract**: PHP writes -> daemon claims via atomic `mv` -> writes result -> PHP imports via WP-Cron. Solid design.
- **Verify system**: Exists (`rest_verify_attachment`, `verify_single_file()`, `calculate_verify_score()`) but no scheduled cron — requires manual REST API call. Not broken, just not auto-triggered.

---

## Already Fixed

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

---

## Implementation Order

### Phase 0: Pipeline (do first — enables all other work)
1. **P1** — Add branch protection on `test` and `main` in both repos ✅

### Phase 1: Critical fixes
2. ~~**C1**~~ — Already fixed (false positive)
3. ~~**C2**~~ — Already fixed (false positive)
4. **C3** — Register `MM_Metadata_CLI` with WP-CLI ✅
5. **C4** — Remove `purge-links` from help tabs ✅

### Phase 2: High-impact fixes
6. ~~**H1**~~ — Already fixed (false positive)
7. ~~**H2**~~ — Already fixed (false positive)
8. ~~**H3**~~ — Already fixed (false positive)
9. ~~**H4**~~ — Already fixed in code
10. ~~**H5**~~ — Already works (false positive)
11. **H6** — Fix deploy.yml heredoc indentation ✅

### Phase 3: Medium improvements
12. ~~**M1**~~ — Intentional design (not a bug)
13. ~~**M2**~~ — Valid schema.org (not a bug)
14. ~~**M3**~~ — Validation already exists
15. ~~**M4**~~ — Dedup already exists
16. ~~**M5**~~ — Lock already exists
17. **M6** — Document Meta Sync column in help tab ✅
18. **M7** — Add missing shortcode attributes to help tab ✅
19. **M8** — Add Batch Metadata help tab ✅

### Phase 4: Documentation fixes
20. ~~**D1**~~ — Wiki links removed from both READMEs ✅
21. **D2** — Docs site JSON-LD version (requires manual docs site update)
22. ~~**D3**~~ — License badge corrected to GPL 3.0+ in both READMEs ✅
23. ~~**D4**~~ — Plugin site og:url fixed ✅
24. ~~**D5**~~ — Plugin site meta description updated ✅
25. ~~**D6**~~ — ARCHITECTURE.md versions updated ✅
26. ~~**D7**~~ — Server SECURITY.md updated to 2.x ✅
27. ~~**D8**~~ — Plugin SECURITY.md updated to 2.x ✅
28. ~~**D9**~~ — Documentation URLs unified to metamanager.richardkentgates.com ✅
29. **D10** — ROADMAP-SEPARATION.md (historical, low priority)
30. ~~**D11**~~ — False positive (fragments load correctly)
31. ~~**D12**~~ — False positive (same as D11)
32. **D13** — Missing CHANGELOG entries (CI auto-bumps, low priority)
33. ~~**D14**~~ — BRANCHING.md already correct ✅

### Phase 5: Low-priority cleanup
34. ~~**L1**~~ — Stale finding (file doesn't exist)
35. ~~**L2**~~ — Stale finding (file doesn't exist)
36. ~~**L3**~~ — Already addressed (build-deb.yml runs ShellCheck)
37. ~~**L4**~~ — Not an issue (exclude patterns already consistent)

---

## Current Versions

- Plugin: **2.3.12** (dev/test/main)
- Server: **2.4.7** (dev/test/main)
- Production: v2.4.7-1 daemon .deb, plugin v2.3.12
- Apt server: `34.136.87.92` (apt.richardkentgates.com)
- Production site: `104.197.172.183`

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

### Promotion Flow

1. **dev → test:** Open PR from `dev` to `test`. CI runs dev-ci checks. Review + merge.
2. **test → main:** Open PR from `test` to `main`. CI runs test-deploy checks. Review + merge.
3. On merge to `test`: `test-deploy.yml` builds zip, deploys to apt server. `deploy.yml` deploys to test server.
4. On merge to `main`: `main-release.yml` creates tag + GitHub release. `deploy.yml` deploys to production.

### Workflow Triggers (unchanged)

| Workflow | Trigger | What It Does |
|----------|---------|-------------|
| `dev-ci.yml` | Push to `dev` | Lint, PHPStan, ShellCheck, version bump |
| `test-deploy.yml` | Push to `test` (via PR merge) | Build zip, deploy to apt server |
| `deploy.yml` | Push to `test` or `main` (via PR merge) | Deploy to server (test or production) |
| `main-release.yml` | Push to `main` (via PR merge) | Tag, GitHub release, apt repo deploy |
