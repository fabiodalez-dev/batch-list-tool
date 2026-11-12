# How to maintain subseries, creators, batches and other reference lists

Every document in the catalogue points to reference records: the subseries it is filed under, the creator who produced it, the batch and notary accession it arrived with, and controlled values such as its document type and the type of box that holds it. This guide explains how an administrator creates, edits and deactivates entries in these lists, and the order in which they should be filled.

## Prerequisites

- [ ] An account with the Administrator role. Several lists under **Classifications**, including **Current Box Types**, **Custody Status Types** and **Entity Types**, open only for these roles.
- [ ] At least one repository, because batches and notary accessions always belong to a repository.

## 1. Plan the order of entry

Fill a list before the records that refer to it. Begin with the controlled values under **Classifications**, such as **Accession Types**, **Entity Types** and **Document Types**, then add **Subseries** and **Authorities**, then **Batches** and **Notary Accessions**, and only then boxes and documents. The **Import Wizard** follows the same order (subseries, authorities, batches, boxes, documents), and document types must be imported before documents because each document row refers to its document type by identifier.

## 2. Create a subseries

Under **Records**, select **Subseries**, then select the "New subseries" button at the top right of the list. Enter the **Identifier** and the **Standard title in English (Plural)**; both are required and the identifier must be unique. To place the entry beneath another one, choose it in **Parent subseries**, or leave the field empty for a top-level subseries, then select **Create**.

![Create a subseries](./block_create-subseries.png)

## 3. Record a creator in Authorities

Select **Authorities**, then the "New authority" button. In **Control Area**, enter the **Citing Reference Code**, which is required and unique; the **NAM Authority Reference Code** is optional. In **Identity Area**, choose the **Type of Entity**, such as **Notary** or **Person**, enter the **Creator Surname** and **Creator Name**, and select **Create**. When **Authorised Form of Name** is left blank, it is completed on saving with the surname first, followed by the name, any suffix and the years of activity, for example "Sample, Anna Maria, 1820-1865".

![Record a creator in Authorities](./block_create-authority.png)

## 4. Create a batch and its notary accession

Select **Batches**, then the "New Batch" button. The **Batch Number** is pre-filled with the next free number, and reserved numbers are refused. Choose the **Accession Type** and the **Repository**, link any existing accessions in **Linked Accessions**, and select **Create**. To record the accession itself, open **Notary Accessions** under **Operations**, select the "New Notary Accession" button, enter the **Title** and **Accession Date**, choose the **Batches** and the **Repository**, and select **Create**. The creator is recorded on each document rather than on the accession.

## 5. Add an entry to a lookup list

Under **Classifications**, open the list concerned, for example **Current Box Types**, and select the "New current box type" button at the top right of the list. Enter the **Identifier**, which is the value stored on records, and the **Label** shown in drop-down lists, then select **Create**. The position of an entry in drop-down lists follows **Sort order**; on lists that allow it, rows can also be dragged into a new order. On **Document Types**, the **Identifier** is pre-filled with the next free code in the form DT00001.

![Add an entry to a lookup list](./block_add-lookup-entry.png)

## 6. Edit an entry

Select **Edit** on the row, change the values and select **Save changes**. The identifier of an entity type or a custody status type cannot be changed while records still use it, because records store that identifier.

## 7. Deactivate an entry that is no longer used

On a lookup list under **Classifications**, select **Deactivate** on the row, then select **Confirm**. The value is hidden from new records, while existing records that already use it are unaffected; select **Activate** to make it available again. For **Document Types** and **Practices**, open the entry and select **Deactivate**, or tick several rows and choose **Deactivate selected**. For **Subseries** and **Batches**, switch off **Is active** on the edit form and select **Save changes**; an inactive batch remains listed but is no longer offered when creating boxes or documents.

## 8. Remove a creator or an accession

**Authorities** and **Notary Accessions** have no active setting, so an entry that is no longer wanted is deleted instead. On the **Authorities** list, the **Delete** action appears only for a creator with no documents attached, so that no document loses its creator.

## Tips

> **Tip:** If a subseries identifier is reported as already in use, the subseries may have been deleted: apply the **Archived (deleted) subseries** filter on the **Subseries** list to find it and restore it.
>
> **Tip:** An entity type that authorities still use, or a custody status type that documents still hold, cannot be deleted. Deactivate it instead.
>
> **Tip:** Select **Download template** on the **Authorities** list to obtain a spreadsheet whose column headings match the import.

## Summary

Fill the controlled values first, then subseries and authorities, then batches and notary accessions, so that every later record finds its references. Keep values that records already use by deactivating them rather than deleting them.
