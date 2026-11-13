# How to review changes in the audit log

The audit log is a read-only record of the changes made in the Logistics Tool: who made each change, when it was made, which record it affected and the old and new value of every field. This guide explains how to find and filter entries, compare old and new values, export the results and, for Super administrators, configure the audit trail and its retention.

## Prerequisites

- [ ] An account whose role includes the audit permission. If **Audit log** does not appear in the sidebar, ask your administrator for access.
- [ ] Be aware of what your role shows: ReadingRoom and General users see only the entries of records in the repositories they can see, while Administrators and Super administrators see the whole log.
- [ ] A Super administrator account, if you need to open the **Audit settings** page.

## 1. Open the audit log

In the sidebar, under **Administration**, select **Audit log**. The page lists the most recent changes first, with the columns **When**, **Who**, **Event**, **Model**, **Record** and **Changes**; dates and times are shown as dd-mm-yyyy hh:mm:ss. The **Event** badge is coloured by type: green for created, amber for updated and red for deleted records.

![Open the audit log](./block_open-audit-log.png)

## 2. Read the entries in the list

Each row is one recorded event. For an updated record, the **Changes** column lists every field that changed, each followed by its old value, an arrow and its new value, with linked records shown by name; where the text is shortened, hover over it to read it in full. A dash in **Who** means that the change was made by the system rather than by a user.

## 3. Add filter rules

Select **Filter** above the table to open the **Filters** panel, then select **Add rule** and choose what to filter on: **When**, **Who**, **Event**, **Model**, **Record ID** or **Field changed**. Complete the rule, for example **Who** with one or more users, **Event** with **Updated** or **Deleted**, or **When** with **Is after** and a date in dd-mm-yyyy format. The **Event** rule also lists the two-factor events: **Two-factor enabled**, **Two-factor disabled** and **Two-factor reset by a super administrator**.

![Add filter rules](./block_add-filter-rules.png)

## 4. Combine the rules and apply them

For every rule after the first, choose how it joins the rules above it: **AND**, **OR** or **AND NOT**. The rules are read from top to bottom, so a rule joined with **OR** widens the rules above it and a rule joined with **AND NOT** excludes its matches. Select **Apply filters** to update the list.

## 5. Open an entry and compare the values

Select **View** on the row you wish to examine. The **Context** section shows when the change was made, the event, the user, the model and the **Record ID**, which links to the record where a page for it exists. Select the **Old values** and **New values** headings to expand them and compare each **Field** with its **Old value** and **New value**.

![Open an entry and compare the values](./block_open-entry.png)

## 6. Export the entries shown

Select **Export CSV** at the top of the audit log. The file contains the entries currently shown, with your filters and search applied, as one row per changed field: when, who, event, record type, record, field, old value, new value and IP address.

## 7. Export the history of a box or document

To export the history of a single record, open the box or document and go to its **History** tab, which combines the audit trail with barcode, seal, location, box move, identifier and flag changes. Select **Export history (CSV)** to download the whole history of that record.

## 8. Configure the audit trail (Super administrators only)

Under **Administration**, select **Audit settings** and, in the **Audit configuration** section, use **Enable audit trail** to turn recording on or off; when it is off, no new entries are written and existing entries are kept. In **Retention threshold (days)**, enter how many days entries are kept: every night at 03:30 a cleanup deletes the entries older than that number of days, and 0 keeps every entry indefinitely. Select **Save changes** to store the settings, which take effect on the next request.

## Tips

> **Tip:** Entries in the audit log cannot be created, edited or deleted from the application, which preserves the integrity of the trail; only the nightly cleanup removes entries older than the retention threshold.
>
> **Tip:** Before you lower the **Retention threshold (days)** value, use **Export CSV** to keep a copy of any older entries you still need, because the next nightly cleanup deletes them from the audit log.
>
> **Tip:** Use the **Field changed** rule with a field name to find every change to one field, for example every change to a box location.
>
> **Tip:** The **Record ID** column is hidden by default, as are the IP address and user agent columns; show them from the column manager above the table.
>
> **Tip:** The **Old values** and **New values** sections show values as they are stored, so a linked record may appear as its number; the **Changes** column in the list shows the same change with the record name.

## Summary

Open **Audit log** under **Administration**, narrow the list with filter rules, select **View** to compare old and new values, and use **Export CSV** or **Export history (CSV)** to keep a copy. Super administrators turn recording on or off and set how long entries are kept on the **Audit settings** page.
