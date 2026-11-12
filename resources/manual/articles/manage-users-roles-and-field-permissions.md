# How to manage users, roles and field permissions

Administrators decide who may sign in to the Logistics Tool, what each person may do and which repositories they work in. This guide explains how to add a user, reset another user's password, deactivate an account and adjust field-level access on the Field permissions page.

## Prerequisites

- [ ] You are signed in with an Administrator role. The Users and Field permissions pages are available only to administrators.
- [ ] The repositories you intend to assign to the user already exist.
- [ ] You have the new user's full name and email address.

## 1. Open the Users page

In the sidebar, under **Administration**, select **Users**. The list shows each user's **Name**, **Email**, **Roles** and whether the account is active.

![Open the Users page](./block_open-users.png)

## 2. Start a new user

Select **New user** at the top right of the page. The **Create User** form opens with three sections: **Identity**, **Initial access** and **Role & access**.

## 3. Enter the user's identity

In the **Identity** section, enter the user's **Name** and **Email**. The email address must not already belong to another user.

![Enter the user's identity](./block_enter-identity.png)

## 4. Set the initial password

In the **Initial access** section, enter a **Password** and repeat it in **Password confirmation**. The password must be at least 12 characters long and contain upper-case letters, lower-case letters and at least one number. **Force password change on next login** is always switched on for a new user, so the user must replace this password when they first sign in.

## 5. Assign a role and repositories

In the **Role & access** section, select a **Role**: **Administrator** for full access including permissions management, **ReadingRoom** for reading and editing records, or **General** for read-only access. Select one or more **Repositories**, then select the **Default repository**. The default repository is required: it is the repository the user works in when signing in, and the one imports use unless another is selected in the top bar. Leave the Is active switch on.

## 6. Create the account

Scroll to the bottom of the form and select **Create**. The user appears in the list and can sign in with the password you set. To add several users in succession, select **Create & create another** instead.

## 7. Reset another user's password

On the **Users** page, select the **Reset password** button (the key icon) in the user's row, then select **Confirm**. The tool generates a 16-character temporary password and displays it in a **Password reset** notification, which remains on screen until you close it. Copy the temporary password and pass it to the user by a secure means.

## 8. Ensure the user changes the temporary password

A reset always sets the account to require a password change. At the user's next sign-in, the tool opens the **Profile** page with the message **You must change your password before continuing.** and no other page is available until the user enters a **New password**, repeats it in **Confirm new password**, enters the temporary password as the **Current password** and selects **Save changes**.

## 9. Deactivate an account

On the **Users** page, select the **Deactivate** button (the no-entry icon) in the user's row, then select **Confirm**. A deactivated user can no longer sign in, and the Is active column shows a cross for that user. To restore access, select **Activate** in the same row. You cannot deactivate your own account or change your own role.

## 10. Open the field permission matrix

In the sidebar, under **Administration**, select **Field permissions**. The **Field-level permission matrix** shows one table per resource, with the fields in rows and the roles in columns.

## 11. Set read, write or hidden access for each role

For each field, tick the boxes under the role concerned: **R** allows the role to read the field, **W** allows it to change the field, and **H** hides the field from that role entirely. Write access implies read access, and hidden takes precedence over both. The first **Administrator** column, for `super_admin`, always shows **RW** and cannot be changed. The row **default (unlisted fields)** applies to every field of the resource that is not listed separately.

![Set read, write or hidden access for each role](./block_set-field-access.png)

## 12. Save the matrix

Select **Save changes**. The notification **Field permissions saved** confirms that the new matrix applies to every user on their next page load. To remove all adjustments and return to the original settings, select **Reset to config defaults** and confirm.

## Password rules and automatic sign-out

Every password set in the tool, whether on the user form, on the **Profile** page or after a reset, must be at least 12 characters long and contain upper-case letters, lower-case letters and at least one number. Existing passwords remain valid until they are next changed. By default, a session ends after 30 minutes without activity, after which the user must sign in again; users should therefore save their work before leaving the tool unattended.

## Tips

> **Tip:** Deactivate an account rather than deleting it: deactivation preserves the audit history linked to that user.
>
> **Tip:** When editing an existing user, leave **Password** blank to keep the current password.
>
> **Tip:** A user with full access sees **Administrator** twice in the **Role** list: the first entry grants full access, including restoring backups and the audit settings. Other administrators see only one **Administrator** entry and cannot grant full access.
>
> **Tip:** Use **Roles** under **Administration** to review what each role may do on whole pages, and **Field permissions** for individual fields.

## Summary

You can now add users with a role, repositories and a default repository, reset a forgotten password with a temporary one that must be changed at the next sign-in, deactivate accounts that are no longer needed, and control read, write and hidden access to individual fields for each role.
