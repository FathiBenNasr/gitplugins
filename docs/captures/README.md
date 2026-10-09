# Captures d'écran

Produites par le banc navigateur (`capture.js`, Puppeteer + Chrome dans le conteneur
`puppeteer-test`) contre le banc GLPI 11 (`http://10.89.20.11`, conteneur `glpi11-web`
sur le réseau isolé `glpi12_banc`), où la ligne `glpi-11-12` du greffon est installée et
active. **Jamais prises à la main.**

| Fichier | Ce qu'il montre |
|---|---|
| `01-plugin.png` | La tuile de Git Plugin Installer dans Configuration → Plugins, filtrée sur `gitplugins` : le logo et la version déployée. |
| `02-installed.png` | *Installed plugins* : chaque greffon présent sur le disque, lu dans son propre `plugin.xml`, avec l'état de sa source git (*managed*, *declared*, *no source*, *marketplace*), le dépôt, la référence et le fournisseur, et les actions *Add source* / *Register as managed source*. |
| `03-sources.png` | *Sources* : les sources git gérées — nom, clé du greffon, fournisseur, politique de référence (branche, dernier tag, tag ou SHA épinglé, archive de version) — avec l'action *Install / Update*. |
| `04-status.png` | *Managed plugin status* : version installée contre version disponible, action en attente, dernière vérification et son résultat, verdict de santé, et les accès *Catalog*, *Targets*, *Bulk update (dry-run)*. |
| `05-config.png` | La configuration : liste blanche des hôtes (garde SSRF), taille et délai maximaux de téléchargement, fréquence de vérification, installation automatique et rétrogradation (désactivées par défaut), courriel de synthèse, sources locales. |

## Anonymisation

Le banc est **une copie de la production** : il contient de vrais noms, courriels,
adresses IP, noms d'hôtes et noms d'entités. Avant chaque capture, le script réécrit la
page dans le navigateur (nœuds de texte, valeurs des champs, attributs `title`,
`placeholder`, `alt`, `aria-label`) :

- le nom, l'identifiant et l'entité de l'utilisateur connecté, relevés dans le menu
  utilisateur, sont remplacés par « Utilisateur Exemple » et « Organisation exemple » ;
  le menu utilisateur et l'avatar sont masqués ;
- les courriels deviennent `utilisateur@example.org`, les IPv4 `192.0.2.10` (plage de
  documentation), les IPv6 `2001:db8::10`, les numéros de téléphone des zéros ;
- tout nom d'hôte non public devient `git.example.org` (`git2.example.org`, …) ; dans
  une URL, le propriétaire du dépôt devient `example`, sauf organisations publiques
  (`pluginsGLPI`, `glpi-project`, `InfotelGLPI`, `teclib`) ;
- tout chemin absolu du serveur devient `/srv/glpi-plugins` ;
- **les lignes des greffons non publics sont retirées des tableaux** (clé hors de
  `PUBLIC_PLUGINS` : `gitplugins`, `matomo` et les greffons connus du catalogue GLPI),
  ainsi que le bandeau « N greffons gérés ont des mises à jour » qui les compte ; leurs clés et leurs noms
  affichés sont ensuite recherchés dans chaque image.

Puis la page est relue : **s'il reste un nom relevé, un courriel, une IP, un nom d'hôte
non public ou une clé de greffon privé, la capture n'est pas écrite et le script échoue.**
`EXTRA_REDACT` (liste séparée par des virgules) ajoute d'autres chaînes à remplacer.

## Ce qui fait échouer le script

Le script sert aussi de test en vrai navigateur. Il sort en erreur :

- si une **boîte de dialogue JavaScript** s'ouvre (`alert`, `confirm`, `prompt`) ;
- sur toute **erreur JavaScript non interceptée** dans une page vue après la connexion
  (sur la page de connexion, elle vient d'un autre greffon et n'est signalée que par un
  `WARN` : sur le banc, l'extrait anti-iframe du greffon tiers `treeview` est invalide) ;
- sur toute réponse **HTTP ≥ 400** venant du banc (page, AJAX, ressource) ;
- si une page du greffon **redirige ailleurs** (session perdue, droit `plugin_gitplugins`
  manquant) ou si son tableau, son formulaire ou sa zone de capture est introuvable ;
- si la tuile du greffon est introuvable ou n'a pas de logo ;
- si l'anonymisation laisse passer quoi que ce soit (voir plus haut) ;
- si la connexion est refusée.

Toute requête hors du banc est bloquée (et notée `blocked:` dans la sortie) : aucune page
ne joint Internet pendant la capture. Une liste de sources vide n'est pas une erreur, mais
le script l'annonce par un `WARN` : il faut alors enregistrer au moins une source publique
(par exemple `matomo`) sur le banc avant de refaire `03-sources.png` et `04-status.png`.

## Les refaire

Pré-requis : le banc GLPI 11 démarré, la ligne `glpi-11-12` de `gitplugins` installée et
active, un compte doté du droit `plugin_gitplugins` (lecture et mise à jour).

Les identifiants restent dans l'environnement de l'appelant : `podman exec -e NOM` (sans
`=valeur`) recopie la variable sans qu'elle apparaisse dans les arguments du processus.
Aucun mot de passe n'est écrit dans un fichier.

```bash
export GLPI_URL=http://10.89.20.11 GLPI_USER=… GLPI_PASS=…
export OUT=/app/screenshots/gitplugins
podman exec puppeteer-test mkdir -p /app/scripts/gitplugins /app/screenshots/gitplugins
podman cp docs/captures/capture.js puppeteer-test:/app/scripts/gitplugins/capture.js
podman exec -e GLPI_URL -e GLPI_USER -e GLPI_PASS -e OUT \
  puppeteer-test node /app/scripts/gitplugins/capture.js
for n in 01-plugin 02-installed 03-sources 04-status 05-config; do
  podman cp puppeteer-test:/app/screenshots/gitplugins/$n.png docs/captures/; done
```

Relire chaque image avant de la publier : le dépôt est miroité sur un GitHub public.
