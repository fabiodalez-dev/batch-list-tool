# How to return boxes to RAS

The **Return to RAS** action moves boxes that are currently held at NRA back to the RAS store. Each box receives a new barcode, the location RAS and the barcode status IN, and a box that is not a RAS box joins a new batch created for the movement.

## Prerequisites

- [ ] Your role allows you to edit both boxes and documents.
- [ ] Your role also allows you to create batches, if any of the boxes is not a RAS box.
- [ ] An active location named RAS exists for the repository of the boxes; otherwise add it under **Locations** first.
- [ ] The new barcode of every box is to hand, because the dialog asks for one barcode per box.

## 1. Select the boxes

Open **Boxes** in the sidebar and tick the checkbox of each box you are returning. You may select up to 50 boxes at a time; with more than 50 selected, the action stops with the message **Too many boxes selected for Return to RAS** and nothing is changed.

![Select the boxes](./block_select-boxes.png)

## 2. Open the Return to RAS action

Select **Power actions** above the table, then select **Return to RAS**. To return a single box, you may instead select **Return to RAS** on its row, or on the box's own page; that button is not offered for a destroyed box, an archived box, or a RAS box that is already IN at RAS.

## 3. Enter a new barcode for each box

The dialog **Return the selected boxes to RAS** lists one row per box, headed by the box and its current barcode. Enter the **New barcode** for every box: it is required, it must differ from the box's current barcode, and it must not belong to any other box. For a box that is not a RAS box, you may also enter a **New box number (optional)** and choose a **Current Box Type**; leave the box number blank to keep its identifier.

![Enter a new barcode for each box](./block_enter-barcodes.png)

## 4. Check the new batch for boxes of another type

When the selection includes a box of another type (NRA, MUS, MAV, STVC and so on), the section **New batch for this movement** appears. Check the suggested **Batch number**, which is the next free number in the repository, the **Accession type**, which is preset to NOTARY ACCESSION, and the **Description**, which is preset to "Boxes returned from NRA to RAS on" followed by today's date. All non-RAS boxes must belong to the same repository; return them one repository at a time otherwise.

## 5. Add a note and confirm

Optionally, enter a **Tracking note (optional)** of up to 500 characters to explain the movement. Select **Return to RAS** at the foot of the dialog to apply the change.

## What is recorded

Each box is changed in its own transaction, so a box that is refused does not prevent the others from being returned. A RAS box keeps its batch and box number; a box of another type becomes a RAS box in the new batch, and every document in it moves to that batch. The previous barcode is kept in the **Barcode history**, the move to RAS is added to the **Location history**, and, for a box of another type, the **Tracking Note** receives a line beginning `[Return to RAS] Was`, followed by its former type, number and barcode; for a RAS box, a line beginning `[Return to RAS]` is added only when a tracking note is entered. The documents physically in the box follow it to RAS, and every change appears in the audit log.

## 6. Check the result

Read the notification that appears after the return. It states how many boxes were returned to RAS, names the batch created for the movement, and lists any box that was skipped together with the reason, for example a box that is destroyed or already at RAS with status IN. In the **Boxes** list, the **Barcode**, **Barcode status**, **Location** and **Tracking Note** columns show the new values.

![Check the result](./block_check-result.png)

## Tips

> **Tip:** Use a filter rule on **Box type** or **Barcode status** to list only the boxes you need before selecting them.
>
> **Tip:** The same barcode cannot be typed for two boxes in one dialog; the form highlights the duplicate before anything is saved.
>
> **Tip:** A skipped box keeps its previous values; correct the reason given in the notification and return it again.

## Summary

Select up to 50 boxes, open **Power actions**, choose **Return to RAS**, enter a new barcode for each box and confirm. The boxes are recorded at RAS with status IN, boxes of other types join a new batch, and the previous barcodes and locations remain available in each box's history.
