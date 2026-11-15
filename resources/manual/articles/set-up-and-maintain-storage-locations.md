# How to set up and maintain storage locations

Locations describe where boxes and documents physically sit within a repository, such as an archive room or a museum. This guide explains how to create a location, how its identifier is generated, how to set a parent and sort order, and how to deactivate, import and delete locations.

## Prerequisites

- [ ] You are signed in to Logistics Tool with permission to create and edit locations.
- [ ] The repository that will hold the locations already exists.
- [ ] The location types you intend to use are active under **Classifications** > **Location Typology**.

## 1. Open the Locations register

In the sidebar, under **Records**, select **Locations**. The list shows each location with its **Name**, **Type**, **Repo**, **Identifier**, the number of **Boxes** and **Documents** it holds, and whether it **Is active**.

![Open the Locations register](./block_open-locations.png)

## 2. Create a location

Select **New Location**. In the **Details** section, choose the **Repository** the location belongs to, choose its **Type**, and enter its **Name**. The name must be unique within the selected repository.

![Create a location](./block_create-location.png)

## 3. Leave the identifier blank or enter your own

The **Identifier** field is optional: if you leave it blank, the application generates the identifier when the location is saved, from the first four letters of the type in capitals, the internal number of the repository and a running number, for example `REPO-1-45`. An identifier that you enter yourself must be unique within the repository and must not exceed 32 characters. Select **Create** to save the location.

## 4. Set the parent location and sort order

The location form does not include a parent or a sort order. To place a location under another one, or to order locations that share a parent, fill in the **Parent location** and **Sort order** columns of the location import template and import it as described below. The parent is matched by its name, so a parent must exist before its children are imported.

## 5. Deactivate a location

In the list, select **Edit** on the location's row. Switch off **Is active** and select **Save changes**. The location remains in the register and keeps its boxes and documents.

![Deactivate a location](./block_deactivate-location.png)

## 6. Import locations in bulk

Select **Download template** and enter one location per row under the columns **Name**, **Type**, **Parent location**, **Repository code**, **Identifier**, **Notes**, **Sort order** and **Is active**, leaving **Repository code** blank only for a location shared by every repository. Select **Import Excel / CSV**: the **Import Wizard** opens with **Locations (physical/logical hierarchy)** already chosen. Upload the file, review the column mapping and the validation, and select **Start import**.

## 7. Delete several locations at once

Tick the check box of each location to remove, open **Bulk actions** and select **Delete selected**. In the **Delete selected locations** dialog, select **Confirm**. Locations that still have child locations or are referenced by boxes or documents are skipped and listed in the notification; the others are deleted.

## Tips

> **Tip:** Select **Export CSV** to download the locations currently shown, in the same columns as the import template, so that you can correct them and import them again without remapping the columns.
>
> **Tip:** If you delete a single location that is still in use, the application refuses with the message **Cannot delete location**. Re-assign its boxes, documents and child locations first.
>
> **Tip:** To list only inactive locations, select the **Filter** button above the table, select **Add rule**, choose **Is active**, set the condition to **Is false** and select **Apply filters**.

## Summary

You can now create locations with a generated or custom identifier, organise them through the import template, deactivate those no longer in use, and delete several unused locations at once. Locations still holding boxes, documents or child locations are always protected from deletion.
