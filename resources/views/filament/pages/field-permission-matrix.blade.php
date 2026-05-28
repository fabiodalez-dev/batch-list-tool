{{--
    RFQ §3.1.8 — read-only field-level permission matrix.

    For each configured resource we render one table: rows are fields,
    columns are the four roles (labelled with their RFQ display name). Each
    cell shows the EFFECTIVE permission resolved by the page class:
      RW = read + write · R = read only · Hidden = removed from form/table
      — = no access. Source of truth: config/field_permissions.php.
--}}
@php
    /** @var array<string, array{fields: array<string, array<string, array{read:bool, write:bool, hidden:bool}>>}> $matrix */
    $matrix = $this->matrix();
    $roles = \App\Filament\Pages\FieldPermissionMatrix::ROLES;
@endphp

<x-filament-panels::page>
    <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
        <p>
            Effective per-field access for each role, resolved exactly as the
            application enforces it at runtime. The matrix is defined in
            <code>config/field_permissions.php</code> and is read-only here;
            changes are made in code and reviewed in version control.
        </p>
        <div class="flex flex-wrap gap-3 text-xs">
            <span><span class="font-semibold text-success-600">RW</span> — read &amp; write</span>
            <span><span class="font-semibold text-primary-600">R</span> — read only</span>
            <span><span class="font-semibold text-gray-500">Hidden</span> — removed from form &amp; table</span>
            <span><span class="font-semibold text-danger-600">—</span> — no access</span>
        </div>
    </div>

    @foreach ($matrix as $resource => $data)
        <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
            <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white capitalize">
                    {{ $resource }}
                </h2>
            </header>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800/50">
                            <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Field</th>
                            @foreach ($roles as $role)
                                <th class="px-4 py-2 text-center font-medium text-gray-700 dark:text-gray-300">
                                    <div>{{ $this->roleLabel($role) }}</div>
                                    <div class="text-[10px] font-normal text-gray-400">{{ $role }}</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['fields'] as $field => $statuses)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="px-4 py-2 font-mono text-xs text-gray-800 dark:text-gray-200">
                                    {{ $field === '_default' ? '(default — unlisted fields)' : $field }}
                                </td>
                                @foreach ($roles as $role)
                                    @php($s = $statuses[$role])
                                    <td class="px-4 py-2 text-center">
                                        @if ($s['hidden'])
                                            <span class="font-semibold text-gray-500">Hidden</span>
                                        @elseif ($s['write'])
                                            <span class="font-semibold text-success-600">RW</span>
                                        @elseif ($s['read'])
                                            <span class="font-semibold text-primary-600">R</span>
                                        @else
                                            <span class="font-semibold text-danger-600">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach
</x-filament-panels::page>
