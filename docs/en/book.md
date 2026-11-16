# Family book

Generates a complete book (ancestry, descendants, indexes, maps, statistics) as a printable page/PDF, Word (.docx) or OpenDocument (.odt), in French, English, Spanish or Catalan.

## In the admin

Menu: **TNG Suite > Documents > Family book**.


![Family book](../images/book.png)

## Shortcodes

```
[tng_book]
```

## How to use

The page (**Documents** tab) has three sections: **Book: generate a book**, **Book: settings** and **Book: overview of profiles**. When profiles exist, the “Generate a book” section first shows only the profile picker: load a profile or click **New book** to display the form (with no profile, the form is shown directly). Photos, the public form and the automatic generation settings are in the **Settings** section.

1. Enter the person ID, the tree and the number of generations.
2. Tick the wanted content, order the parts, pick colours and a layout template.
3. Choose the format and generate. A profile can be saved and reloaded.

## Result on the site

![Family book](../images/exemple%20book.png)

## Notes

- Grouped settings: options are sorted into “Content of the records”, “Parts of the book”, “File size” and “Advanced options” (diagnostic mode). Several settings are merged into a single choice: Photos (none / photos with the spouse’s photo / photos and silhouettes), Baptism and burial (none / burial / both), Timeline (none / family / with historical background), and single tick-boxes for detailed sentences (including ages at marriage and places of residence), translated places (country and départements), military information (war events and “Died for France”), trees (mini tree and visual trees), summary lists and the “In figures” chapter (frequent first names and surnames included). Settings already saved and profiles are converted automatically.
- Surname page: an editor (formatting, tables, images) lets you write an introduction to the surname; it is saved in the book profile and placed with “Order of the book’s parts”. Empty = no page. Images aligned left or right in the editor keep their place, with the text wrapping around them, in the PDF, Word and OpenDocument outputs.
- Silhouettes: for people without a photo, “Style of generic silhouettes” lets you choose between the portrait (circle on a light background) and the round version (no background).
- Children of couples in the ascendancy: tick-box option (on by default) for the Classic, Elegant and Modern templates. Under the ancestor’s marriage sentence, “X and Y have N children:” followed by the list (years of life, link to the record for the child in the line). The list appears in the husband’s record (or the only spouse present) so it is not repeated. In the descendancy, the list of children uses the same sentence with the number of children.
- Historical background in the records: tick-box option (off by default). Under a person’s record, an italic line such as “Historical background: the First World War and the Second World War” lists, from the ticked historical fact lists, the periods (wars, regimes…) in which the birth (or baptism), a marriage or the death (or burial) falls, as well as those lived through when the death (or burial) is known and the person was certainly alive before the end of the period (known birth or baptism, otherwise a marriage); with no death date, nothing is claimed. A death two days after the armistice therefore counts: the person lived through the war. Period names are taken as written in the lists, with no translation and no composed sentence (no mixing of languages). An imprecise date is kept only if all its possibilities are within the period (a birth in “1914” is not counted in the war that starts in August). The dated events (`date | text`) of the lists do not appear in the records but in the **family chronology** (“with historical background” setting), where they fall into place by date and decade; only periods (`date .. date | name`) feed the records’ line: a list made only of events therefore adds nothing to the records. Independent of the chronology.
- Family chronology: tick-box option (off by default); adds a part listing the births, marriages and deaths of the people in the book in date order, grouped by decade (with a link to each record, the place and the age at death). Hidden (living) people are left out. It is placed with “Order of the book’s parts” like any other part.
- Historical background (fact lists): the setting “Timeline: with historical background” inserts, in italics, the facts of the lists ticked in the “Historical background: lists to use” row into the chronology; the same lists feed the “Historical background” sentence of the records. **Supplied lists**: France, Belgium, Switzerland, Spain, Italy, Germany, United Kingdom, United States and Canada (texts in French, or in English if the book is in another language; for a Catalan or Spanish book, copy a list into your lists and translate it). **Your lists**: in **TNG Suite > Documents > Book: settings > Historical facts**, create as many lists as you like (France, England, Catalunya, España…) and write the texts in the language of your choice, one entry per line: `1714-09-11 | Caiguda de Barcelona` for an event (date YYYY, YYYY-MM or YYYY-MM-DD) or `1936-07-17 .. 1939-04-01 | the civil war` for a period (war, regime… with its article: used by the records’ “during …” / “lived through …” sentence). The “List to edit” drop-down gives access to each of your lists (edit, then “Save list”; “Delete this list” removes it), or creates a **new list**, empty or copied from a supplied list (editable copy, in French or English): it then appears in that drop-down and in the tick boxes of the book form. The **supplied lists** can be removed from the book form: under the editor, untick those you do not need (they remain available as templates to copy; tick them again to bring them back). You can also export a list as a `.txt` file and import one. **For each book** (and each profile), the “Historical background: lists to use” row of the form lets you tick the lists to display, for example France and Spain for Mr X, and choose how they apply: **to the whole book** (all ticked lists apply to everyone; a period shared by several lists, such as the First World War, is mentioned only once in the records) or **according to each person’s birthplace**. In the latter case, each list has **place keywords** (for example `Catalunya, Cataluña, Espanya`): a person gets the lists with a keyword matching an element of their birthplace (or baptism place), starting from the end. A list without keywords applies to everyone (family facts, personal events); a single ticked list with keywords applies to the whole book; a place with no recognised keyword gets the **default list** (otherwise the first ticked one), unless it names a known country whose list is not ticked (e.g. Poland: nothing is added). With several lists with keywords, a list is kept only if it has at least two people in the book (or 3%), only for the period its people lived, and each fact then shows its list name in brackets. Custom events from earlier versions are moved into a list called “Événements personnalisés”.
- New book: the “New book” button (and a first-time plugin setup) starts from the recommended settings: Elegant template, single-line frame, colour cover tree, PDF format (HTML if direct PDF is unavailable), TNG photos with generic silhouettes, baptism and burial, detailed sentences, places with country, military information, notes, other unions, a detailed record for each spouse, children of couples, chronology with historical background and historical background in the records, “In figures”, trees, summary lists, cousin marriages and implex, index and places map. Off by default: siblings, sources, diagnostic mode. Profiles already saved do not change.
- Page frame: “None”, “Single line”, “Double line” or “Ornate frame” (hollowed corners and inner line, drawn by the plugin), in the book colour (“headings and links”). Applies to the printable page, PDF, Word and OpenDocument (the ornate frame becomes a double line in Word and OpenDocument); the cover has no frame.
- Cover illustration: “None” (two lines around the title), “Flourishes above and below the title” or a watercolour-style tree: broad oak, leaning tree with light branches, weeping willow, a leafy tree with its roots drawn below, a full-colour tree (green foliage, brown trunk, roots in the soil, empty round medallions on the branches), or a “Full-page cover” (PDF: cream background over the whole first page, watercolour tree growing from the left edge, title below; other formats use the usual leaning tree). Two more full-page covers (PDF) use illustrations supplied with the plugin: a tree with blank ribbons and birds above a flowering meadow, and a large trunk with pastel foliage overlooking the countryside; the title goes in the free area. The trees are drawn by the plugin in the book colour; these two illustrations keep their own colours.
- Table of contents: in the PDF it shows page numbers (native mPDF table, clickable entries). In the printable page, Word and OpenDocument, entries link to their heading without page numbers.
- Other content options: “Siblings on the records” (other children of the family of origin, with a link to their record when it exists); “Other marriages and unions of the ancestors” (besides the union the line descends from); “Cousinages and implexes” (part added only when the same ancestor appears several times); “Map of places of life” (number of people per département or province, in black and white); “Index of names and places” and “Sources”; “Place levels shown” (municipality only, or up to five levels).
- Spouses: in the ancestors section, a small photo of the spouse (“Photos” setting) goes with the union sentence, with the link to their detailed record. In the descendants section, the option “a detailed record for each spouse” (ticked by default for new settings, unticked for settings already saved) adds, right after the descendant’s record, a record for every spouse who is not already in the book (no small photo: the record follows), numbered like the couple followed by “c” (D12c, D12c2 for a second union); the union sentence is then limited to the name with a link. A spouse who is already an ancestor or descendant, hidden or dropped gets no extra record: the link points to the existing one.
- Event notes: with the “Notes” option, each event shown in a record (birth, baptism, occupation, residences, marriage, divorce, death, burial, military events) is followed by its TNG notes.
- Each part starts on a new page.
- The [tng_book] shortcode opens the generator to logged-in visitors if enabled.
- Living people are fully included if you choose to show them: do not share the book without consent.

## Automatic generation

Each profile can be generated automatically, every day, week or month, with no action on your part.

1. Load (or create) a book profile. Its format (PDF, Word, OpenDocument or HTML) is the one used.
2. In the **Automatic generation for this profile** section of the form, tick **Generate automatically**, then choose the frequency, day and time.
3. Click **Update the active profile** (or **Save as profile**): the schedule is saved with the profile.
4. The **Overview of profiles** section shows all profiles (schedule, last generation, latest file, **Generate now** button to test and **Edit** button to load the profile). The **Settings** section holds the shared settings: **destination folder** (absolute path on the server; empty = protected, randomly named folder inside WordPress uploads; to sync with OneDrive, Dropbox, etc., choose a folder your sync tool watches), number of files kept per profile (1 by default, 3 at most; the overview lists them as soon as there are several; older ones are deleted; only files created by the plugin are affected), the **Only if the data has changed** option and the notification email.

Notes:

- WordPress only runs its tasks when the site is visited, so the time is approximate. For an exact time, set up a server cron job that calls `wp-cron.php` (for example every hour).
- Direct PDF uses mPDF, included in the plugin (Releases zip and GitHub source alike). If you removed it, run `composer install --no-dev` in `modules/book`; without mPDF, generating a PDF profile fails (message in the “Last generation” column).
- Photos or notes changed without any change to the records are not detected by “only if the data has changed”: use **Generate now**.

## Background generation (beta)

For a large book (hundreds of pages, many photos), the host may cut the page off before it finishes (“Error 500” after a long wait). The **Generate in the background** button (next to “Save and generate the book”) avoids this: the book is prepared by a scheduled server task, without keeping the page waiting.

1. Set up the book as usual (PDF, Word, OpenDocument or HTML), then click **Generate in the background**. In the **Overview of profiles** section, the **In the background** button does the same for a saved profile.
2. A status panel appears at the top of the **Generate a book** section: waiting, in progress (step and progress), then **Book ready** with the download button. You can leave the page.
3. The file is placed in the automatic generation folder (**Settings** section); the **Cancel** button stops a running job.

Notes:

- A PDF is written in several short steps (about 12 s each): the document is paused on the server and resumed by a new scheduled task, which avoids hosts that cut off a long process. The panel shows the current step.
- One job at a time. If the host stops the process, the panel says so (“Generation interrupted”) with the last step reached: tick “Compress images”, reduce the number of generations or choose the Word format.
- Like automatic generation, the task relies on WP-Cron (see above). If it stays “waiting”, set up a server cron task that calls `wp-cron.php`.
- An e-mail can be sent at the end (common automatic generation setting).

---
[← Module guide](README.md)
