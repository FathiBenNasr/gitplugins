# 0003 — Mémoriser le contenu d'un tag, sans vérifier de signature

- Statut : accepté
- Date : version 1.0.8, 2026-10-25 (consigné le 2026-11-15)

## Contexte

Un tag git n'est pas immuable : son propriétaire peut le réécrire pour qu'il pointe vers un autre
commit. Un administrateur qui a relu la version `v1.2.0` puis la voit remplacée par un contenu
différent installerait du code qu'il n'a pas relu. Vérifier une signature (GPG, Sigstore) supposerait
que les mainteneurs en publient et qu'une liste de clés de confiance soit tenue : ce n'est pas le cas
des dépôts visés.

## Options

1. Ne rien faire : faire confiance au nom du tag.
2. Relever, à l'installation, le commit du tag (et l'empreinte SHA-256 d'une archive de release),
   télécharger ce commit plutôt que le nom du tag, et refuser un tag qui pointe ensuite ailleurs.
3. Exiger une signature vérifiable pour toute installation.

## Décision

Option 2. Pour les politiques de tag et de release, le commit pointé par le tag est enregistré à
l'installation ; le téléchargement vise ce commit ; si le même tag désigne plus tard un autre contenu,
l'installation est refusée (`tag_moved`) avant tout téléchargement. Pour publier un contenu modifié,
il faut publier un nouveau tag. L'option 3 n'est pas retenue pour l'instant.

## Conséquences

- Un tag réécrit après la première installation est détecté ; un tag qu'on ne sait pas résoudre
  échoue fermé.
- C'est une **confiance au premier usage** : le contenu de la première installation d'un tag n'est
  authentifié par rien d'autre que la confirmation humaine. Cet écart est consigné dans
  [SECURITE.md](../SECURITE.md#écarts-connus).
- La politique **Track a branch** n'a pas cette protection : la pointe d'une branche change par nature.
  Les politiques épinglées sont à préférer.
- Une évolution possible : exiger un tag signé ou une somme de contrôle publiée séparément. Elle
  remplacerait cet ADR.
