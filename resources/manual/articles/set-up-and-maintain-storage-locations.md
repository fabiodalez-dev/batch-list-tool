# How to set up and maintain storage locations

Locations describe where boxes and documents physically sit within a repository, such as an archive room, a museum or a showcase. This guide explains how to create a location, how its identifier is generated, how to organise and deactivate locations, how to import them in bulk and how to delete several locations at once.

## Prerequisites

- [ ] An account with permission to create and edit locations.
- [ ] At least one repository to which the locations will belong.
- [ ] The location types you intend to use, configured under **Location Typology**.

## 1. Open the Locations page

In the sidebar, under **Records**, select **Locations**. The table lists every location in the repositories available to you, with its **Type**, **Repo**, **Identifier**, the number of **Boxes** and **Documents** it holds, and whether it **Is active**. To add a single location, select **New Location**.

![Open the Locations page](./block_open-locations.png)

## 2. Enter the location details

In the **Identification** section, choose the **Repository** the location belongs to, then choose its **Type**, for example **Room**, **Museum** or **Repository**. Enter a **Name**; each name must be unique within the selected repository.

![Enter the location details](./block_enter-details.png)

## 3. Leave the identifier blank to generate it automatically

Leave **Identifier** empty unless the location already has an established code. When it is blank, the system assigns one on saving, made of the first four letters of the type, the repository number and a running counter, for example `REPO-1-45` or `ROOM-3-7`. An identifier entered manually must be unique within the repository and is never overwritten.

## 4. Save the location

Keep **Is active** switched on in the **Status** section so that the location can be selected on boxes and documents. Add any remarks under **Notes** if required, then select **Create**.

## 5. Set the parent and the order of locations

Locations created with **New Location** are placed at the top level of the hierarchy. To place a location under a parent, such as a shelf within a room or a showcase within a museum, and to set its position among its siblings, use the **Parent location** and **Sort order** columns of the import sheet described below. To change the order in which the list is displayed, select a column heading such as **Name** or **Type**.

## 6. Deactivate a location

Deactivate a location that is no longer in use rather than deleting it, so that existing records remain readable. Select **Edit** on the row, switch off **Is active** and select **Save changes**.

## 7. Import locations in bulk

Select **Download template** to obtain a spreadsheet with the columns **Name**, **Type**, **Parent location**, **Repository code**, **Identifier**, **Notes**, **Sort order** and **Is active**. Complete one row per location, listing parent locations above their children, then select **Import Excel / CSV** to upload the file through the import wizard.

## 8. Delete several locations at once

Select the checkbox of each location to remove, then open **Bulk actions** above the table and select **Delete selected**. In the **Delete selected locations** dialogue, select **Confirm**. Locations that still have child locations or are referenced by boxes or documents are skipped and listed in the notification; the others are deleted.

![Delete several locations at once](./block_bulk-delete.png)

## Tips

> **Tip:** Re-importing the same sheet never creates duplicates. Existing locations are skipped, unless you tick **Overwrite existing records** on the last step of the Import Wizard, in which case their type, notes, sort order and active state are updated.
>
> **Tip:** If an import row reports that its parent location was not found, import the parent first and run the import again.
>
> **Tip:** Select **Export CSV** to download the locations shown in the same columns as the import template, ready to be edited and re-imported.

## Summary

Locations are created from the **Locations** page, receive an identifier automatically when none is entered, and are best organised in bulk through the import template. Deactivate locations that are no longer used, and use **Delete selected** to remove several unused locations in a single operation.
