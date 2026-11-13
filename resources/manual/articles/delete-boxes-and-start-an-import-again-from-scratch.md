# How to delete boxes and start an import again from scratch

Deleting a box in Logistics Tool does not remove it: the box is archived, and a later import of the same box brings it back with its history. This guide explains how to remove a set of boxes for good, so that the next import of your spreadsheet starts from a clean list.

## Prerequisites

- [ ] An account that may delete boxes. **Delete all documents** is shown only to Super administrators.
- [ ] The spreadsheet you intend to import again, checked and ready.
- [ ] A recent backup, because a box deleted for good cannot be recovered from within the application.

## What deleting a box does

**Delete selected** archives the box: it disappears from the list but is kept in the database. If you then import the same box again, with the same box type, box number and batch, or with the same barcode, the import restores the archived box and updates it, together with its earlier history. To make the import create the boxes afresh, archive them and then delete them permanently, as described in the steps below.

## 1. Filter the boxes by box type

Open **Boxes** and select **Filter**. Select **Add rule**, choose **Box type**, select the type of box you intend to import again (for example `RAS`) and select **Apply filters**. The list now shows only boxes of that type.

![Filter the boxes by box type](./block_filter-by-box-type.png)

## 2. Select every box in the list

Tick the checkbox in the header of the list to select the boxes on the current page. When the boxes do not fit on one page, select **Select all** in the bar above the list, which shows the total number of boxes, so that every box matching the rule is selected.

![Select every box in the list](./block_select-all-boxes.png)

## 3. Archive the selected boxes

Open **Power actions** and select **Delete selected**, then confirm with **Delete**. The boxes are archived and no longer appear in the list.

## 4. Show only the archived boxes

Select **Filters** again. Keep the **Box type** rule, set **Archived (deleted) boxes** to **Only deleted records** and select **Apply filters**. The list now shows the boxes you have just archived.

## 5. Delete the archived boxes permanently

Select the boxes again, using **Select all** when they do not fit on one page. Open **Power actions**, select **Force delete selected** and confirm with **Delete**. The boxes are deleted for good, together with their barcode, seal and location history. A box that still contains documents, archived documents included, is kept and is not deleted.

![Delete the archived boxes permanently](./block_force-delete-boxes.png)

## Restore archived boxes instead

If you archived boxes by mistake, keep **Archived (deleted) boxes** set to **Only deleted records**, select the boxes, open **Power actions** and select **Restore selected**, then confirm with **Restore**. The boxes return to the list with their history.

## 6. Remove the documents of the repository, if required

Because a box that still contains documents cannot be deleted for good, empty the documents first when the documents are to be imported again as well. Open **Documents**, tick any document, open **Power actions** and select **Delete all documents**. The confirmation names the repository and the number of documents it holds; type the repository code shown in the field and select **Delete everything**. Every document of that repository is removed permanently, not only the ticked ones.

## 7. Choose the repository and import again

In the top bar, select the repository the records belong to, rather than **All repositories**. Then open **Import Wizard** and import your spreadsheet as usual. The import uses the repository that is active in the top bar when it starts.

## Tips

> **Tip:** **Import Status** keeps the history of earlier imports. Deleting boxes or documents does not remove those entries.
>
> **Tip:** To remove the documents of a single batch only, do not use **Delete all documents**: filter the **Documents** list by that batch and use **Delete selected**, then set the **Deleted records** filter to show only deleted records, select the same documents and use **Force delete selected**. Until they are deleted permanently, their boxes cannot be deleted for good, and a new import restores them rather than creating them afresh.
>
> **Tip:** **Delete all documents** never works across repositories. If **All repositories** is selected in the top bar, the confirmation asks you to pick a specific repository first.
>
> **Tip:** Filters stay applied when you leave the list. Remove the **Box type** rule and reset **Archived (deleted) boxes** when you have finished.

## Summary

Filter the boxes by type, archive them with **Delete selected**, show only the archived boxes and remove them with **Force delete selected**. Choose the repository in the top bar and run the import again: the boxes are then created afresh rather than restored.
