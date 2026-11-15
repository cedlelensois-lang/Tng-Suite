# Places heatmap

Heat map of birth, marriage, death… places in the tree.

## In the admin

![Places heatmap](../images/heatmap.png)

## Shortcodes

```
[tng_heatmap]
```

## How to use

1. Choose the events taken into account and the map style on the module page.
2. Paste the shortcode into a page.

## Result on the site

![Places heatmap](../images/Exemple%20heatmap.png)

## Notes

- Base map: 8 choices, all free with no API key or sign-up (OpenStreetMap, OpenStreetMap France, OpenTopoMap, Esri). Esri Light Gray is the most understated for reading the heatmap colours; Esri Streets and OpenStreetMap France are the most legible for place names.
- Heat colours: light orange (low), dark orange (medium) and dark red (high) by default, editable in the module settings; the **Restore the recommended palette** button brings them back. The scale is automatic (logarithmic): places with few events stay clearly visible, and only the biggest hotspot of the displayed selection (filters included) reaches the high colour.
- Numbers in the middle of the circles (“Show numbers in the centre” option): they only appear when zoomed in, from county scale, so they stay readable; the number is always visible in the tooltip on hover.
- Places need coordinates in TNG.

---
[← Module guide](README.md)
