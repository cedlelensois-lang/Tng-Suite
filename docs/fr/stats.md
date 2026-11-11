# Statistiques & graphiques

Graphiques publics (sexe, vivants, demi-siècles, mois…). Deux sections : Réglages et Graphiques (titre, libellés, couleurs de chaque graphique).

## Dans l’administration

![Statistiques & graphiques](../images/stats_chart.png)

## Shortcodes

```
[tng_stat_genre]
```

```
[tng_stat_vivants]
```

```
[tng_stat_demi_siecle]
```

```
[tng_stat_mois]
```

```
[tng_stat_esperance basis="birth" min_age="0" min_n="5"]
```

```
[tng_stat_pyramide step="10"]
[tng_stat_pyramide year="1850"]
```

Espérance de vie : âge moyen au décès par siècle de naissance (`basis="death"` pour le siècle du décès), hommes, femmes et ensemble. `min_age="5"` écarte la mortalité infantile. Pyramide des âges : sans `year`, âges au décès ; avec `year`, âges des personnes vivantes cette année-là.


```
[tng_stat type="…" height="380"]
```

## Utilisation

1. Section Réglages : options générales des statistiques.
2. Section Graphiques : renommez titres, libellés et couleurs de chaque graphique.
3. Collez le shortcode du graphique voulu dans une page.

## Résultat sur le site

![Statistiques & graphiques](../images/Exemple%20stats_chart.png)

## Remarques

- Seuls des totaux sont publiés.

---
[← Guide des modules](README.md)
