# How to view and update a document record

Each document in the archive has its own record page, which brings together its identifiers, classification, creators, logistics, disinfestation details, supporting documents and complete history. This guide explains how to open a document from the Documents list, read each part of the record, correct its details, use the actions on the record and raise or review issue flags.

## Prerequisites

- [ ] You are signed in to the archive tool with a role that may view documents.
- [ ] To change a record, your role must also allow you to edit documents; to raise flags, it must allow you to create document flags.
- [ ] The **History** tab is shown to users whose role grants access to the audit trail; the **Audit info** section is shown to super-administrators only.

## 1. Open the document from the Documents list

In the sidebar, under **Records**, select **Documents**. Type an identifier, creator name, subseries code, batch or box number into the **Search** field above the table to narrow the list, then select the row of the document you require to open its record page. The **View** link at the end of each row opens the same page.

![Open the document from the Documents list](./block_open-document.png)

## 2. Review the page header

The page heading shows the document identifier followed by its primary author, with the **Edit** and **Actions** buttons beside it. The **New**, **Edit** and **View** pages use the same sections in the same order.

![Review the page header](./block_review-page-header.png)

## 3. Read the Identification section

The **Identification** section shows the **Identifier**, an internal number that the system fills in and that cannot be edited, then the **Flag**, the **Disinfestation** indication, **Repository**, **Catalogue Identifier**, **Temporary Identifier** and **Authority Reference Code**, which reads as the citing reference code followed by the authorised form of name, for example `R611: Muscat, Elizabeth Amalia, 1983-1985`. Below these are **Volume**, **Dates**, **Part Number**, **Practice**, **Deeds**, **Document Type**, **Subseries**, **Torre**, **Accession**, **Document Note** and **Tracking Note**. On the edit form, **Dates as** asks whether to enter the dates as years, as dates or as free text.

## 4. Read the Alternate Identification section

**Alternate Identification** holds the other identifiers of the document: **Conservation Object Number**, **Past volume numbers**, **Past authorities**, **Past Reference Code**, **Citation Reference** and **Museum Reference**. A field with several values separates them with `; `. When you change the volume on the edit form, the old one is added to **Past volume numbers**. When you use **Replace authority** or **Detach authority**, the old authority is added to **Past authorities**.

## 5. Check the Logistics section

**Logistics** shows the **Batch**, **Current Box**, **Current Box Type**, **Custody Status**, **Location** and **Museum Location**. Select **Current Box** to open the box record, which holds the box details and its history. When the document has no location of its own, it shows the location of its box.

## 6. Check the Disinfestation and Supporting Documents sections

**Disinfestation** shows the **Latest Disinfestation Date**, whether the document is **Currently in Disinfestation**, and the **History of Disinfestation**. While a document is in disinfestation, the Documents list shows **Current Cycle** instead of a date. **Supporting Documents** holds the **Attachments**, **Digitised** and **Digitised link**. Where it is shown, **Audit info** records when the document was created and last updated.

## 7. Review the history tabs

Below the sections, a row of tabs presents the history of the record. **History** lists every recorded change, newest first, with **When**, **Event**, **What**, **From**, **To** and **By** columns, and **Export history (CSV)** downloads the full chronology. Select **Identifier history**, **Issue flags**, **Barcode history**, **Movement history**, **Location history** or **Itemised contents** to see each log on its own. Older movement data recorded on the document is in **Movement history**.

## 8. Edit the record and save your changes

Select **Edit** at the top of the record page, change the fields you need, and select **Save changes** at the bottom of the form. The batch and box cannot be changed here; use **Actions** > **Move to box** instead, so that every box move is recorded.

![Edit the record and save your changes](./block_edit-and-save.png)

## 9. Use the actions on the record

Select **Actions** on the record page. To apply the same action to several documents, tick them in the Documents list and open **Power actions** above the table.

- **Move to box** moves the document to another box, which you find by typing its name, for example `NRA 5`. Boxes with no batch are included. Leave **Location (optional)** empty to use the location of the target box.
- **Move out of box** takes the document out of its box and sets its **Custody Status** and **Location**.
- **Send to museum** asks for the **Museum / showcase**, the **Museum reference code** and the **Custody Status**, and takes the document out of its box.
- **Send to disinfestation** and **Mark disinfested** start and finish the disinfestation of the document.
- **Set subseries** and **Set document type** change the classification.
- **Add authority**, **Replace authority** and **Detach authority** change the authorities. **Replace authority** offers only an authority shared by every selected document.

## 10. Add an issue flag

On the record page, select **Actions**, then **Add flag**. Choose a **Flag type** and a **Severity**, optionally enter a **Title** and a **Description**, and select **Submit**. If the document already has an open flag of the same type, that flag is refreshed rather than duplicated.

## 11. Review and close existing flags

Select the **Issue flags** tab to see the flags that still require attention, with their severity, type, title, status and the user who raised them; change the status filter to show resolved and dismissed flags as well. On a flag row, select **Acknowledge** to confirm that it is being handled, **Mark resolved** to close it with the required **Resolution notes**, or **Dismiss** to close it as not actionable.

## Tips

> **Tip:** Select the **Batch**, **Current box**, **Location** or an authority on the record page to open that record directly.
>
> **Tip:** Fields marked with a dash have no value recorded; they can be completed from the edit form.
>
> **Tip:** Use the **Event** filter on the **History** tab to show only one kind of change, such as box moves or flags.

## Summary

You can now open a document from the Documents list, read each section of its record, review its full history, correct its details and save them, move it with the actions on the record, and raise, acknowledge, resolve or dismiss its issue flags.
