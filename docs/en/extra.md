# Name cloud & birthdays

Surname cloud, today’s anniversaries (births, marriages, deaths) and latest updates. No settings of its own: the admin page is a help page. Living and private people are hidden by default.

## In the admin

Menu **TNG Suite > Media & widgets**, section **Name cloud & birthdays**.

![Name cloud & birthdays](../images/Name%20cloud%20%26%20birthdays.png)

## Shortcodes

```
[tng_name_cloud limit="50"]
```

Cloud of the most frequent surnames.

```
[tng_births_today]
```

Births that occurred on this day in past years.

```
[tng_marriages_today]
```

Marriages of this day.

```
[tng_deaths_today]
```

Deaths of this day.

```
[tng_recent_updates limit="10" date="yes" link="yes" title="Nouveautés"]
```

Latest modified records.

### `[tng_name_cloud]`

| Attribute | Values | Default | Role |
|---|---|---|---|
| limit | 1 – 200 | 50 | Number of names. |

### `[tng_recent_updates]`

| Attribute | Values | Default | Role |
|---|---|---|---|
| limit | 1 – 100 | 10 | Number of records. |
| tree | tree ID | tree from General | Tree (gedcom). |
| date | yes / no | yes | Show the modification date. |
| date_format | PHP format | WordPress format | Date format. |
| link | yes / no | yes | Link to the TNG record. |
| title | text | (empty) | Title shown above the list. |

## Usage

1. Paste the wanted shortcode in a page, post or “Shortcode” widget.

## Result on the site

![Name cloud & birthdays](../images/Exemple_Name%20cloud%20%26%20birthdays.png)

## Notes

- Living or private people follow the **General > Modules** setting (hidden by default, recommended for GDPR).
- Daily lists are cached up to 24 h; recent updates less than an hour.
- Results are cached; after a change in TNG, use **General > Maintenance > Flush caches** to see it immediately.

---
[← Modules guide](README.md)
