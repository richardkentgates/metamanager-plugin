# Contributing to MetaManager

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

## Pull Requests
- Keep changes focused on a single issue.
- Test on a real LAMP server before submitting.
- No Composer dependencies — this is server software.

## Version Management

**Do not manually edit version numbers.** The CI pipeline auto-bumps `MM_VERSION` in `metamanager.php`, the `Version:` header, `readme.txt` `Stable tag:`, and `CHANGELOG.md` on every push to `dev`.

### Daemon updates

Daemon updates are handled automatically. When the plugin is updated, `MM_Daemon_Updater` triggers `apt-get update && apt-get install -y metamanager`. The server's apt channel (test/stable) determines which version is installed. No manual coordination needed.
