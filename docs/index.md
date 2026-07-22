---
layout: default
title: Metamanager Plugin
nav_order: 1
---

# Metamanager Plugin

Lossless image compression and standards-compliant metadata embedding for WordPress.

## Quick Links

- [Installation]({{ site.baseurl }}/getting-started/installation)
- [Configuration]({{ site.baseurl }}/configuration/)
- [Features]({{ site.baseurl }}/features/)
- [FAQ]({{ site.baseurl }}/faq/)
- [GitHub](https://github.com/richardkentgates/metamanager-plugin)

## What is Metamanager?

Metamanager is a WordPress plugin that provides:

- **Lossless Image Compression** — JPEG, PNG, WebP, and video remux via OS-level daemons
- **Metadata Embedding** — EXIF, IPTC, and XMP standards-compliant metadata
- **Bidirectional Sync** — Metadata flows between WordPress and file tags
- **Real-time Dashboard** — Live job queue and history

## Requirements

- WordPress 6.2+
- PHP 8.0+
- Metamanager daemon package (`sudo apt install metamanager`)

## Quick Install

```bash
# Add the apt repository
echo "deb [signed-by=/usr/share/keyrings/metamanager.gpg] https://apt.richardkentgates.com bookworm main" | sudo tee /etc/apt/sources.list.d/metamanager.list

# Install the daemon package
sudo apt update && sudo apt install metamanager

# Install the WordPress plugin
wp plugin install metamanager --activate
```

## Documentation

| Section | Description |
|---------|-------------|
| [Getting Started]({{ site.baseurl }}/getting-started/) | Installation and setup |
| [Features]({{ site.baseurl }}/features/) | Compression, metadata, and more |
| [Configuration]({{ site.baseurl }}/configuration/) | Settings and customization |
| [FAQ]({{ site.baseurl }}/faq/) | Common questions |
| [Troubleshooting]({{ site.baseurl }}/troubleshooting/) | Issues and solutions |
