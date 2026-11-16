# Éventail / roue de parenté

Roue d’ascendants, de descendants ou mixte, interactive (zoom, déplacement, export PDF). La page comporte deux sections : **Générer une roue** (aperçu + shortcode à copier) et **Réglages** (valeurs par défaut).

## Dans l’administration

Menu **TNG Suite > Arbres & visualisations**, sections **Éventail : générer une roue** et **Éventail : réglages**.

![Éventail / roue de parenté](../images/fan.png)

## Réglages du module

| Réglage | Effet |
|---|---|
| Personne de départ par défaut | Identifiant TNG utilisé quand `person` n’est pas indiqué. |
| Type / Générations | Ascendants, descendants ou mixte ; nombre de générations (ascendants jusqu’à 12, descendants jusqu’au plafond réglé). |
| Forme / Hauteur | Cercle complet, trois quarts ou demi-cercle ; hauteur en pixels. |
| Coloration / Style / Palette | Par génération, par branche ou par sexe ; teinte légère ou plein ; palette (Arc-en-ciel, Classique, Pastel, Vive, Daltonien, Camaïeu de bleus, Terre, Gris) ou palette personnalisée. |
| Contenu : dates, lieux, prénoms, style des textes, conjoints, zoom, ancêtres inconnus | Années / dates complètes / aucune ; lieux (aucun, commune, complet) ; premier prénom ou tous ; symboles (° †) ou lettres (N : D : M :) ; affichage des conjoints ; texte qui s’enrichit au zoom ; cases vides pour les ancêtres inconnus. |
| Qui peut voir le graphique | Tout le monde, utilisateurs connectés ou administrateurs. |
| Cache (minutes) | Durée de mise en cache des données de la roue (30 par défaut). |

## Shortcodes

```
[tng_fan person="I1" asc="6"]
```

Roue d’ascendants sur 6 générations.

```
[tng_fan person="I1" mode="mixed" asc="4" desc="3" shape="half"]
```

Roue mixte en demi-cercle.

```
[tng_roue person="I1"]
```

Alias français de `[tng_fan]`.

### Attributs

| Attribut | Valeurs | Défaut | Rôle |
|---|---|---|---|
| person | I1 | {'fr': 'réglage', 'en': 'setting'} | Personne de départ (obligatoire si aucune valeur par défaut). |
| tree | {'fr': 'identifiant d’arbre', 'en': 'tree ID'} | {'fr': 'Général', 'en': 'General'} | Arbre (gedcom). |
| mode | ancestors, descendants, mixed | {'fr': 'réglage', 'en': 'setting'} | Type de roue. |
| asc / desc | {'fr': '1–12 / 1–plafond', 'en': '1–12 / 1–cap'} | {'fr': 'réglage', 'en': 'setting'} | Générations d’ascendants / de descendants. |
| shape | full, three, half | {'fr': 'réglage', 'en': 'setting'} | Cercle complet, trois quarts, demi-cercle. |
| color | generation, branch, sex | {'fr': 'réglage', 'en': 'setting'} | Mode de coloration. |
| style | tint, solid | {'fr': 'réglage', 'en': 'setting'} | Teinte légère ou couleur pleine. |
| palette | {'fr': 'id de palette ou `custom`', 'en': 'palette id or `custom`'} | {'fr': 'réglage', 'en': 'setting'} | Palette de couleurs. |
| colors | {'fr': '`#aabbcc,#112233…`', 'en': '`#aabbcc,#112233…`'} | {'fr': '(vide)', 'en': '(empty)'} | Couleurs imposées (jusqu’à 12), prioritaires sur la palette. |
| dates | years, full, none | {'fr': 'réglage', 'en': 'setting'} | Format des dates. |
| places | none, commune, full | {'fr': 'réglage', 'en': 'setting'} | Lieux affichés. |
| spouses | 1 / 0 | {'fr': 'réglage', 'en': 'setting'} | Afficher les conjoints. |
| living | default, hide, show | default | `hide` masque les vivants ; `show` les affiche seulement si Général l’autorise. |
| zoomdetail | 1 / 0 | {'fr': 'réglage', 'en': 'setting'} | Texte enrichi au zoom. |
| labels | symbols, letters | {'fr': 'réglage', 'en': 'setting'} | Symboles (° †) ou lettres. |
| pdf | 1 / 0 | 1 | Bouton d’export PDF. |
| controls | 1 / 0 | 1 | Boutons de zoom / navigation. |
| height | {'fr': 'pixels', 'en': 'pixels'} | {'fr': 'réglage', 'en': 'setting'} | Hauteur de la roue. |
| class | {'fr': 'classe CSS', 'en': 'CSS class'} | {'fr': '(vide)', 'en': '(empty)'} | Classe CSS ajoutée au conteneur. |

## Utilisation

1. Section « Générer une roue » : choisissez la personne, le type, les générations, les couleurs et le contenu, puis cliquez sur **Générer la roue**.
2. Vérifiez l’aperçu, puis copiez le shortcode affiché sous le bouton.
3. Collez-le dans une page. Les valeurs par défaut se règlent dans la section Réglages.

## Remarques

- `living="show"` n’a aucun effet si « Toujours masquer » est choisi dans **Général**.
- Si l’accès est limité (connectés / administrateurs), les autres visiteurs voient un message à la place du graphique.
- Les résultats sont mis en cache ; après une modification dans TNG, utilisez **Général > Maintenance > Vider les caches** pour les voir tout de suite.

---
[← Guide des modules](README.md)
