<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Concerns\HandlesCustomFieldForm;
use App\Filament\Resources\DocumentResource;
use App\Models\Box;
use Filament\Resources\Pages\CreateRecord;

class CreateDocument extends CreateRecord
{
    use HandlesCustomFieldForm;

    protected static string $resource = DocumentResource::class;

    /**
     * Feedback1 Wave B (B6) — "Add document to this box" lands here with
     * ?current_box_id=<id>. Pre-fill the form so the new document is already
     * assigned to that box (the operator only fills the rest).
     *
     * Validated against an existing, non-destroyed Box so a stale / forged
     * query param is silently ignored rather than creating a dangling FK.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $boxId = $this->prefillBoxId();

        // Pass the prefill straight to fill(): Filament merges it with each
        // field's own default, so the box lands pre-selected while every other
        // default (repository, custody_status, …) is still applied.
        $this->form->fill($boxId !== null ? ['current_box_id' => $boxId] : []);

        $this->callHook('afterFill');
    }

    /**
     * Defence in depth: even if the field is hidden by field-permissions, the
     * box assignment from the query param is still applied on create.
     * Also strips the 'custom' sub-array so it does not reach Eloquent's fill()
     * (delegated to HandlesCustomFieldForm::mutateFormDataBeforeCreate via
     * explicit call because this class method shadows the trait method).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Strip and stash custom fields (HandlesCustomFieldForm logic).
        // Calling the trait method explicitly because this class method
        // shadows the trait's version in PHP's method resolution order.
        $data = $this->mutateFormDataBeforeCreateCustomFields($data);

        if (empty($data['current_box_id'])) {
            $boxId = $this->prefillBoxId();
            if ($boxId !== null) {
                $data['current_box_id'] = $boxId;
            }
        }

        return $data;
    }

    /**
     * Resolve a valid, non-destroyed Box id from the `current_box_id` query
     * param, or null when absent / invalid.
     */
    private function prefillBoxId(): ?int
    {
        $raw = request()->query('current_box_id');
        if ($raw === null || $raw === '' || ! ctype_digit((string) $raw)) {
            return null;
        }

        $box = Box::query()->whereKey((int) $raw)->first();
        if ($box === null || $box->isDestroyed()) {
            return null;
        }

        return (int) $box->getKey();
    }
}
