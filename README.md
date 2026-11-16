# TNG Suite

**Français** · [English below](#english)

Extension WordPress qui affiche vos données [TNG](https://tngsitebuilding.com/) (The Next Generation of Genealogy Sitebuilding) : arbres et descendance, frise chronologique, carte de chaleur des lieux, galerie de médias, statistiques, éventail, implexe et consanguinité, individu au hasard, nuage de noms, et un **livre généalogique** (page imprimable, PDF, Word, OpenDocument).

Licence : GPLv2 ou ultérieure. Prérequis : WordPress 5.8+, PHP 7.4+. Interface et textes publics en français et en anglais.

## Présentation et documentation

- **[Guide des modules en français](docs/fr/README.md)** : une page par module, avec ses réglages et des captures d'écran
- **[Guide d'utilisation complet (texte)](LISEZ-MOI.txt)** : installation, réglages communs, vivants / privés, modèles du livre…
- [Journal des changements](readme.txt) (rubrique « Changelog »)

| Famille (menu **TNG Suite**) | Modules |
|---|---|
| Arbres & visualisations | [Arbres & descendance](docs/fr/trees.md), [Bouton vers la fiche](docs/fr/link.md), [Frise chronologique](docs/fr/timeline.md), [Éventail / roue de parenté](docs/fr/fan.md) |
| Analyses | [Statistiques & graphiques](docs/fr/stats.md), [Heatmap des lieux](docs/fr/heatmap.md), [Implexe & consanguinité](docs/fr/implexe.md) |
| Documents | [Livre généalogique](docs/fr/book.md), [Fiche & listes de documents](docs/fr/fiche.md) |
| Médias & widgets | [Galerie de médias](docs/fr/gallery.md), [Nuage de noms & anniversaires](docs/fr/extra.md), [Individu au hasard](docs/fr/random.md) |

Les réglages communs (connexion à la base TNG, préfixe, adresse de TNG, vivants / privés, activation des modules) sont dans [Général](docs/fr/general.md).

![Menu TNG Suite](docs/images/menu-fr.png)

## Installation

1. Téléchargez `tng-suite.zip` dans la dernière [Release](../../releases/latest) (et non « Source code (zip) » : le zip de la Release contient le PDF direct et les bibliothèques).
2. Dans WordPress : Extensions → Ajouter → Téléverser une extension.
3. Réglez la connexion à la base TNG dans **TNG Suite → Général**.

## Mises à jour automatiques

Elles fonctionnent d'office : WordPress affiche « mise à jour disponible » dès qu'une nouvelle Release est publiée sur ce dépôt (public). Rien à ajouter dans `wp-config.php`.

Le réglage ci-dessous ne sert que dans trois cas : suivre **un fork**, utiliser **un dépôt privé**, ou **désactiver** la vérification. Dans `wp-config.php`, avant la ligne « That's all, stop editing » :

```php
define( 'TNGSUITE_GITHUB_REPO', 'https://github.com/VOTRE-COMPTE/Tng-Suite/' );   // un fork
define( 'TNGSUITE_GITHUB_REPO', '' );                                             // désactiver
```

## Publier une version (mainteneur)

1. Mettre à jour le numéro de version dans `tng-suite.php` (en-tête et `TNGSUITE_VERSION`) et dans `readme.txt` (`Stable tag`, changelog).
2. Pousser sur `main`, puis créer une **Release** sur GitHub avec l'étiquette `vX.Y.Z` (par exemple `v2.9.1`), sans « pre-release » pour une version stable.
3. L'action GitHub (`.github/workflows/release.yml`) construit `tng-suite.zip` (avec mPDF et les bibliothèques JavaScript) et l'ajoute à la Release.

## Notes

- Le nom « TNG » désigne un logiciel tiers ; ce projet n'est pas affilié à son auteur.
- Bibliothèques tierces : voir `libs/LISEZ-MOI.txt`.

---

## English

WordPress plugin that displays your [TNG](https://tngsitebuilding.com/) (The Next Generation of Genealogy Sitebuilding) data: trees and descendants, timeline, places heatmap, media gallery, statistics, fan chart, pedigree collapse (implex) and inbreeding coefficient, random individual, name cloud, and a **genealogy book** (printable page, PDF, Word, OpenDocument).

License: GPLv2 or later. Requirements: WordPress 5.8+, PHP 7.4+. Admin screens and public texts in French and English.

### Overview and documentation

- **[Module guide in English](docs/en/README.md)**: one page per module, with its settings and screenshots
- **[Full user guide (text)](USER-GUIDE.txt)**: installation, shared settings, living / private people, book templates…
- [Changelog](readme.txt) (“Changelog” section)

| Family (**TNG Suite** menu) | Modules |
|---|---|
| Trees & visualisations | [Trees & descendants](docs/en/trees.md), [Record link button](docs/en/link.md), [Timeline](docs/en/timeline.md), [Fan / kinship wheel](docs/en/fan.md) |
| Analyses | [Statistics & charts](docs/en/stats.md), [Places heatmap](docs/en/heatmap.md), [Implex & inbreeding](docs/en/implexe.md) |
| Documents | [Family book](docs/en/book.md), [Individual sheet & document lists](docs/en/fiche.md) |
| Media & widgets | [Media gallery](docs/en/gallery.md), [Name cloud & birthdays](docs/en/extra.md), [Random individual](docs/en/random.md) |

Shared settings (TNG database connection, table prefix, TNG URL, living / private people, module activation) are in [General](docs/en/general.md).

![TNG Suite menu](docs/images/menu-en.png)

### Installation

1. Download `tng-suite.zip` from the latest [Release](../../releases/latest) (not “Source code (zip)”: the Release zip includes direct PDF output and the libraries).
2. In WordPress: Plugins → Add New → Upload Plugin.
3. Set the TNG database connection in **TNG Suite → General**.

### Automatic updates

They work out of the box: WordPress shows “update available” as soon as a new Release is published on this (public) repository. Nothing to add to `wp-config.php`.

The setting below is only needed to follow **a fork**, use **a private repository**, or **turn the check off**:

```php
define( 'TNGSUITE_GITHUB_REPO', 'https://github.com/YOUR-ACCOUNT/Tng-Suite/' );   // a fork
define( 'TNGSUITE_GITHUB_REPO', '' );                                             // turn off
```

### Notes

- “TNG” is the name of third-party software; this project is not affiliated with its author.
- Third-party libraries: see `libs/LISEZ-MOI.txt`.
