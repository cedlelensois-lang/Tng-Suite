# Nuage de noms & anniversaires

Nuage des noms de famille, anniversaires du jour (naissances, mariages, décès) et dernières mises à jour. Module sans réglages propres : la page d’administration est une aide. Les vivants et les personnes privées sont masqués par défaut.

## Dans l’administration

Menu **TNG Suite > Médias & widgets**, section **Nuage de noms & anniversaires**.

![Nuage de noms & anniversaires](../images/Name%20cloud%20%26%20birthdays.png)

## Shortcodes

```
[tng_name_cloud limit="50"]
```

Nuage des noms de famille les plus fréquents.

```
[tng_births_today]
```

Naissances survenues ce jour-ci les années passées.

```
[tng_marriages_today]
```

Mariages de ce jour.

```
[tng_deaths_today]
```

Décès de ce jour.

```
[tng_recent_updates limit="10" date="yes" link="yes" title="Nouveautés"]
```

Dernières fiches modifiées.

### `[tng_name_cloud]`

| Attribut | Valeurs | Défaut | Rôle |
|---|---|---|---|
| limit | 1 – 200 | 50 | Nombre de noms. |

### `[tng_recent_updates]`

| Attribut | Valeurs | Défaut | Rôle |
|---|---|---|---|
| limit | 1 – 100 | 10 | Nombre de fiches. |
| tree | identifiant d’arbre | arbre de Général | Arbre (gedcom). |
| date | yes / no | yes | Afficher la date de modification. |
| date_format | format PHP | format WordPress | Format de la date. |
| link | yes / no | yes | Lien vers la fiche TNG. |
| title | texte | (vide) | Titre affiché au-dessus de la liste. |

## Utilisation

1. Collez le shortcode voulu dans une page, un article ou un widget « Shortcode ».

## Résultat sur le site

![Nuage de noms & anniversaires](../images/Exemple_Name%20cloud%20%26%20birthdays.png)

## Remarques

- Les personnes vivantes ou privées suivent le réglage de **Général > Modules** (masquées par défaut, recommandé pour le RGPD).
- Les listes du jour sont mises en cache jusqu’à 24 h ; les mises à jour récentes moins d’une heure.
- Les résultats sont mis en cache ; après une modification dans TNG, utilisez **Général > Maintenance > Vider les caches** pour les voir tout de suite.

---
[← Guide des modules](README.md)
