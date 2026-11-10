# How to manage boxes and their barcode status

The Boxes register records every physical container that holds notarial documents. This guide explains how to locate a box, open its record, update its type, number, batch, parent RAS box, barcode, barcode status, seal number and location, review the documents it contains together with its barcode, seal and location history, and apply an action to several boxes at once.

## Prerequisites

- [ ] A user account with permission to view and edit boxes.
- [ ] The box number, barcode or seal number of the box you intend to update.

## 1. Find the box on the Boxes list

In the sidebar, under **Records**, select **Boxes**. Enter the box number, barcode or seal number in the **Search** field above the table; the list narrows as you type. To restrict the list further, for example by **Batch** or **Barcode status**, select the **Filter** button beside the search field.

![Find the box on the Boxes list](./block_find-the-box.png)

## 2. Open the box record

The actions for each box are at the end of its row; scroll the table to the right to reach them, then select **View**. The **Details** section of the record page shows the identification, the barcode and its status, the location, the number of documents and any destruction details. The barcode status appears as a coloured badge: green for **IN**, amber for **OUT** and red for **PERM_OUT**.

## 3. Review the box history

Below the sections of the record page, select a tab to review the changes recorded for the box: **History** for the complete chronology, **Barcode history** for each barcode and status change, **Seal history** for each seal number change, and **Location history** for each move.

![Review the box history](./block_review-the-history.png)

## 4. See the documents in the box

The **Documents** figure in the **Details** section states how many documents the box contains. To list them, select **View documents** on the row of the box in the Boxes list; the Documents list opens filtered to that box. Selecting the row itself has the same effect.

## 5. Open the edit form

Select **Edit** on the row of the box in the Boxes list. The **Edit** action is also available at the top of the record page.

## 6. Update the type, number and batch

In the **Details** section, select the **Box type** (for a new box: RAS, IN SITU, NRA or another active type such as MUS; MAV and STVC are kept for existing boxes only), then the **Current Box Type**, choose the **Batch** and enter the **Box number**. A box number must be unique within its batch, and the helper text below the field lists the numbers already used. For an IN_SITU or NRA box, the field is labelled **Box identifier** instead, for example `NRA1`.

![Update the type, number and batch](./block_update-identification.png)

## 7. Set the parent RAS box

If the box type is IN_SITU or NRA, further provenance fields appear in the form. Select the RAS box of origin in **Parent RAS box**. Turn on **Provenance Unknown** only when the RAS box of origin is genuinely unknown.

## 8. Update the barcode, status and seal

In the same section, enter the **Barcode** affixed to the box; it must be unique across all boxes and is required for RAS boxes. Select the **Barcode status**: **IN**, **OUT** or **PERM OUT**. Enter the yellow security seal in **Seal Number** where one is fitted.

## 9. Set the location

Select the room, shelf or holding area in **Location (RFQ §3.1.9)**. The location is optional for every box type. When a RAS box becomes **IN**, its location changes to **RAS** automatically.

## 10. Save the changes

Select **Save changes** at the bottom of the form. Any change to the barcode, the barcode status or the seal number is recorded automatically and appears in the history tabs of the record page.

## 11. Apply an action to several boxes

Tick the boxes in the Boxes list and open **Power actions** above the table.

- **Relocate boxes** moves them to a location of type Repository or Room; see the guide to moving boxes between locations.
- **Change batch** moves them to another batch, together with their documents.
- **Send to disinfestation** marks the documents physically in them (**Custody Status** **In Box**) as currently in disinfestation, shown as **Current Cycle** on the Documents page, and moves the boxes to the location **Disinfestation**. RAS boxes go **OUT**.
- **Finish disinfestation** asks for the **Disinfestation date**, which becomes the latest disinfestation date of their documents, and a **Destination**. **Return to RAS** moves the boxes to the location **RAS** and sets RAS boxes **IN**. **To an archive location (NRA)** moves them to the **Archive location** you choose and sets RAS boxes **PERM OUT**.
- **Mark IN**, **Mark OUT** and **Mark PERM OUT** change the barcode status.
- **Destroy boxes** marks them as destroyed, with an optional **Reason / where destroyed**.

The disinfestation actions need the locations named **Disinfestation** and **RAS** to exist under **Locations**.

## Tips

> **Tip:** MAV and STVC are legacy box types: existing boxes of these types remain editable, but they cannot be assigned to new boxes.
>
> **Tip:** A box without a barcode, such as an IN_SITU box, shows no barcode status on the Boxes list or on its record page.
>
> **Tip:** To relocate a box with a recorded reason, select **Move to location** on the record page instead of editing the location field.

## Summary

You can locate any box from the Boxes list, review its details and contents on its record page, and update its identification, barcode, status, seal and location from the edit form, and apply an action to several boxes through **Power actions**. Every barcode, status and seal change is preserved in the box history for later reference.
