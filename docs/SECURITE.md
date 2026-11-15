# Sécurité — Git Plugin Installer

Référentiels : OWASP Top 10 (2021), OWASP ASVS niveau 2, RGPD et loi tunisienne 2004-63. Pour signaler
une faille : [../SECURITY.md](../SECURITY.md).

## Le risque principal : la chaîne d'approvisionnement

Ce greffon **télécharge du code d'un tiers et l'exécute sur le serveur de GLPI**, avec les droits de
l'utilisateur du serveur web : le code d'installation du greffon cible, et, si l'administrateur active
la construction pour une source, des commandes `composer` ou `npm`. Une source compromise (dépôt
piraté, compte de mainteneur volé, tag réécrit, nom de dépôt repris) devient une exécution de code sur
le serveur. Le greffon réduit ce risque ; il ne le supprime pas.

**Ce que le greffon fait :**

- n'agit que sur une source déclarée par un administrateur, jamais sur une adresse fournie dans une
  requête ordinaire ;
- télécharge en HTTPS depuis des hôtes d'une liste blanche, sans binaire `git` ;
- fige la référence et le SHA que l'administrateur a examinés et refuse d'installer autre chose ;
- mémorise le contenu d'un tag à la première installation et **refuse un tag qui pointe ensuite vers
  un autre contenu** (`tag_moved`) ;
- borne l'archive (format reconnu au contenu, plafonds par entrée et au total, chemins assainis,
  liens symboliques refusés) et la place de façon atomique ;
- garde une sauvegarde inerte et un instantané des tables, et permet le retour arrière en un clic ;
- exige une confirmation humaine qui rappelle que le code de la cible s'exécute (l'installation
  automatique est désactivée par défaut) ;
- réserve son droit aux profils qui peuvent déjà modifier la configuration de GLPI ;
- ne lance jamais de construction `composer`/`npm` à partir d'un formulaire : l'option
  `build_on_install` n'est modifiable ni par l'écran, ni par l'API, ni par une action de masse.

**Ce que le greffon ne fait pas :** il **ne vérifie aucune signature** (GPG, Sigstore ou autre) du
code amont, ne compare pas un tag à une liste de confiance, et ne s'appuie que sur la **confiance au
premier usage** : la première version d'un tag est acceptée telle quelle, et une release (archive
`.tgz`) n'est comparée qu'à l'empreinte relevée lors d'une installation précédente. Un code malveillant
publié dès la première installation passe. L'administrateur doit donc : ne déclarer que des dépôts
qu'il maîtrise ou dont il a relu le code ; préférer les politiques épinglées (tag ou SHA) à
**Track a branch** ; n'ajouter à la liste blanche que des hôtes de confiance ; ne pas activer les
sources locales sur un hébergement partagé ; relire le journal des modifications affiché avant de
confirmer. Un greffon hébergé chez un fournisseur public (GitHub, GitLab) hérite de la sécurité du
compte de son propriétaire.

## Points d'entrée et authentification

Toutes les pages appellent `Session::checkLoginUser()` puis exigent le droit `plugin_gitplugins`. Les
formulaires sont protégés contre la CSRF par l'écouteur de GLPI 11 (champ caché) ; le greffon
n'appelle pas `checkCSRF()` lui-même. **Un seul point d'entrée est anonyme**, délibérément :
`ajax/deploy.php`, déclaré dans `setup.php` par `Firewall::addPluginStrategyForLegacyScripts(…,
STRATEGY_NO_CHECK)` pour ce seul chemin, sans session PHP ; son contrôle d'accès est la signature HMAC.

| Point d'entrée | Méthode | Authentification | Autorisation | Contrôles |
|---|---|---|---|---|
| `front/status.php`, `front/source.php` | GET | session | `plugin_gitplugins` (lecture) | lecture seule, bornée aux entités de la session |
| `front/source.form.php` | GET, POST | session + CSRF | lecture ; mise à jour pour créer ou modifier ; purge pour retirer ; accès par ligne (entité) | modèle de la source : https, hôte dans la liste blanche, clé `[a-z0-9_]`, clé unique, plugin de la marketplace refusé, jeton chiffré, `build_on_install` ignoré |
| `front/discovered.php` | GET, POST | session + CSRF | lecture ; mise à jour pour enregistrer | clé assainie, aucun réseau à l'affichage, https exigé |
| `front/install.php` | GET, POST | session + CSRF | lecture pour voir ; mise à jour **et** accès à l'entité de la source pour examiner ou mettre en file | l'affichage ne contacte pas le réseau ; l'examen résout la référence (garde SSRF) ; la confirmation doit porter sur la référence et le SHA examinés |
| `front/plan.php`, `front/bulk.php` | GET, POST | session + CSRF | lecture ; mise à jour pour mettre en file | POST seulement pour agir, clés assainies |
| `front/rollback.php` | POST | session + CSRF | mise à jour **et** droit sur la source propriétaire de l'instantané | l'instantané doit appartenir à la même entité et au même plugin |
| `front/catalog.php` | GET, POST | session + CSRF | lecture ; mise à jour pour rafraîchir | URL du catalogue en https, hôte dans la liste blanche, garde SSRF |
| `front/targets.php` | GET, POST | session + CSRF | lecture ; mise à jour ; purge pour retirer | nom assaini, secret d'au moins 32 caractères, chiffré, écriture seule |
| `front/config.php` | GET, POST | session + CSRF | mise à jour | bornes de chaque réglage, liste blanche jamais vide |
| `ajax/detect.php` | POST | session + CSRF | mise à jour | URL normalisée, garde SSRF, jeton non journalisé |
| `ajax/check.php` | GET | session | lecture | source de l'entité visible seulement, aucun effet de bord |
| `ajax/deploy.php` | GET | **aucune session** : HMAC | cible enregistrée et active | signature HMAC-SHA256 sur méthode, chemin canonique, horodatage, cible, nonce ; fenêtre de 300 s ; nonce à usage unique (16 à 64 caractères) ; réponse signée ; échec uniforme 403 |
| Tâches `checkUpdates`, `applyUpdates`, `notifyUpdates` | cron GLPI | processus de GLPI | sans objet | réseau seulement vers la liste blanche ; installation seulement d'actions mises en file par un utilisateur autorisé |
| API REST de GLPI (`PluginGitpluginsSource`) | selon GLPI | jeton ou compte GLPI | droits GLPI | mêmes règles métier que le formulaire, dans le modèle |

## Données personnelles

| Donnée | Finalité | Base légale | Durée | Destinataires |
|---|---|---|---|---|
| Identifiant de l'utilisateur dans le journal d'audit (`users_id`), avec l'action, la référence et le SHA | savoir qui a installé ou mis à jour quoi | intérêt légitime de l'organisme (traçabilité de l'administration) ; à confirmer par le responsable de traitement | **un an**, puis purge automatique ; la table est conservée, renommée, à la désinstallation | administrateurs ayant le droit |
| Adresses de courriel des Super-Admin (ou du destinataire choisi) | envoyer le résumé des mises à jour | intérêt légitime (exploitation) | lues dans GLPI à l'envoi, non copiées | les destinataires eux-mêmes |
| Nom d'un compte de dépôt ou URL contenant un propriétaire (champ `url` d'une source) | désigner la source | intérêt légitime | tant que la source existe | administrateurs |
| Jeton de dépôt privé, secret partagé d'une cible | accéder à un dépôt, signer un tirage | sans objet (secret technique) | jusqu'au retrait ; chiffrés | personne : jamais affichés |

Le journal ne contient ni secret ni trace de pile. Aucun transfert hors de Tunisie n'est fait par le
greffon lui-même ; les hôtes git contactés (GitHub, GitLab…) reçoivent l'adresse IP du serveur et, pour
un dépôt privé, le jeton en en-tête `Authorization`. Le droit d'accès et d'effacement se traite par
GLPI (suppression de l'utilisateur) ; les lignes d'audit portent alors un identifiant orphelin
(**à vérifier** : anonymisation à la suppression d'un utilisateur).

## Menaces et mesures

| Menace (OWASP Top 10) | Mesure en place | Exigence ASVS | Test qui le prouve |
|---|---|---|---|
| A10 SSRF : forcer le serveur à joindre une adresse interne | HTTPS seul, liste blanche, adresses privées, boucle locale, lien-local, métadonnées du nuage et IPv4 dans IPv6 refusées, ni identifiants ni port inhabituel, revalidation à chaque redirection, adresse épinglée | V12.6 | `SsrfGuardTest` (`testBlockedIpsAreBlocked`, `testRejectsResolvedPrivateIp`, `testRejectsUserinfoAndOddPort`), `InstallerPinningTest` |
| A08 Intégrité : archive piégée (zip-slip, bombe de décompression) | format reconnu au contenu, chemins assainis, plafonds par entrée et au total pendant la copie, un seul dossier racine | V12.3 | `ExtractorTest` (`testRejectsTraversalAndAbsolute`, `testGzipBombRefusedBeforeTheLibraryOpensIt`, `testBudgetHoldsWhenDeclaredSizesLie`) |
| A08 : redirection d'un dossier par lien symbolique | installation, remplacement, restauration et nettoyage refusent un lien | V12.3 | `ExtractorTest` (`testPlaceAtomicallyRefusesALinkedPluginDir`, `testPlaceAtomicallyRefusesALinkedPluginsBase`) |
| A08 : tag réécrit après relecture | empreinte du commit (ou de la release) à l'installation ; téléchargement du commit, non du nom du tag ; refus si le contenu change | V10.3 | `TagFingerprintTest` (`testSameTagOtherCommitIsMoved`, `testRunRefusesAMovedTagBeforeDownloading`) |
| A08 : installer autre chose que ce qui a été relu | la confirmation porte sur la référence et le SHA examinés ; sinon « source changée » | V1.4 | `InstallerDecisionTest`, `UpdateDecisionTest` |
| A01 Contrôle d'accès : agir sur la source d'une autre entité | droit sur la ligne (entité) pour modifier, retirer, mettre en file, revenir en arrière | V4 | `SourceFormGuardTest`, `RollbackGuardTest` |
| A01 : instantané d'une autre source ou d'un autre plugin | l'instantané doit appartenir à la source et à la clé visées | V4 | `RollbackGuardTest` (`testSnapshotWhoseSourceManagesAnotherPluginDenied`) |
| A01 : droit trop large | accordé à l'installation aux seuls profils qui peuvent mettre à jour `config` ; une révocation survit à une réinstallation | V4 | `SetupHooksTest` (`testReinstallKeepsARevokedRight`) |
| A01/A04 : contournement du formulaire par l'API ou une action de masse | les règles sont dans le modèle (https, liste blanche, clé, marketplace, jeton, `build_on_install`) | V13 | `SourceModelTest` (`testApiWriteCannotBypassHostAllowlistOrHttps`, `testSourceUpdateIgnoresBuildOnInstall`) |
| A04 : deux sources pour un même plugin, copie masquée par la marketplace | clé unique toutes entités confondues ; plugin de la marketplace refusé | V1.4 | `SourceKeyUniquenessTest`, `SourceModelTest` (`testMarketplaceKeyRejected`) |
| A04 : lecture arbitraire du disque par une source locale | désactivée par défaut ; chemin dans une liste blanche, normalisé, vide = refus | V12.3 | `LocalsourceTest` |
| A02 Défaillances cryptographiques : jeton et secret en clair | chiffrés par GLPIKey, écriture seule, jamais journalisés ; déclarés pour la rotation de clé | V6 | `SourceModelTest` (`testCredentialIsEncryptedOnEveryPathAndUndisclosed`) |
| A07 Authentification : tirage de déploiement falsifié ou rejoué | HMAC mutuel, fenêtre de fraîcheur, nonce à usage unique, secret fort, 403 uniforme, route anonyme limitée à ce chemin | V2, V3 | `DeployTest`, `DeployReplayTest` (`testReplayedNonceRejected`, `testShortSecretRefused`, `testDeployRouteDeclaredNoCheckAndOnlyThatRoute`) |
| A05 Mauvaise configuration : sauvegarde exécutable sous le web | archive inerte, hors de l'arborescence web, dossier `0700`, fichier `0600` | V14 | `BackupTest` (`testFilenameIsSafeAndInert`) |
| A03 Injection d'en-têtes de courriel | adresses validées, CR/LF retirés ; expéditeur demandé au cœur | V5 | `UpdateDecisionTest` (`testBuildDigestListsPluginsAndIsCrlfSafe`, `testDigestSenderIsGlpiFromEmail`) |
| A09 Journalisation | messages génériques, champs bornés, pas de secret ; conservation d'un an | V7 | `SetupHooksTest` (`testExpiredAuditRowsArePurged`, `testUninstallKeepsAudit`) |
| A06 Composants vulnérables | aucune dépendance Composer ni npm dans le greffon (ni `composer.json`, ni `vendor/`) ; client HTTP de GLPI | V14 | revue de code |
| Chaîne d'approvisionnement : code amont malveillant dès la première version | **non couverte par une vérification technique** : confiance au premier usage, confirmation humaine, retour arrière | — | aucun (voir Écarts connus) |

Les gardes principales sont aussi couvertes par des mutations (`tests/mutations/audit-20261024.json`) :
chaque garde y est retirée du code, et un test doit échouer.

## Secrets

- **Jeton de dépôt privé** (`glpi_plugin_gitplugins_sources.credential`) et **secret partagé d'une
  cible** (`glpi_plugin_gitplugins_targets.secret`) : chiffrés par la clé de GLPI (`glpicrypt.key`),
  déclarés dans `Hooks::SECURED_FIELDS` pour que `security:change_key` les rechiffre. Écriture seule :
  l'écran n'affiche jamais la valeur. Envoyés seulement en en-tête d'authentification, jamais
  journalisés.
- **Secret d'une cible** : au moins 32 caractères.
- Aucun secret dans le code, le dépôt ni le journal. La clé de GLPI est à sauvegarder avec la base : sans
  elle, les jetons ne sont pas récupérables.

## Écarts connus

- **Aucune vérification de signature du code amont** (voir plus haut). Pistes : exiger un tag signé ou
  un fichier de somme de contrôle publié séparément, pour les sources qui le fournissent.
- **Réglage « installation automatique »** : il est enregistré et affiché, mais le code relu pour cette
  documentation n'en tient pas compte pour déclencher une installation sans confirmation (**à
  vérifier**). Ne pas compter dessus ni l'activer.
- **Anonymisation du journal** à la suppression d'un utilisateur de GLPI : à vérifier.
- **Réseau sortant** : le greffon ne filtre que par nom d'hôte et adresse résolue ; un pare-feu
  sortant côté serveur reste recommandé.
- **`SCHEMA.md`** décrit encore quatre tables sur neuf.

## Signalement

Voir [SECURITY.md](../SECURITY.md).
