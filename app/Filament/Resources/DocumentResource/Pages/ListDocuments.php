<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Concerns\ExportsLikeTheTemplate;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\DocumentResource;
use App\Models\CustomFieldDefinition;
use App\Models\Document;
use App\Models\Scopes\RepositoryScope;
use App\Support\ActiveRepository;
use App\Support\BulkImport\TemplateGenerator;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;

class ListDocuments extends ListRecords
{
    use ExplainsPage;
    use ExportsLikeTheTemplate;

    protected static string $resource = DocumentResource::class;

    protected static function exportEntity(): string
    {
        return 'document';
    }

    protected function getHeaderActions(): array
    {
        return [
            // RFQ §3.1.3 — Bulk Import v2.
            // Per-column dropdown mapping, FK resolution by name (Series via
            // code, Authority via R-code AND surname), F-009 ambiguous-skip
            // policy. See {@see DocumentImporter} for the column declarations.
            Actions\Action::make('import')
                ->label('Import Excel / CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                // One import path. The wizard orders parents ahead of
                // children, offers the overwrite choice and warns about
                // missing structural columns; the in-page modal did none
                // of those, so the same sheet behaved differently
                // depending on where it was uploaded from.
                ->url(ImportWizard::getUrl(['type' => 'documents']))
                // Also gated on the wizard's own access check, so the
                // button is never shown to someone it would 403.
                ->visible(fn (): bool => ImportWizard::canAccess()
                    && (auth()->user()?->can('create', Document::class) ?? false)),

            Actions\Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->authorize(fn () => auth()->user()?->can('view_any_document') ?? false)
                ->action(fn () => $this->exportToCsv()),

            // Blank xlsx whose row-1 headers match Batch_List_Sample.xlsx
            // verbatim — including the legitimately-duplicated headers
            // ("Barcode (IN)", "Barcode RAS 2", "Status 1/2",
            // "Disinfestation Date") that encode multi-step provenance.
            // See TemplateGenerator's docblock for the rationale.
            Actions\Action::make('download_template')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => TemplateGenerator::download('document'))
                ->visible(fn () => auth()->user()?->can('create', Document::class) ?? false),

            // Charlene UX — one-shot "empty this repository" for super_admins.
            // The standard page-by-page bulk delete forced 26 k rows to be
            // confirmed 25-at-a-time. This wipes every Document in the acting
            // user's CURRENT repository scope (RepositoryScope already narrows
            // Document::query() to the active repository — or all repositories
            // when "All" is selected).
            //
            // Delete strategy — chunked forceDelete: documents SoftDelete, but
            // "delete all" means gone, and the dependent rows (box_movements,
            // document_authority, identifier/barcode history …) are wired with
            // ON DELETE CASCADE, so a real DELETE removes them too. We chunk by
            // id (1 000 at a time) selecting only the id column, so memory stays
            // flat and each round-trip is a single mass DELETE — no per-model
            // hydration, events or observers. 26 k rows ≈ 26 statements, which
            // completes well within a request; a synchronous delete also lets
            // the success state be asserted immediately (no queue worker needed).
            Actions\Action::make('deleteAll')
                ->label('Delete all documents')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Delete ALL documents in this repository')
                ->modalDescription('This permanently removes every document in your current repository scope, together with their movements, flags and history. This cannot be undone. Type DELETE to confirm.')
                ->modalSubmitActionLabel('Delete everything')
                ->form([
                    TextInput::make('confirmation')
                        ->label('Type DELETE to confirm')
                        ->required()
                        ->rule('in:DELETE')
                        ->validationMessages(['in' => 'You must type DELETE (in capitals) to confirm.']),
                ])
                ->action(function (): void {
                    abort_unless(
                        auth()->user()?->hasRole('super_admin') ?? false,
                        403,
                        'Only super administrators may delete all documents.',
                    );

                    // SAFETY: a super_admin viewing "All repositories" has no
                    // RepositoryScope narrowing, so Document::query() would span
                    // EVERY repository — a cross-tenant wipe. Require an explicit
                    // active repository and scope the delete to it, never trusting
                    // the ambient scope for a destructive mass operation.
                    $repoId = app(ActiveRepository::class)->id();
                    if ($repoId === null) {
                        Notification::make()
                            ->title('Select a repository first')
                            ->body('Pick a specific repository in the top bar before deleting all documents — this action never deletes across repositories.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $deleted = 0;

                    Document::query()
                        ->withoutGlobalScope(RepositoryScope::class)
                        ->withTrashed()
                        ->where('repository_id', $repoId)
                        ->select('id')
                        ->chunkById(1000, function (Collection $rows) use (&$deleted, $repoId): void {
                            $ids = $rows->pluck('id')->all();
                            Document::query()
                                ->withoutGlobalScope(RepositoryScope::class)
                                ->withTrashed()
                                ->where('repository_id', $repoId)
                                ->whereIn('id', $ids)
                                ->forceDelete();
                            $deleted += count($ids);
                        });

                    Notification::make()
                        ->title($deleted > 0
                            ? "Deleted {$deleted} document(s) from this repository."
                            : 'There were no documents to delete in this repository.')
                        ->success()
                        ->send();
                })
                ->visible(fn () => auth()->user()?->hasRole('super_admin') ?? false),

            Actions\CreateAction::make(),
        ];
    }

    /**
     * Return the active custom-field definitions for the active repository
     * (document entity type), ordered by sort_order. Delegates to
     * CustomFieldResolver so the active-repo logic is centralised.
     *
     * Returns an empty Collection when the resolver finds no repository or
     * when no active definitions exist — safe to iterate unconditionally.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CustomFieldDefinition>
     */
    private function getActiveCustomFieldDefinitions(): \Illuminate\Database\Eloquent\Collection
    {
        return CustomFieldResolver::definitionsFor('document');
    }

    /**
     * Sanitize a single CSV cell to neutralize spreadsheet formula injection.
     *
     * fputcsv only escapes CSV grammar (commas, quotes, newlines) — it does NOT
     * defend against Excel/LibreOffice/Sheets interpreting a leading "=", "+",
     * "-", "@", TAB or CR as a formula trigger (CWE-1236 / OWASP "CSV Injection").
     *
     * OWASP-recommended mitigation: prefix dangerous leading characters with a
     * single quote (') — both Excel and LibreOffice treat that as "show literally,
     * do not evaluate".
     *
     * Apply ONLY to user-controllable string fields (identifier, notes,
     * surnames, codes). Do NOT apply to IDs, integers, formatted dates — those
     * cannot start with a dangerous character.
     */
    private function sanitizeCsvCell(mixed $value): string
    {
        $string = (string) ($value ?? '');
        if ($string === '') {
            return '';
        }
        if (preg_match('/^[=+\-@\t\r]/', $string)) {
            return "'" . $string;
        }

        return $string;
    }
}
