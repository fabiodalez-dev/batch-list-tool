# How to reuse column names from your own spreadsheets with Import Profiles

An Import Profile records which heading in your spreadsheet fills which field in the archive. Once it is saved, you can import a spreadsheet whose headings differ from the template, for example columns renamed by staff, without renaming any column by hand.

## Prerequisites

- [ ] An account with the administrator role, as the **Import Wizard** is available to administrators only.
- [ ] Your spreadsheet saved as an `.xlsx` or `.csv` file of no more than 50 MB, with its headings in the first row.

## 1. Open the Import Wizard and choose the type of records

In the sidebar, under **Importation**, select **Import Wizard**. Under **Type of records**, select the entity your spreadsheet contains, for example **Boxes (physical containers)**, and then select **Next**.

## 2. Upload your spreadsheet

Your own spreadsheet replaces the template, so select **Next** on the **Download the template** step. On the **Upload your filled file** step, drop your file into **Spreadsheet file** and select **Next**.

## 3. Map each of your headings to the field it fills

On the **Preview & map columns** step, the wizard pre-selects the headings it recognises. For each field that shows the wrong heading or **— (skip this column) —**, open its list and choose the heading from your spreadsheet that holds that information. Fields marked with an asterisk are required; continue when the page reads **All required columns are mapped — you can continue.**

![Map each of your headings to the field it fills](./block_map-columns.png)

## 4. Validate the rows

On the **Validate rows** step, select **Run validation** and review any errors that are reported. Select **Next** when you are satisfied with the result.

## 5. Save the mapping as a profile

On the **Confirm and import** step, tick **I have reviewed the preview and I want to import these rows.** and **Save this column mapping as a reusable profile**, then enter a **Profile name** that describes the spreadsheet layout. Tick **Share this profile with the rest of my repository** if colleagues use the same layout, and select **Start import** to save the profile and start the import.

![Save the mapping as a profile](./block_save-profile.png)

## 6. Pick the profile for your next import

The next time you receive a spreadsheet with the same headings, open the **Import Wizard** and select the same **Type of records**. In **Start from a saved profile**, choose your profile: when you upload the file, the **Preview & map columns** step is already filled in with your mapping.

![Pick the profile for your next import](./block_use-profile.png)

## Tips

> **Tip:** Under **Importation**, **Import Profiles** lists every profile you can use; open one and select **Use in Import Wizard** to start the wizard with that profile already loaded.
>
> **Tip:** A profile applies to a single type of records. Selecting a different **Type of records** clears the profile you picked.
>
> **Tip:** The column mapping of a saved profile cannot be edited. To change it, run the wizard again, correct the mapping and save it under a new **Profile name**.

## Summary

You map your own headings once, save the mapping as an Import Profile on the final step of the Import Wizard, and pick that profile in the first step of every later import that uses the same layout.
