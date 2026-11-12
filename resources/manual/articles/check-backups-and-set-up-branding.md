# How to check backups and set up branding

Administrators are responsible for confirming that the archive data is backed up and that the application presents the correct identity to staff. This guide explains how to review backup health, add or edit a backup destination, and change the application name and logo.

## Prerequisites

- [ ] An account with the administrator or super administrator role. Other roles cannot open these pages.
- [ ] For an off-site destination, the connection details supplied by your storage provider, such as the host, port and credentials.
- [ ] For a new logo, an image file of no more than 2 MB.

## 1. Open the Backup & health page

In the sidebar, under **Administration**, select **Backup & Health**. The page opens with three status cards, followed by **Backup archives**, **Run history** and **Retention policy**.

![Open the Backup & health page](./block_open-backup-health.png)

## 2. Review the backup health

Confirm that each status card is green. **Database** shows whether the application is connected to its database, **Latest backup** shows the name, date and size of the most recent archive, and **Local storage** shows the free space on the server disk where backups are written. An amber or red card requires attention, for example when no backup has been found or the disk is nearly full.

## 3. Check the archives and recent runs

Under **Backup archives**, confirm that recent archives are listed for each destination disk; select **Download** to obtain a copy of an archive. Under **Run history**, confirm that the most recent runs have completed rather than failed. To start a full backup immediately, select **Run backup now** and confirm with **Yes, run backup**.

## 4. Open the backup destinations

In the page header, select **Configure destinations**. On the **Backup Destinations** page, select **New Backup destination** to add a target, or select **Edit** on an existing row to change it.

## 5. Enter the destination details

Under **Destination**, enter a **Name** and choose a **Storage type**. Leave **Active** turned on, and turn on **Default destination** if this is to be the primary target. Under **Connection**, complete the fields for the chosen storage type, such as **Host**, **Port**, **Username** and **Password**, and then select **Create**.

![Enter the destination details](./block_fill-destination.png)

## 6. Edit an existing destination

When you edit a destination, change only the fields that require updating and select **Save changes**. Leave **Password** blank to keep the stored credential, because saved credentials are never displayed again.

## 7. Test the connection

On the **Backup Destinations** page, select **Test connection** on the row of the destination. A **Connection OK** notification confirms that the application can reach the destination; **Connection failed** indicates that the connection details must be corrected.

## 8. Open the Branding settings page

In the sidebar, under **Administration**, select **Branding**. The **Branding settings** page opens with the **Brand identity** and **Logo** sections.

## 9. Change the name and logo

Under **Brand identity**, enter the **Application name** and, if required, a **Logo height (CSS)** such as `2.25rem`. Under **Logo**, add an image to **Upload new logo**, and then select **Save changes**. The new branding is applied on the next page load.

![Change the name and logo](./block_edit-branding.png)

## Tips

> **Tip:** Only one destination can be the default. Turning on **Default destination** for one target clears it on all others.
>
> **Tip:** Turn off **Active** to suspend a destination without deleting its configuration.
>
> **Tip:** To return to the brand name shown as text, turn on **Remove current logo** and save, or select **Remove logo** in the page header.
>
> **Tip:** The application colours follow the Notarial Archives Foundation slate-green palette, which is defined in the application configuration. They are not changed on the **Branding settings** page; contact the person responsible for the installation if a different palette is required.

## Summary

You have reviewed backup health, added or edited a backup destination and updated the application name and logo. Review the **Backup & health** page regularly to confirm that backups continue to run successfully.
