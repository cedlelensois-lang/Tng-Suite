# Individual sheet & document lists

Ancestor and descendant lists and individual sheet, as PDF, Excel or Word. Document languages: French, English, Catalan, Spanish. Two sections: **Generate documents** (admin) and **Settings**.

## In the admin

Menu **TNG Suite > Documents**, sections **Sheet: generate documents** and **Sheet: settings**.

![Individual sheet & document lists](../images/individual%20sheet.png)

## Module settings

| Setting | Effect |
|---|---|
| Reference person (Sosa 1) | TNG ID of the starting person used to number sheets. |
| Logged-in users only | Logged-out visitors cannot generate documents. |
| Visitors can tick / untick fields | Shows the field list on the public page. |
| Document language | Automatic (General language), French, English, Catalan or Spanish. |
| Visitors can choose the language | Language selector on the public page. |
| Fields displayed by default | Columns of the ancestor / descendant lists and sheet sections (everything ticked by default). |

## Shortcodes

```
[tng_listes]
```

Public tool: the visitor chooses the document type, the person, the number of generations and the format.

```
[tng_fiche]
```

Former name of the same tool: displays the same as `[tng_listes]`.

## Usage

1. “Generate documents” section: choose the type, the person and the generations, expand “Fields displayed”, then **Create the document**.
2. Settings section: default fields, languages, access reserved to logged-in users.
3. For visitors, paste `[tng_listes]` in a page.

## Result on the site

![Individual sheet & document lists](../images/Exemple%20individual%20sheet.png)

## Notes

- Living or private people follow the **General > Modules** setting (hidden by default, recommended for GDPR).
- In the admin, the language follows **General** (French or English), unless Catalan / Spanish is chosen in the settings.
- The visitor’s language choice is remembered with `localStorage` (no cookie).

---
[← Modules guide](README.md)
