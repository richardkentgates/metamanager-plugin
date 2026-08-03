# Metamanager Roadmap

Last updated 2026-08-03.

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
│ MM_Frontend (media output)  │          │  - jpegtran/optipng/cwebp   │
│ MM_Head_Emitter + Modules   │          │  - ffmpeg remux             │
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

---

## What's Left

### Code

| # | Severity | Issue | File |
|---|----------|-------|------|
| C5 | LOW | `exit` missing in business contact download sub-methods | `class-mm-mod-business-contact.php:73-84` |

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
