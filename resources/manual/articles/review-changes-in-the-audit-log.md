# How to review changes in the audit log

The **Audit log** records every change made to the records held in the Archive Tool: who made the change, when it was made, and the old and new value of each field. This guide explains how to open the log, narrow it with filters, inspect an individual entry and, for super-administrators, where the audit trail itself is configured.

## Prerequisites

- [ ] An account with permission to view the **Audit log**. By default every role (Super administrator, Administrator, ReadingRoom and General) has this permission.
- [ ] The super-administrator role, if you need to change the **Audit settings**.

## 1. Open the Audit log

In the sidebar, under **Administration**, select **Audit log**. The table lists the most recent changes first, showing for each entry **When** it occurred, **Who** made it, the event, the **Model** and **Record** concerned, and a summary of the **Changes**.

![Open the Audit log](./block_open-audit-log.png)

## 2. Filter the entries

Select the **Filter** icon above the table to open the **Filters** panel. Choose a user in **Who**, a record type in **Model** and an event in **Event**, then enter a date range in **From** and **To**. If required, enter a column name in **Field changed** or a number in **Record ID**, and select **Apply filters** to update the list.

## 3. Open an entry

Select **View** at the end of a row to open that entry. The **Context** section shows when the change was made, the event, the user and their email address, the model and the **Record ID**, together with the IP address and the URL from which the change was made.

![Open an entry](./block_open-entry.png)

## 4. Compare the old and new values

Select **Old values** to expand the list of each changed **Field** with its **Old value**, then select **New values** to see the **New value** recorded for the same fields. To open the record that was changed, select the number shown under **Record ID**.

## 5. Configure the audit trail (super-administrators)

In the sidebar, under **Administration**, select **Audit settings**. Use **Enable audit trail** to turn the recording of changes on or off, and, if required, enter a value in **Retention threshold (days)**. Note that at present audit records are kept indefinitely, whatever value is entered in this field. Select **Save changes** to apply the settings.

![Configure the audit trail (super-administrators)](./block_audit-settings.png)

## Tips

> **Tip:** The **Export CSV** button above the table downloads the entries currently shown, with one row per changed field, including the old and new values.
>
> **Tip:** When a summary in the **Changes** column is shortened, the complete text appears as a tooltip over the cell.
>
> **Tip:** An entry for a newly created record has no **Old values** section, and an entry for a deleted record has no **New values** section.
>
> **Tip:** Audit entries cannot be edited or deleted from the panel, which preserves the integrity of the trail. When **Enable audit trail** is off, no new entries are written, but existing entries are retained.

## Summary

The **Audit log** shows who changed which record and when, the filters narrow it to a user, record type, event or period, and each entry lists the old and new value of every changed field. Super-administrators control whether changes are recorded, and for how long they are kept, on the **Audit settings** page.
