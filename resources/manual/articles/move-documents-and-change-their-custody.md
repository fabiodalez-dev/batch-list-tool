# How to move documents and change their custody

The document actions move documents between boxes, take them out of a box, send them to a museum or to disinfestation, reclassify them and change their authorities. Each action can be applied to many documents at once from the **Documents** list, or to a single document from its own page, and each one records what it changed.

## Prerequisites

- [ ] A role that is permitted to edit documents. Without it, the document actions are not shown.
- [ ] A role that is permitted to view documents, for **Export selected (CSV)**.
- [ ] An Administrator account with full access, for **Move to repository (cross-tenant)**. Other roles do not see this action.
- [ ] For moves and museum loans: the target box, and an active location, already recorded in the repository of the documents.

## 1. Select the documents and open Power actions

In the **Documents** list, narrow the rows with the filter rules if necessary, and tick the checkbox of each document you wish to change. Then select **Power actions** to open the menu of document actions; the deleting actions are grouped separately at the bottom of the same menu.

![Select the documents and open Power actions](./block_select-documents.png)

## 2. Alternatively, act on a single document from its page

Open the document and select **Actions** at the top of its page; the same actions are listed there, except **Export selected (CSV)** and **Move to repository (cross-tenant)**, which are available on the list only. On the **Documents** list, each row also offers two icon buttons for the most frequent actions: **Move box** and **Disinfested**.

![Alternatively, act on a single document from its page](./block_open-document-actions.png)

## 3. Move documents to a box

Select **Move to box**, search for the **Target box** (for example by box number, or by batch and box), optionally choose a **Location (optional)** and enter a **Reason (optional)**, then select **Submit**. Each document is placed in the target box, takes the batch of that box (if the box has one) and receives the custody status In Box. Its location becomes the one you chose or, if you left it empty, the location of the target box; the move is recorded in the document's **Movement history** with the previous box, the new box, the date, the reason and your user name.

![Move documents to a box](./block_move-to-box.png)

## When a move to a box is refused

The move is refused if the target box is destroyed or permanently transferred out, and for any document whose repository differs from that of the target box. A location that belongs to another repository is also refused for that document.

## 4. Move documents out of their box

Select **Move out of box**, choose the new **Custody Status** (any status except In Box, as defined under Custody Status Types) and the **Location** where the documents now are, then select **Submit**. The documents are removed from their box and receive the chosen custody status and location; their batch is not changed. The removal is recorded in the **Movement history** as a movement with no target box and the reason "Moved out of box" followed by the new status.

## 5. Send documents to a museum

Select **Send to museum**, choose the **Museum / showcase** location (only active museum and showcase locations are offered), enter the **Museum reference code**, choose the **Custody Status** (Not in Box or Mounted; no box) and, if required, add **Notes (optional)**, then select **Submit**. The documents are removed from their box and receive the museum location, the reference code and the chosen custody status; any notes are appended to the existing notes with a dated prefix. The removal is recorded in the **Movement history** with the reason "Sent to museum" and the location name.

## 6. Reclassify documents into another subseries

Select **Set subseries**, choose the **Target subseries** and select **Submit**. Only the subseries of the documents changes. If the subseries is a wills subseries, the confirmation message reminds you that the documents may belong in Batch 50; to move them, create a box under Batch 50 and use **Move to box**.

## 7. Set the document type

Select **Set document type**, choose a **Document type** from the active document types and select **Submit**. The chosen type replaces the current one on every selected document; new types are created on their own page, not from this action.

## 8. Add an authority

Select **Add authority**, search for the **Authority (notary)**, switch on **Mark as primary authority** if it is the principal authority, and select **Submit**. Documents that already have that authority are skipped. Each attachment is written to the audit log with the authority and its primary status.

## 9. Replace an authority

Select **Replace authority**, choose the **Authority to replace** and the **New authority**, then select **Submit**. The action opens only when every selected document has at least one authority in common; otherwise a message explains that the documents have no authority in common. The new authority keeps the primary or secondary status of the one it replaces, the old authority is kept in the document's past authorities with the note "Replaced by" and the new name, and the change is written to the audit log.

## 10. Detach an authority

Select **Detach authority**, choose the **Authority to detach** and select **Submit**. The authority is removed from the documents that have it; the others are skipped. The removed authority is kept in the document's past authorities with the note "Detached", and the change is written to the audit log.

## 11. Send documents to disinfestation

Select **Send to disinfestation** and then **Confirm**. Each document is marked as currently in disinfestation and its barcode status becomes OUT; for a document in a box the status is set on the box, so every document in that box shows OUT. Documents already in disinfestation are skipped, and each change is written to the audit log.

## 12. Mark documents as disinfested

When the documents return, select **Mark disinfested**, check the **Disinfestation date** (today by default; future dates are not accepted) and select **Submit**. The date becomes the **Latest Disinfestation Date**, the documents are no longer in disinfestation and the barcode status returns to IN. The barcode status is not changed for a box that is permanently transferred out, nor while other documents in the same box are still in disinfestation; each change is written to the audit log.

## 13. Move documents to another repository

Super-admin users only: on the **Documents** list, select **Move to repository (cross-tenant)**, choose the **Target repository**, enter the **Reason (required for audit log)** (at least 8 characters), leave **Clear box and batch on transferred documents** switched on unless there is a reason not to, and confirm. The documents, together with their flags and identifier history, move to the target repository, and their box and batch are cleared when the switch is on. The transfer is written to the audit log with the previous repository, the new repository and your reason.

## 14. Export the selected documents

Select **Export selected (CSV)** to download the selected rows as a CSV file. The file uses the columns of the documents import template, so it can be edited and imported again; your field permissions apply to it. This action does not change any document.

## 15. Check the result

After each action, a notification states how many documents were changed; if some could not be changed, it lists up to five of them with the reason. The selection is cleared when the action finishes. The record of each change is shown on the document's page under **Movement history**, **Location history** and **History**.

## Tips

> **Tip:** Each document is processed on its own: if one document fails, the documents already changed keep their changes, and the notification names the documents that failed.
>
> **Tip:** To return a document that was moved out of its box, or sent to a museum, use **Move to box**; it sets the custody status back to In Box.
>
> **Tip:** **Send to museum**, **Send to disinfestation** and **Replace authority** are shown in amber, and **Detach authority** and **Move to repository (cross-tenant)** in red, because their effects are harder to undo.

## Summary

Select the documents on the **Documents** list and open **Power actions**, or open **Actions** on a document's page, then choose the action and complete its form. Box moves are recorded in the movement history, location changes in the location history, and authority, disinfestation and repository changes in the audit log.
