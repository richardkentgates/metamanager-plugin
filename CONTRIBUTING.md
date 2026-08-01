# Contributing to MetaManager

## Development Setup

This is a WordPress plugin with bash daemons — no Composer dependencies.

### Requirements
- PHP 8.2+
- WordPress 6.4+
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

## Pull Requests
- Keep changes focused on a single issue.
- Test on a real LAMP server before submitting.
- No Composer dependencies — this is server software.

## Version Management

**Do not manually edit version numbers.** The CI pipeline auto-bumps `MM_VERSION` in `metamanager.php`, the `Version:` header, `readme.txt` `Stable tag:`, and `CHANGELOG.md` on every push to `dev`.

### If you change daemon code

1. Push daemon changes to the **server repo** `dev` branch first
2. Wait for CI to bump `debian/changelog` and `VERSION`
3. Open `daemon-compatibility.json` and add an entry mapping your new plugin version to the new daemon version
4. Then push your plugin changes

### If you only change plugin code (no daemon changes)

1. Add an entry to `daemon-compatibility.json` mapping your new plugin version to the **current** daemon version (same as the previous plugin version)
2. Push to `dev`

### What happens if you forget

If your plugin version is not in `daemon-compatibility.json`, the auto-updater will show a persistent error in wp-admin: "Plugin vX.Y.Z is not listed in daemon-compatibility.json." This error will not clear until you add the missing entry and release a new version.
