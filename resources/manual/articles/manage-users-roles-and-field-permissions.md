# How to manage users, roles and field permissions

Administrators control who may use the archive tool and what each person may see or change. This guide explains how to add a user, assign a role and repositories, reset a user's access, and use the field permission matrix to set read, write and hidden access for each role.

## Prerequisites

- [ ] You are signed in with the **Administrator** or **Super administrator** role.
- [ ] The repositories the new user will work in already exist on the **Repositories** page.

## 1. Open the Users page

In the sidebar, under **Administration**, select **Users**. The list shows each user with their **Roles** and active status. Select **New user** to open the form for a new user.

![Open the Users page](./block_open-users.png)

## 2. Enter the user's identity and initial password

In the **Identity** section, enter the user's **Name** and **Email**; the email address must not already be in use. In the **Initial access** section, enter a temporary password in **Password** and enter it again in the confirmation field beside it. New users are always required to change this password at their first sign-in, so **Force password change on next login** remains enabled.

![Enter the user's identity and initial password](./block_enter-identity.png)

## 3. Assign a role and repositories

In the **Role & access** section, select the user's **Role**: **Administrator** for full access, **ReadingRoom** for read and write access to metadata, or **General** for read-only access. **Super administrator** is listed only when you hold that role yourself. Select one or more **Repositories**, then choose the **Default repository**: the repository pre-selected for the user's new records, and the one their document and box imports use when **All repositories** is selected in the top bar. Leave **Is active** enabled and select **Create**.

## 4. Change an existing user's role or repositories

On the **Users** page, select **Edit** on the user's row. Change the **Role**, **Repositories** or **Default repository** as required, then select **Save changes**. You cannot change your own role or deactivate your own account.

## 5. Reset a user's access

If a user can no longer sign in, select **Reset password** on the user's row, then select **Confirm**. A notification displays a temporary password; pass it to the user by a secure channel, as they must replace it at their next sign-in. To suspend a user without deleting the account, select **Deactivate** instead, and select **Activate** later to restore access.

## 6. Open the field permission matrix

In the sidebar, under **Administration**, select **Field permissions**. The **Field-level permission matrix** shows one table per resource, with a row for each field and a column for each role.

![Open the field permission matrix](./block_open-field-permissions.png)

## 7. Set read, write and hidden access for each field

On each field's row, select the checkboxes under the **Administrator**, **ReadingRoom** and **General** columns: **R** lets the role read the field, **W** lets it edit the field, and **H** hides the field from that role. Write access includes read access, and hidden takes precedence over both. The **Super administrator** column always shows **RW** and cannot be changed.

## 8. Save the matrix

Select **Save changes**. The new permissions take effect for every user on their next page load.

## Tips

> **Tip:** Deactivate a user rather than deleting the account, so that the audit history linked to that user is preserved. A deactivated user is signed out at once and can no longer sign in.
>
> **Tip:** The four built-in roles (Super administrator, Administrator, ReadingRoom and General) cannot be renamed or deleted on the **Roles** page; roles you create there can.
>
> **Tip:** Only Administrators and Super administrators manage users and roles; ReadingRoom and General users do not see the **Users** and **Roles** pages.
>
> **Tip:** On the edit form, **Password** may be left blank to keep the user's current password.
>
> **Tip:** The **default (unlisted fields)** row of each table sets access for every field of that resource that has no row of its own.
>
> **Tip:** To discard every change made on the matrix, select **Reset to config defaults** and confirm; the permissions return to the baseline configuration.

## Summary

You have added a user with a role, repositories and a default repository, learned how to reset or suspend access, and set field-level access for each role on the **Field-level permission matrix**.
