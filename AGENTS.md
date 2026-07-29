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
3. Calls `diagnose()` to determine the version state (see diagnosis cases below)
4. If versions match → clears any stale error, returns
5. If infrastructure error (missing file, missing map entry) → stores specific error, does NOT run apt
6. If actual version mismatch → runs `sudo apt-get update && apt-get install -y metamanager` + `systemctl restart` both daemons
7. Logs result to `/var/log/metamanager-update.log` and WordPress error log (WP_DEBUG)
8. Shows success/error admin notice

### Diagnosis Cases

The `diagnose()` method returns a specific status for each failure mode:

| Status | Condition | Message | Action |
|--------|-----------|---------|--------|
| `ok` | VERSION matches map | "Daemon v{X} is up to date." | Clear stale errors |
| `error` | VERSION file missing | "Daemon VERSION file not found at {path}. The daemon package may not be installed." | Store error, skip apt |
| `error` | VERSION file empty/unreadable | "Daemon VERSION file exists at {path} but is empty or unreadable." | Store error, skip apt |
| `error` | Compatibility map missing | "Compatibility map not found at {path}. Cannot determine required daemon version for plugin v{X}." | Store error, skip apt |
| `error` | Plugin version not in map | "Plugin v{X} is not listed in daemon-compatibility.json. Add an entry mapping "{X}" to the correct daemon version." | Store error, skip apt |
| `mismatch` | Installed ≠ required | "Daemon version mismatch: installed v{X}, required v{Y}." | Run apt upgrade |

**Critical**: Infrastructure errors do NOT trigger `apt upgrade` because the problem is not a daemon version mismatch — it's a configuration or packaging issue. Running apt would not fix a missing map entry or missing VERSION file.

### Compatibility Map

**File**: `daemon-compatibility.json` (in plugin root)

**Format**:
```json
{
  "2.3.27": "2.4.8",
  "2.3.26": "2.4.8",
  "2.3.25": "2.4.8",
  "2.3.24": "2.4.8",
  "2.3.23": "2.4.8",
  "2.3.22": "2.4.8",
  "2.3.21": "2.4.8",
  "2.3.20": "2.4.8",
  "2.3.19": "2.4.8",
  "2.3.18": "2.4.8",
  "2.3.17": "2.4.8",
  "2.3.16": "2.4.8",
  "2.3.15": "2.4.8",
  "2.3.14": "2.4.8",
  "2.3.13": "2.4.8",
  "2.3.12": "2.4.7"
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

**If you forget step 2**, the auto-updater will show a persistent error: "Plugin v{X} is not listed in daemon-compatibility.json." This error will NOT go away until you add the missing entry and release a new version.

### Sudoers

`/etc/sudoers.d/sudoers-metamanager` grants www-data passwordless sudo for specific apt/systemctl commands only.

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
- CI auto-bumps `MM_VERSION` on every dev push — do not manually edit version numbers
