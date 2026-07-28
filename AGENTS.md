# AGENTS.md — Metamanager Plugin

## Deployment Rules

**NEVER SCP or copy files directly to production servers unless explicitly testing a fix.**

All software must move to production through native update systems:

- **Plugin + Daemon updates**: Push to `main` → CI/CD builds zip → deploys to apt server `metadata.json` → WordPress detects update via `MM_Updater` → user clicks "Update Now" → plugin updates → plugin automatically triggers daemon update via `MM_Daemon_Updater` (reads `daemon-compatibility.json`, checks `/usr/local/lib/metamanager/VERSION`, runs `apt upgrade metamanager` + `systemctl restart`)

The only exception is temporary testing during active development sessions, where files may be SCP'd for immediate verification. After testing, the fix must go through the proper pipeline before being considered deployed.

## Daemon Auto-Updater

When the plugin is updated (manually or via WP auto-update), it automatically updates the OS daemon package to match. The mechanism:

1. `MM_Updater::on_plugin_updated()` fires after WordPress finishes updating plugin files
2. Calls `MM_Daemon_Updater::handle_plugin_update()`
3. Reads `daemon-compatibility.json` to find the required daemon version for the current plugin version
4. Reads `/usr/local/lib/metamanager/VERSION` to get the installed daemon version
5. If mismatch → runs `sudo apt-get update && apt-get install -y metamanager` + `systemctl restart` both daemons
6. Logs result to `/var/log/metamanager-update.log` and WordPress error log (WP_DEBUG)
7. Shows success/error admin notice

**Compatibility map format** (`daemon-compatibility.json`):
```json
{
  "2.3.18": "2.4.9",
  "2.3.17": "2.4.9",
  "2.3.16": "2.4.9",
  "2.3.15": "2.4.8"
}
```
Multiple plugin versions map to the same daemon version (many-to-one). When adding a new plugin version that doesn't change daemon behavior, add an entry mapping it to the existing daemon version.

**Sudoers**: `/etc/sudoers.d/sudoers-metamanager` grants www-data passwordless sudo for specific apt/systemctl commands only.

## Repos

- Plugin repo: `richardkentgates/metamanager-plugin`
- Server repo: `richardkentgates/metamanager`
- Apt server: `34.136.87.92` (DNS: `apt.richardkentgates.com`)
- Production: `104.197.172.183` (Ubuntu 20.04, WordPress at `/srv/www/wordpress/`)

## Conventions

- Branch protection on `test` and `main`: PRs required, no direct pushes
- Promotion = open PR from `dev` → `test` or `test` → `main`, CI runs, merge
- Shell scripts/daemons update via apt; plugin updates via WordPress native update
- Daemon updates are triggered automatically by plugin updates (no manual apt needed)
- PHP 8.2 for WP-CLI (`php8.2 /usr/local/bin/wp --path=/srv/www/wordpress`)
