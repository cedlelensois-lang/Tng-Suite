# Record link button

A button (emoji + text) linking to a person’s record in TNG. Enabled together with the Trees & descendants module.

## In the admin

Menu **TNG Suite > Trees & visualisations**, section **Record link button**.

![Record link button](../images/record%20link.png)

## Module settings

| Setting | Effect |
|---|---|
| Emoji | Symbol displayed before the text. |
| Button text | Default label (can be changed per button with `text`). |
| Background / text colour | Normal appearance of the button. |
| Hover colour / hover text colour | Appearance on mouse over. |
| Preview | Shows the button live while you adjust it. |

## Shortcodes

```
[tng_link id="I12"]
```

Button to the record of person I12.

```
[tng_link id="I12" tree="monarbre" text="Voir la fiche"]
```

With a specific tree and a custom text.

### Attributes

| Attribute | Values | Default | Role |
|---|---|---|---|
| id | I12 | (empty: nothing) | TNG person ID. |
| tree | tree ID | tree from General | Tree (gedcom) of the person. |
| text | text | text from settings | Label of this button. |

## Usage

1. Set the button style (emoji, text, colours) in the module section.
2. Paste the shortcode with the person ID.

## Result on the site

![Record link button](../images/Exemple%20record%20link.png)

## Notes

- The TNG URL is set in **General**.

---
[← Modules guide](README.md)
