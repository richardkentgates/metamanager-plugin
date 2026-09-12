# Metamanager Plugin — Deployment Checklist

Before each production deploy, run through this checklist.

---

## Pre-Deploy

- [ ] PHPUnit tests pass locally
- [ ] PHPStan passes (no new errors)
- [ ] All changed settings verified on staging (if available) or dev branch
- [ ] No secrets in committed code
- [ ] Version bumped in `metamanager.php` (CI auto-bumps on dev push — do not edit manually)

## Pipeline

- [ ] Push to `dev` — CI runs lint, PHPStan, tests, build
- [ ] Promote to test: `gh workflow run "Promote to Test" --ref dev`
- [ ] Verify on test servers (PEO + Gap Creek):
  - `sudo apt-get update && sudo apt-get upgrade`
  - `gcm status`
  - Check WordPress loads, check error logs
- [ ] Promote to main: `gh workflow run "Promote to Main" --ref test`

## Post-Deploy (Production)

- [ ] Apt server `metadata.json` updated (handled by promote-to-main workflow)
- [ ] Plugin installed on production via `wp plugin install --force` or WordPress auto-update
- [ ] Rewrite rules flushed after install: `wp rewrite flush --path=/srv/www/wordpress`

## Smoke Tests

- [ ] Homepage: title, OG tags, JSON-LD present
- [ ] Sitemap: `/sitemap.xml/` returns 200 OK with valid XML
- [ ] Robots.txt: custom rules present
- [ ] Single post: title, description, canonical, OG, schema
- [ ] Dashboard widget loads with queue stats and cron history

## Cross-Repo Dependencies

| Change type | Requires daemon update? | Requires apt server deploy? |
|-------------|------------------------|----------------------------|
| Schema/SEO module changes | No | No — WordPress auto-update |
| Cron tracker changes | No | No — WordPress auto-update |
| Dashboard widget changes | No | No — WordPress auto-update |
| Job queue contract changes | **Yes** | **Yes** — both plugin zip and daemon .deb |
| New dependency (e.g. php-imagick) | **Yes** | **Yes** — daemon debian/control |
| WP-CLI subcommand changes | No | No — WordPress auto-update |
| Settings structure changes | No | No — but verify `MM_Site_Settings` migration |

**See also:** `JOB_QUEUE_SPEC.md` for the contract between plugin (PHP) and daemons (Bash).
