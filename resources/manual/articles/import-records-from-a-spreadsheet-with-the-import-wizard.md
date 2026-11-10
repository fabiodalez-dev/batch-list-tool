# How to import records from a spreadsheet with the Import Wizard

The **Import Wizard** loads an Excel or CSV batch list into the archive in a single guided process, so that subseries, authorities, batches, locations, boxes and documents do not have to be entered one by one. This guide explains each step of the wizard and the order in which record types should be imported.

## Prerequisites

- [ ] An account with the administrator or super administrator role. The **Import Wizard** is not available to the ReadingRoom or General roles.
- [ ] A batch list saved as an `.xlsx` or `.csv` file of no more than 50 MB, with one header row followed by the data rows.
- [ ] The records on which your file depends already imported, as described in the recommended import order below.

## Follow the recommended import order

Each record type refers to records of an earlier type, so import your files in this order: **Subseries**, **Authorities**, **Batches**, **Locations**, **Boxes** and finally **Documents**. A box row, for example, refers to its batch and to a location code, and fails if either does not exist. A document row fails if its subseries or authority does not exist; a missing batch or box is created automatically, so import batches and boxes first to avoid unintended new records.

## 1. Select the repository and open the wizard

In the top bar, open the repository selector and choose the repository that the records belong to, rather than **All repositories**. The import records this selection, and records that carry no repository of their own are assigned to it. Then select **Import Wizard** under **Importation** in the sidebar.

![Select the repository and open the wizard](./block_choose-repository.png)

## 2. Choose the type of records

On the **What are you importing?** step, select the entity your file contains under **Type of records**. If you have saved a column mapping before, you may choose it in **Start from a saved profile**; otherwise leave it empty and the wizard matches the columns automatically. Select **Next** to continue.

## 3. Download the template

On the **Download the template** step, review the list under **What this template contains**, which describes every column the import expects. Select **Download template** to obtain a blank Excel file with the exact column headers, copy your batch list into it, and then select **Next**.

![Download the template](./block_download-template.png)

## 4. Upload the completed file

On the **Upload your filled file** step, drag the completed spreadsheet onto **Spreadsheet file**, or select **Browse** to choose it from your computer. If the workbook contains more than one worksheet, choose the one that holds your data in **Sheet to import**, and then select **Next**.

![Upload the completed file](./block_upload-file.png)

## 5. Preview the rows and map the columns

On the **Preview & map columns** step, check the **Detected rows** count and the **First 10 rows** of your file. Below the preview, each importer field shows the spreadsheet header it has been matched to; choose a different header where the guess is wrong, or **— (skip this column) —** for data you do not wish to import. Fields marked with an asterisk are required, and the wizard does not continue until every one of them is mapped.

## 6. Validate the rows

On the **Validate rows** step, select **Run validation**. Every row is checked against the import rules and nothing is written to the database; correct any reported errors in the spreadsheet and upload it again if necessary, then select **Next**.

## 7. Confirm and start the import

On the **Confirm and import** step, check the **Summary**, then tick **I have reviewed the preview and I want to import these rows.** Leave the overwrite option unticked for a normal import, so that rows which already exist are skipped rather than rewritten. Select **Start import**; the wizard confirms with an **Import started** notification and returns to the first step.

## Tips

> **Tip:** Import **Document Types** before **Documents**, because each document row is linked to a document type by its identifier.
>
> **Tip:** Tick **Save this column mapping as a reusable profile** on the final step when you expect to import further files with the same layout; next time, choose the profile in **Start from a saved profile**.
>
> **Tip:** Follow the progress of a running import, and download any rows that failed, from **Import Status** under **Importation**.

## Summary

You have selected the repository, chosen the record type, prepared your file from the template, mapped and validated its columns, and started the import. Repeat the process for each record type in the recommended order until the whole batch list has been loaded.
