# How to move boxes between locations

When a box is transferred to another room, shelf or store, its location must be updated so that the catalogue reflects where the box is physically kept. This guide explains how to move a single box or several boxes at once from the Boxes list, what is recorded for each move, and where past movements can be reviewed.

## Prerequisites

- [ ] A user account with permission to edit boxes.
- [ ] The destination must already exist as an active location in **Locations**.
- [ ] The boxes to be moved must not be marked as destroyed.

## 1. Open the Boxes list

In the sidebar, under **Records**, select **Boxes**. The list shows the boxes of the active repository, with the current location of each box in the **Location** column.

![Open the Boxes list](./block_open-boxes-list.png)

## 2. Move a single box

Locate the box in the list and scroll the table to the right to reach the actions of its row, then select **Move to location**. The same action is also available at the top of the page of the box itself.

## 3. Choose the target location

In the **Move box to a different location** dialogue, search for the destination in **Target location**; only active locations are offered. Optionally, explain the move in **Reason (optional)**, then select **Submit**.

![Choose the target location](./block_choose-target-location.png)

## 4. Select several boxes to move together

To move several boxes to the same location, tick the checkbox at the start of each row. Open **Power actions** above the table and select **Relocate boxes**.

![Select several boxes to move together](./block_select-several-boxes.png)

## 5. Confirm the relocation

In the **Relocate boxes** dialogue, select the destination in **Location**, which offers only locations of type Repository or Room, and, if required, add a comment in **Tracking Note**; it is added to every selected box, separated by `; ` from any note already there. Leave **Mark barcode as PERM OUT** switched off for an ordinary move, as this is a permanent custody change. Select **Confirm** to apply the move.

## What is recorded for each move

Each move updates the **Location** of the box and adds an entry to its location history, stating the previous and new locations, the date and time, and the user who made the change; the change is also written to the audit trail. A reason entered for a single box is added to its **Tracking Note**, preceded by `[Move]`. The documents whose **Custody Status** is **In Box** move with the box and show its location from then on, even if they had a location of their own. A document linked to the box but kept elsewhere, for example **Not in Box** in Archive 3, keeps its location until it is moved back into the box. In a bulk relocation, boxes that belong to a different repository from the chosen location are skipped, and the notification states how many.

## 6. Review the location history of a box

In the Boxes list, select **View** in the row of the box, then open the **Location history** tab. Each entry shows when the box was **Moved**, the location it came **From**, the location it went **To**, and **By** whom.

## 7. Review movements on the Box Movements page

In the sidebar, under **Operations**, select **Box Movements**. This register lists every document transferred from one box to another, with its **Document**, **From box**, **To box**, **Movement date**, **Reason** and **User**. Read it together with the location history of each box to reconstruct the full custody chain.

## Tips

> **Tip:** Record a reason for every move: it remains on the box in **Tracking Note**, where it can be read without opening the audit log.
>
> **Tip:** If the destination does not appear in the search, confirm in **Locations** that it exists and is active.
>
> **Tip:** After a move, check the **Location** column of the Boxes list to confirm that each box shows its new location.

## Summary

A single box is moved with **Move to location**, and several boxes are moved together with **Relocate boxes**. Every move is recorded in the **Location history** of the box and in the audit trail, while transfers of documents between boxes are listed on the **Box Movements** page.
