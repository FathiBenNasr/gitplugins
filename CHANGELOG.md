# Changelog

All notable changes to **Git Plugin Installer** (`gitplugins`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-07-05

Robustness + trust programme: turn "place and hope" into "place, build, verify, and roll back," and add browse/bulk/deploy surfaces. All new logic ships with pure unit tests (178 total).

### Added
- **Version rollback** — every update retains an inert pre-update file backup **and** a scoped DB dump as a snapshot; one-click revert restores files + owned tables, re-registers and verifies (retention configurable, default 3 per plugin).
- **Environment preflight** — GLPI/PHP version + required-extension check, both as a pre-fetch **gate** and a report panel on the install-confirm screen.
- **Post-install health gate** — calls the target plugin's own `check_prerequisites()`/`check_config()`; verdict (`ok|warn|fail|unknown`) badged on status; `fail` either flags red (default) or auto-rolls-back (configurable).
- **Hook-collision detector** — warns when the just-installed plugin hooks the same item event + itemtype as another active plugin (the geninventorynumber class of bug).
- **Known-issues registry** — curated conflict/advisory dataset (shipped seed + catalog-fed), consulted on install-confirm and status.
- **Changelog surfacing** — fetches `CHANGELOG.md` at the resolved ref (SSRF-guarded) and shows only the sections between installed and available, as escaped text.
- **Bulk update + dry-run** — a non-mutating plan (action, migration, preflight, known-issues) with select-and-queue; a 15-minute apply cron runs the queue through the same verified pipeline.
- **Plugin catalog** — browse one or more **vendor-neutral** JSON catalog manifests (your own and/or a third party's) and one-click pre-fill a source; each catalog caches and refreshes independently.
- **Local / dev source type** — install from an allowlisted filesystem path (off by default), replacing per-plugin `deploy.sh`.
- **Multi-target deploy (pull model)** — a read-only, HMAC-signed, SHA-pinned deploy manifest endpoint (`ajax/deploy.php`) + targets registry; other instances pull and install via their own pipeline. **No inbound code-execution endpoint.**

### Changed
- Install pipeline now: preflight gate → optional composer/npm build → `.po→.mo` locale compile → atomic placement (with carry-over of `vendor/`/`node_modules/`) → DB snapshot → native install → verify + self-heal → health gate → retain rollback snapshot.
- Auto cache-clear after activate (best-effort) to avoid stale-route 404s.

### Security
- Pre-update backups are **neutralised**: relocated out of the web tree into `GLPI_VAR_DIR`, stored as inert `.zip` (never a runnable PHP tree under `plugins/`), `0600/0700`, web-user owned — a leaked backup cannot be executed to re-introduce a vulnerable version.
- New fetch targets (changelog, catalog, deploy manifest) all go through the existing SSRF guard + host allowlist; the deploy manifest is HMAC-signed with a freshness/replay window and carries no secrets.

[1.0.0]: https://github.com/FathiBenNasr/gitplugins/releases/tag/v1.0.0

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
