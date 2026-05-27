<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Filament\Support\SearchableSelects;
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
 *
 * Tenancy-cascade (review H-3): after re-stamping `documents.repository_id`,
 * we cascade the new tenancy onto the document's child rows that mirror
 * it — `document_flags`, `document_identifier_history`,
 * `document_seal_number_history`. Without this cascade, the document would
 * be visible to operators in the new repo BUT their flags / history
 * panels would render empty because those tables are scoped by
 * `repository_id` (BelongsToRepository) and still point at the old tenant.
 * We use raw DB queries (not Eloquent) so the BelongsToRepository
 * `creating` hook — which would re-validate the OLD tenant — doesn't
 * fire on these updates.
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

        $result = ActionSupport::performBulk(
            $records,
            function (Document $doc) use ($repo, $reason, $clear): void {
                $oldRepoId = $doc->repository_id;

                if ((int) $oldRepoId === (int) $repo->getKey()) {
                    // No-op — silently succeed (operator selected a doc
                    // that already lives in the target repo).
                    return;
                }

                $doc->repository_id = $repo->getKey();
                if ($clear) {
                    $doc->current_box_id = null;
                    $doc->batch_id = null;
                }
                $doc->save();

                // H-3: cascade tenancy onto child rows that mirror it.
                // Raw DB queries, no models — we don't want the
                // BelongsToRepository `creating` hook to re-validate the
                // OLD tenant (it's not creating, it's updating, but the
                // observer is hooked on `saving` which would also fire).
                DB::table('document_flags')
                    ->where('document_id', $doc->getKey())
                    ->update(['repository_id' => $repo->getKey()]);

                DB::table('document_identifier_history')
                    ->where('document_id', $doc->getKey())
                    ->update(['repository_id' => $repo->getKey()]);

                DB::table('document_seal_number_history')
                    ->where('document_id', $doc->getKey())
                    ->update(['repository_id' => $repo->getKey()]);

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
            },
        );

        ActionSupport::notifyBulkResult(
            $result,
            successVerb: "transferred to {$repo->code}",
            failedTitle: 'Transfer failed',
        );
    }
}
