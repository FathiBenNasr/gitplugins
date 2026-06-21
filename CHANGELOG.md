# Changelog

All notable changes to **Git Plugin Installer** (`gitplugins`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-06-21

Initial release — install and update GLPI plugins from a git repository, for GLPI 11.

### Added
- **Install & update plugins from git** (Forgejo / GitHub / GitLab / Gitea), extending GLPI's native plugin system rather than replacing it.
- **Managed git sources**: repository URL + ref policy (branch/tag/commit) + optional GLPIKey-encrypted credential, entity-scoped.
- **Self-declared source discovery**: reads each installed plugin's `plugin.xml` `<gitupdate>` block to surface installed plugins and offer **update / reinstall** from their declared origin, or register that origin as a managed source.
- **SSRF-guarded fetch**: HTTPS-only, host allowlist, DNS resolution blocking private/loopback/link-local/metadata IPs, redirect re-validation, GLPI-proxy aware, size cap.
- **Safe extraction**: zip/tar-slip sanitisation, single-root validation, atomic placement with backup/restore.
- Reuses GLPI core `Plugin::install()/activate()` (the marketplace seam) so the target plugin's own hooks run; **tarball download** (no `git clone`); update-check **CronTask**.
- Confirm-before-install, **semver-aware** (refuses unforced downgrades), audit log, least-privilege right (OWASP Top 10 2021 / ASVS L2+).
- French (`fr_FR`) and English (`en_US`) locales.

[0.1.0]: https://github.com/FathiBenNasr/gitplugins/releases/tag/v0.1.0
