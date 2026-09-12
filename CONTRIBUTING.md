# Contributing to MetaManager

## Architecture Overview

Before contributing, read [ARCHITECTURE.md](ARCHITECTURE.md) for a complete understanding of the codebase. The plugin is organized into:

- **Core**: `MM_DB` (schema), `MM_Job_Queue` (file I/O), `MM_Metadata` (field definitions)
- **Admin**: `MM_Admin` (dashboard, bulk actions), `MM_Settings` (preferences)
- **Modules**: `MM_Mod_*` classes — each handles one output concern (head meta, social, schema, sitemaps, robots, links, etc.)
- **Metaboxes**: `MM_Post_Meta_Panel`, `MM_Term_Meta_Panel`, `MM_User_Meta_Panel`
- **Daemons**: `metamanager-compress-daemon.sh`, `metamanager-meta-daemon.sh`

## Development Setup

This is a WordPress plugin with bash daemons — no Composer dependencies.

### Requirements
- PHP 8.0+
- WordPress 6.0+
- ExifTool (`sudo apt install libimage-exiftool-perl`)

### Local Testing

Install dependencies and set up the test suite:

```bash
# Install Composer dev dependencies (phpunit, phpstan, etc.)
composer install --no-interaction --prefer-dist

# Set up WordPress test suite (MySQL + WP core + test libs)
make install

# Run all tests
make test

# Run unit tests only (no database required)
make test-unit

# Run integration tests only (requires WP test DB)
make test-integration

# Run static analysis
make analyse

# Lint PHP files
make lint
```

## Module System

All output modules extend `MM_Mod_Base` and implement `populate()` which adds data to the head emitter's document array. To add a new module:

1. Create `includes/modules/class-mm-mod-{name}.php` extending `MM_Mod_Base`
2. Implement `populate()` to add your data
3. Register in `MM_Metadata_Loader::run()`
4. Add settings if needed in `MM_Site_Settings`

## Coding Standards

- **WordPress Coding Standards**: tabs for indentation, Yoda conditions, snake_case functions/variables
- **PHPStan level 5**: all new code must pass static analysis
- **ShellCheck**: all shell scripts must pass ShellCheck
- **Security**: sanitize input, escape output, use nonces, use `$wpdb->prepare()`
- **No TODO/FIXME/XXX** in committed code

## Pull Requests
- Keep changes focused on a single issue.
- Test on a real LAMP server before submitting.
- No Composer dependencies — this is server software.
- Add a CHANGELOG entry in the appropriate version section.

## Version Management

**Do not manually edit version numbers.** The CI pipeline auto-bumps `MM_VERSION` in `metamanager.php`, the `Version:` header, `readme.txt` `Stable tag:`, and `CHANGELOG.md` on every push to `dev`.

### Daemon updates

Daemon updates are handled automatically. When the plugin is updated, `MM_Daemon_Updater` triggers `apt-get update && apt-get install -y metamanager`. The server's apt channel (test/stable) determines which version is installed. No manual coordination needed.

## Database Schema Changes

If you need to add or modify database tables:

1. Add the new column/table to `MM_DB::create_or_update_table()` using `dbDelta()`
2. Bump the version check in the function if needed
3. Test on a fresh install AND an upgrade from the previous version
4. Never drop columns — add new ones and deprecate old ones

## REST API Development

New REST endpoints should follow the existing pattern in `MM_Admin::register_rest_routes()`:

1. Register in the `rest_api_init` hook
2. Use appropriate permission callbacks (`$auth_uploader` for read, `$auth_editor` for write)
3. Return `rest_ensure_response()` or `WP_Error`
4. Add help tab documentation for the new endpoint
