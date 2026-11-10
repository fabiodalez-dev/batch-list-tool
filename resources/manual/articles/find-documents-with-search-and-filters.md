# How to find documents with search and filters

The Documents list holds every notarial document in the catalogue. This guide explains how to narrow the list to the records you require by means of the search box and filter rules, how to choose and sort the columns shown, and how to export the result.

## Prerequisites

- [ ] A user account with permission to view documents.
- [ ] Access to the repository whose documents you wish to consult.

## 1. Open the Documents list

In the sidebar, under **Records**, select **Documents**. The list opens sorted by identifier, with the search box, the **Filter** button and the **Column manager** button above the table.

![Open the Documents list](./block_open-documents.png)

## 2. Choose the repository

The list shows only the documents of the repository chosen in the top bar. Select a repository in the repository selector to the right of the top bar, or select **All repositories** to consult every repository you are permitted to access.

## 3. Search the list

Type a term in the **Search** box above the table. The search looks across identifiers, the document type, barcodes, the catalogue identifier, the linked authorities, the subseries, the batch, the box, the location and open flags, and the list updates as you type.

## 4. Open the filters

Select the **Filter** button at the top right of the table. The **Filters** panel opens at the side of the page.

## 5. Build a filter rule

Select **Add rule** and choose the field to filter on, for example **Subseries** or **Volume**. In the rule that appears, choose a condition in the list headed by the field name, such as **Equals**, **Contains** or **Starts with**, and enter the value in **Text**.

![Build a filter rule](./block_add-rule.png)

## 6. Combine rules

Select **Add rule** again to add further rules. Above each rule after the first, choose how it joins the rules above it: **AND**, **OR** or **AND NOT**. The rules read from top to bottom, each one joining everything above it. For example, Authority `R16`, then **OR** Authority `R12`, then **AND** Document Type `Bastardello` finds the Bastardelli of either authority. The list of fields under **Add rule** scrolls, and you can type part of a field name to find it. Below the rules, **Deleted records** decides whether deleted documents are included.

## 7. Apply the filters

Select **Apply filters** at the foot of the panel. The list shows only the matching documents, and the filters remain in place when you leave the page and return to it during your session.

## 8. Choose the columns shown

Select the **Column manager** button beside the **Filter** button. Tick the columns you wish to see, drag a column by its handle to change the order, and select **Apply columns**; select **Reset** to return to the default columns.

![Choose the columns shown](./block_choose-columns.png)

## 9. Sort the list

Every column can be sorted. Select a column heading, such as **Document Type** or **Location**, to sort the list by that column. Select the same heading again to reverse the order.

## 10. Export the filtered list

Select **Export CSV** at the top of the page. The file contains the documents the list currently shows, after the search, the filters and the repository selection are applied, in the same columns as the documents import template.

## Tips

> **Tip:** Rules on text fields start on **Equals**; choose **Contains** when you know only part of the value.
>
> **Tip:** If the list appears empty or incomplete, confirm that the correct repository is selected in the top bar.
>
> **Tip:** To remove every filter at once, open the **Filters** panel and select **Reset**.

## Summary

You can now locate documents by searching, by building and combining filter rules, and by choosing and sorting the columns shown. The **Export CSV** button then provides the filtered list as a file for further work.
