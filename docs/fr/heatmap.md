# Heatmap des lieux

Carte de chaleur des lieux de naissance, baptême, mariage, décès… de l’arbre, avec filtres par événement, siècle, nom de famille et ville. Leaflet est chargé depuis un CDN (sauf copie locale) ; les lieux doivent avoir des coordonnées dans TNG.

## Dans l’administration

Menu **TNG Suite > Analyses**, section **Heatmap des lieux**.

![Heatmap des lieux](../images/heatmap.png)

## Réglages du module

| Réglage | Effet |
|---|---|
| Cache des données | Affiche l’état du cache (lignes agrégées), la dernière erreur éventuelle et un bouton pour vider le cache. Les données sont régénérées automatiquement deux fois par jour. |
| URL recherche TNG | Adresse de la page de recherche de lieux de TNG (ex. `https://exemple.fr/tng/placesearch.php`), utilisée par les liens des infobulles. |
| Personnes vivantes / privées | Affichage en lecture seule du réglage commun (Général > Modules). |
| Fonctionnalités avancées | Activer ou non : filtre par siècle, filtre par nom de famille, recherche par ville, couleurs distinctes par événement. |
| Fond de carte par défaut | 8 fonds gratuits, sans clé d’API : Esri Gris clair (défaut), OpenStreetMap, OpenStreetMap France, OpenTopoMap, Esri Rues / Topographique / Satellite / Océans. |
| Afficher les chiffres au centre | Nombre d’événements dans chaque rond, à partir du zoom régional et sans chevauchement. |
| Style par défaut des ronds | Ronds opaques, couleur et opacité de remplissage (utilisés si « couleurs distinctes » est désactivé). |
| Couleurs de la chaleur | Intensité faible, moyenne, forte ; bouton **Revenir à la palette recommandée**. |

## Shortcodes

```
[tng_heatmap]
```

Affiche la carte. Aucun attribut : tout se règle dans la section du module (un seul par page est recommandé).

## Utilisation

1. Vérifiez l’URL de recherche et choisissez le fond de carte, les filtres et les couleurs.
2. Collez `[tng_heatmap]` dans une page.

## Résultat sur le site

![Heatmap des lieux](../images/Exemple%20heatmap.png)

## Remarques

- Les personnes vivantes ou privées suivent le réglage de **Général > Modules** (masquées par défaut, recommandé pour le RGPD).
- Fond de carte : Esri Gris clair est le plus sobre pour lire les couleurs ; Esri Rues et OpenStreetMap France sont les plus lisibles pour les noms de lieux. Le fournisseur de tuiles reçoit l’adresse IP du visiteur.
- Couleurs : par défaut orange clair (faible), orange foncé (moyenne), rouge sombre (forte). L’échelle est logarithmique : seul le plus gros foyer de la sélection affichée atteint la couleur forte.
- Chiffres au centre : visibles à partir de l’échelle d’une région (zoom 8) ; un chiffre qui en recouvrirait un autre est omis jusqu’au zoom suivant. Le nombre reste visible dans l’infobulle.
- La carte n’est pas générée dans les aperçus de l’éditeur (requêtes REST).

---
[← Guide des modules](README.md)
