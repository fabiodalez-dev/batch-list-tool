# How to check backups and set up branding

This guide is for administrators of Logistics Tool. It explains how to confirm on the **Backup & Health** page that backups are running, how to add or edit a backup destination and test its connection, and how to change the application name and logo on the **Branding** page.

## Prerequisites

- [ ] An account with the Administrator role. Other roles do not see these pages in the sidebar.
- [ ] For an off-site destination, the connection details supplied by your IT department (server address, port, user name and password or key, or the storage bucket details).
- [ ] For a new logo, an image file of no more than 2 MB.

## 1. Open the Backup & Health page

In the sidebar, under **Administration**, select **Backup & Health**. The **Backup & health** page opens with three status cards at the top, followed by the **Backup archives** list, the **Run history** table and the **Retention policy** form.

![Open the Backup & Health page](./block_open-backup-health.png)

## 2. Read the status cards

Check the three cards at the top of the page. **Database** shows **Connected** in green when the application can reach its database. **Latest backup** shows the file name, date and time (dd-mm-yyyy hh:mm) and size of the most recent archive on this server; an amber card reading **No backups found** means no archive exists yet. **Local storage** shows the free space on the server disk where backups are written, marked **(healthy)** below 75% used, **(getting full)** from 75% to 90% and **(low space)** above 90%.

## 3. Review the backup archives

The **Backup archives** list shows every `.zip` archive held on the configured destinations, newest first, with its **File**, **Disk**, **Size** and **Date**. Select **Download** to save a copy of an archive. Select **Delete** only when an archive is no longer required; you are asked to confirm, and the deletion cannot be undone. Administrators with full access also see **Restore**, which overwrites the live database and asks you to tick an acknowledgement and type the current database name before it proceeds.

## 4. Check the run history

The **Run history** table lists the 15 most recent runs with their **Type**, **Status**, **Started**, **Finished**, **Duration**, **Size** and **Message**. A green **Success** badge confirms a completed backup or cleanup and a red **Failed** badge requires attention. A backup started from this page appears as **Running** with the message **Queued from Backup Center**; when it finishes, a separate row of type BACKUP records the result.

## 5. Run a backup on demand

Backups run automatically every night at 02:30 (server time). To take an additional backup, select **Run backup now** at the top of the page and confirm with **Yes, run backup**. To back up only the database or only the application files, select **Run DB-only** or **Run files-only** instead. The backup runs in the background, so you may leave the page.

## 6. Record the retention policy

If the retention policy needs to change, scroll to **Retention policy** and enter the new values in **Keep daily backups** (days), **Keep weekly backups** (weeks) and **Keep monthly backups** (months). Select **Save retention** to store them. Note that the nightly cleanup does not currently use these values: archives are kept according to the application's fixed retention rules (16 days, 8 weeks and 4 months).

## 7. Add a backup destination

Select **Configure destinations** at the top of the **Backup & health** page, or **Backup destinations** in the sidebar, and then select the button labelled "New Backup destination" at the top of the list. Under **Destination**, enter a **Name** and choose the **Storage type**: **Local (this server's disk)**, **FTP**, **SFTP (SSH)** or **S3 / S3-compatible**. Leave **Active** switched on, and switch on **Default destination** only for your primary target.

![Add a backup destination](./block_add-destination.png)

## 8. Enter the connection details

The **Connection** section adapts to the storage type. For FTP or SFTP, enter the **Host**, **Port**, **Username** and **Password** (for SFTP you may paste a **Private key** instead), and optionally a **Root path**. For S3, enter the **Access key ID**, **Secret access key**, **Region** and **Bucket**. Select **Create** to save the destination.

## 9. Edit an existing destination

In the **Backup destinations** list, select **Edit** on the destination's row and change the fields that require updating. The password, key passphrase and secret key fields are always empty when you open the form; leave them empty to keep the stored credential, or type a new value to replace it. Select **Save changes**.

## 10. Test the connection

In the **Backup destinations** list, select **Test connection** on the destination's row. The application writes and removes a small test file on the destination and reports **Connection OK**, or **Connection failed** with the reason returned by the server. To make another destination the default, select **Set default** on its row.

## 11. Open the Branding page

In the sidebar, under **Administration**, select **Branding**. The **Branding settings** page opens with the **Brand identity** and **Logo** sections.

![Open the Branding page](./block_open-branding.png)

## 12. Change the application name

Under **Brand identity**, enter the new name in **Application name**. The name is shown in the browser tab title. If the logo appears too large or too small, adjust **Logo height (CSS)** with a value such as `2.25rem` or `36px`.

## 13. Change the logo

Under **Logo**, **Current logo** shows the logo in use. Drag an image onto **Upload new logo**, or select the field to choose a file. To return to the default logo instead, switch on **Remove current logo**, or select **Remove logo** at the top of the page and confirm. Select **Save changes**; the new name and logo appear on the next page load.

## About colours

The **Branding settings** page has no colour field. The colours are fixed in the application theme: the sidebar and top bar use the NAF Knowledge shade `#2D4A50`, the selected sidebar item `#415C63`, and buttons and badges `#4A6F77`. A change of colour requires a change to the application by its developer.

## Tips

> **Tip:** Check the **Run history** after each scheduled night; a **Failed** row is the earliest sign that a destination or the server needs attention.
>
> **Tip:** Keep at least one off-site destination active, so that a failure of this server does not lose the archive data.
>
> **Tip:** Switching **Active** off on a destination keeps its settings but excludes it from backups until you switch it on again.
>
> **Tip:** The uploaded logo is used on light pages such as the sign-in page. The sidebar and top bar always show the white NAF Knowledge logo.

## Summary

You can now confirm on the **Backup & Health** page that backups are healthy, run a backup on demand, manage and test backup destinations, and change the application name and logo. Colours remain fixed by the application theme.
