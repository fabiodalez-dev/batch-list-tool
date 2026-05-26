<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Filament\Support\SearchableSelects;
use App\Models\Authority;
use App\Models\Document;
use App\Models\Repository;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Action #3 — Cross-tenant transfer.
 *
 * super_admin-only: moves selected documents to a different Repository. This
 * is intentionally bulk-only and gated tighter than every other action because
 * it crosses the multi-tenant boundary, which is the highest-blast-radius
 * write in the system (RFQ §3.5.1).
 *
 * The audit row is tagged `cross_tenant_transfer` for traceability — easy to
 * filter in the audit log when looking for "show me every cross-tenant move
 * in the last 90 days".
 *
 * Optional: detach authorities the target repo doesn't recognise. Since
 * Authority is currently global (no `repository_id` column), this is a
 * forward-compatibility hook — by default we keep the existing pivot rows;
 * the operator can opt-in to a stricter mode that drops them.
 */
final class MoveToRepositoryAction
{
    public static function bulk(string $name = 'bulkMoveToRepository'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Move to repository (cross-tenant)')
            ->icon('heroicon-o-arrow-right-on-rectangle')
            ->color('danger')
            ->modalHeading('Cross-tenant transfer')
            ->modalDescription('This permanently moves the selected documents to a different repository. Only super-admin users may perform this action.')
            ->requiresConfirmation()
            ->form(self::form())
            ->action(function (EloquentCollection $records, array $data): void {
                self::perform($records, $data);
            })
            ->deselectRecordsAfterCompletion()
            ->visible(fn () => auth()->user()?->hasRole('super_admin') ?? false);
    }

    /**
     * @return array<int, Component>
     */
    private static function form(): array
    {
        return [
            SearchableSelects::repository('to_repository_id')
                ->label('Target repository')
                ->required(),
            Textarea::make('reason')
                ->label('Reason (required for audit log)')
                ->required()
                ->minLength(8)
                ->maxLength(500)
                ->rows(3),
            Toggle::make('clear_box_and_batch')
                ->label('Clear box and batch on transferred documents')
                ->helperText('Recommended: the source batches/boxes belong to the source repository and would dangle.')
                ->default(true),
        ];
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        if (! (auth()->user()?->hasRole('super_admin') ?? false)) {
            Notification::make()
                ->title('Not authorised — super_admin only')
                ->danger()->send();

            return;
        }

        $repoId = (int) ($data['to_repository_id'] ?? 0);
        $reason = trim((string) ($data['reason'] ?? ''));
        $clear = (bool) ($data['clear_box_and_batch'] ?? true);

        /** @var Repository|null $repo */
        $repo = Repository::query()->find($repoId);
        if ($repo === null) {
            Notification::make()
                ->title('Cannot transfer — target repository not found')
                ->danger()->send();

            return;
        }

        $ok = 0;
        $errors = [];

        DB::transaction(function () use ($records, $repo, $reason, $clear, &$ok, &$errors): void {
            foreach ($records as $doc) {
                /** @var Document $doc */
                try {
                    $oldRepoId = $doc->repository_id;

                    if ((int) $oldRepoId === (int) $repo->getKey()) {
                        // No-op — silently skip.
                        $ok++;
                        continue;
                    }

                    $doc->repository_id = $repo->getKey();
                    if ($clear) {
                        $doc->current_box_id = null;
                        $doc->batch_id = null;
                    }
                    $doc->save();

                    ActionSupport::logPivotChange(
                        document: $doc,
                        event: 'cross_tenant_transfer',
                        newValues: [
                            'repository_id' => $repo->getKey(),
                            'reason' => $reason,
                        ],
                        oldValues: [
                            'repository_id' => $oldRepoId,
                        ],
                        tags: 'cross_tenant_transfer,repository',
                    );

                    $ok++;
                } catch (\Throwable $e) {
                    $errors[] = "#{$doc->identifier}: {$e->getMessage()}";
                }
            }
        });

        if ($errors === [] && $ok > 0) {
            Notification::make()
                ->title("{$ok} document(s) transferred to {$repo->code}")
                ->success()->send();

            return;
        }

        if ($ok > 0) {
            Notification::make()
                ->title("Partial: {$ok} transferred, " . count($errors) . ' failed')
                ->body(implode("\n", array_slice($errors, 0, 5)))
                ->warning()->send();

            return;
        }

        Notification::make()
            ->title('Transfer failed')
            ->body(implode("\n", array_slice($errors, 0, 5)) ?: 'No documents processed.')
            ->danger()->send();
    }
}
