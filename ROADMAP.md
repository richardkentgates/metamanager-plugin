# Metamanager Roadmap

Complete audit findings from 100% code read of both repos. Generated 2026-07-24.

---

## Repository Overview

| Repo | Branch | Version | Purpose |
|------|--------|---------|---------|
| `metamanager-plugin` | dev/test/main | 2.3.6 | WordPress plugin: metadata sync, frontend output, admin UI, job queue |
| `metamanager` | dev/test/main | 2.4.7 | Daemon scripts (meta embed, compression), apt server deployment |

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

## CRITICAL Issues

### C1: `MM_Frontend::init()` is never called
**File:** `includes/class-mm-frontend.php:50` (definition), `metamanager.php:78` (require_once only)
**Impact:** All 4 methods (`output_json_ld`, `output_open_graph`, `output_license_link`, `output_head_tags`) are defined but unreachable. Media attachment pages get zero structured data — no ImageObject, no VideoObject, no AudioObject, no DigitalDocument, no media OG tags, no license links.
**Fix:** Add `MM_Frontend::init()` call to bootstrap, or wire it through `MM_Metadata_Loader`.

### C2: `write_job()` doesn't check `put_contents()` return
**File:** `includes/class-mm-job-queue.php:165`
**Impact:** If disk is full or permissions fail, no job file is written but DB records a pending row that will never process. Silent job loss with no error logged.
**Fix:** Check `file_put_contents()` return, delete DB row on failure, log error.

### C3: `mm_meta_synced` postmeta registered but never written
**File:** `includes/class-mm-metadata.php:79` (registers), line 308 (deletes)
**Impact:** Dead flag. `register_meta` defines it, cleanup code deletes it, but no code ever writes it. Confusing dead code.
**Fix:** Either implement sync tracking or remove the registration and cleanup code.

---

## HIGH Issues

### H1: `.processing` files not cleaned on attachment delete
**File:** `includes/class-mm-job-queue.php:362` (on_delete_cleanup)
**Impact:** If daemon is processing an attachment when it's deleted, the `*.json.processing` orphan remains on disk permanently. The glob only catches `*.json` and `*.json.failed`.
**Fix:** Extend glob pattern to also delete `*.json.processing`.

### H2: Duplicate `<title>` tag for themes without `title-tag` support
**Files:** `includes/metadata/class-mm-head-emitter.php:65` (outputs `<title>` directly) + `includes/metadata/class-mm-metadata-loader.php:33` (registers `pre_get_document_title` filter)
**Impact:** If theme lacks `add_theme_support('title-tag')`, both the emitter's `<title>` and the filter's output fire, producing duplicate title tags.
**Fix:** Remove the direct `<title>` output from the emitter. Let `pre_get_document_title` handle it exclusively.

### H3: REST API edits don't trigger metadata write-back
**File:** `includes/class-mm-metadata.php:563` (hooks `attachment_fields_to_save`)
**Impact:** REST API / WP-CLI edits to native metadata fields don't queue metadata sync jobs. Only the WP edit screen triggers write-back.
**Fix:** Hook `save_post` for attachment post type to detect native field changes and queue jobs.

### H4: `deploy.yml` metadata.json heredoc has indentation
**File:** `.github/workflows/deploy.yml:101-110`
**Impact:** Produces JSON with leading whitespace. The apt server `metadata.json` may have indentation issues.
**Fix:** Remove indentation from heredoc content, or use a proper JSON generation step.

---

## MEDIUM Issues

### M1: No `og:video`/`og:audio` for media attachments
**File:** `includes/metadata/modules/class-mm-mod-social.php`
**Impact:** Only outputs `og:image` for media. Video and audio attachments get no OG media tags. `MM_Frontend` (currently dead) was supposed to handle this.
**Fix:** Once C1 is fixed, verify `MM_Frontend::output_video_audio_open_graph()` works. If not, add to `MM_Mod_Social`.

### M2: Schema module skips content node for `WebPage`/`WebSite`
**File:** `includes/metadata/modules/class-mm-mod-schema.php:273`
**Impact:** Filters `post_type_object` and skips `content` node for WebPage/WebSite types, losing article properties (datePublished, dateModified, author).
**Fix:** Don't skip the content node — include article properties for all types that have them.

### M3: `SiteNavigationElement` nesting non-standard
**File:** `includes/metadata/modules/class-mm-mod-schema.php:109-114`
**Impact:** Nests under `hasPart` which won't produce Google rich results.
**Fix:** Use standard schema.org pattern or remove the nesting.

### M4: Custom JSON-LD has no validation
**File:** `includes/metadata/modules/class-mm-mod-schema.php:36-41`
**Impact:** Malformed input silently breaks entire `@graph`.
**Fix:** Add JSON validation with `json_decode` check and admin warning.

### M5: No metadata write-back dedup
**Impact:** Rapid save + bulk action queues two metadata jobs for the same attachment.
**Fix:** Check for existing pending job before writing.

### M6: Concurrent `mm_import_completed_jobs()` could duplicate verification
**File:** `metamanager.php:276`
**Impact:** Race condition if two WP-Cron processes run simultaneously.
**Fix:** Use a transient lock or `wp_next_scheduled` check.

---

## LOW Issues

### L1: Stray `}` in `class-mm-cron.php:293`
**Impact:** "Unexpected end of file" warning in PHP error log.
**Fix:** Remove the stray closing brace.

### L2: Stray `}` in `class-mm-daemon-bridge.php:326`
**Impact:** "Unexpected end of file" warning in PHP error log.
**Fix:** Remove the stray closing brace.

### L5: No server repo test workflow on `test` branch
**File:** `.github/workflows/build-deb.yml` (server repo)
**Impact:** Builds .deb without running tests on the test branch.
**Fix:** Add ShellCheck/lint step before build.

### L6: Zip structure inconsistency in `deploy.yml`
**File:** `.github/workflows/deploy.yml`
**Impact:** Includes `stubs/` directory while other workflows exclude it.
**Fix:** Align exclude patterns across all build workflows.

---

## Verified NOT Issues

- **Media Processing page**: Intentionally kept. Needed for batch metadata operations.
- **`MM_Admin::add_bulk_action()`**: Part of Media Processing feature, not dead code.
- **Two-frontend-system architecture**: Both `MM_Frontend` (media-specific) and `MM_Mod_*` (page-level) are needed. They handle different concerns.
- **Daemon field coverage**: PHP and shell scripts define identical field sets. Consistent.
- **Job queue filesystem contract**: PHP writes → daemon claims via atomic `mv` → writes result → PHP imports via WP-Cron. Solid design.
- **Verify system**: Exists (`rest_verify_attachment`, `verify_single_file()`, `calculate_verify_score()`) but no scheduled cron — requires manual REST API call. Not broken, just not auto-triggered.

---

## Implementation Order

### Phase 1: Critical fixes (do first)
1. **C1** — Wire up `MM_Frontend::init()` to restore media structured data output
2. **C2** — Add error handling to `write_job()` 
3. **C3** — Clean up dead `mm_meta_synced` registration

### Phase 2: High-impact fixes
4. **H1** — Fix `.processing` file cleanup on delete
5. **H2** — Fix duplicate `<title>` tag
6. **H3** — Add REST API write-back hook
7. **H4** — Fix deploy.yml heredoc indentation

### Phase 3: Medium improvements
8. **M1** — Verify/fix OG video/audio output
9. **M2** — Fix schema content node for WebPage/WebSite
10. **M3** — Fix SiteNavigationElement nesting
11. **M4** — Add custom JSON-LD validation
12. **M5** — Add write-back dedup
13. **M6** — Add cron import lock

### Phase 4: Low-priority cleanup
14. **L1** — Fix stray `}` in cron
15. **L2** — Fix stray `}` in daemon-bridge
16. **L5** — Add test workflow to server repo test branch
17. **L6** — Align zip build excludes

---

## Current Versions

- Plugin: **2.3.6** (dev/test/main)
- Server: **2.4.7** (dev/test/main)
- Production: v2.4.7-1 daemon .deb, plugin v2.3.6
- Apt server: `34.136.87.92` (apt.richardkentgates.com)
- Production site: `104.197.172.183`

---

## Pipeline

```
dev ──push──> dev-ci.yml ──pass──> version bump ──push──> dev
                                                              │
              push to test ──> test-deploy.yml ──> build zip ──> apt server
                                                              │
              push to main ──> main-release.yml ──> tag + GitHub release
                          ──> deploy.yml ──> production server
```

No branch protection. No PR requirements. Promotion = push to next branch.
