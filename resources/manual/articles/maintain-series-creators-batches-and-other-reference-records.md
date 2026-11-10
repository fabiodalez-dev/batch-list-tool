# How to maintain series, creators, batches and other reference records

Every box and document in the catalogue refers to entries in a set of reference lists: Subseries, Authorities (the creators of records, such as notaries), Batches, Notary Accessions, Document Types, Practices and the classification vocabularies. This guide explains how administrators create, edit and deactivate these entries, and why they must be in place before boxes and documents are imported.

## Prerequisites

- [ ] You are signed in with an administrator account that is permitted to create and edit reference records.
- [ ] You know which repository the new entry belongs to, or that it is to be shared by every repository.
- [ ] You have checked that the entry does not already exist, including among deleted entries.

## Where the reference lists are found

- Under **Records** in the sidebar: **Subseries**, **Batches**, **Authorities** and **Practices**.
- Under **Operations**: **Notary Accessions**, which record each accession received from a notary.
- Under **Classifications**: **Document Types** and the controlled vocabularies **Accession Types**, **Barcode Statuses**, **Box Typology**, **Flag Types**, **Location Typology**, **Physical Box Type**, **Custody Status Types** and **Entity Types**.
- Volumes are not kept as a separate list. The volume number is recorded on each document in the **Volume** field.

## 1. Open the list and start a new entry

In the sidebar, under **Records**, select **Subseries**. The list shows each entry with its **Identifier**, its **Standard title in English (Plural)** and its position in the **Hierarchy**. Select **New Subseries** to add an entry; the other lists provide an equivalent button in the same position.

![Open the list and start a new entry](./block_open-list.png)

## 2. Complete the form and create the entry

Enter a unique **Identifier** and the **Standard title in English (Plural)**, then choose the **Parent subseries**, or leave it empty for a top-level subseries. Under **Repository & document types**, choose the **Repository**, or leave it empty for a subseries shared by all repositories, and select the permitted **Document types**. Select **Create** to save the entry.

![Complete the form and create the entry](./block_complete-form.png)

## Required details in the other lists

- **Authorities**: the **Citing Reference Code**, **Creator Surname**, **Creator Name** and **Type of Entity** are required. The options of **Type of Entity** are kept on the **Entity Types** page. The form is divided into **Control Area**, **Identity Area** and **Description Area**, and **Authorised Form of Name** is built automatically on saving and can be edited. The **NAM Authority Reference Code** is optional but must be unique.
- **Batches**: the **Batch Number**, the **Accession Type** and the **Repository** are required. Accessions are linked under **Linked Accessions**.
- **Notary Accessions**: the **Title** and the **Repository** are required. The **Accession Number** follows the format `2025-124`, and the related batches are chosen under **Batches**.
- **Document Types**: the **Identifier** is pre-filled with the next free code in the form `DT00001`, and the **Name** must be unique.
- **Practices**: the **Name** is required and must be unique. The optional **Identifier** is used to match the practice during import.

## 3. Edit an entry

In the list, select **Edit** on the row of the entry. Amend the values and select **Save changes**.

## 4. Deactivate an entry that is no longer in use

Deactivate an entry rather than delete it, so that the records which already refer to it remain readable. For Subseries, Batches, Practices and Document Types, open the entry with **Edit**, switch off **Is active** and select **Save changes**. Authorities and Notary Accessions cannot be deactivated. In **Document Types** and **Practices**, you may instead select several rows, open **Bulk actions** and choose **Deactivate selected**.

## 5. Deactivate a classification value

In a list under **Classifications**, such as **Box Typology**, select **Deactivate** on the row of the value and select **Confirm**. The value is hidden from new records, while existing records that use it are unaffected; select **Activate** on the same row to make it available again.

![Deactivate a classification value](./block_deactivate-classification.png)

## Why the import order matters

Each imported row is linked to reference entries that must already exist, so import them in this order, as recommended on the first step of the **Import Wizard**: Subseries, Authorities, Batches, Locations, Boxes, Document Types and finally Documents. The wizard does not enforce the order, but rows that refer to entries not yet imported fail or create unintended records. A document row is matched to its subseries, authority, batch, box and document type, and a row that names a subseries which does not yet exist fails with an error. Import volumes only after the documents they belong to.

## Tips

> **Tip:** If an identifier is reported as already in use, set the **Archived (deleted) subseries** filter on the **Subseries** list to find the deleted entry and select **Restore**.
>
> **Tip:** An authority can be deleted only when no documents are attached to it; otherwise, the row offers no **Delete** action.
>
> **Tip:** A custody status type or an entity type that records are using cannot be deleted or re-coded.
>
> **Tip:** An inactive batch remains listed and editable, but it is no longer offered when new boxes or documents are created.
>
> **Tip:** Use **Download template** on a list to obtain a spreadsheet whose column headers match the import.

## Summary

Reference entries are created and edited from their own lists, and entries that are no longer needed are deactivated rather than deleted. Keeping Subseries, Authorities and Batches complete before boxes and documents are imported ensures that every imported row can be linked to its reference records.
