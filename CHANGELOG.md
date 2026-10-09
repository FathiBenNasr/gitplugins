# Changelog

All notable changes to **Git Plugin Installer** (`gitplugins`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-10-09

Security release for the GLPI 12 line: carries every fix of 1.0.4 below (October 2026 audit). **Reinstall required** after copying the files (`plugin:install` then `plugin:activate`). On this line the CSRF protection stays GLPI 12's `Sec-Fetch-Site`/`Origin` check, so the new forms carry no `_glpi_csrf_token` field.

## [1.1.0] - 2026-10-08

### Changed
- **Requires GLPI 12.0** (min 12.0.0, max 12.99.99); the 11.x line stays on the previous minor.
- Class properties inherited from GLPI carry the native types GLPI 12 declares (`public static string $rightname`, …): an untyped redeclaration is a compile error that takes the whole GLPI instance down.
- CSRF: no more `_glpi_csrf_token` fields, `Session::getNewCSRFToken()`/`checkCSRF()` calls or `X-Glpi-Csrf-Token` headers. GLPI 12 validates the browser's `Sec-Fetch-Site`/`Origin` headers instead.
## [1.0.4] - 2026-10-09

Security release (October 2026 audit). **Reinstall required** after copying the
files (`plugin:install` then `plugin:activate`): the schema gains one table and
two columns, and GLPI deactivates a plugin whose code and database versions
differ.

### Security
- **Rollback snapshots**: each snapshot now owns its database dump (`gitplugins-snap-<key>-<stamp>-<random>.sql.gz`). The single per-plugin file was overwritten by every update — a rollback to N-2 restored N-1's schema — and pruning the oldest snapshot deleted the dump the newest one needed. Pruning never deletes a file another snapshot still references.
- **Snapshot restore** only runs a dump that sits in GLPI's dump directory under the plugin's own name, and only if every statement is a `DROP/CREATE/INSERT` on the plugin's own tables (a sibling plugin whose key extends this one is excluded). Anything else executes nothing.
- **Confirmed commit is the installed commit**: the confirm screen's ref/SHA is carried with the queued job (`pending_ref`/`pending_sha`); a source that moved since the review is refused, the cron fetches that exact commit, and the downloaded `setup.php` version is checked again against the installed one (the `release` policy can no longer downgrade; `allow_downgrade` is honoured).
- **Row-level access**: editing, removing, viewing or queuing a source checks the right on that source's entity, not only on the itemtype.
- **Model-level rules**: https, host allowlist, ref policy, local roots and token encryption are enforced in `PluginGitpluginsSource::prepareInputForAdd/Update`, so the REST API and massive actions get them too; `build_on_install` can no longer be set that way; `credential` is an undisclosed field.
- **Marketplace-managed plugins** cannot be registered as a source nor installed over (GLPI would keep running the marketplace copy).
- **SSRF guard**: CGNAT, benchmarking, IETF, documentation, multicast, 6to4, NAT64, Teredo and IPv4-compatible IPv6 ranges are now blocked; behind GLPI's proxy the connection is pinned to the vetted IP with `CURLOPT_CONNECT_TO` (the proxy no longer re-resolves the name); a proxy without a port is no longer `host:0`.
- **Archive bomb**: extraction refuses more than 20,000 entries or 256 MiB uncompressed, checked on the declared sizes before writing and on the bytes really written.
- **Deploy endpoint** (`ajax/deploy.php`): declared `STRATEGY_NO_CHECK` + stateless for that exact path (it was unreachable by a target and reachable by any logged-in account); requests carry a single-use `X-Gp-Nonce` bound into the signature; shared secrets must be at least 32 characters. Protocol change for pull consumers: the signed string gains a fifth line, the nonce.
- **Audit trail**: events now reach GLPI's event log (`class_exists(\Glpi\Event::class)`; the old check was always false); uninstall keeps the log table under a dated archive name; audit rows are purged after 365 days.
- **Key rotation**: `sources.credential` and `targets.secret` are declared as `SECURED_FIELDS`, so `security:change_key` re-encrypts them.
- **Rights**: a reinstall no longer re-grants `plugin_gitplugins` to a profile an administrator took it from; uninstall removes the right through `ProfileRight::deleteProfileRights()`.
- **Confirmation dialogs** are JS-encoded (`PluginGitpluginsUi::confirmAttr`): the French apostrophe broke the handler and the form was submitted without confirmation.

### Fixed
- `check_prerequisites()`/`check_config()` threw a `TypeError` (string given to `Html::displayMessageAfterRedirect(bool)`) instead of reporting the unmet requirement.
- Catalog `known_issues` were always discarded at seeding; `catalog_url` was cut at 255 characters (half a URL with several catalogs); `latest_tag` could pick a pre-release over a stable tag; private repositories never resolved tags or branch heads (no token on the API calls).

### Tests
- New harness (`tests/harness/`) running the real front scripts, `setup.php`, `hook.php` and the DB-backed methods in a child process against GLPI stand-ins; abuse-case tests for every item above.

## [1.0.3] - 2026-10-08

### Fixed
- Fresh install: the `hook_warnings` column comment interpolated an undefined `$PLUGIN_HOOKS` (warning on install, comment cut short). Guarded by `tests/HookDdlTest.php`.
- GLPI 12 readiness, still compatible with GLPI 11: `Glpi\Event::log()` instead of the global `Event` alias (dropped in GLPI 12), and no `inc/includes.php` in web entry points (useless since GLPI 11, deprecated in GLPI 12).

## [1.0.2] - 2026-10-02

### Added
- **`en_GB` catalogue.** GLPI falls back to `en_GB` when a plugin has no catalogue for the session language; without one, every language load of an `en_GB` session probed the missing file again. `en_GB` is a copy of `en_US`; `tests/CataloguesTest.php` pins that it ships, that every `.po` is compiled, and that all catalogues share their msgids.

## [1.0.1] - 2026-07-21

Packaging and hygiene release for the public GLPI plugin catalogue. No functional feature changes.

### Changed
- Default SSRF host allowlist is now GitHub (+ its download hosts) and GitLab only; a self-hosted git host is added explicitly in Configuration.
- `homepage` now points at the public GitHub repository.

### Fixed
- `LICENSE` now contains the full verbatim GPL-2.0 text (previously a truncated stub with the wrong project name).

### Packaging
- Added `.gitattributes` `export-ignore` so release archives exclude `tests/`, `CODING_PLAN.md`, `docs/ENHANCEMENTS-SPEC.md` and `phpunit.xml`.
- Vendor-neutral example catalog manifest; no internal hosts in shipped files.

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

[1.0.1]: https://github.com/FathiBenNasr/gitplugins/releases/tag/v1.0.1
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
