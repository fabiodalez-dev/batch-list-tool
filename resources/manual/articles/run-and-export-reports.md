# How to run and export reports

The Reports page gathers the canned reports of the Logistics Tool in one place. This guide explains how to open a report, narrow it with filters, read and sort the results, export them to CSV, Excel or PDF, and save the configuration as a template for later use.

## Prerequisites

- [ ] A Logistics Tool account whose role includes permission to view reports. The built-in roles Super administrator, Administrator, ReadingRoom and General all include it; without it, **Reports** does not appear in the sidebar.
- [ ] The repository you wish to report on selected as the active repository in the top bar, where your account can see more than one repository.

## 1. Open the Reports page

In the sidebar, under the **Reports** group, select **Reports**. The page lists the **Canned reports** as cards; each card gives the report title, a one-line description and a short count, such as the number of documents or locations, so that you can judge the size of the report before opening it.

![Open the Reports page](./block_open-reports.png)

## 2. Choose a report

Select the card of the report you require, for example **Stock take (by location)**. The report opens on its own page, with its actions in the header and its results in a table below, while **Reports** remains highlighted in the sidebar so that you can return to the list of reports at any time. Select **About this page** under the title for a fuller description of what the report includes.

![Choose a report](./block_choose-report.png)

## What each report is for

- **Stock take (by location)**: lists every box not destroyed, either **Per box** (batch and box, or NRA / MUS / MAV / STVC number, with the current box type and location) or **Per document** (catalogue identifier, Actual Authority Reference Code, volume, creator and dates), grouped by location.
- **Documents by location**: every document with its effective location and the box it sits in, or only the location if it is no longer in a box.
- **Documents by batch**: the number of documents in each batch.
- **Documents by creator / notary**: the number of documents for each authority (notary).
- **Documents by subseries**: the number of documents in each subseries.
- **Boxes ready to be destroyed**: RAS boxes whose documents all have a catalogue identifier, which can therefore be marked as destroyed.
- **Documents pending disinfestation**: documents without a disinfestation date that are not in a PERM_OUT box.
- **Boxes pending disinfestation**: boxes at status IN that hold at least one document (In Box) without a disinfestation date. You can select boxes in this report and choose **Send to disinfestation**.
- **Document movement history**: documents moved between boxes, showing when, from which box to which, why and by whom.
- **Flags by type**: for each flag type and severity, the number of documents with an open or acknowledged flag and the number with a resolved or dismissed flag.
- **RAS ↔ NRA reconciliation**: compares an uploaded RAS report with the RAS boxes in the tool and lists only the discrepancies. This report is described in *How to reconcile RAS barcodes with an uploaded list*.
- **RAS key on documents**: the RAS batch, RAS box and latest barcode IN of each document, flagging documents whose RAS key is incomplete.
- **Mass migration**: the boxes that hold at least one document of the chosen series, document type and Actual Authority Reference Code range; the whole box is listed.
- **Activity over time**: month-by-month charts of the collection, the changes recorded, moves, disinfestations and cataloguing, for the months you choose with **From month** and **To month**. This report is shown as charts and has no export.

## 3. Set the filters

In the **Stock take** report, select **Per box** or **Per document** in the header to choose the view. Select the **Filter** icon above the table, then set the filters you need, for example a **Location** or a **Box type**, or switch on **Include documents kept outside their box (Not in Box / Mounted / Conservation Box)**. Select **Apply filters** to update the results, or **Reset** to clear them.

![Set the filters](./block_set-filters.png)

The subseries filter is labelled **Subseries** in **Documents by batch**, **Documents by creator / notary**, **Documents by subseries** and **Documents pending disinfestation**, where the column of the same name also appears. In the **Per document** view of the stock take and in **Mass migration**, the filter is labelled **Series (with its subseries)**: choosing a series also includes the documents of its subseries.

## 4. Read and sort the results

Type in **Search** to find a particular box, barcode or document within the results. Select a column heading that can be sorted, such as **Batch / Box**, to sort by it, and select it again to reverse the order. In the stock take, each location heading shows how many boxes or documents it holds; dates are shown as dd-mm-yyyy, and the **Per page** selector at the foot of the table shows 25, 50 or 100 rows.

## 5. Export the results

Select **Export CSV**, **Export Excel** or **Export PDF** in the report header. Every export contains the rows that the filters and the search currently show on screen. The file is downloaded by your browser.

## Export limits

- A PDF is printed on A4 paper, holds at most 1,000 rows and is laid out in tables of 50 rows, each with its own header row. When there are more rows, the PDF states how many it left out; use **Export Excel** or **Export CSV** for the complete list.
- CSV and Excel exports of the reports that list individual boxes, documents or movements hold up to 50,000 rows. Beyond that, an **Export truncated** warning appears; add filters to reduce the list.
- **Flags by type** offers **Export CSV** and **Export PDF** only, and **Activity over time** has no export.

## 6. Save the report as a template

To reuse the current filters and sort order, select **Save as template** in the report header (available on every report except **RAS ↔ NRA reconciliation** and **Activity over time**). Enter a name and, optionally, a description, switch on **Share with my repository** if other users in your repository should also see the template, and select **Submit**. The template then appears under **Saved templates** on the **Reports** page; selecting it opens the report with the saved filters and sort order restored.

## Tips

> **Tip:** To rename, review or delete a saved template, open **Report Templates** in the sidebar under the **Reports** group.
>
> **Tip:** A stock take template also remembers whether it was saved **Per box** or **Per document**.
>
> **Tip:** For large reports, apply filters before exporting to PDF, so that the 1,000-row limit covers the rows you need.

## Summary

Open **Reports**, choose a report card, narrow the results with the filters and the search, and export them with **Export CSV**, **Export Excel** or **Export PDF**. Save configurations that you use regularly with **Save as template** so that they are available from **Saved templates**.
