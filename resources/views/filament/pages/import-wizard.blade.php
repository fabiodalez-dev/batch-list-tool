{{--
    RFQ §3.1.3 — Bulk Import v2 multi-step Wizard.

    A real Filament 5 Wizard (Step → Step → Step), not a card grid.
    All the logic lives on the page class (ImportWizard); this view
    just renders the form schema, which is the Wizard component
    returned by `ImportWizard::form()`.
--}}
<x-filament-panels::page>
    <form wire:submit="startImport">
        {{ $this->form }}
    </form>
</x-filament-panels::page>
