# Git Plugin Installer — Enhancements Spec

> **Status:** SPEC / PLAN ONLY (2026-07-01). No code yet. This document plans a
> multi-phase enhancement of `gitplugins`, distilled from lessons learned across
> our in-house GLPI plugins (`assetreport`, `assetcve`, `comm`, `commcenter`,
> `mb-api`, `glpi-sre-hub`) and the OCS/glpiinventory integration work.
>
> **Sequencing rule:** ship **Phase 0 (Robustness)** first — every later feature
> depends on the install pipeline being trustworthy (backup-until-verified,
> rollback, cache-clear). Do not start a feature phase until Phase 0 is merged
> and its tests pass.
>
> **Design invariants (unchanged from CLAUDE.md / SCHEMA.md):**
> - Meta-plugin: **no business functionality of its own**; extend GLPI's native
>   `Plugin::install()/activate()` seam, never a parallel installer.
> - Touch **only** `glpi_plugin_gitplugins_*` tables; read core `glpi_plugins`
>   read-only; never write another plugin's tables.
> - Security-first: SSRF-guarded fetch, GLPIKey-encrypted credentials, generic
>   log messages (no secrets/stack detail), installs run **out of the web request**
>   in CronTask and are resumable.
> - Pure/testable core (fetch, extract, version-compare, decision functions) with
>   unit tests under `tests/`.

---

## Anchor points in the current code

The pipeline these enhancements hook into (verified 2026-07-01):

| Seam | File / method | Role |
|---|---|---|
| Install/update entry | `Installer::run($source,$ref,$sha)` | fetch → extract → place → nativeInstall → upsert `installs` |
| Native drive | `Installer::nativeInstall($key)` | `checkPluginState` → `install($id)` → `activate($id)` |
| Atomic placement | `Extractor::placeAtomically($staged,$base,$key)` | rename target→`.bak.<ts>`, move staged→target, **drops backup on success** |
| Layout probe | `Extractor::layoutHasSetup`, `singleTopDir` | validate archive shape |
| Manifest parse | `Manifest::parseXml`, `parseInfo` | reads `plugin.xml` (name, key, version, **requirements**, `<gitupdate>`) |
| Discovery | `Discovery::scan`, `installedPlugins`, `decideState` | on-disk plugins vs managed sources |
| Update check | `Updatecheck::cronCheckUpdates`, `resolveLatest`, `buildDigest` | hourly available-version resolution + digest |
| Decision (pure) | `Installer::decideAction`, `Version::isNewer/highest` | install vs update vs none |
| Config singleton | `Config::singleton()` + getters/`saveFields` | SSRF allowlist, caps, policy gates |

Data model today: `sources`, `installs`, `logs`, `config` (see `docs/SCHEMA.md`).

---

# Phase 0 — Robustness fixes (foundation, ship first)

> **STATUS (2026-07-02): IMPLEMENTED — R1–R8 all landed on branch
> `phase0-robustness`, 102 unit tests green, not yet committed (awaiting owner
> say-so).** New classes: `Backup` (neutralised out-of-web-tree inert-zip backup
> + restore), `Preflight` (R6), `Builder` (R5), `Locales` (R7), `Snapshot` (R8,
> fail-safe scoped DB dump). `run()` now does preflight-gate → build → locale-
> compile → atomic place w/ backup → snapshot → nativeInstall → verify → rollback
> (files + schema) on failure. Config: `carry_over_dirs`, `auto_cache_clear`,
> `build_timeout_seconds`, `snapshot_max_mb`; sources: `build_on_install`.

These convert `run()`/`placeAtomically()` from "place and hope" into
"place, build, verify, and roll back on failure." They are the reason our
per-plugin `deploy.sh` scripts exist; folding that discipline into gitplugins
retires those scripts.

### R1 — Preserve carry-over dirs across update (`vendor/`, `node_modules/`)
- **Lesson:** `assetreport/scripts/deploy.sh` uses `rsync --exclude=vendor` so a
  one-click `composer require mpdf/mpdf` (the optional high-fidelity PDF backend)
  survives updates. `placeAtomically` currently `rrmdir`s the backup on success,
  so a locally-built `vendor/` is **destroyed** on every update.
- **Seam:** `Extractor::placeAtomically` — add `array $carryOver = ['vendor','node_modules']`.
  After the staged tree is renamed into place and **before** the backup is
  dropped: for each carry-over dir present in the backup but **absent** in the
  new tree, move `$backup/<dir>` → `$target/<dir>`. Never overwrite a dir the new
  release ships itself.
- **Config gate:** `carry_over_dirs` JSON in `config` (default `["vendor","node_modules"]`).
- **Tests:** backup-has-vendor + release-lacks-vendor → carried; release-ships-vendor → release wins; neither → no-op.

### R2 — Keep the backup until post-install verification (enables rollback)
- **Lesson:** OCS schema drift and the geninventorynumber hook showed installs
  can fail *after* files are placed (during migration/activate). Today the backup
  is dropped the instant the rename succeeds — too early to recover.
- **Seam:** split `placeAtomically` so it **returns the backup path** instead of
  deleting it. `Installer::run` owns the backup lifetime: drop it only after R3
  verification passes; restore it on any failure.
- **Tests:** simulated `nativeInstall` throw → backup restored, target back to prior tree.

### R3 — Post-install verify + self-heal
- **Lesson:** `deploy.sh` ends by asserting `state==1 && version==expected`,
  because a code/DB version mismatch silently **deactivates** the plugin and 404s
  every page. gitplugins reports "ok" today without checking.
- **Seam:** after `nativeInstall`, add `Installer::verifyInstalled($key,$expectedVersion)`:
  1. `Plugin::getFromDBbyDir` → assert `state == Plugin::ACTIVATED` and
     `version === installedVersion($key)`.
  2. On mismatch: retry `install($id)` **once** (idempotent, `--force` equivalent).
  3. Still bad → roll back from the R2 backup, mark `last_result='error'`,
     `last_error='verify_failed'`, log, return false.
- **Feeds:** the "Post-install health gate" feature (F5) reuses this seam.
- **Tests:** active+match → ok; deactivated → retry path; retry-fails → rollback path.

### R4 — Auto cache-clear after activate
- **Lesson:** the README still instructs a manual cache clear; forgetting it is
  our most common post-update 404 cause (stale plugin route/asset cache).
- **Seam:** in `nativeInstall`, after `activate`, invalidate GLPI's cache the way
  core does post-plugin-change (`$GLPI_CACHE` clear + plugin cache reset). Best
  effort, wrapped so a cache backend without flush never fails the install.
- **Tests:** unit-cover the "cache backend throws → install still ok" branch.

### R5 — Build step for source (branch/tag/SHA) installs
- **Lesson:** `assetreport` and `glpi-sre-hub` ship `composer.json`; a branch/tag
  **source tarball** (as opposed to a prebuilt `release` `.tgz`) arrives without
  `vendor/`, so the plugin is broken on arrival. The `release` policy exists to
  dodge this, but source policies still need a build.
- **Seam:** new `Builder::run($dir)` invoked in `run()` after extract, before
  `placeAtomically`. If `composer.json` present and `vendor/` absent →
  `composer install --no-dev --no-interaction` in the staged dir; if
  `package.json` + build script present and `node_modules/` absent → the declared
  build. Runs in CronTask context (never the web request), time/again-caps from
  `config`, **opt-in** per source (`build_on_install` bool on `sources`).
- **Security:** running upstream build scripts executes third-party code — same
  trust boundary as `install()`, but call it out explicitly in the confirm screen
  and default the flag **off**.
- **Tests:** decision-only (needs-build? yes/no) is pure and unit-tested; the
  exec itself is integration-gated behind a capability probe.

### R6 — Requirements + environment preflight (pre-fetch gate)
- **Lesson:** our plugins need PHP `snmp`, `pcntl`, `gd`, `zip`; a missing
  extension yields a half-installed plugin. `Manifest::parseInfo` already reads
  `plugin.xml` requirements (GLPI min/max, PHP min).
- **Seam:** new pure `Preflight::check(array $manifestInfo): array` returning
  `{ok, blockers[], warnings[]}`. Verifies GLPI version in `[min,max]`, PHP
  `>= min`, and required extensions (from a `requirements/extensions` list in
  `plugin.xml` when present, plus a small built-in heuristic map for our known
  plugins). `run()` aborts before fetch when `blockers` is non-empty.
- **Note:** this is the *gate*; feature **F4** is the human-facing report screen
  built on the same function.
- **Tests:** table-driven — version in/out of range, ext present/absent.

### R7 — Locale compile (`.po` → `.mo`) on fetch
- **Lesson:** external sources sometimes ship only `.po`; GLPI loads `.mo`, so
  translations silently fall back to English. Our plugins ship `.mo`, but managed
  third-party ones may not.
- **Seam:** in `Builder::run` (or a sibling `Locales::compile($dir)`): for each
  `locales/*.po` with a missing/older `.mo`, compile with `msgfmt` when available;
  skip silently (warning in log) when the binary is absent.
- **Tests:** stale-mo detection is pure; compile is capability-gated.

### R8 — DB-table snapshot before migration (completes rollback)
- **Lesson:** R2 restores files; a failed migration can still leave **schema**
  ahead of the restored code. Full rollback needs the plugin's own tables saved.
- **Seam:** before `nativeInstall`, `Snapshot::dumpOwnedTables($key)` writes a
  gzipped dump of `glpi_plugin_<key>_*` tables to `GLPI_DUMP_DIR`. On R3 rollback,
  offer/execute restore. **Bounded** (row/size cap from `config`); skip + warn
  when over cap rather than blocking the update.
- **Security/scope:** only ever dumps the target plugin's own prefixed tables.
- **Tests:** owned-table enumeration is pure; dump/restore is integration-gated.

**Phase 0 config additions (`config` table):**
`carry_over_dirs` JSON, `build_timeout_seconds` INT, `snapshot_max_mb` INT,
`auto_cache_clear` bool (default 1).

**Phase 0 exit criteria:** an update that fails migration leaves the previously
installed, working version active (files + schema), with a clear error — verified
by an integration test using a deliberately broken fixture plugin.

---

# Phase 1 — Local / dev source type

> **STATUS (2026-07-02): IMPLEMENTED.** New `Localsource` (pure `normalisePath` +
> `pathAllowed` allowlist check, live `copyToStaged` with realpath re-check +
> symlink/VCS skip). `run()` branches: `provider==='local'` skips fetch and copies
> the allowlisted path into the staged tree, then the whole Phase 0 pipeline runs
> unchanged. `resolveLatest` reads the local plugin.xml version (no network);
> install.php treats a same-version local re-sync as an update. Config gains
> `allow_local_sources` (default OFF) + `local_source_roots`; sources.provider
> gains `local`. UI: source-type toggle on the source form + config controls.
> 9 pure allowlist tests (111 total green).

- **Purpose:** register a source that points at a **local path** (our working
  dir) and sync + reinstall on demand — replacing the hand-rolled per-plugin
  `deploy.sh`.
- **Lesson:** we maintain a bespoke `deploy.sh` per plugin (rsync + reinstall +
  chown + verify). One source type generalises it.
- **Data model:** `sources.provider` gains `local`; `sources.url` holds an
  absolute path. `ref_policy` ignored for `local`.
- **Seam:** `Installer::archiveFor`/`run` branch: for `local`, skip fetch; copy
  the path into the staged dir (respecting R1 carry-over, R5 build, R6 preflight,
  R3 verify). Reuse the entire Phase 0 pipeline — local is just a different
  *acquire* step.
- **Security:** path must be under an admin-configured `local_source_roots`
  allowlist in `config` (no arbitrary filesystem read); never expose in
  multi-tenant/hosted installs (gate behind a config flag, default off).
- **UI:** `front/source.form.php` adds a "Local path" provider option; `install.php`
  shows "sync from local" instead of "download".
- **Tests:** path-in-allowlist decision (pure); copy+build+verify (integration).

# Phase 2 — Version rollback

> **STATUS (2026-07-02): IMPLEMENTED.** New `glpi_plugin_gitplugins_snapshots`
> table + `Rollback` class. Phase 0's per-update R2 file backup + R8 DB dump are
> now RETAINED (not discarded) as snapshot rows; `record()` prunes to the newest
> `rollback_keep` (config, default 3, deleting pruned files). `Rollback::to()`
> restores files → owned tables → re-register + verify (`Installer::reinstallActive`).
> UI: per-plugin snapshot dropdown + Rollback button on status.php; front/rollback.php
> handler (UPDATE right, framework CSRF). Uninstall purges snapshot files.
> `idsToPrune` is pure (5 tests; 116 total green).

- **Purpose:** one-click revert a managed plugin to its previous version.
- **Lesson:** failed migrations (OCS, geninventorynumber) needed a clean way back.
- **Data model:** new `glpi_plugin_gitplugins_snapshots` (id, plugin_key,
  source_id, version, sha, files_archive_path, db_dump_path, created_at). Retain
  last **N** (config `rollback_keep`, default 3); prune oldest.
- **Seam:** Phase 0 already produces a file backup (R2) and DB dump (R8) per
  update — persist the last N instead of discarding. `Rollback::to($key,$snapshotId)`
  restores files + tables, runs `nativeInstall` + R3 verify against the old
  version, records a `logs` row.
- **UI:** `front/status.php` per-plugin "Rollback to…" with the retained list.
- **Tests:** restore decision + retention/prune are pure; apply is integration.

# Phase 3 — Multi-target deploy

- **Purpose:** install/update a managed plugin to **several GLPI instances**
  (e.g. dev → `servicedesk` prod) from one screen.
- **Lesson:** we deploy to prod as a separate manual step today.
- **Data model:** new `glpi_plugin_gitplugins_targets` (id, name, base_url,
  auth token GLPIKey-encrypted, is_active). A deploy = fan-out of the same
  resolved artifact to each target.
- **Mechanism:** targets expose gitplugins' own **authenticated internal
  endpoint** (`ajax/deploy.php`, token-gated, CSRF-exempt but HMAC-signed) that
  accepts "install artifact X at ref Y" and runs the local Phase 0 pipeline.
  **No SSH/rsync** — stays within the GLPI/PHP trust model and reuses SSRF/verify.
- **Security:** high-value — mutual token auth, per-target allowlist, artifact
  SHA pinned so a target installs exactly what the origin resolved. Rate-limited.
- **UI:** `front/targets.php` (CRUD) + a "Deploy to targets" action on status.
- **Tests:** signature/verify (pure); fan-out orchestration + partial-failure
  reporting (integration, mocked HTTP).
- **Open question:** is a push endpoint acceptable, or should targets **pull** on
  their own cron from a shared source? Pull is simpler/safer; push is faster.
  → decide before building (see Open Questions).

# Phase 4 — Environment preflight (report screen)

- **Purpose:** human-facing "will this install cleanly here?" report.
- **Built on:** R6 `Preflight::check`. This phase is the **UI**: a pre-install
  panel on `front/install.php` listing GLPI/PHP/extension checks with pass/warn/
  block badges, and a standalone "Environment" tab summarising the host.
- **Tests:** rendering from a fixed `check()` result (snapshot).

# Phase 5 — Post-install health gate

- **Purpose:** after install, surface the plugin's **own** health, not just
  "activated".
- **Lesson:** `assetreport`/`glpi-sre-hub` expose `check_prerequisites()` /
  `check_config()`; a plugin can activate yet be misconfigured.
- **Built on:** R3 verify. Additionally call the target's
  `plugin_<key>_check_prerequisites()` and `plugin_<key>_check_config()` when
  present, capture the result, store on `installs` (`health` ENUM
  `ok|warn|fail|unknown`, `health_detail`), show on status.
- **Policy:** `health==fail` after install → keep active (don't auto-disable) but
  flag red + log; admin decides. Configurable via `health_fail_action`
  (`flag|rollback`, default `flag`).
- **Tests:** result-mapping (pure); invocation guarded when functions absent.

# Phase 6 — Hook-collision detector

- **Purpose:** predict the class of failure geninventorynumber caused (its
  `pre_item_update` hook reverted `otherserial` → 500 on native inventory).
- **Mechanism:** after install, read the newly active plugin's registered
  `$PLUGIN_HOOKS` entries and compare against other active plugins' registrations
  for the **same item hook** (`pre_item_update`, `item_add`, etc. on the same
  itemtype). Report overlaps as warnings ("both X and Y hook pre_item_update on
  Computer — order/precedence may conflict").
- **Data model:** none persisted beyond a `logs` warning + a cached
  `installs.hook_warnings` JSON for the badge.
- **Scope guard:** read-only introspection of the in-memory `$PLUGIN_HOOKS`
  map; never modifies another plugin.
- **Tests:** overlap detection is pure (feed two hook maps → expected collisions).

# Phase 7 — Conflict / known-issues registry

- **Purpose:** warn about known-bad combinations before enabling.
- **Mechanism:** a curated `known_issues` dataset (shipped JSON, refreshable from
  the catalog source in F10) keyed by `plugin_key` + optional version range,
  listing conflicts (`conflicts_with`), min/max peer versions, and free-text
  advisories (e.g. "geninventorynumber ≤ x blocks native NetInventory").
  Consulted at install-confirm and surfaced on status.
- **Data model:** `glpi_plugin_gitplugins_known_issues` (plugin_key, version_range,
  kind ENUM `conflict|advisory|min_peer`, peer_key, peer_range, message, source).
- **Feeds:** F6 collisions can auto-append entries here.
- **Tests:** range-match + conflict-resolution (pure, table-driven).

# Phase 8 — Changelog surfacing

- **Purpose:** show what changes on the confirm screen.
- **Lesson:** every in-house plugin keeps a `CHANGELOG.md`.
- **Mechanism:** during `resolveLatest`/confirm, fetch `CHANGELOG.md` at the
  resolved ref (SSRF-guarded `fetchText`, size-capped) and extract the section(s)
  between installed and available version (reuse `Version` compare). Render as
  read-only markdown on `front/install.php`. Also fetch `<gitupdate>` release
  notes when the forge exposes them.
- **Data model:** none (transient; optional short cache on `installs`).
- **Tests:** changelog slicing (pure — given a CHANGELOG + two versions → section).

# Phase 9 — Bulk update + dry-run

- **Purpose:** "update all managed plugins," preview first.
- **Mechanism:**
  - **Dry-run:** `Planner::plan(sources[])` → per plugin: installed→available
    version, required migration?, preflight (R6) verdict, changelog summary (F8),
    known-issues (F7). No mutation. Rendered as a table.
  - **Apply:** queue `pending_action='update'` for the selected rows; the
    existing CronTask worker (`cronCheckUpdates` sibling `cronApply`) runs them
    sequentially through the Phase 0 pipeline, each independently
    verified/rolled-back.
- **Data model:** reuse `installs.pending_action`; add `cronApplyUpdates`
  CronTask.
- **UI:** `front/status.php` gains "Check all", "Dry-run report", "Update
  selected".
- **Tests:** plan construction (pure) from mocked resolve results; worker
  idempotency.

# Phase 10 — Convergent plugin catalog

- **Purpose:** biggest force-multiplier — a curated list of our
  `git.convergent.tn` plugins so an admin browses and one-click installs the whole
  suite instead of registering each source URL by hand.
- **Mechanism:** a **catalog manifest** (single JSON hosted on
  `git.convergent.tn`, SSRF-allowlisted) listing each plugin: key, name,
  description, repo URL, recommended `ref_policy`, category, and optional
  `known_issues` (feeds F7). gitplugins fetches + caches it; `front/catalog.php`
  renders cards with Install/Manage buttons that pre-fill a `sources` row (reusing
  `ajax/detect.php`'s `<gitupdate>` self-declaration validation).
- **Data model:** `glpi_plugin_gitplugins_catalog` cache (key, name, url,
  ref_policy, category, description, updated_at) + `config.catalog_url`.
- **Security:** catalog is **advisory only** — every install still runs the full
  confirm + preflight + "this runs the target's own code" gate. Catalog URL is
  itself SSRF-allowlisted and signature-checked if we sign it.
- **Tests:** manifest parse/validate (pure); card→prefill mapping.

---

## Consolidated data-model changes

New tables: `snapshots` (P2), `targets` (P3), `known_issues` (P7), `catalog` (P10).
New columns: `sources.build_on_install`, `sources.provider+=local`;
`installs.health`, `installs.health_detail`, `installs.hook_warnings`.
New `config` keys: `carry_over_dirs`, `build_timeout_seconds`, `snapshot_max_mb`,
`auto_cache_clear`, `rollback_keep`, `local_source_roots`, `health_fail_action`,
`catalog_url`. All added via the existing idempotent `ADD COLUMN` migrate block
in `hook.php` (top-up pattern already in use).

## Security review checklist (applies to every phase)

- New fetch targets (catalog, changelog, release notes, multi-target endpoints)
  go through `Fetcher::assertSafeUrl` + `config.allowed_hosts`.
- Build/exec (R5, R7) and local-source (P1) execute or read third-party
  content → **opt-in, off by default, allowlisted**, and called out in the
  confirm UI.
- Multi-target (P3) is the highest-risk addition — mutual token auth, artifact
  SHA pinning, rate-limit, audit every deploy.
- Logs stay generic; credentials/tokens GLPIKey-encrypted, never echoed.
- No secrets in snapshots/dumps beyond what the plugin itself stores.

## Sequencing & dependencies

```
Phase 0 (R1–R8)  ── foundation, MUST be first
  ├─ P2 Rollback           (needs R2 backup + R8 dump)
  ├─ P4 Env preflight UI   (needs R6)
  ├─ P5 Health gate        (needs R3)
  ├─ P1 Local/dev source   (needs full Phase-0 pipeline)
  └─ P8 Changelog          (independent-ish; needs Version)
P6 Hook-collision   ── independent (post-activate introspection)
P7 Known-issues     ── consumes P6 output; consumed by P9/P10
P9 Bulk + dry-run   ── composes R6 + P7 + P8
P3 Multi-target     ── last of the "engine" work (highest risk)
P10 Catalog         ── ties it together; consumes P7
```

Recommended shipping order: **Phase 0 → P4/P5 (cheap, high trust) → P8 → P2 →
P9 → P6/P7 → P1 → P10 → P3.**

## Open questions (decide before the relevant phase)

1. **P3 push vs pull.** Push endpoint (fast, riskier) or targets pull from a
   shared source on their own cron (simpler, safer)? *Recommendation: pull.*
2. **R5/P1 exec policy.** Are build scripts / local-source reads acceptable on
   the production `servicedesk` box, or restrict to a dev instance only?
3. **P10 catalog signing.** Sign the catalog manifest (GPG/minisign) or rely on
   HTTPS + host allowlist? Signing adds key management.
4. **Snapshot retention (P2).** `rollback_keep=3` default — confirm disk budget
   on prod.
