{{--
    RFQ §3.1.8 — editable field-level permission matrix.

    Per resource, one borderless table: rows are fields, columns are roles. The
    super_admin column is fixed (always RW). For admin / editor / viewer each
    cell exposes three native-styled checkboxes — R (read), W (write), H
    (hidden) — bound to the page state. No card / no white background: the
    tables sit directly on the panel background; only thin row dividers and the
    primary-accent checkbox carry the structure, matching the app's palette.
--}}
@php
    $roles = \App\Filament\Pages\FieldPermissionMatrix::ROLES;
    $editable = \App\Filament\Pages\FieldPermissionMatrix::EDITABLE_ROLES;
@endphp

<x-filament-panels::page>
    {{-- Legend / intro --}}
    <div class="flex flex-col gap-3 text-sm text-gray-600 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
        <p class="max-w-2xl">
            Adjust per-field access for each role, then <strong class="font-semibold text-gray-950 dark:text-white">Save changes</strong>.
            Edits are stored as overrides on the <code class="rounded bg-gray-500/10 px-1 py-0.5 text-xs">config/field_permissions.php</code>
            baseline and apply on each user's next page load.
            <code class="rounded bg-gray-500/10 px-1 py-0.5 text-xs">super_admin</code> always has full access.
        </p>
        <div class="flex shrink-0 items-center gap-3 text-xs">
            <span class="inline-flex items-center gap-1.5"><span class="font-semibold text-gray-700 dark:text-gray-300">R</span> read</span>
            <span class="inline-flex items-center gap-1.5"><span class="font-semibold text-gray-700 dark:text-gray-300">W</span> write</span>
            <span class="inline-flex items-center gap-1.5"><span class="font-semibold text-gray-700 dark:text-gray-300">H</span> hidden</span>
        </div>
    </div>

    @foreach ($state as $resource => $fields)
        <section class="space-y-2">
            <h2 class="flex items-center gap-2 text-base font-semibold capitalize text-gray-950 dark:text-white">
                <x-filament::icon icon="heroicon-o-table-cells" class="size-5 text-gray-400" />
                {{ $resource }}
            </h2>

            <div class="-mx-2 overflow-x-auto sm:mx-0">
                <table class="w-full border-separate border-spacing-0 text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="border-b border-gray-200 px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">Field</th>
                            @foreach ($roles as $role)
                                <th class="border-b border-gray-200 px-3 py-2 text-center dark:border-white/10">
                                    <div class="font-semibold text-gray-950 dark:text-white">{{ $this->roleLabel($role) }}</div>
                                    <div class="font-mono text-[10px] font-normal text-gray-400">{{ $role }}</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($fields as $field => $rolePerms)
                            <tr class="group transition hover:bg-gray-500/5">
                                <td class="border-b border-gray-100 px-3 py-2.5 align-middle dark:border-white/5">
                                    @if ($field === '_default')
                                        <span class="italic text-gray-500 dark:text-gray-400">default (unlisted fields)</span>
                                    @else
                                        <span class="font-mono text-xs text-gray-800 dark:text-gray-200">{{ $field }}</span>
                                    @endif
                                </td>

                                {{-- super_admin: fixed full access --}}
                                <td class="border-b border-gray-100 px-3 py-2.5 text-center align-middle dark:border-white/5">
                                    <x-filament::badge color="success" class="inline-flex">RW</x-filament::badge>
                                </td>

                                @foreach ($editable as $role)
                                    <td class="border-b border-gray-100 px-3 py-2.5 align-middle dark:border-white/5">
                                        <div class="flex items-center justify-center gap-4">
                                            @foreach (['read' => 'R', 'write' => 'W', 'hidden' => 'H'] as $perm => $letter)
                                                <label class="inline-flex cursor-pointer flex-col items-center gap-1">
                                                    <input
                                                        type="checkbox"
                                                        wire:model="state.{{ $resource }}.{{ $field }}.{{ $role }}.{{ $perm }}"
                                                        class="fi-checkbox-input"
                                                    />
                                                    <span class="text-[10px] font-medium uppercase text-gray-400">{{ $letter }}</span>
                                                </label>
                                            @endforeach
                                        </div>
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
