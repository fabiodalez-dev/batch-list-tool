# How to send boxes to disinfestation and record the end of a round

Boxes are sent to disinfestation, and brought back at the end of the round, from the Boxes list. This guide explains how to send one or several boxes, how to record the end of a round with its date and destination, which documents travel with each box, how to handle a single document, and how to see what is still pending.

## Prerequisites

- [ ] Permission to edit both boxes and documents. Without it, the box disinfestation actions are not available.
- [ ] An active location named **Disinfestation** for the repository of the boxes and, for boxes that return to RAS, an active location named **RAS**. Both are maintained under **Locations**.
- [ ] For boxes that go to the archive instead, an active location of type Repository or Room to receive them.

## 1. Select the boxes on the Boxes list

Select **Boxes** in the sidebar. Tick the checkbox of each box to be sent, narrowing the list with the filter rules first if necessary, and then select **Power actions** above the table.

![Select the boxes on the Boxes list](./block_select-boxes.png)

## 2. Send the boxes to disinfestation

In the **Power actions** menu, select **Send to disinfestation**; a confirmation dialog opens.

## 3. Confirm the sending

The dialog **Send the selected boxes to disinfestation** summarises what will change. Select **Confirm** to proceed, or **Cancel** to leave the boxes as they are. After **Confirm**, the boxes move to the location **Disinfestation**, RAS boxes take the barcode status **OUT**, and a notification states how many boxes were sent. A destroyed box, a RAS box at **PERM OUT** and a box already in disinfestation are skipped and named in the notification.

![Confirm the sending](./block_confirm-send.png)

## Which documents follow their box

Only the documents physically in the box, those whose **Custody Status** is **In Box**, travel with it. They are marked as currently in disinfestation: the **Disinfested Date** column of the **Documents** list shows **Current Cycle**, and the document page shows **Currently in Disinfestation** as **Yes**. A document linked to the box but kept elsewhere, for example **Not in Box**, stays where it is. Whenever a box moves, the documents in it take the location of the box.

## 4. Record the end of the round

When the boxes come back, select them on the **Boxes** list, select **Power actions** and then **Finish disinfestation**. Only boxes at the location **Disinfestation** are finished; any other box is skipped.

## 5. Enter the date and the destination

Enter the **Disinfestation date**: it defaults to today and cannot be in the future. Under **Destination**, choose **Return to RAS** or **To an archive location (NRA)**; for the latter, choose the **Archive location**. Select **Submit**. **Return to RAS** moves the boxes to the location **RAS** and sets RAS boxes **IN**; an archive location sets RAS boxes **PERM OUT**. The date becomes the latest disinfestation date of every document that travelled with its box.

![Enter the date and the destination](./block_enter-date-destination.png)

## One round per date

Each date counts as one round. When a document already holds an earlier date, that date is kept in its history and the new date becomes the current one; finishing again with the same date does not add a second round. The rounds are listed under **History of Disinfestation** on the document page. A document holds at most four dates: if a box contains a document that already holds four different dates, the box is skipped and those documents are named in the notification.

## 6. Send or mark a single document

To handle one document on its own, open it from the **Documents** list, select **Actions** at the top of the page and choose **Send to disinfestation** or **Mark disinfested**; the latter asks for the **Disinfestation date**. On the **Documents** list, the **Disinfested** icon on a row marks that document directly, and both actions are also available under **Power actions** for several selected documents. A box returns to **IN** only when the last of its documents in disinfestation has been marked.

## 7. See what is pending

Select **Reports** in the sidebar. **Documents pending disinfestation** lists the documents without a disinfestation date that are not PERM_OUT, with the **Days waiting** of each. **Boxes pending disinfestation** lists the boxes at status IN that hold at least one document (In Box) with no disinfestation date, with the number of **Documents pending**; select boxes there and choose **Send to disinfestation**, exactly as on the Boxes list.

## Tips

> **Tip:** The notification shown after each box action stays on screen until it is closed, and lists every skipped box with the reason.
>
> **Tip:** Hover over a date in the **Disinfested Date** column to see the earlier rounds; **from box** under a date means that the document shows the date of its box.
>
> **Tip:** Every change made by these actions is recorded in the **Audit log**.

## Summary

Send boxes with **Send to disinfestation** and close the round with **Finish disinfestation**, giving the date and the destination. The documents in each box follow it, single documents have their own actions, and the two pending reports show what still needs to go.
