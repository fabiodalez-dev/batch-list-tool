# How to find documents with search and filters

The **Documents** list shows the documents of the repository chosen in the top bar. This guide explains how to narrow the list with the search box and with filter rules, how to choose, arrange and sort the columns, and how to export the result to a CSV file.

## Prerequisites

- [ ] A user account with permission to view documents.
- [ ] The repository you wish to work in, chosen in the repository selector in the top bar. Choose **All repositories** to work across every repository you may access.

## 1. Open the Documents list

In the sidebar, under **Records**, select **Documents**. The list shows the documents of the repository named in the selector beside the **Search** field in the top bar; choose another repository there to change the list.

![Open the Documents list](./block_open-documents.png)

## 2. Search the list

Type a word or code in the **Search** box above the table, for example a creator's surname, a reference code, a batch or box number, a location or a flag. The list updates as you type and the search is shown under **Active filters**; select the cross beside it to clear the search.

## 3. Add filter rules

Select the **Filter** button (the funnel icon beside the search box) to open the **Filters** panel. Select **Add rule**, type part of a field name to shorten the list of fields, and select the field. Choose the condition, such as **Equals** or **Contains**, and enter the value.

![Add filter rules](./block_add-filter-rules.png)

## 4. Combine rules with AND, OR and AND NOT

From the second rule onwards, each rule has its own connector: **AND**, **OR** or **AND NOT**. The rules are read from left to right, top to bottom, and each rule joins everything above it. For example, Creator (surname) equals Galdes, OR Creator (surname) equals Pulis, AND Disinfested Date is after 01-03-2024 finds the documents of either creator that were disinfested after that date.

## 5. Enter dates as dd-mm-yyyy

For a date rule, such as **Disinfested Date**, choose the condition (for example **Is after**), keep **Date type** on **Specific date**, and type the date in the form dd-mm-yyyy, for example 01-03-2024, or pick it from the calendar.

## 6. Include deleted records if required

Below the rules, the **Deleted records** filter is set to **Without deleted records**. Choose **With deleted records** or **Only deleted records** when you need to find documents that have been deleted.

## 7. Apply or reset the filters

Select **Apply filters** to update the list. The rules are shown under **Active filters** above the table and stay in place while you move between pages during your session. Select **Reset** to remove every rule and return to the full list.

## 8. Choose, reorder and save the columns

Select the **Column manager** button (the columns icon beside the **Filter** button), tick the columns you wish to see, such as **Subseries** or **Batch**, and clear the others. Drag a column by its handle to change its position. Select **Apply columns** to save the layout, which is kept for your user account the next time you sign in. Select **Reset** in the **Columns** panel to return to the standard layout.

## 9. Sort the list

Select a column heading to sort the list by that column, and select it again to reverse the order. The **Creator** column sorts by surname and then by given names, and the **Batch** and **Box** columns sort in number order.

## 10. Export the filtered list

Select **Export CSV** at the top of the page to download every document the list currently shows, with the search, the filter rules and the chosen repository applied. The file uses the same columns as the import template.

## 11. Export only the selected documents

Tick the documents you need, select **Power actions** above the table and choose **Export selected (CSV)**. Only the ticked documents are exported, in the same columns as **Export CSV**.

![Export only the selected documents](./block_export-selected.png)

## Tips

> **Tip:** To find documents in a particular kind of box, add the **Box type** rule and choose the box types you need.
>
> **Tip:** A file downloaded with **Export CSV** can be corrected in a spreadsheet and imported again through the **Import Wizard** without remapping the columns.
>
> **Tip:** Filter rules remain in place during your session. Select **Reset** in the **Filters** panel before starting a new search so that earlier rules do not narrow the result.

## Summary

Use the search box for a quick look-up and the filter rules for precise questions, then choose and sort the columns you need and export the result with **Export CSV** or **Export selected (CSV)**. The list always reflects the repository chosen in the top bar.
