// Regenerates the README screenshots of Git Plugin Installer (gitplugins) against the
// GLPI 11 bench, and doubles as a real-browser test of its pages.
//
// The bench is a COPY OF PRODUCTION: every page is anonymised in the DOM before it is
// photographed (person names, logins, e-mail addresses, phone numbers, IP addresses,
// host names, filesystem paths, entity names, and plugins that are not public), then
// re-scanned; the run fails closed if anything recognisable is left.
//
// Runs in the Puppeteer container; credentials come from the environment only:
//   GLPI_URL, GLPI_USER, GLPI_PASS, OUT
// Optional:
//   PUBLIC_PLUGINS  comma-separated plugin keys allowed on screen (default list below)
//   EXTRA_REDACT    comma-separated extra strings to replace (e.g. an internal project name)
//   ROWS            maximum table rows kept on screen (default 12)
//
// The script FAILS (exit code 1) on: a JavaScript dialog, an uncaught page error, an HTTP
// status >= 400 from the bench, a redirect away from the page (lost session / access
// denied), a missing plugin tile or logo, a missing page element, or any sensitive
// value still visible after anonymisation.
const puppeteer = require('/app/scripts/node_modules/puppeteer');
const env = k => { const v = process.env[k]; if (!v) throw new Error('missing ' + k); return v; };
const pause = ms => new Promise(r => setTimeout(r, ms));
const fail = m => { console.error('FAIL: ' + m); process.exitCode = 1; };
const out = n => `${env('OUT')}/${n}.png`;

const G = env('GLPI_URL').replace(/\/+$/, '');
const ORIGIN = new URL(G).origin;
const ROOT = G + '/plugins/gitplugins/front/';
const ROWS = parseInt(process.env.ROWS || '12', 10);

// Plugins whose key, name and repository may appear in a published picture: this one,
// its public sibling, and the well-known upstream plugins of the GLPI catalogue.
// Every other row (our private or commercial plugins) is removed from the tables.
const PUBLIC_PLUGINS = (process.env.PUBLIC_PLUGINS || [
  'gitplugins', 'matomo', 'fields', 'formcreator', 'datainjection', 'genericobject', 'tag',
  'behaviors', 'mreporting', 'escalade', 'news', 'reports', 'manageentities', 'oauthimap',
  'glpiinventory', 'ocsinventoryng', 'uninstall', 'accounts', 'order', 'credit',
  'moreticket', 'timelineticketfilter', 'webapplications', 'databaseinventory',
  'satisfaction', 'positions', 'treeview', 'pdf', 'addressing', 'singlesignon',
  'mydashboard', 'tasklists', 'metademands', 'racks', 'openvas',
].join(',')).split(',').map(s => s.trim().toLowerCase()).filter(Boolean);

// Hosts and repository owners that are public and may stay readable.
const PUBLIC_HOSTS = ['github.com', 'codeload.github.com', 'api.github.com', 'objects.githubusercontent.com',
  'gitlab.com', 'codeberg.org', 'glpi-project.org', 'plugins.glpi-project.org', 'services.glpi-network.com',
  'example.org', 'example.com', 'example.net'];
const PUBLIC_OWNERS = ['pluginsglpi', 'glpi-project', 'infotelglpi', 'teclib', 'example'];

// ── In-page anonymiser (serialised into the page) ──
function anonymise(cfg) {
  const isPublicHost = h => { h = h.toLowerCase();
    return cfg.publicHosts.some(p => h === p || h.endsWith('.' + p)); };
  const hostMap = new Map();
  const fakeHost = h => { if (isPublicHost(h)) return h;
    if (!hostMap.has(h.toLowerCase())) hostMap.set(h.toLowerCase(), hostMap.size ? `git${hostMap.size + 1}.example.org` : 'git.example.org');
    return hostMap.get(h.toLowerCase()); };
  const esc = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  // Collected names (user, login, entities, extras): longest first, so "A > B" goes before "A".
  const names = [...cfg.names].filter(s => s && s.length >= 3).sort((a, b) => b.length - a.length);
  const rules = [
    // URLs: non-public host → example.org; non-public owner → "example"
    [/\b(https?|ssh|git):\/\/([^\s/:'"<>@]+@)?([a-z0-9.-]+)(:\d+)?(\/[^\s'"<>]*)?/gi, (m, sc, cred, host, port, path) => {
      let p = path || '';
      const seg = p.split('/');
      if (seg.length > 1 && seg[1] && !cfg.publicOwners.includes(seg[1].toLowerCase())) seg[1] = 'example';
      p = seg.join('/');
      return `${sc}://${fakeHost(host)}${isPublicHost(host) ? (port || '') : ''}${p}`; }],
    [/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, 'utilisateur@example.org'],
    [/\b(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)\b/g, '192.0.2.10'],
    [/\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{0,4}\b/gi, m => /[a-f]/i.test(m) || m.includes('::') ? '2001:db8::10' : m],
    [/\b(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+(?:tn|com|org|net|lan|local|fr|io|eu|internal|corp|intra|home|dev)\b/gi,
      m => isPublicHost(m) ? m : fakeHost(m)],
    [/\+\d{1,3}[\s.-]?\d[\d\s.-]{6,}\d/g, '+000 00 000 000'],
    [/\b\d{2}[ .]\d{3}[ .]\d{3}\b/g, '00 000 000'],
    [/(^|[\s"'(=])\/(?:root|home|var|opt|srv|etc|usr|tmp)(?:\/[^\s"'<>)]*)?/g, '$1/srv/glpi-plugins'],
  ];
  const clean = s => {
    if (!s || !/\S/.test(s)) return s;
    let t = s;
    for (const n of names) t = t.replace(new RegExp('(^|[^\\p{L}\\p{N}_])' + esc(n) + '(?=$|[^\\p{L}\\p{N}_])', 'giu'), '$1' + (cfg.nameFor[n] || 'Exemple'));
    for (const [re, to] of rules) t = t.replace(re, to);
    return t;
  };
  // Who is logged in, and where: hidden outright, after their strings were collected.
  document.querySelectorAll('.user-menu, .user-menu-dropdown-toggle, .avatar').forEach(e => { e.style.visibility = 'hidden'; });
  // Text nodes
  const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let n = w.nextNode(); n; n = w.nextNode()) {
    if (n.parentElement && /^(SCRIPT|STYLE)$/.test(n.parentElement.tagName)) continue;
    const c = clean(n.nodeValue); if (c !== n.nodeValue) n.nodeValue = c;
  }
  // Form values and visible attributes
  document.querySelectorAll('input, textarea').forEach(i => {
    if (i.type === 'password' || i.type === 'hidden') return;
    const c = clean(i.value); if (c !== i.value) i.value = c; });
  document.querySelectorAll('[title],[placeholder],[alt],[aria-label],[data-bs-original-title]').forEach(e => {
    for (const a of ['title', 'placeholder', 'alt', 'aria-label', 'data-bs-original-title']) {
      const v = e.getAttribute(a); if (v) { const c = clean(v); if (c !== v) e.setAttribute(a, c); } } });
  document.title = clean(document.title);
  return [...hostMap.keys()].length;
}

// Re-scan after anonymisation: returns what is still recognisable (fail closed).
function residue(cfg) {
  const isPublicHost = h => { h = h.toLowerCase();
    return cfg.publicHosts.some(p => h === p || h.endsWith('.' + p)); };
  const root = (cfg.root && document.querySelector(cfg.root)) || document.body;
  let text = root.innerText;
  root.querySelectorAll('input, textarea').forEach(i => { if (i.type !== 'password' && i.type !== 'hidden') text += '\n' + i.value; });
  root.querySelectorAll('[title],[placeholder]').forEach(e => { text += '\n' + (e.getAttribute('title') || '') + '\n' + (e.getAttribute('placeholder') || ''); });
  const left = [];
  const esc = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  for (const n of cfg.names) if (n && n.length >= 3 && new RegExp('(^|[^\\p{L}\\p{N}_])' + esc(n) + '($|[^\\p{L}\\p{N}_])', 'iu').test(text)) left.push('name:' + n.slice(0, 2) + '…');
  for (const m of text.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi) || []) if (!/@example\.(org|com|net)$/i.test(m)) left.push('email');
  for (const m of text.match(/\b(?:\d{1,3}\.){3}\d{1,3}\b/g) || []) if (!m.startsWith('192.0.2.')) left.push('ip:' + m.split('.')[0] + '.x');
  for (const m of text.match(/\b(?:[a-z0-9-]+\.)+(?:tn|com|org|net|lan|local|fr|io|eu|internal|corp|intra|home|dev)\b/gi) || [])
    if (!isPublicHost(m)) left.push('host');
  for (const k of cfg.hiddenKeys) if (new RegExp('\\b' + k + '\\b', 'i').test(text)) left.push('plugin:' + k.slice(0, 2) + '…');
  return left;
}

(async () => {
  const b = await puppeteer.launch({executablePath: '/usr/bin/google-chrome',
    args: ['--no-sandbox', '--disable-gpu', '--ignore-certificate-errors'], headless: 'new'});
  const p = await b.newPage();
  await p.setViewport({width: 1440, height: 900});

  // Nothing leaves the bench (the bench network has no egress anyway).
  await p.setRequestInterception(true);
  p.on('request', r => {
    const u = r.url();
    if (u.startsWith(ORIGIN + '/') || u === ORIGIN || u.startsWith('data:') || u.startsWith('blob:')) r.continue();
    else { console.log('blocked: ' + new URL(u).host); r.abort(); }
  });
  p.on('dialog', async d => { fail('dialog fired: ' + d.message()); await d.dismiss(); });
  // Errors on the login page come from other plugins (e.g. a third-party anti-framing
  // snippet) and say nothing about this one: reported, not fatal. After login, fatal.
  let loggedIn = false;
  p.on('pageerror', e => {
    const m = 'page error on ' + p.url().replace(ORIGIN, '') + ': ' + e.message;
    if (loggedIn) fail(m); else console.warn('WARN (login page, not gitplugins): ' + m); });
  p.on('response', r => {
    if (r.url().startsWith(ORIGIN) && r.status() >= 400) fail(`HTTP ${r.status()} on ${r.url().replace(ORIGIN, '')}`);
  });

  // ── Log in ──
  await p.goto(G + '/', {waitUntil: 'networkidle2'});
  await p.type('#login_name', env('GLPI_USER'));
  await p.type('input[type=password]', env('GLPI_PASS'));
  await Promise.all([p.waitForNavigation({waitUntil: 'networkidle2'}), p.click('button[type=submit]')]);
  if (await p.$('#login_name')) throw new Error('login refused');
  loggedIn = true;

  // ── What must disappear: who is logged in, the entity tree, extra strings ──
  const who = await p.evaluate(() => {
    const m = document.querySelector('.user-menu'); if (!m) return [];
    const s = [];
    m.querySelectorAll('[title]').forEach(e => s.push(e.getAttribute('title')));
    (m.innerText || '').split('\n').forEach(l => s.push(l));
    const h = m.querySelector('.dropdown-header'); if (h) s.push(h.textContent);
    return s.map(x => (x || '').trim()).filter(Boolean);
  });
  const nameFor = {};
  const names = new Set();
  // Generic accounts and profile names are not personal data, and "glpi" would erase the product name.
  const GENERIC = /^(glpi|admin|tech|normal|post-only|super-admin|admin|technician|technicien|self-service|observer|observateur|hotliner|supervisor|superviseur)$/i;
  const addName = (s, fake) => { s = s.replace(/\s*\([^)]*\)\s*$/, '').trim(); if (s.length >= 3 && !GENERIC.test(s)) { names.add(s); nameFor[s] = fake; } };
  addName(env('GLPI_USER'), 'utilisateur');
  for (const s of who) {
    if (s.includes('>')) { s.split('>').map(x => x.trim()).forEach(x => addName(x, 'Organisation exemple')); addName(s, 'Organisation exemple'); }
    else addName(s, 'Utilisateur Exemple');
  }
  for (const s of (process.env.EXTRA_REDACT || '').split(',')) addName(s, 'Exemple');
  // GLPI's profile names stay (generic); the entity root "Root entity"/"Entité racine" too.
  for (const g of ['Root entity', 'Entité racine']) names.delete(g);
  console.log(`redacting ${names.size} collected strings`);

  // Plugin keys present on this server that are not public: harvested from the plugin's
  // own inventory page, then both removed from tables and checked for in every picture.
  const hiddenKeys = [];

  const visit = async (path, expect) => {
    const resp = await p.goto(ROOT + path, {waitUntil: 'networkidle2', timeout: 120000});
    if (!resp) fail('no response for ' + path);
    if (!p.url().includes('/plugins/gitplugins/front/' + path.split('?')[0])) fail(`redirected away from ${path} (to ${p.url().replace(ORIGIN, '')})`);
    await pause(1500);
    if (expect && !(await p.$(expect))) fail(`${path}: element ${expect} not found`);
  };
  const filterRows = () => p.evaluate((pub, max) => {
    let removed = 0, kept = 0;
    document.querySelectorAll('table tbody tr').forEach(tr => {
      const codes = [...tr.querySelectorAll('code')].map(c => c.textContent.trim().toLowerCase());
      if (codes.length && !codes.some(k => pub.includes(k))) { tr.remove(); removed++; return; }
      if (codes.length && ++kept > max) { tr.remove(); removed++; }
    });
    return {removed, kept: Math.min(kept, max)};
  }, PUBLIC_PLUGINS, ROWS);
  // The "N managed plugins have updates" banner counts private plugins too.
  const dropUpdateCounter = () => p.evaluate(() => document.querySelectorAll('.alert').forEach(a => {
    if (/\d+\s.*(mises? à jour disponibles?|updates? available)/i.test(a.innerText)) a.remove(); }));
  const shoot = async (name, sel) => {
    const cfg = {names: [...names], nameFor, publicHosts: PUBLIC_HOSTS, publicOwners: PUBLIC_OWNERS, hiddenKeys};
    await p.evaluate(anonymise, cfg);
    await pause(300);
    const left = await p.evaluate(residue, cfg);
    if (left.length) { fail(`${name}: still visible after anonymisation: ${[...new Set(left)].join(', ')}`); return; }
    const el = (await p.evaluateHandle(s => s === 'heading'
      ? (document.querySelector('h2.mt-3, .d-flex > h2') || {closest: () => null}).closest('.container-fluid')
      : document.querySelector(s), sel)).asElement();
    if (!el) { fail(`${name}: capture area ${sel} not found`); return; }
    await el.screenshot({path: out(name)});
    console.log('wrote ' + name + '.png');
  };

  // ── Inventory first: learn which plugin keys must never appear ──
  await visit('discovered.php', 'table tbody');
  const allKeys = await p.$$eval('table tbody tr td:first-child code', cs => cs.map(c => c.textContent.trim().toLowerCase()));
  for (const k of allKeys) if (!PUBLIC_PLUGINS.includes(k) && k.length >= 3) hiddenKeys.push(k);
  // Their display names too (bold text next to the key).
  const hiddenNames = await p.$$eval('table tbody tr', (trs, pub) => trs.map(tr => {
    const c = tr.querySelector('td:first-child code'), s = tr.querySelector('td:first-child strong');
    return c && s && !pub.includes(c.textContent.trim().toLowerCase()) ? s.textContent.trim() : null; }).filter(Boolean), PUBLIC_PLUGINS);
  for (const n of hiddenNames) addName(n, 'Greffon privé');
  console.log(`installed plugins: ${allKeys.length}, kept public: ${allKeys.length - hiddenKeys.length}`);

  // ── 01: the tile in Configuration → Plugins ──
  await p.goto(G + '/front/marketplace.php', {waitUntil: 'networkidle2', timeout: 120000});
  await pause(2000);
  const filter = await p.$('input[placeholder*="iltr"], input[placeholder*="ilter"]');
  if (!filter) fail('plugin filter box not found');
  else { await filter.type('gitplugins'); await pause(2500); }
  const tile = await p.evaluate(() => {
    const li = [...document.querySelectorAll('li.plugin')].find(e => /git plugin installer|gitplugins/i.test(e.innerText));
    if (!li) return null;
    const img = li.querySelector('.icon img, img');
    return {version: li.innerText.match(/\d+\.\d+\.\d+/)?.[0] || '', logo: img ? img.getAttribute('src') : ''};
  });
  console.log('tile → ' + JSON.stringify(tile));
  if (!tile) fail('gitplugins tile not found');
  else if (!tile.logo) fail('gitplugins tile has no logo');
  else {
    const cfg = {names: [...names], nameFor, publicHosts: PUBLIC_HOSTS, publicOwners: PUBLIC_OWNERS, hiddenKeys: []};
    await p.evaluate(anonymise, cfg);
    const left = await p.evaluate(residue, {...cfg, root: 'li.plugin'});
    if (left.length) fail('01-plugin: still visible after anonymisation: ' + [...new Set(left)].join(', '));
    await p.setViewport({width: 1440, height: 900, deviceScaleFactor: 2});
    const li = await p.evaluateHandle(() =>
      [...document.querySelectorAll('li.plugin')].find(e => /git plugin installer|gitplugins/i.test(e.innerText)));
    const box = await li.boundingBox();
    await p.screenshot({path: out('01-plugin'),
      clip: {x: box.x - 4, y: box.y - 4, width: box.width + 8, height: box.height + 8}});
    console.log('wrote 01-plugin.png');
    await p.setViewport({width: 1440, height: 900, deviceScaleFactor: 1});
  }

  // ── 02: installed plugins and their git-source state ──
  await visit('discovered.php', 'table tbody');
  const disc = await filterRows();
  console.log('discovered rows → ' + JSON.stringify(disc));
  if (disc.removed) await dropUpdateCounter();
  await shoot('02-installed', 'heading');

  // ── 03: managed sources ──
  await visit('source.php', 'table tbody');
  const src = await filterRows();
  console.log('source rows → ' + JSON.stringify(src));
  if (!src.kept) console.warn('WARN: no public managed source on the bench; 03-sources.png shows an empty list');
  await shoot('03-sources', 'heading');

  // ── 04: status (installed vs available, health, update badge) ──
  await visit('status.php', 'table tbody');
  const st = await filterRows();
  console.log('status rows → ' + JSON.stringify(st));
  // The update counter covers every managed plugin, private ones included: hide it if rows were removed.
  if (st.removed) await dropUpdateCounter();
  await shoot('04-status', 'heading');

  // ── 05: configuration (SSRF allowlist, caps, notifications) ──
  // Tall viewport: the form fits without scrolling, so GLPI's sticky header cannot overlap it.
  await p.setViewport({width: 1440, height: 2400});
  await visit('config.php', 'form textarea[name=allowed_hosts]');
  await shoot('05-config', 'form.card');

  await b.close();
  if (process.exitCode) console.error('captures FAILED (see above)');
  else console.log('captures OK');
})().catch(e => { console.error('ERR', e.message); process.exit(1); });
