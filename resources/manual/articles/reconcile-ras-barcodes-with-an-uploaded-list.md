# How to reconcile RAS barcodes with an uploaded list

The **RAS ↔ NRA reconciliation** report compares the list received from RAS with the RAS boxes recorded in the Logistics Tool. You upload the RAS export, and the report matches every line by barcode, batch / box and status, so that you can review only the rows that do not tally, correct the records and export the result.

## Prerequisites

- [ ] A role that allows you to view reports. To upload a list or to compare it again, your role must also allow you to edit boxes; otherwise the upload and comparison buttons are not shown.
- [ ] The export received from RAS, saved as an Excel file (`.xlsx` or `.xls`) or a CSV file of no more than 20 MB.
- [ ] The repository whose RAS boxes you wish to check selected in the repository switcher, because the report compares the list with the RAS boxes of the active repository only.

## 1. Open the reconciliation report

In the sidebar, select **Reports**. On the Reports page, select the **RAS ↔ NRA reconciliation** card; the card also shows the date of the last upload, or **No upload yet**. While the report is open, **Reports** remains highlighted in the sidebar.

## 2. Upload the list received from RAS

Select **Upload RAS report**. In the **Upload the RAS report** dialogue, add the file to the **RAS report** field, then select **Compare**. The report reads the barcode from Column 1, the RAS reference in the form ARK/box-batch from Column 4 (for example, ARK/123-9 is box 123 of batch 9) and the status from Column 6; a sheet with the headers Barcode, Reference and Status is also accepted.

![Upload the list received from RAS](./block_upload-the-ras-list.png)

## 3. Review the summary of the comparison

When the comparison is complete, a notification states how many RAS rows were compared, how many are reconciled and how many are discrepancies. Above the table, the report shows the name of the file, who uploaded it and when, followed by one tile per result with its number of rows. Select a tile to list only the rows with that result, or select the **Reconciled** tile to list every row.

![Review the summary of the comparison](./block_review-the-summary.png)

## 4. Read the comparison

Each row sets one line of the RAS list beside the matching box in the Logistics Tool. **RAS barcode**, **RAS batch / box** and **RAS status** show what RAS reported; **Our barcode**, **Our batch / box** and **Our status** show our box, and selecting either of the first two opens that box. **Result** states the outcome of the comparison in a coloured badge and **Note** explains it, for example by giving the barcode our box carries now; when the statuses disagree, both status values are shown in red.

## What each result means

- **Reconciled**: the RAS barcode is the current barcode of one of our RAS boxes, or an earlier barcode kept in that box's barcode history, and both the batch / box and the status agree. For an earlier barcode, our status is the one the box had when that barcode was replaced.
- **Batch / box differs**: the barcode matches one of our boxes, but the RAS reference does not match that box's batch and box number, or cannot be read as ARK/box-batch.
- **Status differs**: the barcode and the batch / box match, but the RAS status (In, Out or Perm Out) is not the barcode status of our box (IN, OUT or PERM_OUT), or RAS gives a status that is not recognised.
- **Barcode differs**: RAS lists a barcode that none of our boxes has, but one of our RAS boxes has the same batch and box number; the note gives the barcode of our box.
- **Earlier barcode not in our history**: as for Barcode differs, but the current barcode of our box also appears on the RAS list, so RAS is listing an earlier barcode that our barcode history does not record, typically a Perm Out container.
- **Only in RAS**: neither the barcode nor the batch / box matches any of our RAS boxes.
- **Only in our system**: one of our RAS boxes whose current barcode does not appear on the RAS list and that no Barcode differs row already points to.
- The badge colours help you to scan the list: green for Reconciled, amber for Status differs and Barcode differs, red for Batch / box differs, blue for Only in RAS and Only in our system, and cream, the neutral colour, for Earlier barcode not in our history. The same colours are used on the summary tiles.

## 5. Show discrepancies only, or every row

The report opens with **Discrepancies only** switched on, so reconciled rows are hidden. To change the selection, open the table filters, switch **Discrepancies only** on or off and, if required, choose one or more results under **Discrepancy**, then select **Apply filters**. With **Discrepancies only** switched on, an empty table means that everything tallies.

![Show discrepancies only, or every row](./block_show-discrepancies-only.png)

## 6. Compare again after correcting the records

Correct the boxes concerned, for example their barcode, barcode status or box number, then return to the report and select **Compare again**. The same RAS list is checked against our records as they are now, without uploading the file again, and the summary, the table and the **Last compared** time are updated.

## 7. Export the result

Select **Export CSV**, **Export Excel** or **Export PDF**. Each export contains the rows currently listed, so the filters and the search you have applied are respected. The CSV and Excel files contain every column, including the separate RAS batch and RAS box values and the row of the RAS file; the PDF lists the first 100 rows only, so use **Export Excel** for the complete list.

## Tips

> **Tip:** Only the latest upload is kept for each repository. Uploading a new list replaces the previous one for every user of that repository.
>
> **Tip:** Leading zeros and letter case are ignored when comparing: batch 04 and box 054 match batch 4 and box 54, and box 9a matches box 9A.
>
> **Tip:** The **Row in file** and **RAS container** columns are hidden by default. Show them from the table's column selector to find a line in the original RAS file.

## Summary

Upload the RAS export with **Upload RAS report**, review the discrepancies by result, correct the records and use **Compare again** until the list tallies. Export the result as CSV, Excel or PDF to keep a record of the reconciliation.
