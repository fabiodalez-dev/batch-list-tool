<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Actions\Documents\DocumentActionGroup;
use App\Filament\Resources\DocumentResource;
use Filament\Actions;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class ViewDocument extends ViewRecord
{
    protected static string $resource = DocumentResource::class;

    /**
     * Page heading override — replaces the default `"View 42"` with a
     * speaking title built from the document's archival identifier and
     * its primary author, e.g. `"R45 — Abela Antonio"`.
     *
     * The primary author is the one flagged `is_primary` on the pivot;
     * if no flag is set we fall back to the first attached author so the
     * heading never silently drops to `—`. If the document has no
     * identifier OR no author, we degrade to `"Document #<id>"` instead
     * of producing a heading with awkward dashes.
     */
    public function getTitle(): string|Htmlable
    {
        $record = $this->record;
        $identifier = trim((string) ($record->identifier ?? ''));

        $primary = $record->authorities->firstWhere('pivot.is_primary', 1)
            ?? $record->authorities->firstWhere('pivot.is_primary', true)
            ?? $record->authorities->first();

        $authorParts = $primary
            ? array_filter([
                trim((string) ($primary->surname ?? '')),
                trim((string) ($primary->given_names ?? '')),
            ])
            : [];
        $author = implode(' ', $authorParts);

        if ($identifier === '' && $author === '') {
            return 'Document #' . $record->getKey();
        }

        if ($author === '') {
            return $identifier;
        }

        if ($identifier === '') {
            return $author;
        }

        return $identifier . ' — ' . $author;
    }

    /**
     * Render the relation-manager tabs (Identifier history, Issue flags)
     * ABOVE the infolist instead of Filament's default
     * below-everything position, so the document's history is the first thing
     * on the page. Mirrors the base ViewRecord::content() with the two
     * components swapped.
     */
    public function content(Schema $schema): Schema
    {
        if ($this->hasCombinedRelationManagerTabsWithContent()) {
            return $schema->components([
                $this->getRelationManagersContentComponent(),
            ]);
        }

        return $schema->components([
            $this->getRelationManagersContentComponent(),
            $this->hasInfolist()
                ? $this->getInfolistContentComponent()
                : $this->getFormContentComponent(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            ActionGroup::make(DocumentActionGroup::singleHeaderActions())
                ->label('Actions')
                ->icon('heroicon-o-bolt')
                ->color('primary')
                ->button(),
        ];
    }
}
