=== Metamanager ===
Contributors: richardkentgates
Tags: seo, metadata, sitemap, schema, open-graph
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 2.3.126
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Lossless media compression, EXIF/IPTC/XMP metadata sync, Schema.org JSON-LD, XML sitemaps, Open Graph, and broken link checker.

== Description ==

**Metamanager** manages metadata at two layers of your WordPress stack — the media files themselves and your web presence.

**Media layer** — lossless compression for images, video, and audio; bidirectional metadata sync between WordPress fields and embedded file tags (EXIF/IPTC/XMP, ID3, QuickTime atoms, Vorbis comments); PDF metadata import; GPS import; write-back verification — all via OS-level daemons using ExifTool and ffmpeg.

**Web layer** — per-post/page/term/user title and description control; Open Graph and Twitter/X card output; Schema.org JSON-LD for 20+ types; XML sitemaps (pages, media, video); HTML sitemap shortcode; robots.txt management; async broken link checker; business profile with contact card block; author profiles with structured data.

= Requirements =

This plugin requires the **Metamanager daemon package** installed on the server. The package provides OS-level tools and bash daemons that process media files asynchronously:

* **metamanager-compress-daemon.sh** — lossless JPEG/PNG/WebP/video compression via jpegtran, optipng, cwebp, ffmpeg
* **metamanager-meta-daemon.sh** — EXIF/IPTC/XMP metadata read and write via ExifTool
* **systemd** — daemon process supervision and auto-restart

Individual tool dependencies:

* **ExifTool** — metadata embedding
* **jpegtran** — lossless JPEG compression
* **optipng** — lossless PNG compression
* **cwebp** — lossless WebP compression
* **ffmpeg** — video remux

Use the bundled `metamanager-install.sh` script to install everything automatically on Ubuntu/Debian or RHEL/Rocky Linux.

= Features =

**Media:**

* Lossless compression: JPEG (jpegtran), PNG (optipng), WebP (cwebp -lossless)
* EXIF/IPTC/XMP metadata written simultaneously via ExifTool
* ID3 tags for MP3, QuickTime atoms for MP4/M4A, Vorbis comments for OGG/FLAC
* PDF title, author, keywords imported on upload; XMP written back
* Bidirectional sync: embedded tags populate WP fields on upload; WP edits written back to file
* GPS coordinates imported from EXIF; included in Schema.org ImageObject
* Write-back verification after daemon embedding
* Bulk compress all unprocessed attachments
* Expanded metadata fields: Creator, Copyright, Owner, Headline, Credit, Keywords, Date, Rating, City, State, Country

**Web / SEO:**

* Per-post/page/term/user title and description with template tokens
* Open Graph: og:title, og:description, og:image (with dimensions/type/alt), og:video, og:audio, article timestamps; Twitter/X cards
* Schema.org JSON-LD: Article, BlogPosting, WebPage, BreadcrumbList, ImageObject, VideoObject, AudioObject, DigitalDocument, Product, FAQPage, HowTo, Recipe, Event, Course, JobPosting, Review, Service, Organization, LocalBusiness, Person — 20+ types
* XML sitemaps: /sitemap.xml, /sitemap-media.xml, /sitemap-video.xml with ping on publish
* HTML sitemap via [mm_sitemap] shortcode
* Robots.txt: auto-appended Sitemap: directives; global per-type noindex; per-post robots controls
* Async broken link checker with HTTP codes, dashboard view, email alerts, re-check, and ignore
* Link hygiene: global nofollow/noopener/target=_blank rules for external links
* Business profile: name, address, contact, hours, geo — powers LocalBusiness JSON-LD, Gutenberg block, sameAs social links
* Author profiles: job title, bio, social links; Person JSON-LD on author archives

== Installation ==

= Automatic (standard WordPress install) =

1. Search for "Metamanager" in your WordPress dashboard under Plugins → Add New.
2. Click Install Now, then Activate.

= Full server install (daemons + plugin) =

For VPS or dedicated servers where you want compression and metadata embedding:

1. Add the apt repository and install the daemon package:
```
curl -fsSL https://apt.richardkentgates.com/metamanager.asc | sudo gpg --dearmor -o /usr/share/keyrings/metamanager.gpg
echo "deb [signed-by=/usr/share/keyrings/metamanager.gpg] https://apt.richardkentgates.com bookworm main" | sudo tee /etc/apt/sources.list.d/metamanager.list
sudo apt update && sudo apt install metamanager
```

2. Install the WordPress plugin via dashboard or WP-CLI:
```
wp plugin install metamanager --activate
```

3. Verify daemons are running:
```
systemctl status metamanager-compress-daemon
systemctl status metamanager-meta-daemon
```

= Test (pre-release) builds =

Test builds are produced from the `test` branch. They include the latest fixes but have not been promoted to `main` yet.

```
# See available versions
apt-cache policy metamanager

# Install a specific test version
sudo apt install metamanager=2.4.11~test1722500000

# Return to stable when ready
sudo apt-mark unhold metamanager
sudo apt update && sudo apt install metamanager
```

= Manual server install (legacy) =

For servers where the OS-level tools (ExifTool, jpegtran, etc.) are not yet installed:

1. On your server: `wget -qO- https://raw.githubusercontent.com/richardkentgates/metamanager/main/metamanager-install.sh | sudo bash`
2. The script installs all dependencies, copies the plugin, and starts the daemons.

== Frequently Asked Questions ==

= Does this work on shared hosting? =

The SEO features (metadata panels, Open Graph, Schema.org, sitemaps, link checker) work on any WordPress host. The media compression and metadata embedding features require OS-level tools (ExifTool, jpegtran, etc.) which are typically only available on VPS or dedicated servers.

= What image formats are supported for lossless compression? =

JPEG (jpegtran), PNG (optipng), and WebP (cwebp -lossless). GIF and AVIF are not currently supported.

= Does compression affect image quality? =

No. The plugin uses only lossless methods — jpegtran's lossless optimisation, optipng's deflate recompression, and cwebp's -lossless flag. Pixel data is unchanged.

= Can I use just the SEO features without the compression daemons? =

Yes. All web/SEO features are fully functional without the OS daemons. Jobs are queued but simply won't execute until the daemons are running.

== Screenshots ==

1. Media Library with compression status column and per-image actions.
2. Bulk metadata editor for batch-updating EXIF/IPTC/XMP fields.
3. Job queue dashboard with real-time daemon status indicators.
4. Post meta panel: Open Graph, Schema.org type, and robots controls.
5. XML sitemap configuration with per-post-type toggles.
6. Business profile settings powering LocalBusiness JSON-LD.
7. Broken link checker dashboard with HTTP codes and per-link actions.
8. Preferences page with notification, compression, and metadata settings.

== Changelog ==

= 2.3.51 =
* Fixed CI workflows: version bumps, auto-triggered promotions, and branch divergence issues.
* Fixed METADATA_URL using HTTP instead of HTTPS in MM_Updater.
* Removed orphan importer class that was never loaded.
* Removed deprecated wpmu_new_blog hook (plugin requires WP 6.2+).
* Fixed Plugin URI pointing to server repo instead of plugin repo.
* Removed sslverify=false from link checker for security.
* Added unit tests for MM_Mod_Head_Meta, MM_Mod_Local, MM_Mod_Social, and MM_Mod_Author.
* Removed Person-to-Organization worksFor linkage in author schema.
* Fixed GPL license mismatch (LICENSE file is GPL-3.0, headers were GPL-2.0).

= 2.1.7 =
* Fixed all WordPress Plugin Checker security warnings: output escaping, input unslash/sanitize, prepared SQL using %i identifier placeholders.
* Bumped minimum WordPress version to 6.2 for %i placeholder support in wpdb::prepare().
* Added readme.txt with proper WP.org headers.
* Added .distignore for clean distribution ZIP builds.

= 2.1.6 =
* Merged metadata integration subsystem: per-post/page/term/user SEO meta, Schema.org JSON-LD (20+ types), Open Graph, async link checker, HTML sitemap shortcode, business profile, author profiles.
* Renamed Settings to Preferences in the dashboard navigation.
* Added GitHub CI workflow with PHPUnit integration tests.
* Updated top navigation on the documentation site (responsive, nested dropdowns, no sidebar).

= 2.1.5 =
* Initial public release with media compression and metadata embedding features.

