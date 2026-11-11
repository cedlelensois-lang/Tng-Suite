# TNG Suite

Extension WordPress qui affiche vos données [TNG](https://tngsitebuilding.com/) (The Next Generation of Genealogy Sitebuilding) : arbres, frise chronologique, heatmap des lieux, galerie, statistiques, éventail, implexe et consanguinité, livre généalogique (page imprimable, PDF, Word, OpenDocument).

Licence : GPLv2 ou ultérieure. Prérequis : WordPress 5.8+, PHP 7.4+.

## Installation

1. Téléchargez `tng-suite.zip` dans la dernière [Release](../../releases/latest).
2. Dans WordPress : Extensions → Ajouter → Téléverser une extension.
3. Réglez la connexion à la base TNG dans **TNG Suite → Général**.

## Mises à jour automatiques

Rien à configurer : le plugin interroge les Releases de ce dépôt (public) et WordPress affiche « mise à jour disponible » dès qu'une nouvelle Release est publiée.

Pour un fork ou un dépôt privé, ou pour désactiver la vérification, définissez dans `wp-config.php` (avant la ligne « That's all, stop editing ») :

```php
define( 'TNGSUITE_GITHUB_REPO', 'https://github.com/VOTRE-COMPTE/tng-suite/' );   // '' pour désactiver
```

## Publier une version

1. Mettre à jour le numéro de version dans `tng-suite.php` (en-tête et `TNGSUITE_VERSION`) et dans `readme.txt` (`Stable tag`).
2. Valider puis poser l'étiquette :
   ```
   git tag v1.29.2
   git push origin main --tags
   ```
3. L'action GitHub construit le zip et crée la Release.

## Notes

- Le nom « TNG » désigne un logiciel tiers ; ce projet n'est pas affilié à son auteur.
- Bibliothèques tierces : voir `libs/LISEZ-MOI.txt`.
