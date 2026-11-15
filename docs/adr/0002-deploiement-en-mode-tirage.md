# 0002 — Déployer vers d'autres instances en mode tirage

- Statut : accepté
- Date : version 1.0.0, 2026-07-05 (durci en 1.0.4 ; consigné le 2026-11-15)

## Contexte

Une organisation peut gérer plusieurs instances GLPI et vouloir y installer les mêmes greffons aux
mêmes versions. Un point d'entrée qui **reçoit** l'ordre d'installer et l'exécute serait une
exécution de code à distance par construction : quiconque contourne son authentification installe ce
qu'il veut.

## Options

1. Pousser : l'instance d'origine appelle un point d'entrée de chaque cible, qui installe.
2. Tirer : chaque cible vient chercher, auprès de l'instance d'origine, un manifeste en lecture
   seule, et installe par **son propre** pipeline vérifié.
3. Ne rien offrir et laisser chaque instance déclarer les mêmes sources.

## Décision

Option 2. `ajax/deploy.php` ne sert que des données : la liste des greffons et le SHA exact à installer,
signée par HMAC avec le secret partagé de la cible. Le point d'entrée est anonyme (la cible n'a pas de
session) et protégé par la signature de la requête, une fenêtre de fraîcheur de 300 secondes et un
nonce à usage unique. Il n'exécute jamais de code fourni par l'appelant.

## Conséquences

- Aucun point d'entrée n'exécute de code reçu ; compromettre l'instance d'origine ne donne pas
  l'exécution sur les cibles sans passer par leur confirmation et leurs contrôles.
- Un secret partagé de 32 caractères au moins par cible, chiffré par GLPIKey, écriture seule.
- Une route anonyme à surveiller : un test garantit qu'elle est déclarée pour ce seul chemin
  (`DeployReplayTest::testDeployRouteDeclaredNoCheckAndOnlyThatRoute`).
- Le côté cible (client qui tire le manifeste) n'est **pas** décrit ici : à vérifier dans le code.
