# Name cloud & birthdays

Cloud of surnames and today’s anniversaries (births, marriages, deaths). No settings: the admin page is a help page.

## In the admin

![Name cloud & birthdays](../images/Name%20cloud%20%26%20birthdays.png)

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

Latest individuals updated in TNG (bold name, last modification date on the right), newest first. Parameters: `limit` (1 to 100, default 10), `tree` (TNG tree, defaults to the one in the common settings), `date` (`yes`/`no`), `date_format` (PHP codes, for example `d/m/Y` or `j F Y`), `link` (`yes`/`no`, link to the TNG record) and `title` (heading above the list). Living or private people are excluded by default; the list is cached for one hour.

## How to use

1. Paste the wanted shortcode into a page or widget.

## Result on the site

![Name cloud & birthdays](../images/Exemple_Name%20cloud%20%26%20birthdays.png)

---
[← Module guide](README.md)
