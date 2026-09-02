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

The plugin's `MM_Daemon_Updater` triggers `apt-get update && apt-get install -y metamanager` after every plugin update. The server's channel (test/stable) — configured in GCM — determines which daemon version apt installs. No version mapping file is needed.

### Architecture

- **Plugin PHP** (`MM_Daemon_Updater`): After plugin update, runs `apt-get update && apt-get install -y metamanager` and restarts daemons. The server's apt channel determines the installed version. Called via `MM_Updater` after plugin update.

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
