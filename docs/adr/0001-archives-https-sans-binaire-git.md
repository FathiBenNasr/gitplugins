# 0001 — Télécharger des archives en HTTPS, sans binaire git

- Statut : accepté
- Date : version 0.1.0, 2026-06-21 (à vérifier ; consigné le 2026-11-15)

## Contexte

Le greffon doit récupérer le code d'un greffon depuis GitHub, GitLab, Gitea ou Forgejo. Appeler le
binaire `git` sur le serveur de GLPI ouvrirait plusieurs fronts : l'exécutable doit être présent,
`git clone` suit des sous-modules, des crochets et des protocoles que la liste blanche d'hôtes ne
contrôle pas, et un dossier `.git` se retrouverait sous `plugins/`, parfois lisible depuis le web.

## Options

1. Appeler `git clone` ou `git fetch`.
2. Télécharger l'archive (`tar.gz` ou `zip`) du fournisseur par HTTPS.
3. N'accepter que des archives de release publiées par le mainteneur.

## Décision

Option 2, avec l'option 3 comme politique de référence supplémentaire (`release`) pour les greffons
qui exigent une étape de construction. Le téléchargement passe par un client HTTP qui vérifie chaque
adresse (liste blanche, adresses privées refusées, revalidation à chaque redirection, adresse
épinglée) avant d'ouvrir la connexion.

## Conséquences

- Aucun binaire à installer ni à surveiller ; aucun `.git` dans `plugins/`.
- La surface réseau se réduit à des requêtes HTTPS vers des hôtes listés, que la garde SSRF contrôle.
- Le greffon doit lui-même ouvrir l'archive : d'où l'extraction durcie (format reconnu au contenu,
  plafonds, chemins assainis, liens symboliques refusés).
- Un hôte qui ne publie pas d'archive par commit ne peut pas servir de source (**à vérifier** pour un
  Gitea ou un Forgejo ancien).
- L'historique du dépôt n'est pas disponible : pas de vérification de signature de commit.
