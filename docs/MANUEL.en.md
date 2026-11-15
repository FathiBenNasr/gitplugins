# Git Plugin Installer — user manual

Version: **1.1.6** (GLPI 12 line; production runs 1.0.9 on GLPI 11)

## What the plugin is for

The Git Plugin Installer tracks GLPI plugins hosted in Git repositories (GitHub, GitLab, Forgejo, Gitea…). It regularly checks whether a newer version exists, tells you in a status screen and in a digest email, then installs the update when you confirm it. Before each update, it keeps a backup copy of the plugin so you can roll back in one click.

> **Important:** installing a plugin runs that plugin's installation code on the server. Only confirm trusted sources.

## Where to find it

**Setup → Git Plugin Installer** menu. The menu only appears if your profile has the plugin right. It offers:

| Entry | What you do there |
|---|---|
| **Sources** | List of tracked repositories; the "+" button creates a source |
| **Installed plugins** | See all installed plugins and their source status |
| **Status** | See installed and available versions, run an update, roll back |
| **Configuration** | Configure the plugin |

From **Status**, three buttons lead to additional screens: **Catalog** (catalog), **Targets** (deployment targets) and **Bulk update (dry-run)** (bulk update with preview). These three labels are displayed in English.

The plugin adds no tab to GLPI forms.

## Required rights

The right is called **plugin_gitplugins** (to be set in Administration → Profiles). At installation it is granted only to the profiles that can already change the GLPI configuration.

| Level | What it allows |
|---|---|
| Read | See the sources, the installed plugins, the status, the update plan, the catalog and the targets |
| Update | Create and edit sources, queue an install or update, run a bulk update, roll back, change the configuration, manage the catalog and the targets |
| Delete (purge) | Remove a source or a target |

Sources are attached to an entity: you only act on those in your entities.

## The tasks, step by step

### Add a source

1. Open **Setup → Git Plugin Installer → Sources**, then click **New source** (or "+").
2. Paste the **Repository URL (https only)** and click **Detect**: the fields are pre-filled from the repository's plugin.xml file. If detection fails, fill in the fields by hand.
3. Check the **Display name** and the **Plugin key (directory name)**: lowercase letters, digits and underscore only.
4. Choose the **Ref policy**:
   - **Latest release tag**: the most recent published version;
   - **Track a branch**: the tip of a branch;
   - **Pin to a tag** or **Pin to a commit SHA**: a frozen version;
   - **Latest built release (.tgz asset)**.
5. For the "branch", "tag" or "SHA" policies, fill in the **Ref (branch / tag / SHA)**.
6. For a private repository, enter the **Private-repo token (read-only)**. It is encrypted and never displayed again; leave the field empty to keep the stored token, or tick **Remove the stored token**.
7. Leave **Active (checked for updates)** ticked, then click **Save**.

Result: the message "Source created." is displayed and the plugin appears in the list of sources and in the **Status** screen.

If an error message appears, it states the cause: non-https URL, host not on the allowed-hosts list, key already managed by another source, or plugin "managed by the GLPI marketplace" (in that case, update it from the marketplace).

### Register an already installed plugin

1. Open **Installed plugins**. Each plugin has a status: **managed**, **declared**, **marketplace** or **no source**.
2. For a "declared" plugin (the plugin announces its own repository), click **Register as managed source**. For a plugin with no source, click **Add source**.
3. If the repository is private, then open the source (**Edit source**) and add the token.

Result: "Source registered as managed."; no download takes place at this stage.

### Check the status and spot an update

1. Open **Status**.
2. Read the table: **Plugin key**, **Installed** (installed version), **Available** (available version), **Pending** (pending action), **Last check**, **Last result** and **Health** (health status: ok, warn, fail).
3. A yellow row indicates an available update; a banner at the top gives the count.
4. Additional badges may appear: hook conflicts with other plugins, or "known issues". Hover over them for details.

Result: you know which plugins are up to date. The screen does not query the Internet: it shows the result of the last automatic check.

### Install or update a plugin

1. In **Sources** or **Status**, click **Install / Update** next to the plugin.
2. Click **Review install / update**: the plugin contacts the source and displays the exact ref, the commit SHA, the installed version and the available version, an environment check (**Environment preflight**: ready or blocked), any known issues and the changelog between the two versions.
3. If the check says "blocked", do not continue: note the reasons shown and contact support.
4. Read the warning, then click **Queue install / update** and confirm.

Result: "Queued. The installer cron will fetch and install it on its next run." The installation is therefore not immediate: follow it in **Status** (**Pending** and **Last result** columns).

Special cases: "Already up to date — nothing to install."; "Refused: the available version is older than the installed one…" (downgrade not allowed); "The source moved since you reviewed it" (start the review again).

### Update several plugins at once

1. In **Status**, click **Bulk update (dry-run)**.
2. The table is a preview: for each plugin, the planned **Action**, a likely **Migration**, the **Preflight** (ready or blocked) and the number of **Known issues**.
3. Tick the applicable rows (or the header box to tick all), then click **Update selected** and confirm.

Result: a message announces the number of plugins queued (in English); the automatic action then applies them one by one. If nothing is applicable, the screen says so.

### Roll back to a previous version

1. In **Status**, find the plugin's row: a dropdown list of backups (version and date) appears if any exist.
2. Choose the backup, click **Rollback** and confirm. The plugin's code and its tables are replaced.

Result: the message "Rolled back to the selected snapshot." (displayed in English) confirms the rollback. If it fails, the plugin stays as it is and an error message is displayed.

### Remove a source

1. Open the source (**Sources**, then its name), click **Remove** and confirm.

Result: "Source removed from management." The installed plugin is **not** uninstalled. This right is the delete (purge) right.

### Other screens (advanced use)

- **Catalog**: list of plugins offered by one or more configured catalogs. Click **Refresh catalog** to refresh it, then **Add source** to pre-fill a new source. This installs nothing.
- **Targets** ("Deploy targets"): GLPI instances allowed to retrieve a signed deployment manifest. This plugin never installs anything on these instances. Added via **Add target** (name, informational address, shared secret to enter; it is never displayed again).
- **Configuration**: allowed hosts, maximum sizes and timeouts, check frequency, number of backups to keep, action to take if the health check fails after installation (report only or roll back automatically), catalog addresses, and digest email settings. These settings are the administrator's; if in doubt, contact support.

## What users receive

Only the recipients of the digest email receive anything. The email is sent through GLPI's mail system, at most once a day, and **only if** the list of available updates has changed since the last sending. Its subject is of the type "2 managed plugins have updates available". For each plugin, it contains the installed version, the available version and the repository address, then the invitation "Review and apply updates from the Git Plugin Installer status page."

Recipients: the address entered in **Digest recipient (optional)**; failing that, all active users of the Super-Admin profile; failing that, the GLPI administration address. Sending is controlled by the **Email a digest when managed plugins have updates available** box (enabled by default). Another box adds the Convergent Cloud Computing services menu to the email.

The plugin creates no ticket or notification in the GLPI interface.

## Automatic actions

Visible in **Setup → Automatic actions**, under "Git plugin update check":

| Task | What it does | Default frequency |
|---|---|---|
| Check managed git sources for updates and run pending installs | Checks the active sources, updates the **Status** screen and runs pending installs | Every hour |
| Email a digest of managed plugins that have an available update | Sends the digest email described above | Once a day |
| Apply plugin installs/updates queued from the bulk dry-run (English label) | Applies the queued installs and updates | Every 15 minutes |

Each installation is checked and, if it fails, rolled back. A queued installation only moves forward if these tasks are running: if **Pending** does not change, see below.

## Frequently asked questions and common problems

| Symptom | What to do |
|---|---|
| The menu does not appear | Your profile does not have the plugin right. Ask an administrator for it, then log in again. |
| "Host … is not in the allowed-hosts list" | Contact support: the host must be added to the configuration. |
| "This plugin is managed by the GLPI marketplace" | Update it from the GLPI marketplace, not here. |
| "This plugin key is already managed by another source" | A plugin has only one source. Edit the existing source. |
| "This repository is declared private — a token is required" | Add a read token in the source; otherwise contact support. |
| After "Queued", nothing changes | The installation is waiting for the automatic action (up to 15 minutes to an hour). Wait; if nothing changes, contact support. |
| "Health" badge red (fail) | The plugin fails its own check after installation. Contact support; a rollback to the previous version is possible (see Rollback). |
| No list of backups for a plugin | No backup is kept for it (the number kept is a setting). Contact support. |
| No digest email | It is only sent if the setting is enabled and if a new update has appeared since the last sending. Otherwise, contact support. |

## Support

contact@convergent.tn
