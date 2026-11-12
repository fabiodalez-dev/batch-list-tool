# How to move boxes between locations

When a box is transferred to another room, shelf or repository, its location in Logistics Tool must be updated so that every report reflects where the box now stands. You can move one box at a time with **Move to location**, or move several boxes in a single operation with **Relocate boxes**; each move is recorded with its date, its author and, where given, its reason.

## Prerequisites

- [ ] Your role must allow you to edit boxes. Without that permission the move actions are not available to you.
- [ ] The target location must exist and be active. An inactive or deleted location is not offered in the list.
- [ ] The target location must be global or belong to the same repository as the box. A location of another repository is refused.

## 1. Open the list of boxes

In the sidebar, under **Records**, select **Boxes**. Use the filter rules or the search field to bring the boxes you intend to move into the list.

![Open the list of boxes](./block_open-boxes.png)

## 2. Move a single box

In the row of the box, select **Move to location**; the same action is available at the top of the box page. In the **Move box to a different location** window, choose the **Target location**, enter the reason in **Reason (optional)** if you wish to keep one, and select **Submit**. The confirmation **Box moved to** followed by the location name appears once the move is saved.

![Move a single box](./block_move-one-box.png)

## 3. Move several boxes at once

Tick the checkbox of each box to move, open **Power actions** and select **Relocate boxes**. In the **Relocate boxes** window, choose the target in **Location**, add a **Tracking Note** if required, and select **Confirm**. Only active locations of type Repository or Room, either shared by every repository or belonging to the repository you are working in, are offered; boxes of another repository are skipped and counted in the confirmation message.

![Move several boxes at once](./block_move-several-boxes.png)

## Leave PERM OUT switched off for an ordinary move

The **Relocate boxes** window also contains **Mark barcode as PERM OUT**. Leave it switched off when you are only changing the location: marking boxes PERM OUT is a permanent custody change, and it is the only case in which the location may be left empty.

## What is recorded

Every change of location adds an entry to the box's **Location history**, with the previous location, the new location, the date and time, and the user who made the move. A reason entered in **Move to location** is appended to the box's **Tracking Note** with the prefix `[Move]`; the note entered in **Relocate boxes** is appended as written. The change is also written to the **Audit log**, which records the field that changed with its old and new values.

## Which documents follow the box

Documents whose custody status is In Box travel with the box: any location of their own is cleared, so that they show the location of the box from then on, and each document whose location was cleared receives an entry in the **Audit log**. Documents that are still linked to the box but kept elsewhere, with a custody status such as Not in Box, Mounted; no box or Conservation Box, keep their own location until they are placed back in the box.

## 4. Review the moves

Open the box with **View** and select the **Location history** tab, where each row shows **Moved** (the date and time, as dd-mm-yyyy hh:mm), **From**, **To** and **By**; the **How** and **Notes** columns can be shown from the column selector. To review documents moved from one box to another, open **Reports** and select **Document movement history**. A box that changes location while its contents stay in the same box does not appear in that report; its move is found in the box's **Location history**.

## Tips

> **Tip:** If the message **Cannot move — target location belongs to a different repository** appears, choose a location of the box's own repository or a global location.
>
> **Tip:** If **Relocate boxes** reports **No boxes changed**, either every field was left blank or all the selected boxes belong to a repository other than that of the target location.
>
> **Tip:** A destroyed box cannot be moved: **Move to location** is not shown for it.
>
> **Tip:** To send boxes back to the RAS store, use **Return to RAS**, which is a separate action with its own form.

## Summary

Use **Move to location** for a single box and **Relocate boxes** for a selection. Each move is recorded in the box's **Location history**, in the **Tracking Note** when a reason or note is given, and in the **Audit log**, and the documents held In Box follow the box to its new location.
