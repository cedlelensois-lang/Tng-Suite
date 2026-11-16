# Nuage de noms & anniversaires

Nuage des noms de famille et anniversaires du jour (naissances, mariages, décès). Module sans réglages : la page d’administration est une aide.

## Dans l’administration

Menu **TNG Suite > Médias & widgets > Nuage de noms & anniversaires**.


![Nuage de noms & anniversaires](../images/Name%20cloud%20%26%20birthdays.png)

## Shortcodes

```
[tng_name_cloud limit="50"]
```

```
[tng_births_today]
```

```
[tng_marriages_today]
```

```
[tng_deaths_today]
```

```
[tng_recent_updates limit="10"]
```

Derniers individus mis à jour dans TNG (nom en gras, date de dernière modification à droite), le plus récent en premier. Paramètres : `limit` (1 à 100, défaut 10), `tree` (arbre TNG, par défaut celui des réglages communs), `date` (`yes`/`no`), `date_format` (codes PHP, par exemple `d/m/Y` ou `j F Y`), `link` (`yes`/`no`, lien vers la fiche TNG) et `title` (titre au-dessus de la liste). Les personnes vivantes ou privées sont exclues par défaut ; la liste est mise en cache une heure.

## Utilisation

1. Collez le shortcode voulu dans une page ou un widget.

## Résultat sur le site

![Nuage de noms & anniversaires](../images/Exemple_Name%20cloud%20%26%20birthdays.png)

---
[← Guide des modules](README.md)
