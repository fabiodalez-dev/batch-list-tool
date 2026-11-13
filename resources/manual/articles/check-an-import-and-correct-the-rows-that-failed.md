# How to check an import and correct the rows that failed

Imports run in the background, so the Import Wizard does not show their outcome. A notification under the bell icon in the top bar tells you when an import has finished, and the Import Status page shows how far each import has progressed, how many rows were imported and why any rows were rejected, so that you can correct those rows and import them again.

## Prerequisites

- [ ] An account with the Administrator or Super administrator role. The Import Status and Import Wizard pages are not available to other roles.
- [ ] An import that has already been started from the Import Wizard.
- [ ] Microsoft Excel or another spreadsheet program to edit the error report.

## 1. Open the Import Status page

In the sidebar, under **Importation**, select **Import Status**. The page lists the 50 most recent imports, newest first, with one row for each file that was imported.

![Open the Import Status page](./block_open-import-status.png)

## 2. Follow the progress and the totals

Locate your file in the **File** column. **Processed / Total** shows how many rows of the file have been worked through out of the total, **OK** shows how many were imported successfully, and **Failed** shows the rows that were not imported. **Completed** shows when the import finished, or **Pending** while it is still running; **Started by** names the user who started it.

## 3. Check that the Failed count is final

Rely on the **Failed** figure only once **Completed** shows a time. While an import is **Pending**, **Failed** is calculated as the total minus the rows imported so far, so it also includes rows that have not yet been processed. If **Stalled — queue worker?** appears, the import has been waiting for more than five minutes and your system administrator should be informed.

A red note under **Processed / Total** stating that rows were never imported means that part of the file was not processed at all. Those rows are included in **Failed** but have no reason and are not in the error report: import them again from your original file.

## 4. Open the notification when the import finishes

When an import finishes, the user who started it receives a notification; the bell icon in the top bar shows the number of unread notifications and is refreshed every 30 seconds, so the notification may take up to half a minute to appear. Select the bell icon to open **Notifications**, where the **Import completed** notification states how many rows were processed and how many failed. When rows failed, select **Download information about the failed rows** to download the error report.

![Open the notification when the import finishes](./block_open-notification.png)

## 5. Download the error report from Import Status

On the **Import Status** page, read the reasons listed under the **Failed** count, then select **Errors CSV**, either below the count or in the **Download** column. This is the same report as the one offered in the notification: it contains every rejected row with its original values, followed by a final column that states why the row was rejected. The link appears only after the import has completed with at least one failed row.

## 6. Correct the rows in the spreadsheet

In your spreadsheet program, correct each row as its reason indicates, for example by supplying a required value or replacing a location code that does not exist. Keep the column headings exactly as they are, so that the columns are recognised again; the final reason column is not part of the import and should be deleted before the file is saved. Rows rejected with the reason "Skipped — row already exists (skip duplicates enabled)." were not errors and should be left out of the corrected file unless you intend to update those records.

## 7. Import the corrected rows again

Select **Import Wizard** in the sidebar and take the corrected file through the wizard, with the same **Type of records** as the original import, until you reach the final step, **Confirm and import**.

## 8. Decide how existing rows are handled

Leave **Overwrite existing records (update rows that already exist instead of skipping them)** unticked for a normal import. A row that matches a record already in the archive is then skipped, never rewritten, and is counted under **Failed** with the reason "Skipped — row already exists (skip duplicates enabled)." Tick the box only when you deliberately intend to update existing records from the file.

![Decide how existing rows are handled](./block_existing-rows.png)

## 9. Start the import and check the result

Tick **I have reviewed the preview and I want to import these rows.** and select **Start import**. When the new **Import completed** notification arrives, open **Import Status** and confirm, once **Completed** shows a time, that **Failed** is 0 or lists only the rows you expected.

## Tips

> **Tip:** Import only the corrected rows rather than the whole original file. Rows that were already imported would otherwise be skipped and counted as failed, or rewritten if **Overwrite existing records (update rows that already exist instead of skipping them)** is ticked.
>
> **Tip:** Selecting **Download information about the failed rows** also marks the notification as read. Use **Mark all as read** or **Clear** at the top of **Notifications** to tidy the list.
>
> **Tip:** Immediately after you start an import, the **Download failed rows (CSV)** button at the top of the **Import Wizard** page downloads the report for that import, with the reason in a column named `_validation_error`.
>
> **Tip:** Reasons are grouped on the **Import Status** page, so a line such as "3× Document batch does not match its box batch." covers three rows with the same problem.

## Summary

Watch for the **Import completed** notification under the bell icon, then wait until **Completed** shows a time before relying on the **Failed** count. Use the notification or **Errors CSV** to see why each row was rejected, correct those rows and import them again through the **Import Wizard**. Rows that already exist are skipped unless **Overwrite existing records (update rows that already exist instead of skipping them)** is ticked.
