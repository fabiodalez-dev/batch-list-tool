# How to manage boxes and their barcode status

Every box held by the Notarial Archives has its own record in the Logistics Tool, with its type, batch, number, barcode, barcode status, seal and location. This guide explains how to find a box, read its page, correct its details and use the box actions, so that the record always matches the physical box on the shelf.

## Prerequisites

- [ ] A user account with permission to view boxes. Editing a box or changing its barcode status also requires permission to update boxes; **Change batch** requires permission to update both boxes and documents.
- [ ] Permission to delete boxes, if you need to mark boxes as physically destroyed.
- [ ] The repository you work in selected in the repository switcher at the top of the page.

## 1. Open the Boxes list and search it

In the sidebar, under **Records**, select **Boxes**. To find a particular box, type its box number, barcode or seal number in the search field above the list.

![Open the Boxes list and search it](./block_open-the-boxes-list.png)

## 2. Choose the columns you need

By default the list shows **Batch**, **Box number**, **Barcode**, **Barcode status**, **Box type**, **Location**, **Tracking Note**, **Seal Number**, **Notes**, **Destroyed**, **Parent box**, **Is legacy** and **Inputter**. Use the column manager above the list to show hidden columns, such as **Current Box Type**, or to hide those you do not need; column headings can also be dragged into a different order. A box without a barcode shows a dash under **Barcode status**, and a box without a location of its own shows the location shared by its documents, marked `from documents`.

## 3. Narrow the list with filter rules

Select the filter icon above the list to open the **Filters** panel, select **Add rule** and choose a field, for example **Barcode status** or **Box type**, then set its condition. Every further rule begins with **AND**, **OR** or **AND NOT**, which joins it to the rules above it, read from top to bottom. Select **Apply filters** to run the rules; set **Archived (deleted) boxes** if deleted boxes should also be listed.

## 4. Open a box

Selecting a row opens the **Documents** list filtered to that box, including the documents that have since moved on into an In Situ box; **View documents** at the end of the row does the same. To open the page of the box itself, select **View** at the end of the row.

## 5. Read the box page

The **Details** section shows the box type, **Current Box Type**, **Batch**, number, barcode, **Seal Number**, **Barcode status** and **Location**, together with the counts **Documents**, **Documents, including those moved on** and **Child IN_SITU boxes**. Below it, the **History** tab lists everything recorded about the box in one chronology, and the **Barcode history**, **Seal history** and **Location history** tabs show each log on its own.

![Read the box page](./block_read-the-box-page.png)

## 6. Edit the box type, batch and number

On the box page, select **Edit**. Choose the **Box type**; MAV and STVC are legacy types that remain editable on existing boxes but cannot be given to new ones. Select the **Batch**, which is required for a RAS box and optional for an IN_SITU or NRA box, then enter the **Box number**; for an IN_SITU or NRA box the same field is labelled **Box identifier** and takes a value such as `NRA1`.

![Edit the box type, batch and number](./block_edit-type-batch-number.png)

## 7. Set the parent RAS box of an IN_SITU or NRA box

For an IN_SITU or NRA box, select the **Parent box batch** first and then its box in **Parent RAS box**. Use **Additional parent boxes** when the box was assembled from several RAS boxes, and tick **Provenance Unknown** only if the RAS box of origin is genuinely unknown.

## 8. Edit the barcode, barcode status, seal and location

Enter the **Barcode**, which is required for a RAS box and must not be used by any other box, then choose the **Barcode status**, which only RAS boxes have. Enter the **Seal Number** of the yellow security seal and, if needed, choose a **Location (RFQ §3.1.9)**. Select **Save changes** to record the changes.

## Barcode status rules

A RAS box starts at IN and can change to OUT or PERM OUT; PERM OUT is a permanent transfer, so a box at PERM OUT cannot change to OUT and can return to IN only with a new barcode that differs from the previous one. When a RAS box changes to IN, its location is set to RAS automatically. Every change of barcode or status is written to **Barcode history**, and every change of seal number to **Seal history**.

## The uniqueness rule for box type and number

A box type and number can be used only once per batch, or once per repository for boxes with no batch. If you save a type and number that another box already carries, the form names the box that already carries them, with its batch and record number, and the box is not saved until the number or batch is corrected. When a batch is selected, the help text under **Box number** lists the numbers already used in that batch.

## 9. Move boxes to another batch

On the Boxes list, tick the boxes to move, open **Power actions** and select **Change batch**. Choose the **New batch** and submit; the documents in each box move to the new batch with it. A box is skipped, and named in the notification, if it is already in that batch, if the batch belongs to a different repository, or if the batch already has a box with the same type and number.

## 10. Change the barcode status of several boxes

Tick the boxes, open **Power actions** and select **Mark IN**, **Mark OUT** or **Mark PERM OUT**, then confirm. Only RAS boxes are changed; boxes of other types, destroyed boxes and boxes already at that status are skipped and named, and the rules above still apply to each box.

## 11. Mark a box as destroyed

On the box page or at the end of its row, select **Mark as destroyed**, optionally enter the **Reason / where destroyed**, and select **Mark destroyed**. The box can be marked only once every document in it has a catalogue identifier; otherwise the tool states how many uncatalogued documents remain. To mark several boxes at once, tick them and select **Destroy boxes** under **Power actions**.

## Tips

> **Tip:** Filters remain applied when you leave the Boxes list and return to it, so select **Reset** in the **Filters** panel when you need the full list again.
>
> **Tip:** A destroyed box stays in the list with **Destroyed** set to Yes, so that its provenance is kept.
>
> **Tip:** To move a single box to a different shelf with a recorded reason, use **Move to location** at the end of its row instead of editing the location field.

## Summary

You can find a box with the search field and filter rules, read its contents and histories on its page, and correct its type, batch, number, parent, barcode, status, seal and location from **Edit**. The **Power actions** menu applies **Change batch**, the status actions and **Destroy boxes** to several boxes at once, while each box is checked against the same rules.
