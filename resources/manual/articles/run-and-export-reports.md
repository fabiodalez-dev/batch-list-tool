# How to run and export reports

The **Reports** page gathers the pre-built summaries of the archive in one place. This guide explains how to open a report, narrow its results with filters, read the table and export the results to CSV, Excel or a print-friendly PDF.

## Prerequisites

- [ ] Your account has permission to view reports. If **Reports** does not appear in the sidebar, contact your administrator.
- [ ] If you work across several repositories, select the repository you require in the top bar, or leave **All repositories** selected. Users who are not administrators see only the data of their own repository.

## 1. Open the Reports page

In the sidebar, under the **Reports** group, select **Reports**. The page lists the **Canned reports** as cards; each card shows the report title, a short description and a current count. Select the card of the report you wish to run.

![Open the Reports page](./block_open-reports.png)

## Available reports

- **Stock take (by location)**: every box and document with its identifiers, latest batch and box numbers and NRA location, for verifying what is held and where.
- **Documents by location**: every document with its effective location and the box it sits in, or the location alone if it is no longer in a box.
- **Documents by batch**: the number of documents in each batch.
- **Documents by creator / notary**: the number of documents attributed to each authority (notary).
- **Documents by subseries**: the number of documents in each subseries.
- **Boxes ready to be destroyed**: boxes whose documents all have a catalogue identifier and can therefore be marked as destroyed.
- **Documents pending disinfestation**: documents without a disinfestation date that are not permanently out, with the number of days each has been waiting.
- **Box movement history**: a chronological log of transfers between boxes, with the date, reason and the user who performed each move.
- **Flags by type**: the number of open and resolved issue flags in each category and severity.
- **RAS ↔ NRA reconciliation**: the RAS batch, box and barcode of each document beside its current NRA batch and box, marking the rows that cannot be reconciled.
- **Activity over time**: four monthly charts covering the last twelve months: changes recorded, moves, disinfestations and documents catalogued.

## 2. Set the report filters

Select the **Filter** button at the top right of the table to open the **Filters** panel. Choose the values you require, for example a **Stock row type** or one or more entries under **NRA location**, then select **Apply filters**; **Reset** clears every filter. To find a specific record, type an identifier or box number in the **Search** box.

![Set the report filters](./block_set-filters.png)

## 3. Read the results

The table updates to show only the matching rows, and the filters in use are listed under **Active filters** above the table. Select a column heading to sort by that column. The footer shows the number of results, and the **Per page** list sets how many rows each page displays.

## 4. Export the results

Select **Export CSV**, **Export Excel** or **Export PDF** at the top of the report. The file contains the rows that match the filters and search currently applied on screen, not the whole archive. The **Flags by type** report offers CSV and PDF only, and **Activity over time** is viewed on screen only.

![Export the results](./block_export-results.png)

## 5. Print the report

To print a report, select **Export PDF** and open the downloaded file. The PDF is laid out on A4 paper and records the report title, the date and time it was generated and the user who generated it. Print it from your PDF viewer.

## Tips

> **Tip:** Select **Save as template** to store the current filters and sort order under a name. Saved templates appear under **Saved templates** on the Reports page and reopen the report in the same state; enable **Share with my repository** to make a template available to colleagues in your repository.
>
> **Tip:** CSV and Excel exports are limited to 50,000 rows and PDF exports to 5,000 rows. CSV and Excel exports show a notification when they are shortened, whereas PDF exports are cut at 5,000 rows without notice; apply narrower filters and export again.
>
> **Tip:** Apply filters before exporting a large report. The on-screen filters determine the content of the file.

## Summary

Open **Reports** from the sidebar, select a report card, apply the filters you require and review the table. Then select **Export CSV**, **Export Excel** or **Export PDF** to obtain a file of exactly the rows shown, and use the PDF when a printed copy is needed.
