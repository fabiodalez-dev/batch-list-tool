# How to delete boxes and start an import again from scratch

Deleting a box from the Boxes list only archives it: if the same box is imported again, with the same box type, box number and batch or with the same barcode, the archived box is brought back together with its history. To start an import again from a clean state, archive the boxes first and then delete them permanently, as described below.

## Prerequisites

- [ ] An administrator account with permission to delete and permanently delete boxes.
- [ ] The box type of the boxes you intend to remove, for example MUS.
- [ ] The spreadsheet you intend to import again once the boxes have been removed.

## 1. Filter the list by box type

In the sidebar, select **Boxes**, then select the **Filter** icon above the list to open the **Filters** panel. Choose **Add rule**, then **Box type**, select the type (for example MUS) and select **Apply filters**.

![Filter the list by box type](./block_filter-by-box-type.png)

## 2. Select every box of that type

Tick the checkbox at the top of the list to select every box on the page. If the boxes do not fit on one page, select **Select all** in the bar that appears above the list, so that every matching box is selected.

![Select every box of that type](./block_select-boxes.png)

## 3. Archive the selected boxes

Open **Power actions** and choose **Delete selected**, then confirm with **Delete**. The boxes are archived: they no longer appear in the list, but they have not yet been removed.

## 4. Show the archived boxes

Select the **Filter** icon again and set **Archived (deleted) boxes** to **Only deleted records**, keeping the **Box type** rule in place, then apply the filters. The list now shows only the archived boxes of that type.

## 5. Delete the archived boxes permanently

Select the boxes again as in step 2, open **Power actions**, choose **Force delete selected** and confirm with **Delete**. The boxes are removed for good, together with their barcode, seal and location history. A box that still contains documents is kept: move or delete its documents first, then repeat this step.

![Delete the archived boxes permanently](./block_force-delete.png)

## 6. Choose your repository before importing again

Before you import the spreadsheet again, select your repository in the selector in the top bar instead of **All repositories**. Boxes without a batch (In Situ, NRA and MAV boxes) take the repository selected there; with **All repositories** selected, such rows may be refused.

## Restoring archived boxes instead

If the boxes were archived by mistake, they can be brought back instead of being deleted permanently. With **Only deleted records** applied, select the boxes, open **Power actions** and choose **Restore selected**. The boxes return to the list with their full history.

## Tips

> **Tip:** Permanent deletion cannot be undone. Before you confirm, ensure that the list shows only the boxes you intend to remove.
>
> **Tip:** If some boxes are reported as not deleted, they still contain documents. Their documents must be moved to another box or deleted before the box can be removed.
>
> **Tip:** The history on the **Import Status** page is not removed. Earlier imports remain listed there after the boxes have been deleted.

## Summary

Deleting a box only archives it, so a clean re-import requires two passes: **Delete selected** to archive the boxes, then **Force delete selected** from the archived view to remove them for good. Select your repository in the top bar before you import the spreadsheet again.
