# TNG Suite – Guide des modules

Ce guide présente chaque module : où le régler, comment l’utiliser et à quoi il ressemble. Anglais : [English guide](../en/README.md).

![Menu TNG Suite](../images/menu-fr.png)

Le menu **TNG Suite** compte cinq entrées : **Général** et quatre familles. Chaque ancienne page d’administration est une section de sa famille (barre de sections en haut de la page) :

| Entrée du menu | Contient |
|---|---|
| Général | connexion, réglages communs, modules, maintenance |
| Arbres & visualisations | Arbres & descendance, Bouton vers la fiche, Frise chronologique, Éventail / roue de parenté |
| Analyses | Statistiques & graphiques, Heatmap des lieux, Implexe & consanguinité, Cohérence des données |
| Documents | Livre généalogique, Fiche & listes de documents |
| Médias & widgets | Galerie de médias, Nuage de noms & anniversaires, Individu au hasard |

« Bouton vers la fiche » est activé avec « Arbres & descendance », et « Individu au hasard » avec « Nuage de noms & anniversaires » (une seule case d’activation et un seul réglage « Vivants / privés » pour chaque couple) ; leurs shortcodes sont inchangés.

- [Général](general.md)
- **Arbres & visualisations**
  - [Arbres & descendance](trees.md)
  - [Bouton vers la fiche](link.md)
  - [Frise chronologique](timeline.md)
  - [Éventail / roue de parenté](fan.md)
- **Analyses**
  - [Statistiques & graphiques](stats.md)
  - [Heatmap des lieux](heatmap.md)
  - [Implexe & consanguinité](implexe.md)
  - [Cohérence des données](coherence.md) (administrateurs)
- **Documents**
  - [Livre généalogique](book.md)
  - [Fiche & listes de documents](fiche.md)
- **Médias & widgets**
  - [Galerie de médias](gallery.md)
  - [Nuage de noms & anniversaires](extra.md)
  - [Individu au hasard](random.md)

## Référence rapide des shortcodes

| Module | Shortcodes |
|---|---|
| [Arbres & descendance](trees.md) | `[arbre_tng]` `[enfants_tng]` `[patronyme_tng]` `[liste_enfants_tng]` `[stats_descendants_tng]` `[lien_fiche_tng]` |
| [Bouton vers la fiche](link.md) | `[tng_link]` |
| [Frise chronologique](timeline.md) | `[tng_timeline]` |
| [Éventail / roue de parenté](fan.md) | `[tng_fan]` `[tng_roue]` |
| [Statistiques & graphiques](stats.md) | `[tng_stat stat="…"]` et `[tng_stat_centenaires]` `[tng_stat_genre]` `[tng_stat_vivants]` `[tng_stat_age_union]` `[tng_stat_esperance]` `[tng_stat_pyramide]` `[tng_stat_distance]` `[tng_stat_demi_siecle]` `[tng_stat_mois]` `[tng_stat_villes]` `[tng_stat_metiers]` |
| [Heatmap des lieux](heatmap.md) | `[tng_heatmap]` |
| [Implexe & consanguinité](implexe.md) | `[tng_implexe]` `[tng_consanguinite]` |
| [Cohérence des données](coherence.md) | aucun (page d’administration réservée aux administrateurs) |
| [Livre généalogique](book.md) | `[tng_book]` |
| [Fiche & listes de documents](fiche.md) | `[tng_listes]` `[tng_fiche]` |
| [Galerie de médias](gallery.md) | `[tng_media_gallery]` |
| [Nuage de noms & anniversaires](extra.md) | `[tng_name_cloud]` `[tng_births_today]` `[tng_marriages_today]` `[tng_deaths_today]` `[tng_recent_updates]` |
| [Individu au hasard](random.md) | `[tng_random_individual]` |

## Confidentialité et sécurité en bref

- Par défaut, les **personnes vivantes et privées sont masquées** dans tous les modules (réglage commun dans Général, modifiable module par module). « Individu au hasard » et les anniversaires restent masqués par défaut.
- Le plugin **lit** votre base TNG : utilisez un compte MySQL en lecture seule. Le mot de passe saisi dans le plugin est chiffré ; avec `wp-config.php`, rien n’est stocké.
- Aucun cookie, aucune statistique de visite, aucune donnée envoyée à l’auteur. Les graphiques, réseaux et cartes peuvent charger des bibliothèques depuis un CDN public (copie locale possible dans `libs/`) et des tuiles de carte auprès du fournisseur choisi ; le site interroge GitHub pour les mises à jour (désactivable). Le détail figure dans `readme.txt` (« External services ») et dans le texte de confidentialité suggéré par WordPress.
- Les actions d’administration sont protégées (droits `manage_options`, jetons de sécurité) ; les téléchargements du livre automatique sont protégés ; le contrôle de cohérence des données est réservé aux administrateurs et lit la base TNG sans jamais y écrire (ses résultats, qui peuvent citer des personnes vivantes, sont stockés dans la base WordPress) ; la désinstallation supprime les réglages, caches et tâches du plugin, jamais vos données TNG.

---
[Haut de page](#tng-suite--guide-des-modules)
