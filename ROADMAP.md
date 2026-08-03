# Metamanager Roadmap

Full codebase audit — 100% source read of both repos, contrasted against all documentation, wiki, GitHub Pages, and plugin help tabs. Last updated 2026-08-01.

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
│ MM_Abilities (AI API)       │          │                             │
│ MM_MCP_Server (AI tools)    │          │                             │
│ MM_Mod_Discovery (llms.txt) │          │                             │
└─────────────────────────────┘          └─────────────────────────────┘
```

**Frontend output systems:**
- `MM_Frontend` — media-specific structured data (ImageObject/VideoObject/AudioObject/DigitalDocument + OG for media + license links)
- `MM_Mod_*` via `MM_Head_Emitter` — page-level SEO (title/desc/canonical/robots + WebSite/BlogPosting/LocalBusiness schema + OG/Twitter cards)
- `MM_Mod_Discovery` — machine-readable AI agent discovery files (`/llms.txt`, `/llms-full.txt`, `/.well-known/api-catalog`)

**AI integration:**
- `MM_Abilities` — registers 6 SEO capabilities with WordPress Abilities API (requires WP 6.9+, gracefully degrades)
- `MM_MCP_Server` — exposes abilities as MCP tools via WordPress MCP Adapter plugin (gracefully degrades)
- `MM_Mod_Discovery` — generates `llms.txt` and API catalog for AI agent discovery

---

## Current Status — Production

| Item | Value |
|------|-------|
| Plugin on production | v2.3.58 (auto-updated via apt) |
| Plugin on apt server | v2.3.63 (CI deployed) |
| Daemon version | v2.4.27 |
| WordPress version | 6.9 |
| Production URL | https://thepeosolution.com |
| Production IP | 104.197.172.183 |
| Apt server IP | 34.136.87.92 (apt.richardkentgates.com) |

---

## Audit Bugs — All Fixed

All 6 bugs from the full codebase audit (2026-07-31) are fixed and deployed.

| # | Bug | Priority | Fix | Version |
|---|-----|----------|-----|---------|
| BUG-1 | Video sitemap indexes YouTube/Vimeo embeds | HIGH | Removed embed extraction, YouTube/Vimeo settings, `get_cached_oembed()` | v2.3.41 |
| BUG-2 | Organization schema uses invalid type `ProfessionalService` | HIGH | Validate against `get_business_types()`, fall back to `LocalBusiness` | v2.3.45 |
| BUG-3 | Compression UI labels imply lossy quality | MEDIUM | Updated labels to "Effort Level" with lossless clarification | v2.3.41 |
| BUG-4 | Author settings don't persist | MEDIUM | Confirmed working — values persist correctly, not reproducible | N/A |
| BUG-5 | Two separate sitemap configuration pages | LOW | Consolidated into `MM_Site_Settings`, removed standalone options | v2.3.43 |
| BUG-6 | Duplicate Person schema controls | LOW | Removed `schema.author_persons`, kept `authors.person_schema` | v2.3.41 |

---

## Additional Fixes (Post-Audit)

| # | Issue | Version |
|---|-------|---------|
| F39 | Video sitemap YouTube/Vimeo embed removal | v2.3.41 |
| F40 | Compression UI labels lossless clarification | v2.3.41 |
| F41 | Person schema duplicate control consolidation | v2.3.41 |
| F42 | Sitemap settings consolidation into MM_Site_Settings | v2.3.43 |
| F43 | Updater fatal error — stdClass used as array in `inject_update()` | v2.3.44 |
| F44 | Organization schema business type validation | v2.3.45 |
| F45 | SiteNavigationElement — nav_menu taxonomy term_meta fix | v2.3.46 |
| F46 | Discovery files rewrite rules for .txt extensions, `class_exists` bug | v2.3.47 |

---

## Earlier Fixes (May-July 2026)

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
| F14-F30 | Stale findings, false positives, doc updates | 2026-07-27 |
| F31 | Updater filter fix — returns `false` when no update to inject | 2026-07-29 |
| F32 | Metadata transient stale cache cleared | 2026-07-29 |
| F33 | Version header drift fixed (CI sed hardened) | 2026-07-29 |
| F34 | Production update_plugins option rebuilt | 2026-07-29 |
| F35 | All promotions completed through PR #32 (v2.3.40) | 2026-07-30 |
| F36 | MM_CLI fatal error fix (redundant WP_CLI stubs removed) | 2026-07-30 |
| F37 | CI phpunit.xml path fix | 2026-07-30 |
| F38 | WP_CLI\Utils function stubs added | 2026-07-30 |

---

## Verified NOT Issues

- **Media Processing page**: Intentionally kept. Needed for batch metadata operations.
- **`MM_Admin::add_bulk_action()`**: Part of Media Processing feature, not dead code.
- **Two-frontend-system architecture**: Both `MM_Frontend` (media-specific) and `MM_Mod_*` (page-level) are needed. Different concerns.
- **Daemon field coverage**: PHP and shell scripts define identical field sets. Consistent.
- **Job queue filesystem contract**: PHP writes -> daemon claims via atomic `mv` -> writes result -> PHP imports via WP-Cron. Solid design.
- **Verify system**: Exists but no scheduled cron — requires manual REST API call. Not broken, just not auto-triggered.
- **Schema primary menu**: No menu checked = NO SiteNavigationElement schema (intentional design).
- **All compression is lossless**: optipng effort 1-7, jpegtran `-copy all -optimize -progressive`, cwebp `-m 6 -q 100`, ffmpeg `-c copy`.

---

## Audit #2 — 2026-08-01

Full codebase audit covering code quality, test coverage, and documentation.

### Codebase Issues

| # | Severity | Issue | File | Status |
|---|----------|-------|------|--------|
| C1 | **CRITICAL** | `METADATA_URL` uses `http://` not `https://` — MITM can inject malicious plugin zip | `includes/class-mm-updater.php:33` | FIXED |
| C2 | MEDIUM | `MM_Importer` class (328 lines) never loaded — dead code | `includes/metadata/class-mm-importer.php` | FIXED |
| C3 | MEDIUM | `sslverify => false` in link checker — disables SSL verification | `includes/metadata/modules/class-mm-mod-links.php:260` | FIXED |
| C4 | LOW | `wpmu_new_blog` hook deprecated since WP 5.1, plugin requires WP 6.2+ | `metamanager.php:218` | FIXED |
| C5 | LOW | Business contact download endpoints lack `exit` in sub-methods | `includes/metadata/modules/class-mm-mod-business-contact.php:73-84` | OPEN |

### Test Coverage Gaps

45% of classes have zero test coverage (19 untested classes). Top priority gaps:

| Priority | Class | Lines | Why Critical | Status |
|----------|-------|-------|-------------|--------|
| 1 | `MM_Page_Context` | 124 | Foundation for every module — wrong context = wrong output everywhere | FIXED |
| 2 | `MM_Mod_Head_Meta` | 351 | Title resolution (9 levels), canonical, robots — drives all SEO output | FIXED |
| 3 | `MM_Head_Emitter` | 106 | Orchestrates all module output into wp_head | FIXED |
| 4 | `MM_Mod_Author` | 92 | Person schema for Knowledge Panel eligibility | FIXED |
| 5 | `MM_Mod_Local` | 341 | LocalBusiness schema — highest LOC untested module | FIXED |

Other untested: `MM_Post_Meta_Panel` (198 lines), `MM_Metadata_Admin` (366 lines), `MM_Mod_Social` (247 lines), `MM_Mod_Sitemap_Web` (337 lines), `MM_Abilities` (436 lines), `MM_Term_Meta_Panel` (119 lines), `MM_User_Meta_Panel` (105 lines), `MM_Mod_Robots` (67 lines), `MM_Mod_Hygiene` (79 lines), `MM_Mod_Html_Sitemap` (257 lines), `MM_MCP_Server` (60 lines), `MM_Biz_Card_CSS` (103 lines), `MM_Status` (338 lines), `MM_Metadata_Help` (293 lines), `MM_Metadata_Loader` (71 lines).

### Documentation Issues

| # | Severity | File | Issue | Status |
|---|----------|------|-------|--------|
| D1 | **HIGH** | `readme.txt` | Changelog stuck at v2.1.7 (40+ releases missing) | FIXED |
| D2 | **HIGH** | `readme.txt` | LICENSE header says GPL-2.0 but LICENSE file is GPL-3.0 | FIXED |
| D3 | **HIGH** | `CHANGELOG.md` | 40+ auto-increment noise entries bury real changelog | FIXED |
| D4 | **HIGH** | `ROADMAP.md` | WordPress listed as "7.0.2" (doesn't exist); version stale at 2.3.45 | FIXED |
| D5 | **HIGH** | `JOB_QUEUE_SPEC.md` | `job_type` wrong (`compress` vs `compression`); `metadata` key wrong (`metadata` vs `fields`); trigger values incomplete | FIXED |
| D6 | **HIGH** | `ARCHITECTURE.md` | REST API table missing 3+ endpoints; `daemons/` listed but not in plugin repo; several files missing from layout tree | FIXED |
| D7 | **HIGH** | `docs/` (gh-pages) | Quick Links point to 5 non-existent pages | FIXED |
| D8 | MEDIUM | `metamanager.php` | Plugin URI points to server repo, not plugin repo | FIXED |
| D9 | MEDIUM | `AGENTS.md` | Compatibility map example stale (ends at 2.3.27) | FIXED |
| D10 | MEDIUM | `CONTRIBUTING.md` | PHP/WordPress version requirements inconsistent; references non-existent test script | FIXED |
| D11 | MEDIUM | `SECURITY.md` | REST endpoint table missing `/embed`; AJAX capability claims outdated | FIXED |
| D12 | MEDIUM | `.github/` | No issue templates, PR templates, or funding config | FIXED |

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

**Promotions are manual** (`workflow_dispatch`). Direct pushes to `test` and `main` are blocked by branch protection.

### Branch Protection Rules

| Branch | Requires PR | Required Status Checks | Restrict Pushes |
|--------|------------|----------------------|-----------------|
| `test` | Yes (from `dev`) | ci.yml passes (lint, PHPStan, ShellCheck, tests) | No direct push; merge only |
| `main` | Yes (from `test`) | ci.yml passes | No direct push; merge only |

### CI Workflows

| Branch | Workflow | Trigger |
|--------|----------|---------|
| `dev` | `ci.yml` — lint + PHPStan + ShellCheck + phpunit + build artifact | Push to `dev` |
| `dev` | `version-bump.yml` — auto-increment patch version | Push to `dev` (skips if actor is `github-actions[bot]`) |
| `test` | `test-deploy.yml` — build zip + deploy to apt server | Push to `test` |
| `main` | `release.yml` — tag + GitHub release + deploy to apt server | Push to `main` (or tag push) |
| any | `promote-to-test.yml` — creates PR dev→test, merges | Manual (`workflow_dispatch`) |
| any | `promote-to-main.yml` — creates PR test→main, merges, tags | Manual (`workflow_dispatch`) |

### Compatibility Map

`daemon-compatibility.json` maps plugin versions to daemon versions. CI auto-bumps `MM_VERSION` on every dev push and auto-adds the next entry to the compatibility map.

---

## Pending Tasks

### Immediate (Security)

- [ ] **C1**: Change `METADATA_URL` from `http://` to `https://` in `includes/class-mm-updater.php:33`

### Short-term (Code Quality)

- [ ] **C2**: Wire `MM_Importer` into loader or delete the file
- [ ] **C4**: Remove deprecated `wpmu_new_blog` hook (plugin requires WP 6.2+)
- [ ] **C5**: Add `exit` to business contact download sub-methods

### Short-term (Test Coverage — Priority 1)

- [ ] Write tests for `MM_Page_Context` (foundation class)
- [ ] Write tests for `MM_Mod_Head_Meta` (title resolution, canonical, robots)
- [ ] Write tests for `MM_Head_Emitter` (output orchestration)
- [ ] Write tests for `MM_Mod_Author` (Person schema)
- [ ] Write tests for `MM_Mod_Local` (LocalBusiness schema)

### Short-term (Test Coverage — Priority 2)

- [ ] Write tests for `MM_Post_Meta_Panel` (per-post SEO save)
- [ ] Write tests for `MM_Metadata_Admin` (settings, sanitization, AJAX)
- [ ] Write tests for `MM_Mod_Social` (OG + Twitter cards)
- [ ] Write tests for `MM_Mod_Sitemap_Web` (XML sitemap engine)

### Short-term (Documentation)

- [ ] **D1**: Update `readme.txt` changelog to cover all 2.2.x-2.3.x releases
- [ ] **D2**: Resolve GPL-2.0 vs GPL-3.0 license mismatch
- [ ] **D3**: Clean up `CHANGELOG.md` — strip auto-increment noise, preserve real entries
- [ ] **D5**: Fix `JOB_QUEUE_SPEC.md` — correct `job_type`, `metadata` key, trigger values
- [ ] **D6**: Update `ARCHITECTURE.md` — fix REST API table, remove `daemons/`, add missing files
- [ ] **D8**: Fix Plugin URI in `metamanager.php` to point to plugin repo
- [ ] **D9**: Update `AGENTS.md` compatibility map example

### Medium-term

- [ ] **D7**: Build out GitHub Pages docs (installation, features, configuration, FAQ, troubleshooting)
- [ ] **D10**: Fix `CONTRIBUTING.md` version requirements and test script references
- [ ] **D11**: Update `SECURITY.md` REST endpoint table and AJAX capability claims
- [ ] **D12**: Add GitHub issue templates, PR templates, funding config
- [ ] WordPress Plugin Checker — run via browser admin
- [ ] Consider adding `wpseo`/`rank-math` schema compatibility imports
- [ ] Consider structured data validation endpoint

---

## Release History

| Version | Key Changes | Date |
|---------|------------|------|
| v2.3.51 | CI workflow refactor — clean promotion chain | 2026-08-01 |
| v2.3.50 | CI workflow refactor — clean promotion chain | 2026-08-01 |
| v2.3.49 | CI workflow refactor — daemon-compatibility auto-add | 2026-08-01 |
| v2.3.48 | Test fixes — table existence checks, suppress_errors, delta pattern | 2026-08-01 |
| v2.3.47 | Discovery files rewrite rules fix | 2026-07-31 |
| v2.3.46 | SiteNavigationElement term_meta fix | 2026-07-31 |
| v2.3.45 | Organization schema business type validation | 2026-07-31 |
| v2.3.44 | Updater fatal error fix (stdClass → array) | 2026-07-31 |
| v2.3.43 | Sitemap settings consolidation into MM_Site_Settings | 2026-07-31 |
| v2.3.41 | Video sitemap self-hosted only, compression labels, person schema consolidation | 2026-07-31 |

---

## Conventions

- Branch protection on `test` and `main`: PRs required, no direct pushes
- Promotions are manual (`workflow_dispatch`) — no auto-trigger on push
- VERSION file must stay in sync with debian/changelog (CI handles this automatically)
- CI auto-bumps both `debian/changelog` and `VERSION` on every dev push — never edit either file manually
- PHP 8.2 for WP-CLI (`php8.2 /usr/local/bin/wp --path=/srv/www/wordpress`)
- SSH user: `richardkentgates` (not root); use default SSH key (no `-i` flag)
- Plugin triggers daemon updates automatically — no manual SSH prompts on success, only on failure
- No system reboot required for daemon updates — `systemctl restart` in-place
- Always log errors/warnings to WordPress error log and OS syslog; info-level only when WP_DEBUG enabled
- Never assume causes — run controlled tests to identify and isolate issues
- Never SCP files to production outside active development sessions — always use native update systems
- Compression is lossless ONLY — no lossy compression allowed anywhere
- Video sitemaps index self-hosted videos only — not YouTube/Vimeo embeds
