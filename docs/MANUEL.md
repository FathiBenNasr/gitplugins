# Installateur de plugins Git — manuel d'utilisation

Version déployée : **1.0.9**

## À quoi sert le greffon

L'Installateur de plugins Git suit des plugins GLPI hébergés dans des dépôts Git (GitHub, GitLab, Forgejo, Gitea…). Il vérifie régulièrement si une version plus récente existe, vous l'indique dans un écran d'état et dans un courriel de synthèse, puis installe la mise à jour quand vous la confirmez. Avant chaque mise à jour, il conserve une copie de sauvegarde du plugin pour pouvoir revenir en arrière en un clic.

> **Important :** installer un plugin exécute le code d'installation de ce plugin sur le serveur. Ne confirmez que des sources de confiance.

## Où le trouver

Menu **Configuration → Installateur de plugins Git**. Le menu n'apparaît que si votre profil possède le droit du greffon. Il propose :

| Entrée | Ce que vous y faites |
|---|---|
| **Sources** | Liste des dépôts suivis ; le bouton « + » crée une source |
| **Plugins installés** | Voir tous les plugins installés et leur état de source |
| **État** | Voir les versions installées et disponibles, lancer une mise à jour, revenir en arrière |
| **Configuration** | Régler le greffon |

Depuis **État**, trois boutons mènent à des écrans complémentaires : **Catalog** (catalogue), **Targets** (cibles de déploiement) et **Bulk update (dry-run)** (mise à jour groupée avec prévisualisation). Ces trois libellés s'affichent en anglais.

Le greffon n'ajoute aucun onglet aux fiches GLPI.

## Droits nécessaires

Le droit s'appelle **plugin_gitplugins** (à régler dans Administration → Profils). Il est attribué à l'installation aux seuls profils qui peuvent déjà modifier la configuration de GLPI.

| Niveau | Ce qu'il permet |
|---|---|
| Lecture | Voir les sources, les plugins installés, l'état, le plan de mise à jour, le catalogue et les cibles |
| Mise à jour | Créer et modifier des sources, mettre en file une installation ou mise à jour, lancer une mise à jour groupée, revenir en arrière, modifier la configuration, gérer le catalogue et les cibles |
| Suppression (purge) | Retirer une source ou une cible |

Les sources sont rattachées à une entité : vous n'agissez que sur celles de vos entités.

## Les tâches, pas à pas

### Ajouter une source

1. Ouvrez **Configuration → Installateur de plugins Git → Sources**, puis cliquez sur **Nouvelle source** (ou sur « + »).
2. Collez l'**URL du dépôt (https uniquement)** et cliquez sur **Détecter** : les champs se préremplissent à partir du fichier plugin.xml du dépôt. Si la détection échoue, remplissez les champs à la main.
3. Vérifiez le **Nom affiché** et la **Clé du plugin (nom du répertoire)** : minuscules, chiffres et tiret bas uniquement.
4. Choisissez la **Politique de référence** :
   - **Dernier tag de version** : la version la plus récente publiée ;
   - **Suivre une branche** : la pointe d'une branche ;
   - **Épingler à un tag** ou **Épingler à un SHA de commit** : une version figée ;
   - **Dernière release pré-compilée (asset .tgz)**.
5. Pour les politiques « branche », « tag » ou « SHA », renseignez la **Référence (branche / tag / SHA)**.
6. Pour un dépôt privé, saisissez le **Jeton de dépôt privé (lecture seule)**. Il est chiffré et jamais réaffiché ; laissez le champ vide pour conserver le jeton enregistré, ou cochez **Supprimer le jeton enregistré**.
7. Laissez **Actif (vérifié pour les mises à jour)** coché, puis cliquez sur **Enregistrer**.

Résultat : le message « Source créée. » s'affiche et le plugin figure dans la liste des sources et dans l'écran **État**.

Si un message d'erreur apparaît, il indique la cause : URL non https, hôte absent de la liste des hôtes autorisés, clé déjà gérée par une autre source, ou plugin « géré par le marketplace GLPI » (dans ce cas, mettez-le à jour depuis le marketplace).

### Enregistrer un plugin déjà installé

1. Ouvrez **Plugins installés**. Chaque plugin porte un état : **managed**, **declared**, **marketplace** ou **no source**.
2. Pour un plugin « declared » (le plugin annonce lui-même son dépôt), cliquez sur **Register as managed source**. Pour un plugin sans source, cliquez sur **Add source**.
3. Si le dépôt est privé, ouvrez ensuite la source (**Edit source**) et ajoutez le jeton.

Résultat : « Source enregistrée comme gérée. » ; aucun téléchargement n'a lieu à ce stade.

### Consulter l'état et repérer une mise à jour

1. Ouvrez **État**.
2. Lisez le tableau : **Plugin key**, **Installed** (version installée), **Available** (version disponible), **Pending** (action en attente), **Last check**, **Last result** et **Health** (état de santé : ok, warn, fail).
3. Une ligne jaune signale une mise à jour disponible ; un bandeau en haut en donne le nombre.
4. Des pastilles complémentaires peuvent apparaître : conflits de hooks avec d'autres plugins, ou « known issues » (problèmes connus). Passez la souris dessus pour le détail.

Résultat : vous savez quels plugins sont à jour. L'écran n'interroge pas Internet : il affiche le résultat de la dernière vérification automatique.

### Installer ou mettre à jour un plugin

1. Dans **Sources** ou **État**, cliquez sur **Installer / Mettre à jour** en face du plugin.
2. Cliquez sur **Examiner l'installation / la mise à jour** : le greffon contacte la source et affiche la référence exacte, le SHA du commit, la version installée et la version disponible, un contrôle de l'environnement (**Environment preflight** : ready ou blocked), les problèmes connus éventuels et le journal des modifications entre les deux versions.
3. Si le contrôle indique « blocked », ne continuez pas : notez les raisons affichées et contactez le support.
4. Lisez l'avertissement, puis cliquez sur **Mettre en file l'installation / mise à jour** et confirmez.

Résultat : « Mis en file. La tâche planifiée d'installation le téléchargera et l'installera lors de sa prochaine exécution. » L'installation n'est donc pas immédiate : suivez-la dans **État** (colonnes **Pending** et **Last result**).

Cas particuliers : « Déjà à jour — rien à installer. » ; « Refusé : la version disponible est plus ancienne… » (rétrogradation interdite) ; « La source a changé depuis votre relecture » (recommencez l'examen).

### Mettre à jour plusieurs plugins d'un coup

1. Dans **État**, cliquez sur **Bulk update (dry-run)**.
2. Le tableau est une prévisualisation : pour chaque plugin, l'**Action** prévue, une **Migration** probable, le **Preflight** (ready ou blocked) et le nombre de **Known issues**.
3. Cochez les lignes applicables (ou la case d'en-tête pour tout cocher), puis cliquez sur **Update selected** et confirmez.

Résultat : un message annonce le nombre de plugins mis en file (en anglais) ; la tâche automatique les applique ensuite un par un. Si rien n'est applicable, l'écran l'indique.

### Revenir à une version précédente

1. Dans **État**, repérez la ligne du plugin : une liste déroulante de sauvegardes (version et date) apparaît s'il en existe.
2. Choisissez la sauvegarde, cliquez sur **Rollback** et confirmez. Le code du plugin et ses tables sont remplacés.

Résultat : le message « Rolled back to the selected snapshot. » (affiché en anglais) confirme le retour. En cas d'échec, le plugin reste tel quel et un message d'erreur s'affiche.

### Retirer une source

1. Ouvrez la source (**Sources**, puis son nom), cliquez sur **Retirer** et confirmez.

Résultat : « Source retirée de la gestion. » Le plugin installé n'est **pas** désinstallé. Ce droit est celui de suppression (purge).

### Autres écrans (usage avancé)

- **Catalog** : liste de plugins proposés par un ou plusieurs catalogues configurés. Cliquez sur **Refresh catalog** pour la rafraîchir, puis sur **Add source** pour préremplir une nouvelle source. Cela n'installe rien.
- **Targets** (« Deploy targets ») : des instances GLPI autorisées à récupérer un manifeste de déploiement signé. Ce greffon n'installe jamais rien sur ces instances. Ajout via **Add target** (nom, adresse informative, secret partagé à saisir ; il n'est jamais réaffiché).
- **Configuration** : hôtes autorisés, tailles et délais maximum, fréquence de vérification, nombre de sauvegardes à conserver, action en cas d'échec du contrôle de santé après installation (signaler seulement ou revenir automatiquement en arrière), adresses de catalogues, et réglages du courriel de synthèse. Ces réglages relèvent de l'administrateur ; en cas de doute, contactez le support.

## Ce que reçoivent les utilisateurs

Seuls les destinataires du courriel de synthèse reçoivent quelque chose. Le courriel est envoyé via la messagerie de GLPI, au maximum une fois par jour, et **seulement si** la liste des mises à jour disponibles a changé depuis le dernier envoi. Son sujet est du type « 2 plugins gérés ont des mises à jour disponibles ». Il contient pour chaque plugin la version installée, la version disponible et l'adresse du dépôt, puis l'invitation « Consultez et appliquez les mises à jour depuis la page d'état de l'Installateur de plugins Git. »

Destinataires : l'adresse saisie dans **Destinataire du résumé (facultatif)** ; à défaut, tous les utilisateurs actifs du profil Super-Admin ; à défaut, l'adresse d'administration de GLPI. L'envoi se règle par la case **Envoyer un résumé par e-mail lorsque des mises à jour sont disponibles pour les plugins gérés** (activée par défaut). Une autre case ajoute au courriel le menu des services de Convergent Cloud Computing.

Le greffon ne crée ni ticket ni notification dans l'interface GLPI.

## Tâches automatiques

Visibles dans **Configuration → Actions automatiques**, sous « Vérification des mises à jour des plugins Git » :

| Tâche | Ce qu'elle fait | Cadence par défaut |
|---|---|---|
| Vérifie les mises à jour des sources Git gérées et exécute les installations en attente | Consulte les sources actives, met à jour l'écran **État** et exécute les installations en attente | Toutes les heures |
| Envoyer par e-mail un résumé des plugins gérés ayant une mise à jour disponible | Envoie le courriel de synthèse décrit ci-dessus | Une fois par jour |
| Apply plugin installs/updates queued from the bulk dry-run (libellé anglais) | Applique les installations et mises à jour mises en file | Toutes les 15 minutes |

Chaque installation est contrôlée et, en cas d'échec, annulée. Une installation mise en file n'avance que si ces tâches tournent : si **Pending** ne bouge pas, voir ci-dessous.

## Questions fréquentes et problèmes courants

| Symptôme | Que faire |
|---|---|
| Le menu n'apparaît pas | Votre profil n'a pas le droit du plugin. Demandez-le à un administrateur, puis reconnectez-vous. |
| « Hôte … n'est pas dans la liste des hôtes autorisés » | Contacter le support : l'hôte doit être ajouté à la configuration. |
| « Ce plugin est géré par le marketplace GLPI » | Mettez-le à jour depuis le marketplace de GLPI, pas ici. |
| « Cette clé de greffon est déjà gérée par une autre source » | Un plugin n'a qu'une source. Modifiez la source existante. |
| « Ce dépôt est déclaré privé — un jeton est requis » | Ajoutez un jeton de lecture dans la source ; sinon contacter le support. |
| Après « Mis en file », rien ne change | L'installation attend la tâche automatique (jusqu'à 15 minutes à une heure). Patientez ; si rien ne change, contacter le support. |
| Pastille « Health » en rouge (fail) | Le plugin ne passe pas son propre contrôle après installation. Contacter le support ; un retour à la version précédente est possible (voir Rollback). |
| Aucune liste de sauvegardes pour un plugin | Aucune sauvegarde n'est conservée pour lui (le nombre conservé est un réglage). Contacter le support. |
| Pas de courriel de synthèse | Il n'est envoyé que si le réglage est activé et si une nouvelle mise à jour est apparue depuis le dernier envoi. Sinon, contacter le support. |

## Support

contact@convergent.tn
