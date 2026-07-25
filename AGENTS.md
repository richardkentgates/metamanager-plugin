# AGENTS.md — Metamanager Plugin

## Deployment Rules

**NEVER SCP or copy files directly to production servers unless explicitly testing a fix.**

All software must move to production through native update systems:

- **Plugin updates**: Push to `main` → CI/CD builds zip → deploys to apt server `metadata.json` → WordPress detects update via `MM_Updater` → user clicks "Update Now" in Dashboard → Updates
- **Daemon updates**: Push to `main` → CI/CD builds `.deb` → deploys to apt server repo → `apt upgrade` on production server

The only exception is temporary testing during active development sessions, where files may be SCP'd for immediate verification. After testing, the fix must go through the proper pipeline before being considered deployed.

## Repos

- Plugin repo: `richardkentgates/metamanager-plugin`
- Server repo: `richardkentgates/metamanager`
- Apt server: `34.136.87.92` (DNS: `apt.richardkentgates.com`)
- Production: `104.197.172.183` (Ubuntu 20.04, WordPress at `/srv/www/wordpress/`)

## Conventions

- No branch protection, no PR requirements
- All changes go through dev → test → main pipeline (promotion, not push)
- Shell scripts/daemons update via apt; plugin updates via WordPress native update
- PHP 8.2 for WP-CLI (`php8.2 /usr/local/bin/wp --path=/srv/www/wordpress`)
