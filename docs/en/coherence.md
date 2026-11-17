# Data consistency

Check of your TNG tree: impossible or inconsistent dates, ages, sex, family structure, badly typed names, places written in several ways and **possible duplicates** (with a probability score). The module is reserved for administrators, has **no shortcode** and is **read-only**: nothing is changed in TNG, each anomaly links to the TNG record so that you can correct it yourself.

## In the administration

Menu **TNG Suite > Analyses**, sections **Consistency: data check** and **Consistency: thresholds and ignored anomalies**.

## Usage

1. Choose the **tree**, then click **Start the analysis**. The analysis runs in batches with a progress bar (people, families, ancestry loops, children of several families, duplicate couples, duplicates, places): it suits large trees and can take several minutes. You can **cancel** it (the results are then partial); if the page is closed midway, a **Resume** button appears when you come back.
2. Anomalies are shown in a table: **severity** (Error, Warning, Information), **type**, message, the **records** concerned (links to TNG, opened in a new tab) and the **score** for duplicates. The Severity, Type and Score columns are **sortable**; the list can be filtered by type, severity and text (name, ID…).
3. Correct in TNG, then run the analysis again to update the results.
4. **Ignore this anomaly** removes a false alarm from the list; it stays ignored in later analyses (same check, same people or family). The **Thresholds and ignored anomalies** section lists them and lets you **restore** them one by one or all at once.
5. **Export as CSV** downloads the filtered list (semicolon separator, UTF-8 with BOM: opens directly in Excel or LibreOffice).
6. **Clear results** deletes the results of the tree shown.

## What is checked

| Type | Examples of checks |
|---|---|
| Dates | impossible date (31 February, non-existent month), unrecognised date, date in the future; death, baptism or burial before birth or death; excessive age at death; person flagged “living” although born very long ago or although a death is recorded; marriage before birth or after the death of a spouse; minimum age at marriage; child born before the parents, after the mother’s death, or more than 9 months after the father’s death; age of the mother and father at the children’s births; divorce before marriage. Approximate dates (“about”, “before”, “after”…) are compared with a tolerance. |
| Sex | father (husband) who is female, mother (wife) who is male, unknown sex although the person has children, same-sex couple, first name usually of the opposite sex (indicative only, based on a list of common French first names). |
| Structure | person with no link at all, ancestry loop (a person who is their own ancestor), person who is their own spouse, family with no husband or wife, empty family, natural child of several families, parent and child of the same family, child recorded twice, couple in several families, reference to a person missing from the database; names: lowercase family name, extra spaces, question mark or digit, person with no name or first name. |
| Places | place written in several ways (case, accents, spaces…). |
| Duplicates | people who seem to be one person; children of the same family who seem to be the same person. |

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
| Ancestry loops (tick-box) and search depth | yes / 40 generations | The search can lengthen the analysis of a large tree. |
| Maximum number of anomalies kept per check | 5,000 | Checks that reach this cap are flagged. |

## Notes

- **Read-only**: the module only runs read queries on the TNG database. Results are stored in two tables of the WordPress database (`tngs_coh_res`, `tngs_coh_key`); the list of ignored anomalies is kept in the site options.
- **Privacy**: the results contain people’s names and dates, **including living people**. They are visible to administrators only (`manage_options` capability), never displayed on the site; every action is protected by security tokens. “Clear results” deletes them; uninstalling the plugin removes the tables and options.
- Results are kept until the next analysis or until “Clear results”; the “Flush all caches” button in **General > Maintenance** does not erase them.
- Names, dates and places shown come from your TNG database: they are never translated.
- The module can be turned off in **General > Modules** (nothing is then loaded; results and settings are kept).

---
[← Modules guide](README.md)
