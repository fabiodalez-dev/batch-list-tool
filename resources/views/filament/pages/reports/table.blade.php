{{--
    RFQ §3.1.10 — shared table view for canned report pages.

    All 5 sub-report pages use this view. The Page declares the table
    via `public function table(Table $table)` and Filament's
    InteractsWithTable trait renders it via `{{ $this->table }}`.
--}}
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
