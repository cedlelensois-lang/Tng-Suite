# Data consistency

Check of your TNG tree: impossible or inconsistent dates, ages, sex, family structure, badly typed names, places written in several ways and **possible duplicates** (with a probability score). The module is reserved for administrators, has **no shortcode** and is **read-only**: nothing is changed in TNG, each anomaly links to the record’s edit page in TNG so that you can correct it yourself.

## In the administration

Menu **TNG Suite > Stats & analyses**, sections **Consistency: data check** and **Consistency: thresholds and ignored anomalies**.

## Usage

1. Choose the **tree**, unfold **Parts to analyse** if you want to tick only what interests you (see below), then click **Start the analysis**. The analysis runs in batches with a progress bar (people, families, ancestry loops, children of several families, duplicate couples, duplicates, places): it suits large trees and can take several minutes. You can **cancel** it (the results are then partial); if the page is closed midway, a **Resume** button appears when you come back.
2. Anomalies are shown in a table: **severity** (Error, Warning, Information), **type**, **anomaly** (the title of the check, for example “Death before birth”), **detail** (the message with names and dates), the **records** concerned (a click opens the record **in edit mode** in TNG, in a new tab; you must be logged in to the TNG administration) and the **score** for duplicates. The Severity, Type, Anomaly, Records and Score columns are **sortable**; the list can be filtered by type, by **anomaly** (list grouped by type, with the number of cases), by severity and by text (name, ID…). The exported CSV follows the filters and the sort order.
3. Correct in TNG, then run the analysis again to update the results.
4. **Ignore this anomaly** removes a false alarm from the list; it stays ignored in later analyses (same check, same people or family). The **Thresholds and ignored anomalies** section lists them and lets you **restore** them one by one or all at once.
5. **Export as CSV** downloads the filtered list (semicolon separator, UTF-8 with BOM: opens directly in Excel or LibreOffice).
6. **Clear results** deletes the results of the tree shown.

## What is checked

| Type | Examples of checks |
|---|---|
| Dates | impossible date (31 February, non-existent month), unrecognised date (a date of “Y”, “Yes” or “Oui”, the TNG convention for “event occurred, date unknown”, is not reported), date in the future; death, baptism or burial before birth or death; excessive age at death; person flagged “living” although born very long ago or although a death is recorded; marriage before birth or after the death of a spouse; minimum age at marriage; child born before the parents, after the mother’s death, or more than 9 months after the father’s death; age of the mother and father at the children’s births; divorce before marriage. Approximate dates (“about”, “before”, “after”…) are compared with a tolerance. |
| Sex | father (husband) who is female, mother (wife) who is male, unknown sex although the person has children, same-sex couple, first name usually of the opposite sex (indicative only, based on a list of common French first names). |
| Structure | surname of a birth child that differs from the father’s (adopted, natural and foster children excluded; ignored when the names are close, share a word or contain “dit”; adjustable or can be switched off), person with no link at all, ancestry loop (a person who is their own ancestor), person who is their own spouse, family with no husband or wife, empty family, natural child of several families, parent and child of the same family, child recorded twice, couple in several families, reference to a person missing from the database; names: lowercase family name, extra spaces, digit in the name, question mark mixed with letters (uncertain reading), person with no name or first name. |
| Privacy | person with no known death or burial, born less than 100 years ago (adjustable), who is marked neither “living” nor “private”: the record would be exported in clear. |
| Events | for the events in TNG’s events table (occupation, residence, emigration, custom events of people and families): impossible, unrecognised or future date; event before birth or more than a year after death (burial, funeral, will, estate and commemorations are accepted). |
| Sources and media | citation of a source that no longer exists; source never cited; share of people with no source at all (only if the database cites sources); link to a deleted media item; **media attached several times to the same person** (duplicate links in the same place = warning; same media attached to different events = information; the “Records” column also holds a link to the media item's edit page in TNG). |
| GEDCOM export | slash “/” in a name (it delimits the surname in GEDCOM), control or invisible character, name longer than 120 characters (GEDCOM limit). |
| Places | place written in several ways (case, accents, spaces…). |
| Duplicates | people who seem to be one person; children of the same family who seem to be the same person. |

**Genealogical conventions respected**: a name or first name reduced to “?” (“???”, “…”, “-”) or placed in square brackets (“[Marie]”, “[de la Tour]”) stands for an unknown or presumed name: it is never reported. A question mark (uncertain reading, for example “Dup?nt”) and a digit (probable typing error) are two separate checks, both of “Information” severity; a “?” inside square brackets (“Anne [Marie?]”) is not reported. People without a usable name are not compared for duplicates.

## Choosing the parts to analyse

Before starting, **Parts to analyse** lists eight parts: people, families, ancestry loops, children of several families and duplicate couples, possible duplicates, events, sources and media, places. Your choice is remembered. Only the ticked parts are run again: **the results of the other parts are kept**, with their analysis date (“Parts analysed”). Possible duplicates, the longest part on a large database, need the “People” part (it is ticked automatically). If a table or column expected in your TNG version is missing, the part concerned is skipped and flagged in yellow instead of stopping the analysis.

## Duplicates and score

Two people are matched on their **name** (identical, or similar: close pronunciation and spelling), **first name**, **birth date or year**, **death year**, **birth place** and **parents** (same parents, same father or same mother). Each criterion adds or removes points; the result is a **score from 0 to 100**, shown in the Score column (sortable). The criteria used are listed in the message (“identical names, same birth year, same parents”). Two people of known and different sex are never matched. Only pairs whose score reaches the **minimum score** (70 by default) are reported. Groups of people with extremely common names are not compared (the number of groups skipped is shown).

A high score is only a **probability**: always check both records before merging in TNG.

## Settings

Section **Consistency: thresholds and ignored anomalies**. Thresholds apply to the next analysis.

| Setting | Default | Effect |
|---|---|---|
| Minimum / maximum age of a mother at a child’s birth | 12 / 50 years | Beyond: warning (error if extreme). |
| Minimum / maximum age of a father at a child’s birth | 13 / 75 years | Beyond: warning. |
| Minimum age at marriage | 15 years | Below: warning. |
| Maximum age at death | 105 years | Beyond: warning. |
| Person flagged “living” born more than | 110 years ago | Beyond: warning. |
| Child born more than (months) after the father’s death | 9 months | Beyond: error. |
| Tolerance for approximate dates | 2 years | Margin applied to “about”, “before”, “after”… dates. |
| Minimum score to report a possible duplicate | 70 | From 30 to 100. |
| Search depth for ancestry loops | 40 generations | The search can lengthen the analysis of a large tree; it is ticked or unticked in “Parts to analyse”. |
| Person with no known death, marked neither “living” nor “private”, born less than (years) ago | 100 | Privacy check before export (range 50 to 130). |
| Surname of the child and of the father: minimum similarity to consider them identical, in % | 75 | 0 switches the check off. Two names are also treated as identical if they share a word (double surnames) or contain “dit” or “alias”. |
| Maximum number of anomalies kept per check | 5,000 | Checks that reach this cap are flagged. |

## Notes

- **Read-only**: the module only runs read queries on the TNG database. Results are stored in two tables of the WordPress database (`tngs_coh_res`, `tngs_coh_key`); the list of ignored anomalies is kept in the site options.
- **Privacy**: the results contain people’s names and dates, **including living people**. They are visible to administrators only (`manage_options` capability), never displayed on the site; every action is protected by security tokens. “Clear results” deletes them; uninstalling the plugin removes the tables and options.
- Results are kept until the next analysis or until “Clear results”; the “Flush all caches” button in **General > Maintenance** does not erase them.
- Names, dates and places shown come from your TNG database: they are never translated.
- The module can be turned off in **General > Modules** (nothing is then loaded; results and settings are kept).

---
[← Modules guide](README.md)
