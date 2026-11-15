# Family book

Generates a complete book (ancestry, descendants, indexes, maps, statistics) as a printable page/PDF, Word (.docx) or OpenDocument (.odt), in French, English, Spanish or Catalan.

## In the admin

![Family book](../images/book.png)

## Shortcodes

```
[tng_book]
```

## How to use

The page has three tabs: **Generate a book**, **Settings** and **Overview of profiles**. When profiles exist, the “Generate a book” tab first shows only the profile picker: load a profile or click **New book** to display the form (with no profile, the form is shown directly). Photos, the public form and the automatic generation settings are in the **Settings** tab.

1. Enter the person ID, the tree and the number of generations.
2. Tick the wanted content, order the parts, pick colours and a layout template.
3. Choose the format and generate. A profile can be saved and reloaded.

## Result on the site

![Family book](../images/exemple%20book.png)

## Notes

- Surname page: an editor (formatting, tables, images) lets you write an introduction to the surname; it is saved in the book profile and placed with “Order of the book’s parts”. Empty = no page. Images aligned left or right in the editor keep their place, with the text wrapping around them, in the PDF, Word and OpenDocument outputs.
- Silhouettes: for people without a photo, “Style of generic silhouettes” lets you choose between the portrait (circle on a light background) and the round version (no background).
- Family chronology: tick-box option (off by default); adds a part listing the births, marriages and deaths of the people in the book in date order, grouped by decade (with a link to each record, the place and the age at death). Hidden (living) people are left out. It is placed with “Order of the book’s parts” like any other part.
- Each part starts on a new page.
- The [tng_book] shortcode opens the generator to logged-in visitors if enabled.
- Living people are fully included if you choose to show them: do not share the book without consent.

## Automatic generation (beta)

Each profile can be generated automatically, every day, week or month, with no action on your part.

1. Load (or create) a book profile. Its format (PDF, Word, OpenDocument or HTML) is the one used.
2. In the **Automatic generation for this profile** section of the form, tick **Generate automatically**, then choose the frequency, day and time.
3. Click **Update the active profile** (or **Save as profile**): the schedule is saved with the profile.
4. The **Overview of profiles** tab shows all profiles (schedule, last generation, latest file, **Generate now** button to test and **Edit** button to load the profile). The **Settings** tab holds the shared settings: **destination folder** (absolute path on the server; empty = protected, randomly named folder inside WordPress uploads; to sync with OneDrive, Dropbox, etc., choose a folder your sync tool watches), number of files kept per profile (older ones are deleted; only files created by the plugin are affected), the **Only if the data has changed** option and the notification email.

Notes:

- WordPress only runs its tasks when the site is visited, so the time is approximate. For an exact time, set up a server cron job that calls `wp-cron.php` (for example every hour).
- Direct PDF uses mPDF, already included in the GitHub Releases zip. If the plugin is installed from source, run `composer install --no-dev` in `modules/book`; without mPDF, generating a PDF profile fails (message in the “Last generation” column).
- Photos or notes changed without any change to the records are not detected by “only if the data has changed”: use **Generate now**.

## Background generation (beta)

For a large book (hundreds of pages, many photos), the host may cut the page off before it finishes (“Error 500” after a long wait). The **Generate in the background** button (next to “Save and generate the book”) avoids this: the book is prepared by a scheduled server task, without keeping the page waiting.

1. Set up the book as usual (PDF, Word, OpenDocument or HTML), then click **Generate in the background**. In the **Overview of profiles** tab, the **In the background** button does the same for a saved profile.
2. A status panel appears at the top of the **Generate a book** tab: waiting, in progress (step and progress), then **Book ready** with the download button. You can leave the page.
3. The file is placed in the automatic generation folder (**Settings** tab); the **Cancel** button stops a running job.

Notes:

- A PDF is written in several short steps (about 12 s each): the document is paused on the server and resumed by a new scheduled task, which avoids hosts that cut off a long process. The panel shows the current step.
- One job at a time. If the host stops the process, the panel says so (“Generation interrupted”) with the last step reached: tick “Compress images”, reduce the number of generations or choose the Word format.
- Like automatic generation, the task relies on WP-Cron (see above). If it stays “waiting”, set up a server cron task that calls `wp-cron.php`.
- An e-mail can be sent at the end (common automatic generation setting).

---
[← Module guide](README.md)
