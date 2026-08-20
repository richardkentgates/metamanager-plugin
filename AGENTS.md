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
- **Daemon test channel**: Push to `dev` → trigger `promote-to-test.yml` → merges dev→test, builds `.deb` → deploys to `dists/bookworm-test` on apt server → install on test site via `apt-get install -t bookworm-test`
- **Daemon production channel**: Push to `dev` → trigger `promote-to-main.yml` → merges test→main, tags, releases → deploys to `dists/bookworm` on apt server → install on production site via `apt-get upgrade` or `apt-get install -t bookworm`

The only exception is temporary testing during active development sessions, where files may be SCP'd for immediate verification. After testing, the fix must go through the proper pipeline before being considered deployed.

## Daemon Version Detection

The plugin provides version detection for display purposes only. Daemon updates are handled by the shell-based self-updater (see `metamanager/AGENTS.md`).

### Architecture

- **Shell self-updater** (`/usr/local/bin/metamanager-self-updater.sh`): Reads `daemon-compatibility.json` from the plugin directory, extracts the installed plugin version from the plugin header, looks up the required daemon version, and runs `apt-get upgrade` if needed. Runs every 60 seconds via systemd timer.
- **Plugin PHP** (`MM_Daemon_Updater`): Reads `daemon-compatibility.json` and the `VERSION` file to display version status in the dashboard widget. No apt/exec logic — display only.

The plugin does NOT trigger daemon updates. The shell self-updater is the single authority for daemon version management.

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

**Rule**: When releasing a new plugin version, always add an entry to `daemon-compatibility.json`:
- **If daemon code changed**: Map to the new daemon version (you must also push the daemon to dev to trigger its version bump)
- **If daemon code did NOT change**: Map to the existing daemon version (same as the previous plugin version)

### Release Checklist

Before pushing a new plugin version to dev:

1. **Did daemon code change?**
   - Yes → Push daemon changes to dev first. Wait for CI to bump `debian/changelog` and `VERSION`. Then add an entry mapping the new plugin version to the new daemon version.
   - No → Add an entry mapping the new plugin version to the current daemon version.
2. **Verify the entry exists**: Open `daemon-compatibility.json` and confirm your new plugin version has a mapping.
3. **CI will auto-bump** `MM_VERSION` in `metamanager.php` — do NOT manually set the version number.

**If you forget step 2**, the shell self-updater will show status "Cannot determine required version (plugin missing or no compat map)" in the dashboard widget and status JSON.

## Repos

- Plugin repo: `richardkentgates/metamanager-plugin`
- Server repo: `richardkentgates/metamanager`
- Apt server: `34.136.87.92` (DNS: `apt.richardkentgates.com`)
- Production: `34.10.253.160` (Debian 13 trixie, WordPress at `/srv/www/wordpress/`)

## Apt Server Channels

- **Test channel (plugin)**: `metamanager-test/` — WordPress detects update via `MM_Updater`
- **Production channel (plugin)**: `metamanager/` — WordPress detects update via `MM_Updater`
- **Test channel (daemons)**: `dists/bookworm-test` — install via `apt-get install -t bookworm-test`
- **Production channel (daemons)**: `dists/bookworm` — install via `apt-get install -t bookworm` or `apt-get upgrade`

## Conventions

- Branch protection on `test` and `main`: PRs required, no direct pushes
- Promotion = workflow_dispatch triggers direct git merge (no PRs)
- Shell scripts/daemons update via apt; plugin updates via WordPress native update
- Daemon updates are handled by the shell self-updater, not by the plugin PHP
- PHP 8.4 for WP-CLI (`php8.4 /usr/local/bin/wp --path=/srv/www/wordpress`)
- CI auto-bumps `MM_VERSION` on every dev push — do not manually edit version numbers
- **Test channel**: Used for development verification before production deployment
- **Production channel**: Used by production site, requires explicit workflow_dispatch promotion
