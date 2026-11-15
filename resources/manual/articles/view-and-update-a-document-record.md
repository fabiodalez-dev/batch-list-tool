# How to view and update a document record

Each document held in the Logistics Tool has its own record page, which brings together its identifiers, classification, creators, logistics, disinfestation details, supporting documents and complete history. This guide explains how to open a document from the Documents list, read each part of its page, correct its details and save them, and raise or review issue flags.

## Prerequisites

- [ ] You are signed in to the Logistics Tool with a role that may view documents, and the repository that holds the document is selected.
- [ ] To change a record, your role must also allow you to edit documents; to raise flags, it must allow you to create document flags.
- [ ] The **History** tab is shown only to users whose role grants access to the audit trail, and the **Audit info** section only to Super administrators.

## 1. Open the document from the Documents list

In the sidebar, under **Records**, select **Documents**. Type an identifier, creator name, subseries code, batch or box number into the search field above the table to narrow the list, then select **View** at the end of the document's row to open its record page. To combine conditions, use the filter rules described in *How to find documents with search and filters*.

![Open the document from the Documents list](./block_open-document.png)

## 2. Review the page header

The page heading shows the document identifier followed by its primary author, for example 10482 — Zammit Paolo. Beside it are the **Edit** button, which opens the edit form, and the **Actions** button, which opens the list of actions available for this record. The view and edit pages present the same sections in the same order.

![Review the page header](./block_review-page-header.png)

## 3. Read the Identification section

**Identification** begins with the **Identifier**, an internal number that the system assigns, followed by **Flag**, which lists the open flags, and **Disinfestation**, which shows the latest disinfestation date, **Current Cycle** or **Not disinfested**. It then shows **Repository**, **Catalogue Identifier**, **Temporary Identifier** and **Authority Reference Code**, where each authority reads as its citing reference code and authorised form of name and links to the authority record. Below these are **Volume**, **Dates**, **Part Number**, **Practice**, **Deeds**, **Document Type**, **Subseries** (code and title), **Torre**, **Accession**, **Note** and **Tracking**, the tracking note.

## 4. Read the Alternate Identification section

**Alternate Identification** holds the other identifiers of the document, and more than one of each may exist: **Conservation Object Reference Number**, **Past volume numbers**, **Past authorities**, **Citation Reference** and **Museum Reference**, together with **Prev Attributed Identifier** and **Past Reference Code** where they apply. Each past volume or past authority shows when it was changed and why, for example `5 (changed 05-11-2026; wrongly attributed)`.

## 5. Check the Logistics section

**Logistics** shows the **Batch**, **Current Box**, **Current Box Type**, **Custody Status**, **Location** and **Museum Location**. Select **Current Box** to open the box record, or **Location** to open the location. When the document has no location of its own, **Location** shows the location of its box with the note **Inherited from the current box — set a location on this document to override it.**

## 6. Check the Disinfestation and Supporting Documents sections

**Disinfestation** shows the **Latest Disinfestation Date**, whether the document is **Currently in Disinfestation** and the **History of Disinfestation**; while the document is in disinfestation, a **Current Cycle** badge appears at the top of the section. Dates throughout the page are shown as day-month-year, for example `05-11-2026`. **Supporting Documents** lists the **Attachments**, the **Digitised** source and the **Digitised link**, which opens in a new tab.

## 7. Review the history tabs

Below the sections, a row of tabs presents the history of the record. **History** lists everything recorded about the document, newest first, in the **When**, **Event**, **What**, **From**, **To** and **By** columns, and **Export history (CSV)** downloads the full chronology. Select **Identifier history**, **Issue flags**, **Barcode history**, **Movement history**, **Location history** or **Itemised contents** to see each log on its own.

## 8. Edit the record and save your changes

Select **Edit** at the top of the record page, change the fields you need, and select **Save changes** at the bottom of the form. The **Batch** and **Current Box** fields are locked on this form; use the **Move to box** action instead, so that every box move is recorded. When you change the volume or remove an authority, the old value is added to **Past volume numbers** or **Past authorities** automatically.

![Edit the record and save your changes](./block_edit-and-save.png)

## 9. Use the actions on the record

Select **Actions** on the record page to move the document with **Move to box** or **Move out of box**, **Send to museum**, **Send to disinfestation** or **Mark disinfested**, **Set subseries**, **Set document type**, or **Add authority**, **Replace authority** and **Detach authority**. Each of these, and the same actions for several documents at once through **Power actions** on the Documents list, is explained in its own article.

## 10. Add an issue flag

On the record page, select **Actions**, then **Add flag**. Choose a **Flag type** and a **Severity**, optionally enter a **Title** and a **Description**, and select **Submit**. If the document already has an open flag of the same type, that flag is refreshed rather than duplicated.

## 11. Review and close existing flags

Select the **Issue flags** tab to see the open flags with their severity, type, title, status, **Flagged by** and **Flagged** date; change the status filter to **All (incl. closed)** to include closed flags. On a flag row, select **Acknowledge** to confirm that it is being handled, **Mark resolved** to close it with the required **Resolution notes**, or **Dismiss** to close it as not actionable.

## Tips

> **Tip:** Select the **Batch**, **Current Box**, **Location**, **Subseries**, **Accession** or an authority on the record page to open that record directly.
>
> **Tip:** A dash means that no value has been recorded; you can complete the field from the edit form.
>
> **Tip:** On the edit form, a flag removed from the **Flag** field is marked resolved on the Document Flags page.
>
> **Tip:** Use the **Event** filter on the **History** tab to show only one kind of change, such as box moves or flags.

## Summary

You can now open a document from the Documents list, read each section of its record page, review its history, correct its details and save them, and raise, acknowledge, resolve or dismiss its issue flags.
