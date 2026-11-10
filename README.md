# TNG Suite

Extension WordPress qui affiche vos données [TNG](https://tngsitebuilding.com/) (The Next Generation of Genealogy Sitebuilding) : arbres, frise chronologique, heatmap des lieux, galerie, statistiques, éventail, implexe et consanguinité, livre généalogique (page imprimable, PDF, Word, OpenDocument).

Licence : GPLv2 ou ultérieure. Prérequis : WordPress 5.8+, PHP 7.4+.


WordPress plugin that displays your TNG (The Next Generation of Genealogy Sitebuilding) data: family trees, timelines, location heat maps, galleries, statistics, fan charts, pedigree collapse and inbreeding analysis, and genealogy books (printable pages, PDF, Word, and OpenDocument formats).

License: GPL v2 or later.
 Requirements: WordPress 5.8+ and PHP 7.4+.

## Installation

1. Téléchargez `tng-suite.zip` dans la dernière [Release](../../releases/latest).
2. Dans WordPress : Extensions → Ajouter → Téléverser une extension.
3. Réglez la connexion à la base TNG dans **TNG Suite → Général**.

1. Download tng-suite.zip from the latest ../../releases/latest.
2. In WordPress, go to Plugins → Add New → Upload Plugin.
3. Configure the connection to your TNG database in TNG Suite → General.

## Mises à jour automatiques Facultatif/ Option

Dans `wp-config.php`, avant la ligne « That's all, stop editing » :

```php
define( 'TNGSUITE_GITHUB_REPO', 'https://github.com/VOTRE-COMPTE/tng-suite/' );
```

WordPress affichera alors « mise à jour disponible » dès qu'une nouvelle Release est publiée (dépôt public).


In wp-config.php, before the line "That's all, stop editing!":

```php
define( 'TNGSUITE_GITHUB_REPO', 'https://github.com/VOTRE-COMPTE/tng-suite/' );
```
WordPress will then automatically display an "Update available" notification whenever a new Release is published on the public GitHub repository.


## Notes

- Le nom « TNG » désigne un logiciel tiers ; ce projet n'est pas affilié à son auteur.
- Bibliothèques tierces : voir `libs/LISEZ-MOI.txt`.

## Documentation

[Guide en français](docs/fr/README.md) · [User guide in English](docs/en/README.md)
