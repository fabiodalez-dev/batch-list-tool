<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Actions\Documents\DocumentActionGroup;
use App\Filament\Concerns\HandlesCustomFieldForm;
use App\Filament\Resources\DocumentResource;
use App\Filament\Support\DocumentDates;
use App\Models\Document;
use App\Models\DocumentIdentifierHistory;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\EditRecord;

class EditDocument extends EditRecord
{
    use HandlesCustomFieldForm;

    protected static string $resource = DocumentResource::class;

    private ?string $volumeBeforeSave = null;

    /** @var list<int> */
    private array $authorityIdsBeforeSave = [];

    /** @var list<int> */
    private array $historyIdsBeforeSave = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            // The 13 single-record power-actions live in a dropdown group
            // so the header stays scannable. ActionGroup is the Filament 5
            // way to nest header actions under a single trigger.
            ActionGroup::make(array_map(
                fn (Action $action): Action => $action->after(fn () => $this->reloadForm()),
                DocumentActionGroup::singleHeaderActions(),
            ))
                ->label('Actions')
                ->icon('heroicon-o-bolt')
                ->color('primary')
                ->button(),
            Actions\RestoreAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete document')
                ->modalDescription('This record will be deleted.'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->mutateFormDataBeforeSaveCustomFields($data);

        return DocumentDates::apply($data, $this->data['dates_as'] ?? null, $this->document());
    }

    /**
     * Runs before the form's relationships are saved, so the authorities and
     * history rows read here are the ones the document had before this save.
     */
    protected function beforeSave(): void
    {
        $record = $this->document();

        $this->volumeBeforeSave = $record->volume_number;
        $this->authorityIdsBeforeSave = $record->authorities()->pluck('authorities.id')->map(fn (mixed $id): int => (int) $id)->all();
        $this->historyIdsBeforeSave = $record->identifierHistory()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    protected function afterSave(): void
    {
        $this->saveCustomFields();
        $this->trackAttributionChanges();
        $this->reloadForm();
    }

    /**
     * The page stays open after a save and after a header action, and its next
     * save writes the form's state back: a history row it does not list would
     * be deleted, and an action's change undone. So the form always holds what
     * the document holds now.
     */
    private function reloadForm(): void
    {
        $this->getRecord()->refresh();
        $this->getRecord()->unsetRelations();
        $this->fillForm();
    }

    private function document(): Document
    {
        $record = $this->getRecord();
        if (! $record instanceof Document) {
            throw new \LogicException('The Edit Document page edits a document.');
        }

        return $record;
    }

    /**
     * Client 2026-11-05: "From volume 5 I want to change it to volume 4 but I
     * want to keep track of the old volume", and "keep track of to whom it used
     * to be attributed". Only here: creating a document or importing one has
     * nothing to keep. A past value typed by hand in this same save is already
     * a row, and is not written twice.
     */
    private function trackAttributionChanges(): void
    {
        $record = $this->document();

        $typedRows = $record->identifierHistory()->whereNotIn('id', $this->historyIdsBeforeSave)->get();

        $previousVolume = trim((string) $this->volumeBeforeSave);
        if ($previousVolume !== ''
            && $previousVolume !== trim((string) $record->volume_number)
            && ! $typedRows->contains(fn (DocumentIdentifierHistory $row): bool => trim((string) $row->previous_volume) === $previousVolume)) {
            DocumentIdentifierHistory::recordPastVolume($record, $previousVolume, $record->volume_number);
        }

        $currentAuthorityIds = $record->authorities()->pluck('authorities.id')->map(fn (mixed $id): int => (int) $id)->all();
        foreach (array_diff($this->authorityIdsBeforeSave, $currentAuthorityIds) as $authorityId) {
            if (! $typedRows->contains(fn (DocumentIdentifierHistory $row): bool => (int) $row->previous_authority_id === $authorityId)) {
                DocumentIdentifierHistory::recordPastAuthority($record, $authorityId);
            }
        }

        $record->unsetRelation('pastVolumes');
        $record->unsetRelation('pastAuthorities');
    }
}
