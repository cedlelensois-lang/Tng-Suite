# TNG Suite – Module guide

This guide presents each module: where to set it up, how to use it and what it looks like. French: [Guide français](../fr/README.md).

![TNG Suite menu](../images/menu-en.png)

The **TNG Suite** menu has five entries: **General** and four families. Each former admin page is a section of its family (section bar at the top of the page):

| Menu entry | Contains |
|---|---|
| General | connection, shared settings, modules, maintenance |
| Trees & visualisations | Trees & descendants, Record link button, Timeline, Fan / kinship wheel |
| Stats & analyses | Statistics & charts, Places heatmap, Implex & inbreeding, Data consistency |
| Documents | Family book, Individual sheet & document lists, Surname flash list |
| Media & widgets | Media gallery, Name cloud & birthdays, Random individual |

“Record link button” is switched on with “Trees & descendants”, and “Random individual” with “Name cloud & birthdays” (one activation box and one “Living / private” setting for each pair); their shortcodes are unchanged.

- [General](general.md)
- **Trees & visualisations**
  - [Trees & descendants](trees.md)
  - [Record link button](link.md)
  - [Timeline](timeline.md)
  - [Fan / kinship wheel](fan.md)
- **Stats & analyses**
  - [Statistics & charts](stats.md)
  - [Places heatmap](heatmap.md)
  - [Implex & inbreeding](implexe.md)
  - [Data consistency](coherence.md) (administrators)
- **Documents**
  - [Family book](book.md)
  - [Individual sheet & document lists](fiche.md)
  - [Surname flash list](flash.md)
- **Media & widgets**
  - [Media gallery](gallery.md)
  - [Name cloud & birthdays](extra.md)
  - [Random individual](random.md)

## Shortcodes quick reference

| Module | Shortcodes |
|---|---|
| [Trees & descendants](trees.md) | `[arbre_tng]` `[enfants_tng]` `[patronyme_tng]` `[liste_enfants_tng]` `[stats_descendants_tng]` `[lien_fiche_tng]` |
| [Record link button](link.md) | `[tng_link]` |
| [Timeline](timeline.md) | `[tng_timeline]` |
| [Fan / kinship wheel](fan.md) | `[tng_fan]` `[tng_roue]` |
| [Statistics & charts](stats.md) | `[tng_stat stat="…"]` and `[tng_stat_centenaires]` `[tng_stat_genre]` `[tng_stat_vivants]` `[tng_stat_age_union]` `[tng_stat_esperance]` `[tng_stat_pyramide]` `[tng_stat_distance]` `[tng_stat_demi_siecle]` `[tng_stat_mois]` `[tng_stat_villes]` `[tng_stat_metiers]` |
| [Places heatmap](heatmap.md) | `[tng_heatmap]` |
| [Implex & inbreeding](implexe.md) | `[tng_implexe]` `[tng_consanguinite]` |
| [Surname flash list](flash.md) | `[tng_flash]` |
| [Data consistency](coherence.md) | none (administration page reserved for administrators) |
| [Family book](book.md) | `[tng_book]` |
| [Individual sheet & document lists](fiche.md) | `[tng_listes]` `[tng_fiche]` |
| [Media gallery](gallery.md) | `[tng_media_gallery]` |
| [Name cloud & birthdays](extra.md) | `[tng_name_cloud]` `[tng_births_today]` `[tng_marriages_today]` `[tng_deaths_today]` `[tng_recent_updates]` |
| [Random individual](random.md) | `[tng_random_individual]` |

## Privacy and security at a glance

- By default, **living and private people are hidden** in every module (shared setting in General, adjustable per module). “Random individual” and the birthdays stay hidden by default.
- The plugin **reads** your TNG database: use a read-only MySQL account. A password entered in the plugin is encrypted; with `wp-config.php`, nothing is stored.
- No cookie, no visit statistics, no data sent to the author. Charts, networks and maps may load libraries from a public CDN (local copy possible in `libs/`) and map tiles from the chosen provider; the site queries GitHub for updates (can be disabled). Details are in `readme.txt` (“External services”) and in the suggested privacy text in WordPress.
- Admin actions are protected (`manage_options` capability, nonces); automatic-book downloads are protected; the data consistency check is reserved for administrators and reads the TNG database without ever writing to it (its results, which may name living people, are stored in the WordPress database); uninstalling removes the plugin’s settings, caches and tasks, never your TNG data.

---
[Back to top](#tng-suite--module-guide)
