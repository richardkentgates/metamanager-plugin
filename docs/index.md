---
layout: default
title: Metamanager Plugin
nav_order: 1
---

# Metamanager Plugin

Lossless image compression and standards-compliant metadata embedding for WordPress.

## Quick Links

- [GitHub](https://github.com/richardkentgates/metamanager-plugin)
- [Architecture Reference](https://github.com/richardkentgates/metamanager-plugin/blob/main/ARCHITECTURE.md)
- [Changelog](https://github.com/richardkentgates/metamanager-plugin/blob/main/CHANGELOG.md)
- [Contributing](https://github.com/richardkentgates/metamanager-plugin/blob/main/CONTRIBUTING.md)

## What is Metamanager?

Metamanager is a WordPress plugin that provides:

- **Lossless Image Compression** — JPEG, PNG, WebP, and video remux via OS-level daemons
- **Metadata Embedding** — EXIF, IPTC, and XMP standards-compliant metadata
- **Bidirectional Sync** — Metadata flows between WordPress and file tags
- **Real-time Dashboard** — Live job queue and history

## Requirements

- WordPress 6.2+
- PHP 8.0+
- **Metamanager daemon package** (`sudo apt install metamanager`) — installs the OS-level tools and bash daemons that process media files:

| Component | Purpose |
|-----------|---------|
| `metamanager-compress-daemon.sh` | inotifywait loop — lossless JPEG/PNG/WebP/video compression via jpegtran, optipng, cwebp, ffmpeg |
| `metamanager-meta-daemon.sh` | inotifywait loop — EXIF/IPTC/XMP metadata read and write via ExifTool |
| `metamanager-install.sh` | Server installer: OS dependencies, systemd service setup |
| systemd units | Process supervision, auto-restart, hardening (`NoNewPrivileges`, `ProtectSystem=strict`) |

> **Note:** The WordPress plugin can be installed without the daemon package, and all web/SEO features (schema, sitemaps, Open Graph, title/description) will work. However, compression and metadata embedding jobs will queue but not execute until the daemons are running.

## Installation

### 1. Add the apt repository

```bash
# Import the signing key
curl -fsSL https://apt.richardkentgates.com/metamanager.asc | sudo gpg --dearmor -o /usr/share/keyrings/metamanager.gpg

# Add the repository
echo "deb [signed-by=/usr/share/keyrings/metamanager.gpg] https://apt.richardkentgates.com bookworm main" | sudo tee /etc/apt/sources.list.d/metamanager.list

sudo apt update
```

### 2. Install the daemon package

```bash
sudo apt install metamanager
```

This installs:
- `metamanager-compress-daemon.sh` — lossless JPEG/PNG/WebP/video compression
- `metamanager-meta-daemon.sh` — EXIF/IPTC/XMP metadata read/write via ExifTool
- systemd service units with auto-restart and security hardening
- All OS-level tool dependencies (jpegtran, optipng, cwebp, ffmpeg, ExifTool)

### 3. Install the WordPress plugin

```bash
wp plugin install metamanager --activate
```

Or upload the zip via Plugins → Add New → Upload in wp-admin.

### 4. Verify

```bash
# Check daemon is running
systemctl status metamanager-compress-daemon
systemctl status metamanager-meta-daemon

# Check from WordPress
wp metamanager status --path=/srv/www/wordpress
```

---

## Test vs Release Channels

The apt server hosts two types of daemon packages:

| Channel | Version format | Example | Source |
|---------|---------------|---------|--------|
| **Release** | `X.Y.Z` | `2.4.15` | `main` branch (tagged releases) |
| **Test** | `X.Y.Z~testEPOCH` | `2.4.15~test1722500000` | `test` branch (pre-release builds) |

### Release (stable)

```bash
# Install or upgrade to the latest stable version
sudo apt update && sudo apt install metamanager

# Pin to a specific version
sudo apt install metamanager=2.4.15
```

`apt upgrade` always prefers release builds over test builds because Debian version ordering treats `~` as sorting before everything (`2.4.15~test...` < `2.4.15`). This means upgrading from a test build to a release build works automatically.

### Test (pre-release)

Test builds are produced from the `test` branch. They include the latest fixes but have not been promoted to `main` yet.

```bash
# See all available versions (test builds appear before release)
apt-cache policy metamanager

# Install a specific test version
sudo apt install metamanager=2.4.15~test1722500000

# Prevent apt from "downgrading" back to release
# (optional — only if you want to stay on test)
sudo apt-mark hold metamanager
```

To release back to stable, unhold and upgrade:

```bash
sudo apt-mark unhold metamanager
sudo apt update && sudo apt install metamanager
```

### How updates flow

```
dev  ──push──>  CI (ShellCheck + version bump)
                    │
                    │  workflow_dispatch
                    ▼
              test  ──push──>  CI (build .deb + deploy to apt)
                                   │  version: X.Y.Z~testEPOCH
                                   │
                                   │  workflow_dispatch
                                   ▼
              main  ──push──>  CI (tag + GitHub release + deploy to apt)
                                   │  version: X.Y.Z
                                   ▼
                              apt upgrade on production
```

The WordPress plugin auto-updates independently via `MM_Updater` (checks GitHub releases). When the plugin updates, it automatically triggers `apt upgrade metamanager` on the server to keep the daemon package in sync.

### Plugin auto-updater

The plugin checks `https://apt.richardkentgates.com/metamanager/metadata.json` every 12 hours. When a new release is found:

1. WordPress downloads and installs the plugin zip
2. WordPress activates the updated plugin

### Daemon updates

Daemon updates are handled by the plugin's `MM_Daemon_Updater` class. When the plugin is updated, it reads `daemon-compatibility.json` from the plugin directory, compares the installed daemon version against the required version, and triggers `apt-get install` automatically if a mismatch is detected. No manual SSH required.

## Documentation

| Section | Description |
|---------|-------------|
| [Architecture](https://github.com/richardkentgates/metamanager-plugin/blob/main/ARCHITECTURE.md) | Internal design, component map, connection points |
| [Changelog](https://github.com/richardkentgates/metamanager-plugin/blob/main/CHANGELOG.md) | Release history and changes |
| [Contributing](https://github.com/richardkentgates/metamanager-plugin/blob/main/CONTRIBUTING.md) | Development setup and PR guidelines |
| [Security](https://github.com/richardkentgates/metamanager-plugin/blob/main/SECURITY.md) | Vulnerability reporting and security model |
