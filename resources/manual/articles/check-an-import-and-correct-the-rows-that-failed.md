# How to check an import and correct the rows that failed

After you start an import from the Import Wizard, the rows are processed in the background. The Import Status page shows how far each import has progressed, how many rows were saved and how many were rejected, together with the reason for every rejection, so that you can correct those rows in your spreadsheet and import them again.

## Prerequisites

- [ ] An account with the administrator or super administrator role. The Import Wizard and the Import Status page are not available to other roles.
- [ ] An import that you have already started from the Import Wizard.
- [ ] The spreadsheet that you imported, so that you can correct it.

## 1. Open the Import Status page

In the sidebar, under **Importation**, select **Import Status**. The page lists the 50 most recent imports, with the newest at the top. Locate your import by the spreadsheet name shown in the **File** column.

![Open the Import Status page](./block_open-import-status.png)

## 2. Follow the progress of the import

While the import is running, the **Completed** column shows **Pending** and the **Processed / Total** column shows how many rows have been processed out of the total in the file. The page does not refresh by itself, so reload it in your browser to see the latest figures. Until the import has finished, the **Failed** column also counts the rows that have not been processed yet, so rely on it only once the **Completed** column shows a time.

## 3. Review the totals and the reasons for failure

When the import has finished, the **Completed** column shows how long ago it ended. The **OK** column gives the number of rows that were saved and the **Failed** column the number of rows that were rejected. Below the number of failed rows, each reason is listed once with the number of rows it affects, for example `12×` followed by the explanation, so that you can see at a glance what needs to be corrected.

## 4. Download the error report

Select **Errors CSV**, either below the number in the **Failed** column or in the **Download** column. The file contains every rejected row with the values it had in your spreadsheet, followed by an `error` column that explains in plain English why that row was rejected, for example that a required value is missing or that the row refers to a record which does not exist yet.

## 5. Correct the rows in your spreadsheet

Using the `error` column of the report as your guide, correct each failed row in your original spreadsheet. Keep the corrected rows in a copy of the spreadsheet that has the same column headings and contains only the rows that failed. Rows that were saved successfully do not need to be imported again.

## 6. Import the corrected rows again

In the sidebar, select **Import Wizard** and work through its steps with the corrected file, choosing the same type of records as before. On the final step, **Confirm and import**, tick **I have reviewed the preview and I want to import these rows.** and select **Start import**.

![Import the corrected rows again](./block_import-again.png)

## 7. Decide how rows that already exist are handled

Before you select **Start import**, decide whether to tick **Overwrite existing records (update rows that already exist instead of skipping them)**. Each row is matched to an existing record by its identifying value, such as a document identifier or a box barcode, so importing the same row again never creates a duplicate. Leave the option unticked for a normal import, in which rows that already exist are skipped and listed among the failed rows with the reason **Skipped — row already exists (skip duplicates enabled)**; tick it only when you intend to update the existing records with the values in your file.

## 8. Check the result of the new import

Return to **Import Status**. The new import appears at the top of the list. When it has finished, confirm that the **Failed** column shows `0`; if any rows are still rejected, repeat the correction for those rows only.

## Tips

> **Tip:** If **Stalled — queue worker?** appears in the **Completed** column instead of **Pending**, the import has been waiting for more than five minutes. Contact your system administrator, because the import cannot proceed until the background queue is running.
>
> **Tip:** The **Validate rows** step of the Import Wizard checks formats, allowed values and required cells before anything is written. Missing parent records and rows that already exist are reported only after the import, on the **Import Status** page.
>
> **Tip:** Immediately after an import starts, the Import Wizard also offers **Download failed rows (CSV)**, which provides the same error report for that import.
>
> **Tip:** An `error` that reports a missing parent record usually means that the records were imported in the wrong order. Import the parent records first, following the order given in the first step of the Import Wizard.
>
> **Tip:** If you deliberately include rows that were already imported, expect them to appear among the failed rows as skipped unless you tick the overwrite option. This is not an error and the existing records remain unchanged.

## Summary

The Import Status page shows the progress and totals of each import and explains why every rejected row failed. Download the error report, correct the failed rows in your spreadsheet and import them again from the Import Wizard; existing records are matched rather than duplicated, and are updated only when you choose to overwrite them.
