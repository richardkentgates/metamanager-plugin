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
| Plugin on production | v2.3.45 (manually updated 2026-07-31) |
| Plugin on apt server | v2.3.47 (CI deployed) |
| Daemon version | v2.4.8 |
| WordPress version | 7.0.2 |
| Production URL | https://thepeosolution.com |
| Production IP | 104.197.172.183 |
| Apt server IP | 34.136.87.92 (apt.richardkentgates.com) |

**WordPress updater**: Will detect v2.3.47 on next scheduled check (every 12 hours). Metadata transient was cleared.

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

## Production State

### What's Deployed (v2.3.45, manually installed)

- [x] All 6 audit bugs fixed
- [x] SiteNavigationElement — term_meta fix (primary checkbox on menus)
- [x] Organization schema — business type validation
- [x] Video sitemap — self-hosted only
- [x] Compression — lossless labels
- [x] Sitemap settings — consolidated
- [x] Person schema — single control
- [x] Updater — fatal error fixed, filter returns false when no update

### What's on Apt Server (v2.3.47, CI deployed)

- [x] All of v2.3.45
- [x] Discovery files — rewrite rules for .txt extensions fixed
- [x] Discovery files — `class_exists` bug fixed
- [x] `add_rewrite_rule` replaces broken `add_rewrite_endpoint`
- [x] Priority 1 on `template_redirect` to prevent canonical redirect interception

### Discovery Files — Verified Working on Production

| Endpoint | Status | Content-Type |
|----------|--------|-------------|
| `/llms.txt` | 200 OK | text/plain |
| `/llms-full.txt` | 200 OK | text/plain |
| `/.well-known/api-catalog` | 200 OK | application/linkset+json |

### AI Integration — Graceful Degradation

| Component | Status | Reason |
|-----------|--------|--------|
| Abilities API | Degrades gracefully | WP 7.0.2 doesn't have `wp_register_ability` yet |
| MCP Server | Degrades gracefully | MCP Adapter plugin not installed |
| Discovery files | Working | All 3 endpoints serving |

---

## Pending Tasks

### Immediate

- [ ] Update production plugin to v2.3.47 via WordPress updater (apt server has it, transient cleared)

### Short-term

- [ ] Install WordPress MCP Adapter plugin on production when available
- [ ] Re-test Abilities API when WordPress adds `wp_register_ability` (planned for WP 6.9+, may be in future minor release)
- [ ] Add integration tests for discovery file endpoints
- [ ] Add integration tests for nav_menu term_meta operations

### Medium-term

- [ ] WordPress Plugin Checker — run via browser admin (hangs over SSH due to runtime HTTP checks)
- [ ] Consider adding `wpseo`/`rank-math` schema compatibility imports
- [ ] Consider structured data validation endpoint (check schema.org compliance)

---

## Release History

| Version | Key Changes | Date |
|---------|------------|------|
| v2.3.41 | Video sitemap self-hosted only, compression labels, person schema consolidation | 2026-07-31 |
| v2.3.43 | Sitemap settings consolidation into MM_Site_Settings | 2026-07-31 |
| v2.3.44 | Updater fatal error fix (stdClass → array) | 2026-07-31 |
| v2.3.45 | Organization schema business type validation | 2026-07-31 |
| v2.3.46 | SiteNavigationElement term_meta fix | 2026-07-31 |
| v2.3.47 | Discovery files rewrite rules fix | 2026-07-31 |

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

### CI Workflows

| Branch | Workflow | Trigger |
|--------|----------|---------|
| `dev` | ShellCheck + lint + PHPStan + auto-version bump | Push to `dev` |
| `test` | Build zip + deploy to apt repo | PR merge from `dev` |
| `main` | Create git tag + GitHub release + deploy to apt repo | PR merge from `test` |

### Compatibility Map

`daemon-compatibility.json` maps plugin versions to daemon versions. CI auto-bumps `MM_VERSION` on every dev push. Developers must add an entry for `current_version + 1` before pushing.

---

## Conventions

- Branch protection on `test` and `main`: PRs required, no direct pushes
- Promotion = open PR from `dev` → `test` or `test` → `main`, CI runs, merge
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
