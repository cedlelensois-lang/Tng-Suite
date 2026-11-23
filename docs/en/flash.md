# Surname flash list

A **flash list** is the table of the surnames you research, giving for each one the **number of people**, the **minimum date** and the **maximum date**, and the **places** with their date range. It is used to exchange with other genealogists and quickly spot possible common branches. The module builds it automatically from your TNG database (read-only).

## In the administration

Menu **TNG Suite > Documents**, section **Flash list**.

| Setting | Role |
|---|---|
| Place level | Town, department, region or country. TNG places are free text: “Town, Department, Region, Country” is assumed (postal codes are ignored). Default: department. |
| Minimum per surname | Surnames borne by fewer people are hidden (default: 2; 1 = show all). |
| Variants | Groups spelling variants (Lecoq, Lecocq, Lecoc…); they stay shown in brackets. |
| Places per surname | Number of places kept for each surname (the most frequent). |
| Public export | Offers visitors the CSV, TXT and “Copy for a forum” exports. |

The page also shows a **preview** of the list, the export links and a **Recalculate** button. The result is cached (duration: the common “cache” setting).

## Usage

Paste the `[tng_flash]` shortcode in a page or a post.

| Attribute | Values | Default | Role |
|---|---|---|---|
| level | commune, departement, region, pays | setting | Place level. |
| min | 1 to 50 | setting | Minimum number of people per surname. |
| variants | 1 / 0 | setting | Group spelling variants. |
| tree | identifier | tree of the common settings | TNG tree. |
| search | 1 / 0 | 1 | Search and filters. |
| export | 1 / 0 | setting | Export buttons. |
| limit | 10 to 500 | 50 | Rows shown per page (“Show more” button). |
| living | hide, show | module setting | `living="hide"` hides living or private people on this page (and in its exports), even if the module is set to “Always show”; `living="show"` shows them (same conditions as `rgpd="0"` below). Without the attribute the module setting applies. |
| rgpd | 0 | module setting | `rgpd="0"` (same as `living="show"`) also shows living or private people on this page (and in its exports). Accepted only if the page author can edit other people’s content (editor or above) and the module is not set to “Always hide”. |
| title | text | empty | Title above the list. |

## What the visitor sees

- A table: **surname** (variants in brackets), **people**, **dates** (min – max over births, marriages and deaths) and **places** with their range and number of events; a tooltip details births, marriages and deaths.
- A **search** by surname, a **filter** by place and by **period** (without a place: a surname is kept if its dates overlap the period; with a place: a matching place whose dates overlap the period is required; other places are dimmed; the filter searches all the places of a surname, not only the displayed ones); **sorting** by surname, number of people or dates (click the header).
- **Export**: CSV (semicolon separator, UTF-8 with BOM, opens in Excel or LibreOffice), TXT (one line per surname: “NAME ; min date ; max date ; places”) and **Copy for a forum** (copies the rows shown after filtering).

## Privacy

Living or private people are excluded or included according to **TNG Suite > General > Modules** (the “Living / private” setting of the Flash list module). The **administration preview** (administrators only) always includes them, unless the module is set to “Always hide”. To show them on one specific public page too, add `rgpd="0"` to the shortcode: `[tng_flash rgpd="0"]` (only for a private or password-protected page: visitors would see living people).
