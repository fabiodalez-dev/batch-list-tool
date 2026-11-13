# How to import records from a spreadsheet with the Import Wizard

The Import Wizard in the Logistics Tool loads an Excel or CSV batch list into the archive in six guided steps: choose the record type, download the template, upload the completed file, map its columns, validate every row and start the import. Nothing is written to the database until you select **Start import** on the final step, and you are notified under the bell icon in the top bar when the import has finished.

## Prerequisites

- [ ] An account with the Administrator or Super administrator role. The Import Wizard is not available to the ReadingRoom or General roles.
- [ ] The batch list saved as an .xlsx or .csv file of no more than 50 MB.
- [ ] The records each row depends on already exist in the Logistics Tool (see the list below).

## Recommended import order and the records each row needs

- The first step of the wizard states the order in which records should be imported, so that every row finds the records it points to: Subseries, then Authorities, Locations, Batches, Boxes and Documents. Document Types are imported before Documents, and Volumes after Documents.
- Subseries: no other records are needed. A row that names a parent must have its parent on another row of the same sheet or already imported.
- Authorities: no other records are needed.
- Locations: a matching location type, and the repository code if the location belongs to one repository (leave it blank for a location shared by every repository). Parent locations must be imported before their children.
- Batches: at least one repository.
- Boxes: the batch must already exist, because the box import does not create batches. Import the RAS parent boxes first, then the boxes inside them, and enter locations by their location code (for example `SHELF-A3`).
- Document Types: no other records are needed. Import them before documents, because each document row links to a document type by its identifier.
- Documents: the subseries, every authority and any NRA location code on the row must already exist, otherwise the row fails. A batch number that does not yet exist is created during the import.
- Volumes: the documents must already exist, because each row is matched to its document by its document identifier in the repository of the import.
- New Accession: the subseries and any practice named on the row must already exist. The authority, accession, batch and box are created automatically.

## 1. Choose the repository in the top bar

Before you open the wizard, select the repository that the records belong to in the repository selector in the top bar. The import is carried out for the repository selected when you start it. If **All repositories** is selected, document, volume and box imports use the **Default repository** recorded on your user account; with no default repository, document and volume rows are refused, and so are box rows without a batch unless the repository can be taken from their parent RAS box.

![Choose the repository in the top bar](./block_choose-repository.png)

## 2. Open the Import Wizard

In the sidebar, select **Import Wizard** under **Importation**. The wizard opens on its first step, **What are you importing?**, which states the recommended import order.

## 3. Choose the type of records

Under **Type of records**, select the kind of record that your file contains. The text beneath each option states which records must exist before that type is imported.

## 4. Optionally start from a saved profile

If a column mapping has been saved for this record type before, you may choose it in **Start from a saved profile**. Leave the field on **— Start from scratch (use auto-guess) —** to let the wizard match the columns itself, then select **Next**.

## 5. Download the template

On the **Download the template** step, review **What this template contains**, which lists every column of the template together with a note on what it holds. Select **Download template** to save a blank Excel file with the expected column headings, complete it, and then select **Next**.

## 6. Upload the completed file

On the **Upload your filled file** step, drop your .xlsx or .csv file into **Spreadsheet file**. If the workbook contains more than one worksheet, choose the one that holds your data in **Sheet to import**, then select **Next**.

## 7. Preview the rows and map the columns

On the **Preview & map columns** step, check **Detected rows** and **First 10 rows** to confirm that the right data was read. For each field, choose the matching column heading of your file, or **— (skip this column) —**; fields marked with an asterisk are required. When the step reports that all required columns are mapped, select **Next**.

![Preview the rows and map the columns](./block_map-columns.png)

## 8. Validate the rows

On the **Validate rows** step, select **Run validation**. The wizard checks every row without writing anything and lists each failing row with its field and error. You may continue when at least one row is valid; invalid rows are skipped during the import, so correct the spreadsheet first if they matter.

## 9. Confirm the import

On the **Confirm and import** step, check the **Summary** and tick **I have reviewed the preview and I want to import these rows.** Leave **Overwrite existing records (update rows that already exist instead of skipping them)** unticked for a normal import, in which rows that already exist are skipped; tick it only to update existing records deliberately from the file.

![Confirm the import](./block_confirm-import.png)

## 10. Optionally save the column mapping

To reuse this mapping for files with the same layout, tick **Save this column mapping as a reusable profile** and enter a **Profile name**. Tick **Share this profile with the rest of my repository** to make it available to your colleagues.

## 11. Start the import

Select **Start import**. The **Import started** notification confirms how many rows have been queued, the import continues in the background, and the wizard returns to its first step.

## 12. Check the result under the bell icon

When the import has finished, a notification appears under the bell icon in the top bar, even if you have since moved to another page. Select the bell icon to read how many rows were processed and how many failed. If any rows failed, select **Download information about the failed rows** in the notification to obtain them with the reason for each failure.

## Tips

> **Tip:** The notifications are refreshed automatically every 30 seconds, so a finished import appears under the bell icon without reloading the page.
>
> **Tip:** While you remain on the Import Wizard page after starting an import, **Download failed rows (CSV)** at the top of the page also provides the failed rows of that import with the reason for each failure.
>
> **Tip:** The Import buttons on the record lists open the Import Wizard with the matching record type already selected, except on **Document Types**, where you choose the type yourself.

## Summary

Select the repository in the top bar, choose the record type, complete the template, map and validate the columns, and confirm with **Start import**. Import records in the recommended order so that every row finds the records it depends on, and check the notification under the bell icon for the result.
