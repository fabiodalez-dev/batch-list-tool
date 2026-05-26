<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Action #5 — Mark document(s) as disinfested with an explicit date.
 *
 * The DatePicker defaults to today() and refuses future dates (an archive
 * cannot pre-record a disinfestation that has not happened yet). This is a
 * prerequisite for Action #6 (PERM_OUT) per RFQ App.1 #5.
 */
final class MarkDisinfestedAction
{
    public static function make(string $name = 'markDisinfested'): Action
    {
        return Action::make($name)
            ->label('Mark disinfested')
            ->icon('heroicon-o-shield-check')
            ->color('success')
            ->modalHeading('Mark this document as disinfested')
            ->form(self::form())
            ->action(function (Document $record, array $data): void {
                self::perform(ActionSupport::asCollection($record), $data);
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkMarkDisinfested'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Mark disinfested')
            ->icon('heroicon-o-shield-check')
            ->color('success')
            ->modalHeading('Mark selected documents as disinfested')
            ->form(self::form())
            ->action(function (EloquentCollection $records, array $data): void {
                self::perform($records, $data);
            })
            ->deselectRecordsAfterCompletion()
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    /**
     * @return array<int, Component>
     */
    private static function form(): array
    {
        return [
            DatePicker::make('disinfestation_date')
                ->label('Disinfestation date')
                ->required()
                ->default(now()->toDateString())
                ->maxDate(now())
                ->helperText('No future dates — record the actual fumigation date.'),
        ];
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        $date = $data['disinfestation_date'] ?? null;
        if ($date === null) {
            Notification::make()
                ->title('Disinfestation date is required')
                ->danger()->send();

            return;
        }

        $ok = 0;
        $errors = [];

        DB::transaction(function () use ($records, $date, &$ok, &$errors): void {
            foreach ($records as $doc) {
                /** @var Document $doc */
                try {
                    $doc->disinfestation_date = $date;
                    $doc->save();
                    $ok++;
                } catch (\Throwable $e) {
                    $errors[] = "#{$doc->identifier}: {$e->getMessage()}";
                }
            }
        });

        if ($errors === [] && $ok > 0) {
            Notification::make()
                ->title("{$ok} document(s) marked disinfested on {$date}")
                ->success()->send();

            return;
        }

        if ($ok > 0) {
            Notification::make()
                ->title("Partial: {$ok} marked, " . count($errors) . ' failed')
                ->body(implode("\n", array_slice($errors, 0, 5)))
                ->warning()->send();

            return;
        }

        Notification::make()
            ->title('Failed to mark disinfested')
            ->body(implode("\n", array_slice($errors, 0, 5)) ?: 'No documents updated.')
            ->danger()->send();
    }
}
