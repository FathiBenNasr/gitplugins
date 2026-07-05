# Git Plugin Installer — install & update GLPI plugins from a git repository

[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![GLPI](https://img.shields.io/badge/GLPI-%3E%3D%2011.0-orange)](https://glpi-project.org)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D%208.2-purple)](https://www.php.net/)

A GLPI 11 **meta-plugin** that installs and updates other plugins from a
**git/HTTPS source** (GitHub, GitLab, Gitea, Forgejo) — for plugins not on the
official marketplace (private or self-hosted repos, e.g. your own on
`git.convergent.tn`). It **extends** GLPI's native pipeline rather than replacing
it: it owns a security-hardened **fetch + extraction** step, then drives GLPI
core's own **`Plugin::install()/activate()`** seam so managed plugins behave
exactly like marketplace ones (their own install hooks run).

## Features

- **Managed git sources** — register a repository: URL + **ref policy**
  (track a branch, latest tag, pin a tag, pin a commit SHA, or a prebuilt
  **release `.tgz`**) + an optional **GLPIKey-encrypted** credential for private
  repos. Entity-scoped.
- **Detect from URL** — paste a repo URL and the plugin fetches its `plugin.xml`
  to pre-fill the plugin key, name, and declared ref from the
  **`<gitupdate>`** self-declaration block (server-side re-validated for SSRF).
- **Installed-plugins discovery** — scans plugins already on disk (reading each
  `plugin.xml` locally, no network at render) and offers **install / update /
  reinstall** from their declared origin, or to register that origin as a managed
  source. **Marketplace-managed plugins are excluded** (left to GLPI's own
  marketplace).
- **HTTPS tarball download** (no `git` binary), so no `.git` ever lands in
  `plugins/`. The **`release`** policy downloads a publisher's prebuilt `.tgz`
  release asset (so plugins needing a composer/npm build step ship runnable).
- **Confirm-before-install** screen showing the source, resolved ref and version,
  installed vs available — with an explicit "this runs the target's own code"
  warning. Installs run **out of the web request** and are **resumable**.
- **Update badge + daily email digest** — an hourly check populates a cached
  "update available" flag (drives the UI badge); a daily cron emails a digest of
  managed plugins with updates available (anti-spam: only when the available
  set changes).
- **Status view** — installed vs available version/SHA, health, hook/known-issue
  badges, last check/result; remove-from-management without uninstalling the GLPI
  plugin.

### Robustness & trust (0.2.0)

- **Verified install pipeline** — environment **preflight gate**, optional
  **composer/npm build** for source tarballs, **`.po`→`.mo`** locale compile,
  atomic placement that **carries over** `vendor/`/`node_modules/`, a
  **pre-migration DB snapshot**, then **post-install verify + self-heal**. A
  failed migration leaves the previous working version active (files **and**
  schema).
- **One-click version rollback** — each update retains an inert file backup + a
  scoped DB dump as a **snapshot** (keep last *N*, default 3); revert restores
  files + owned tables, re-registers and verifies.
- **Post-install health gate** — calls the target's own
  `check_prerequisites()`/`check_config()` and badges the verdict
  (`ok|warn|fail|unknown`); a `fail` either flags red (default) or auto-rolls-back.
- **Hook-collision detector** — warns when a plugin hooks the same item event +
  itemtype as another active plugin.
- **Known-issues registry** — curated conflict/advisory dataset (shipped seed +
  catalog-fed), shown on install-confirm and status.
- **Changelog on confirm** — fetches `CHANGELOG.md` at the resolved ref
  (SSRF-guarded) and shows the sections between installed and available.
- **Bulk update + dry-run** — a non-mutating plan (action / migration / preflight
  / known-issues) with select-and-queue; a 15-minute apply cron runs the queue.
- **Plugin catalog** — browse one or more **vendor-neutral** JSON catalog
  manifests (your own and/or a third party's) and one-click pre-fill a source.
- **Local / dev source type** — install from an allowlisted filesystem path
  (off by default), replacing per-plugin `deploy.sh`.
- **Multi-target deploy (pull model)** — serve a read-only, **HMAC-signed,
  SHA-pinned** deploy manifest that other GLPI instances pull and install through
  their own verified pipeline — **no inbound code-execution endpoint**.

## Requirements

- **GLPI** ~11.0 (`11.0.0`–`11.99.99`).
- **PHP** >= 8.2 (`declare(strict_types=1)`, namespaced GLPI 11 API,
  `Toolbox::getGuzzleClient()`).
- **No other plugin dependencies** — it extends GLPI's native plugin system and
  reuses GLPI's bundled HTTP client and proxy configuration.

## Installation

1. Obtain the plugin — download a release `.tgz` and extract it, or clone — so the
   code lands in the **`gitplugins`** directory: `<glpi>/plugins/gitplugins/`.
2. Install + activate, running as the **web user**:
   ```bash
   sudo -u www-data php bin/console plugin:install gitplugins
   sudo -u www-data php bin/console plugin:activate gitplugins
   ```
   …or use **Setup → Plugins** in the GLPI UI (Install, then Enable).
3. Clear the cache after deploying:
   ```bash
   sudo -u www-data php bin/console cache:clear
   ```
4. **System cron is required.** The deferred install/update runner, the
   update-check, and the email digest all run as **CronTasks** — add GLPI's
   system cron so they fire:
   ```cron
   * * * * * php /path/to/glpi/front/cron.php
   ```
   Without it, fetches/installs stay queued and update checks never run.

> **Self-managed:** once installed, Git Plugin Installer can install and update
> **itself** from its own git source like any other managed plugin.

## Usage

Menu: **Setup → Git Plugin Installer**.

1. **Add a source** — enter the repo HTTPS URL, the target plugin key, and a
   **ref policy** (`track_branch` / `latest_tag` / `pin_tag` / `pin_sha` /
   `release`), plus an optional encrypted credential for a private repo. Or use
   **Detect from URL** to auto-fill the key/name/ref from the repo's
   `plugin.xml` `<gitupdate>` block.
   Alternatively, open **Installed plugins** discovery to act on a plugin already
   on disk that declares a `<gitupdate>` origin (marketplace-managed plugins are
   not listed).
2. **Install / update** — the confirm screen shows the source, resolved ref and
   version, and an "this runs the target's own code" warning. Confirming
   **enqueues** the action; the cron worker performs the SSRF-guarded fetch,
   safe extraction, atomic placement, and native `Plugin::install()/activate()`.
3. **Stay current** — the hourly update-check raises an **update badge** when a
   newer ref/version is available; the daily **email digest** notifies the
   configured recipient (or Super-Admins) of pending updates.
4. **Bulk & browse** — the **Status** page links to a **Bulk update (dry-run)**
   report (preview action/migration/preflight/known-issues, tick and queue), a
   **Catalog** browser (one-click pre-fill a source from a configured manifest),
   and **Targets** (register instances that pull the signed deploy manifest). When
   an update ships badly, use the per-plugin **Rollback** control to restore the
   previous version (files + schema) in one click.

## Configuration

**Setup → Git Plugin Installer → Configuration** (requires
`plugin_gitplugins` at `UPDATE`). Fields (from `front/config.php` /
`inc/config.class.php`):

| Field | Meaning |
|-------|---------|
| **Allowed hosts** (one per line) | SSRF allowlist — the server only fetches from these exact hosts. Empty keeps a safe default set (GitHub + download hosts, GitLab, `git.convergent.tn`). |
| **Max download size (MB)** | Archive download cap (1–500, default 50). |
| **Fetch timeout (seconds)** | Per-fetch network timeout (5–300, default 30). |
| **Update-check frequency (minutes)** | Cadence of the update checker (5–40320, default 1440). |
| **Allow automatic (unattended) install on update** | OFF by default → confirm-before-install. |
| **Allow downgrading to an older version** | OFF by default; downgrades refused unless enabled. |
| **Email a digest when updates are available** (`notify_updates`) | ON by default. |
| **Digest recipient** (`notify_recipient`) | Optional email override; blank → all active Super-Admins / GLPI admin email. |
| **Carry-over dirs** (`carry_over_dirs`) | Runtime-built dirs preserved across an update (default `vendor`, `node_modules`). |
| **Auto cache-clear** (`auto_cache_clear`) | Clear GLPI caches after activate (ON by default; avoids stale-route 404s). |
| **Build timeout (seconds)** (`build_timeout_seconds`) | Cap for composer/npm build steps (30–1800, default 300). |
| **DB snapshot cap (MB)** (`snapshot_max_mb`) | Pre-migration snapshot size cap; over it, skip+warn (0 = unlimited). |
| **Allow local/dev sources** (`allow_local_sources`) | OFF by default; reads the server filesystem. |
| **Local source roots** (`local_source_roots`) | Absolute-path allowlist a local source must sit under (empty = none). |
| **Rollback snapshots to keep** (`rollback_keep`) | Pre-update snapshots retained per plugin for one-click rollback (0–50, default 3). |
| **On failed health check** (`health_fail_action`) | `flag` red and keep active (default) or `rollback` to the previous version. |
| **Plugin catalog manifest URLs** (`catalog_url`) | One or more https catalog manifests (one per line) — your own and/or a third party's — to browse on the Catalog page. Vendor-neutral. |

## Permissions

One GLPI right is registered on install:

- **`plugin_gitplugins`** — controls every `front/`/`ajax/` entry (each checks
  login + this right) and the Configuration page (`UPDATE`).

Because installing remote code is the highest-privilege capability, the right is
granted on install **only** to profiles that already hold **`config: UPDATE`**
(typically Super-Admin). Logged-in admins must re-login for session rights to
refresh. Uninstall removes the right.

## Architecture

Git Plugin Installer **extends** GLPI's native plugin pipeline; it owns only the
remote-acquisition and safety steps, then hands off to core:

- **SSRF-guarded fetch** — HTTPS-only, host **allowlist**, DNS resolution with
  blocking of private / loopback / link-local / cloud-metadata
  (`169.254.169.254`) / IPv4-mapped-IPv6 addresses, **re-validation on every
  redirect**, peer-IP pinning (DNS-rebinding mitigation), proxy-aware
  (`$CFG_GLPI['proxy_*']`), size cap + timeout. **No `git` binary** — HTTPS
  tarballs only.
- **Zip/tar-slip-safe extraction** — every archive entry sanitised
  (reject `..` / absolute / backslash), extracted into a fresh temp dir,
  single-root layout validated, then **atomic placement** with backup-and-restore.
- **Native install** — drives GLPI core's public
  **`Plugin::install()/activate()`** so the target plugin's own hooks run exactly
  as the marketplace would.
- **`<gitupdate>` self-declaration + discovery** — a plugin's `plugin.xml` may
  declare `<gitupdate>` (repo, ref, ref_type, provider, private); discovery reads
  the on-disk `glpi_plugins` set and surfaces non-marketplace plugins, offering
  update/reinstall from the declared origin.
- **Ref policies** — `track_branch` (follow a branch's HEAD SHA),
  `latest_tag` (newest release tag), `pin_tag` / `pin_sha` (exact pin), and
  `release` (download the publisher's prebuilt `.tgz` release asset). Ref → URL
  mapping is a **pure, unit-tested** builder (`PluginGitpluginsRefResolver`).
- **CronTasks** (`PluginGitpluginsUpdatecheck`, web + CLI cron) — `checkUpdates`
  (hourly: resolve refs, set the cached update-available flag, run queued
  install/update actions), `applyUpdates` (every 15 min: apply the bulk-queued
  updates through the verified pipeline), and `notifyUpdates` (daily: email the
  update digest).

The schema is eight tables, all prefixed `glpi_plugin_gitplugins_`: `sources`,
`installs`, `logs`, `config`, plus `snapshots` (retained rollback points),
`known_issues` (conflict/advisory registry), `catalog` (cached catalog manifests),
and `targets` (pull-model deploy targets). The plugin writes **only** to
`glpi_plugin_gitplugins_*` (plus `glpi_profilerights` for its right) and **reads**
the core `glpi_plugins` table for discovery. See [`docs/SCHEMA.md`](docs/SCHEMA.md)
and [`docs/ENHANCEMENTS-SPEC.md`](docs/ENHANCEMENTS-SPEC.md) for the full data
model and the phased design notes.

## Security

Installing remote code is a supply-chain surface; the controls are first-class,
to **OWASP Top 10 (2021)** / **ASVS L2+**:

- **A10 SSRF** — HTTPS-only, host allowlist, DNS resolution blocking
  private/loopback/link-local/cloud-metadata/IPv4-mapped-IPv6 addresses, no
  userinfo/odd ports, **re-validation on every redirect**, peer-IP pinning
  (DNS-rebinding mitigation), proxy-aware fetch, size cap + timeout.
- **Zip/tar-slip** — every archive entry sanitised before write; extraction into
  a fresh temp dir; single-root layout validated; **atomic placement** with
  backup-and-restore.
- **Neutralised backups** — pre-update backups are relocated **out of the web
  tree** (into `GLPI_VAR_DIR`) as an inert `.zip` (never a runnable PHP tree under
  `plugins/`), `0600/0700`, web-user owned — a leaked backup cannot be executed to
  re-introduce a vulnerable version.
- **Multi-target deploy** — pull model only: the origin serves a read-only,
  **HMAC-signed, SHA-pinned** deploy manifest (freshness/replay window, no
  secrets); there is **no inbound code-execution endpoint** on any instance.
- **A01 Broken Access Control** — dedicated `plugin_gitplugins` right, granted
  only to `config: UPDATE` profiles; every front/ajax entry checks login + right.
- **Secrets** — credentials **GLPIKey-encrypted** at rest, sent only as an auth
  header, never logged or displayed.
- **A04 Insecure Design** — downgrade refused unless explicitly allowed;
  auto-install OFF by default (confirm-before-install).
- **A09 Logging** — every fetch/install/update audited with generic messages
  (no PII, secrets, or stack traces).
- **Scope discipline** — writes only to `glpi_plugin_gitplugins_*`
  (+ `glpi_profilerights`); reads `glpi_plugins`; never touches core or other
  plugins' tables.

## Screenshots

Installing/updating a plugin (here **Matomo**) from git with Git Plugin Installer:

1. **Configuration** — declare the allowed git hosts (SSRF allowlist).
   ![Configuration](docs/screenshot-1.png)
2. **Installed plugins** — every manually-installed plugin is listed; marketplace-managed ones are excluded. Add a git source to any of them.
   ![Installed plugins](docs/screenshot-2.png)
3. **New source** — paste the repository URL.
   ![New source](docs/screenshot-3.png)
4. **Detect from URL** — the plugin key, name, provider and reference are auto-filled from the repository's `plugin.xml`.
   ![Detect from URL](docs/screenshot-4.png)
5. **Sources** — the managed git sources, with install / update actions.
   ![Sources](docs/screenshot-5.png)
6. **Confirm** — review key, reference, SHA and installed → available version before queuing.
   ![Confirm install](docs/screenshot-6.png)
7. **Status** — the cron fetches, extracts and installs; per-plugin result.
   ![Status](docs/screenshot-7.png)
8. **Update detection** — installed plugins with an available update are flagged automatically (cloud badge), no manual check needed.
   ![Update available](docs/screenshot-8.png)

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md).

## Support

Issues: <https://github.com/FathiBenNasr/gitplugins/issues>

## License

GPL-2.0-or-later. © 2026 Convergent Cloud Computing (Fathi Ben Nasr).

---

<div align="center">

## Developed by

[![Convergent Cloud Computing](https://www.convergent.tn/assets/images/convergent-logo.png)](https://www.convergent.tn)

**[Convergent Cloud Computing](https://www.convergent.tn)**  
Cloud infrastructure, open-source integration, and cybersecurity solutions for Tunisian and international businesses.

📧 contact@convergent.tn | 🌐 [www.convergent.tn](https://www.convergent.tn)

</div>
