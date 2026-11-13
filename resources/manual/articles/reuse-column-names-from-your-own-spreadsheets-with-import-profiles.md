# How to reuse column names from your own spreadsheets with Import Profiles

When a spreadsheet uses its own column headings instead of those in the import template, the Import Wizard asks you to match each heading to a field by hand. An Import Profile saves that column mapping, so that the next spreadsheet with the same layout can be imported without renaming any column or repeating the mapping.

## Prerequisites

- [ ] An account with the Administrator or Super administrator role: the **Import Wizard** is not available to the ReadingRoom or General roles, and both **Import Wizard** and **Import Profiles** are in the **Importation** group of the sidebar.
- [ ] A spreadsheet (.xlsx or .csv) whose column headings differ from the template headings.
- [ ] The records that the spreadsheet depends on, such as Subseries, Authorities or Batches, already exist in Logistics Tool.

## 1. Map your headings in the Import Wizard

Open **Import Wizard**, select the **Type of records** and upload your file in **Upload your filled file**. In **Preview & map columns**, choose the heading from your spreadsheet for each importer field; fields marked with an asterisk are required. Continue when the wizard displays **All required columns are mapped — you can continue.** and complete **Validate rows**.

## 2. Save the mapping as a profile on the last step

On **Confirm and import**, tick **I have reviewed the preview and I want to import these rows.** and then tick **Save this column mapping as a reusable profile**. Enter a recognisable **Profile name**, and tick **Share this profile with the rest of my repository** if colleagues import the same layout. Select **Start import**; the notification **Profile saved** confirms that the profile has been created.

![Save the mapping as a profile on the last step](./block_save-profile.png)

## 3. Start the next import from the saved profile

The next time you receive a spreadsheet with the same headings, open **Import Wizard** and select the same **Type of records**. Open **Start from a saved profile** and choose your profile; the most recently used profiles appear first. The field is shown only when at least one profile exists for the selected type.

![Start the next import from the saved profile](./block_start-from-profile.png)

## 4. Check the pre-filled mapping and import

Upload the new file and continue to **Preview & map columns**: each importer field is already matched to your heading. Confirm that the wizard displays **All required columns are mapped — you can continue.**, then complete **Validate rows** and **Confirm and import** as usual.

## 5. Review your profiles in Import Profiles

Open **Import Profiles** in the **Importation** group. The list shows each profile's **Name**, the records it **Imports**, whether it is **Shared**, its **Owner**, how many times it has been **Used** and when it was **Last used**. Select **View** to read the column mapping and to open the profile with **Use in Import Wizard**, or **Edit** to rename it, add a description or change **Share with my repository**.

![Review your profiles in Import Profiles](./block_manage-profiles.png)

## Tips

> **Tip:** Profiles can only be created from the last step of the Import Wizard; the **Import Profiles** page has no create button.
>
> **Tip:** The column mapping of a saved profile cannot be edited. To change it, run the wizard again with a real file and save a new profile.
>
> **Tip:** You see your own profiles and the profiles that colleagues have shared in your repository. Only the owner, an Administrator or a Super administrator can edit a profile, and only an Administrator or a Super administrator can delete one.
>
> **Tip:** A profile does not bypass any checks: every import started from a profile still runs **Validate rows** before anything is written.

## Summary

Save your column mapping once on **Confirm and import**, pick it in **Start from a saved profile** for every later spreadsheet with the same layout, and use **Import Profiles** to review, rename or share the profiles you have saved.
