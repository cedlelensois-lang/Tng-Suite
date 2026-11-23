# Fan / kinship wheel

Ancestors, descendants or mixed wheel, interactive (zoom, pan, PDF or PNG export). The page has two sections: **Generate a wheel** (preview + shortcode to copy) and **Settings** (default values).

## In the admin

Menu **TNG Suite > Trees & visualisations**, sections **Fan chart: generate a wheel** and **Fan chart: settings**.

![Fan / kinship wheel](../images/fan.png)

## Module settings

| Setting | Effect |
|---|---|
| Default starting person | TNG ID used when `person` is not given. |
| Type / Generations | Ancestors, descendants or mixed; number of generations (ancestors up to 12, descendants up to the configured cap). |
| Shape / Height | Full circle, three quarters or half circle; height in pixels. |
| Colouring / Style / Palette | By generation, branch or sex; light tint or solid; palette (Rainbow, Classic, Pastel, Vivid, Colour-blind, Blues, Earth, Greys, plus 10 genealogy palettes: Sepia, Heritage, Autumn, Sunset, Coast, Spring, Shades of green / red / purple, Blue and pink — the last is made for colouring “by sex”: its first two colours are used for men and women; the shades suit “by generation”) or custom palette. |
| Content: dates, places, first names, text style, spouses, zoom, unknown ancestors | Years / full dates / none; places (none, town, full); first name or all; symbols (° †) or letters (B: D: M:); spouses; text that gets richer when zooming; empty slots for unknown ancestors. |
| Who can see the chart | Everyone, logged-in users or administrators. |
| Cache (minutes) | How long wheel data is cached (30 by default). |

## Shortcodes

```
[tng_fan person="I1" asc="6"]
```

Ancestors wheel over 6 generations.

```
[tng_fan person="I1" mode="mixed" asc="4" desc="3" shape="half"]
```

Mixed half-circle wheel.

```
[tng_roue person="I1"]
```

French alias of `[tng_fan]`.

### Attributes

| Attribute | Values | Default | Role |
|---|---|---|---|
| person | I1 | setting | Starting person (required if there is no default). |
| tree | tree ID | General | Tree (gedcom). |
| mode | ancestors, descendants, mixed | setting | Wheel type. |
| asc / desc | 1–12 / 1–cap | setting | Ancestor / descendant generations. |
| shape | full, three, half | setting | Full circle, three quarters, half circle. |
| color | generation, branch, sex | setting | Colouring mode. |
| style | tint, solid | setting | Light tint or solid colour. |
| palette | palette id or `custom` | setting | Colour palette. |
| colors | `#aabbcc,#112233…` | (empty) | Forced colours (up to 12), take priority over the palette. |
| dates | years, full, none | setting | Date format. |
| places | none, commune, full | setting | Places displayed. |
| spouses | 1 / 0 | setting | Show spouses. |
| living | default, hide, show | default | `hide` hides living people; `show` displays them only if General allows it. |
| zoomdetail | 1 / 0 | setting | Richer text when zooming. |
| nicknames | 1 / 0 | setting | Shows the TNG nickname in brackets after the first name (“Jean (Titi)”); ignored if it is just one of the first names. Also in the panel (“Nicknames” tick-box) and in the PDF/PNG export. |
| labels | symbols, letters | setting | Symbols (° †) or letters. |
| pdf | 1 / 0 | 1 | Export button (PDF or PNG). |
| controls | 1 / 0 | 1 | Zoom / navigation buttons. |
| height | pixels | setting | Wheel height. |
| class | CSS class | (empty) | CSS class added to the container. |

## Usage

1. Section “Generate a wheel”: choose the person, type, generations, colours and content, then click **Generate the wheel**.
2. Check the preview, then copy the shortcode shown under the button.
3. Paste it in a page. Default values are set in the Settings section.

## Notes

- Export: the **Export as PDF or PNG** button opens a dialog with the **output format** (vector PDF or PNG), the sheet size (fit to text, **maximum size**, automatic, A4 to A0), the orientation and the content (**complete** by default: full dates and places; or as in the chart settings). Each box's text is scaled down to fit, down to 0.4 pt: in the narrow outer generations it is complete but tiny, to be read by zooming in the **PDF** (vector, unlimited zoom). The dialog shows how many boxes have readable text (4 pt or more). A PNG is limited in pixels and cannot show that detail: prefer the PDF for a large wheel. **PNG** is an image: pick the resolution (150, 200 — recommended — or 300 dpi) and a white or **transparent** background (tick-box). If the requested size exceeds the browser’s image limit, the resolution is reduced automatically and a message says so. The PNG is built in the browser: nothing is sent to the server.
- `living="show"` has no effect if “Always hide” is chosen in **General**.
- If access is restricted (logged-in / administrators), other visitors see a message instead of the chart.
- Results are cached; after a change in TNG, use **General > Maintenance > Flush caches** to see it immediately.

---
[← Modules guide](README.md)
