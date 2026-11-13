# How to manage users, roles and field permissions

Administrators decide who may sign in to the Logistics Tool, what each person may do and which repositories they work in. This guide explains how to add a user, reset another user's password or two-factor authentication, deactivate an account and adjust field-level access on the Field permissions page.

## Prerequisites

- [ ] You are signed in with the Administrator or Super administrator role. The Users and Field permissions pages are not available to the ReadingRoom and General roles.
- [ ] To reset another user's two-factor authentication, you are signed in with the Super administrator role.
- [ ] The repositories you intend to assign to the user already exist.
- [ ] You have the new user's full name and email address.

## 1. Open the Users page

In the sidebar, under **Administration**, select **Users**. The list shows each user's **Name**, **Email**, **Roles** and whether the account is active, with the actions for each user at the end of the row.

![Open the Users page](./block_open-users.png)

## 2. Start a new user

Select **New user** at the top right of the page. The **Create User** form opens with three sections: **Identity**, **Initial access** and **Role & access**.

## 3. Enter the user's identity

In the **Identity** section, enter the user's **Name** and **Email**. The email address must not already belong to another user.

![Enter the user's identity](./block_enter-identity.png)

## 4. Set the initial password

In the **Initial access** section, enter a **Password** and repeat it in **Password confirmation**. The password must be at least 12 characters long and contain upper-case letters, lower-case letters and at least one number. **Force password change on next login** is always switched on for a new user, so the user must replace this password at the first sign-in.

## 5. Assign a role and repositories

In the **Role & access** section, select a **Role**: **Super administrator**, **Administrator**, **ReadingRoom** or **General**. Select one or more **Repositories**, then select the **Default repository**, which is required: it is pre-selected as the repository of new records, and imports started with **All repositories** in the top bar use it. Leave **Is active** switched on.

## 6. Create the account

Scroll to the bottom of the form and select **Create**. The user appears in the list and can sign in with the password you set. To add several users in succession, select **Create & create another** instead.

## 7. Reset another user's password

On the **Users** page, select the **Reset password** button (the key icon) in the user's row, then select **Confirm**. The tool generates a 16-character temporary password and displays it in a **Password reset** notification, which remains on screen until you close it. Copy the temporary password and give it to the user by a secure means.

## 8. Ensure the user changes the temporary password

A reset always requires the user to change the password. At the next sign-in, the tool opens the **Profile** page with the message **You must change your password before continuing.**, and no other page is available until the user enters a **New password**, repeats it in **Confirm new password**, enters the temporary password as the **Current password** and selects **Save changes**.

## 9. Reset another user's two-factor authentication

If a user has lost the device that holds their authenticator, select **Reset two-factor authentication** (the red mobile telephone icon) in the user's row on the **Users** page, or at the top of the user's view or edit page, then confirm with **Reset two-factor authentication**. The user's authenticator secret and recovery codes are deleted, so the user signs in with the password alone until two-factor authentication is set up again under **My Account** > **Two-factor authentication**. The action is available only to a Super administrator, only for users who have two-factor authentication set up and never for your own account, and every reset is recorded in the **Audit log**.

## 10. Deactivate an account

On the **Users** page, select the **Deactivate** button (the no-entry icon) in the user's row, then select **Confirm**. A deactivated user who is signed in is signed out on the next request, any later sign-in attempt is refused, and the **Is active** column shows a cross. To restore access, select **Activate** in the same row. You cannot deactivate your own account or change your own role.

## 11. Open the field permission matrix

In the sidebar, under **Administration**, select **Field permissions**. The **Field-level permission matrix** shows one table per resource, with the fields in rows and the roles in columns.

## 12. Set read, write or hidden access for each role

For each field, tick the boxes under the role concerned: **R** allows the role to read the field, **W** allows it to change the field, and **H** hides the field from that role entirely. Write access implies read access, and hidden takes precedence over both. The **Super administrator** column always shows **RW** and cannot be changed, and the row **default (unlisted fields)** applies to every field of the resource that is not listed separately.

![Set read, write or hidden access for each role](./block_set-field-access.png)

## 13. Save the matrix

Select **Save changes**. The notification **Field permissions saved** confirms that the new matrix applies to every user on their next page load. To remove all adjustments and return to the original settings, select **Reset to config defaults** and confirm.

## The four built-in roles

**Super administrator** has full access, including restoring backups, the audit settings and resetting another user's two-factor authentication. **Administrator** manages records, users, roles and field permissions, but cannot change, reset or deactivate a Super administrator account. **ReadingRoom** reads, creates and edits records but has no access to users or roles, and **General** has read-only access. These four roles cannot be renamed or deleted under **Roles**, and no other role can be given one of their names; roles that you create yourself can be renamed and deleted.

## Password rules and automatic sign-out

Every password set in the tool, whether on the user form, on the **Profile** page or after a reset, must be at least 12 characters long and contain upper-case letters, lower-case letters and at least one number. Existing passwords remain valid until they are next changed. By default, a session ends after 30 minutes without activity, after which the user must sign in again.

## Tips

> **Tip:** Deactivate an account rather than deleting it: deactivation preserves the audit history linked to that user.
>
> **Tip:** When editing an existing user, leave **Password** blank to keep the current password.
>
> **Tip:** Only a **Super administrator** can give another user the **Super administrator** role; other administrators do not see it in the **Role** list.
>
> **Tip:** Use **Roles** under **Administration** to review what each role may do on whole pages, and **Field permissions** for individual fields.

## Summary

You can now add users with a role, repositories and a default repository, reset a forgotten password with a temporary one that must be changed at the next sign-in, reset the two-factor authentication of a user who has lost their device, deactivate accounts that are no longer needed, and control read, write and hidden access to individual fields for each role.
