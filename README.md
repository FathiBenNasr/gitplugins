# Git Plugin Installer — install/update GLPI plugins from git

[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![GLPI](https://img.shields.io/badge/GLPI-%3E%3D%2011.0-orange)](https://glpi-project.org)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D%208.2-purple)](https://www.php.net/)

A GLPI 11 **meta-plugin** that installs and updates other plugins from a
**git/HTTPS source** (GitHub, GitLab, Gitea, Forgejo) — for plugins not on the
official marketplace (private or self-hosted repos, e.g. our own on
`git.convergent.tn`).

It **extends** GLPI's native pipeline rather than replacing it: it owns a
security-hardened **fetch + extraction** step, then drives GLPI core's own
**`Plugin::install()/activate()`** seam so managed plugins behave like
marketplace ones (their own install hooks run).

## Features
- Register a source: repo URL + ref policy — track a branch, latest release tag,
  or pin a tag / commit SHA. Optional **GLPIKey-encrypted** token for private repos.
- **HTTPS tarball** download (no `git` binary), so no `.git` lands in `plugins/`.
- **Confirm-before-install** screen showing the source, resolved ref and commit
  SHA, with an explicit "this runs the target's own code" warning.
- **Update-check cron**; installs run **out of the web request** and are
  **resumable** (a crash leaves the job pending + a backup, retried next tick).
- Status view (installed vs available version, last check/result);
  **remove-from-management** without uninstalling the GLPI plugin.

## Security (OWASP Top 10 2021 / ASVS L2+)
Installing remote code is a supply-chain surface; the controls are first-class:
- **A10 SSRF:** HTTPS-only, host allowlist, **DNS resolution + blocking of
  private / loopback / link-local / cloud-metadata (`169.254.169.254`) /
  IPv4-mapped-IPv6** addresses, no userinfo/odd-ports, **re-validation on every
  redirect**, peer-IP pinning (DNS-rebinding mitigation), proxy-aware fetch
  (honours `$CFG_GLPI['proxy_*']`), size cap + timeout.
- **Zip-slip:** every archive entry sanitised (reject `..`/absolute/backslash)
  before write; extraction into a fresh temp dir; single-root layout validated;
  **atomic placement** with backup-and-restore.
- **A01:** dedicated `plugin_gitplugins` right, granted only to config-UPDATE
  profiles; every front/ajax entry checks login + right.
- **Secrets:** tokens GLPIKey-encrypted, sent only as an auth header, never
  logged or displayed.
- **A04:** downgrade refused unless explicitly allowed; auto-install OFF by default.
- **A09:** every fetch/install/update audited (generic messages, no PII/stack traces).

## Scope / data discipline
Writes only to `glpi_plugin_gitplugins_*` (+ `glpi_profilerights` for its right);
READS `glpi_plugins`. Never touches core or other plugins' tables.

## License
GPL-2.0-or-later. © 2026 Convergent Cloud Computing (Fathi Ben Nasr).
