# AGENTS.md — Metamanager Plugin

## MANDATORY WORKFLOW — ZERO EXCEPTIONS

**You are FORBIDDEN from doing ANY of the following:**
- Checking out `test` or `main` locally
- Running `git checkout test`, `git checkout main`, or `git switch test/main`
- Running `git merge`, `git rebase`, `git push`, or `git reset` on `test` or `main`
- Running `git branch -d`, `git branch -D`, `git push --delete` on `test` or `main`
- Creating files, editing files, or running any `git` command while on `test` or `main`
- Running `gh pr merge` to resolve conflicts (close the PR instead)

**You MUST follow this exact process for ALL changes:**

1. **ALL work happens on `dev`**: `git checkout dev`, make changes, commit, push to dev
2. **Promote via workflow_dispatch only**: Trigger `promote-to-test.yml` — merges dev→test, builds, deploys to apt
3. **Then promote test→main**: Trigger `promote-to-main.yml` — merges test→main, tags, releases, deploys to apt
4. **If a merge has conflicts**: CLOSE the workflow, do NOT try to resolve them by checking out test/main
5. **If you need to update test with main's changes**: Create a new empty commit on dev that triggers CI, or ask the user to resolve

**The ONLY branch you are allowed to checkout, edit, commit, or push is `dev`.**

## CI Flow

```
dev  ──  all development, direct push; CI runs checks + auto-version bump
    │  workflow_dispatch: promote-to-test.yml
    ▼
test  ──  build zip + deploy to apt server TEST channel (/metamanager-test/)
    │  workflow_dispatch: promote-to-main.yml
    ▼
main  ──  tag + GitHub release + deploy to apt server PRODUCTION channel (/metamanager/)
```

- On every dev push: CI first auto-bumps `MM_VERSION`, then runs PHP lint, PHPStan, ShellCheck, integration tests, and builds artifact
- The actor check (`github.actor != 'github-actions[bot]'`) prevents infinite loops — version bump commits don't re-trigger CI
- Promotion workflows merge directly via git (no PRs), build, and deploy to apt server
- **Test channel**: `/var/www/html/metamanager-test/` — safe for testing, does not affect production WordPress updates
- **Production channel**: `/var/www/html/metamanager/` — WordPress polls this for updates via `MM_Updater`
- WordPress detects the update via `MM_Updater` making direct HTTP requests to `metadata.json` (no caching)

## Deployment Rules

**NEVER SCP or copy files directly to production servers unless explicitly testing a fix.**

All software must move to production through native update systems:

- **Plugin test channel**: Push to `dev` → trigger `promote-to-test.yml` → merges dev→test, builds zip → deploys to `metamanager-test/` on apt server → WordPress detects update via `MM_Updater`
- **Plugin production channel**: Push to `dev` → trigger `promote-to-main.yml` → merges test→main, tags, releases → deploys to `metamanager/` on apt server → WordPress detects update via `MM_Updater`
- **Daemon test channel**: Push to `dev` → trigger `promote-to-test.yml` → merges dev→test, builds `.deb` → deploys to `dists/test` on apt server → install on test site via `apt-get install -t test`
- **Daemon production channel**: Push to `dev` → trigger `promote-to-main.yml` → merges test→main, tags, releases → deploys to `dists/stable` on apt server → install on production site via `apt-get upgrade` or `apt-get install -t stable`

The only exception is temporary testing during active development sessions, where files may be SCP'd for immediate verification. After testing, the fix must go through the proper pipeline before being considered deployed.

## Daemon Version Detection

The plugin is the single authority for daemon version management. The plugin's `MM_Daemon_Updater` reads `daemon-compatibility.json` and the installed `VERSION` file, and triggers `apt-get update && apt-get install -y metamanager` when versions don't match.

### Architecture

- **Plugin PHP** (`MM_Daemon_Updater`): Reads `daemon-compatibility.json` (bundled with the plugin) and the `VERSION` file. When versions don't match, triggers `apt-get update && apt-get install -y metamanager` and restarts daemons. Called via `MM_Updater` after plugin update.

The plugin is the single authority for daemon version management.

### Diagnosis Cases

The `diagnose()` method returns a specific status for display:

| Status | Condition | Message |
|--------|-----------|---------|
| `ok` | VERSION matches map | "Daemon v{X} is up to date." |
| `error` | VERSION file missing | "Daemon VERSION file not found at {path}." |
| `error` | VERSION file empty/unreadable | "Daemon VERSION file exists at {path} but is empty or unreadable." |
| `error` | Compatibility map missing | "Compatibility map not found at {path}." |
| `error` | Plugin version not in map | "Plugin v{X} is not listed in daemon-compatibility.json." |
| `mismatch` | Installed ≠ required | "Daemon version mismatch: installed v{X}, required v{Y}." |

### Compatibility Map

**File**: `daemon-compatibility.json` (in plugin root)

**Format**:
```json
{
  "2.3.82": "2.4.32",
  "2.3.81": "2.4.32",
  "2.3.80": "2.4.32"
}
```

**Keys**: Plugin version strings (`MM_VERSION` from `metamanager.php`)
**Values**: Daemon version strings (from `/usr/local/lib/metamanager/VERSION`)

### Many-to-One Mapping

Multiple plugin versions map to the same daemon version. This is normal — most plugin releases are plugin-only changes (UI fixes, new metadata fields, etc.) that don't require daemon changes.

### Plugin Manages the Map

The plugin is the single authority for `daemon-compatibility.json`. The plugin reads this file to determine which daemon version is required, and triggers `apt-get update && apt-get install -y metamanager` when versions don't match. The daemon repo does NOT write to this file.

When a new daemon version is released, the plugin developer adds an entry to `daemon-compatibility.json` mapping the current plugin version to the new daemon version.

### Release Checklist

Before pushing a new plugin version to dev:

1. **Did daemon code change?**
   - Yes → Add an entry mapping the new plugin version to the new daemon version.
   - No → Add an entry mapping the new plugin version to the current daemon version (same as the previous plugin version).
2. **Verify the entry exists**: Open `daemon-compatibility.json` and confirm your new plugin version has a mapping.
3. **CI will auto-bump** `MM_VERSION` in `metamanager.php` — do NOT manually set the version number.

**If you forget step 2**, the dashboard widget will show "No compatibility mapping for this plugin version".

## Repos

- Plugin repo: `richardkentgates/metamanager-plugin`
- Server repo: `richardkentgates/metamanager`
- Apt server: `34.136.87.92` (DNS: `apt.richardkentgates.com`)
- Production: `34.10.253.160` (Debian 13 trixie, WordPress at `/srv/www/wordpress/`)

## Apt Server Channels

- **Test channel (plugin)**: `metamanager-test/` — WordPress detects update via `MM_Updater`
- **Production channel (plugin)**: `metamanager/` — WordPress detects update via `MM_Updater`
- **Test channel (daemons)**: `dists/test` — install via `apt-get install -t test`
- **Production channel (daemons)**: `dists/stable` — install via `apt-get install -t stable` or `apt-get upgrade`

## Conventions

- Branch protection on `test` and `main`: PRs required, no direct pushes
- Promotion = workflow_dispatch triggers direct git merge (no PRs)
- Shell scripts/daemons update via apt; plugin updates via WordPress native update
- Daemon updates are handled by the plugin's `MM_Daemon_Updater` class (called via `MM_Updater` after plugin update).
- PHP 8.4 for WP-CLI (`php8.4 /usr/local/bin/wp --path=/srv/www/wordpress`)
- CI auto-bumps `MM_VERSION` on every dev push — do not manually edit version numbers
- **Test channel**: Used for development verification before production deployment
- **Production channel**: Used by production site, requires explicit workflow_dispatch promotion
