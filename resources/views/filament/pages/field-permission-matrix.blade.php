{{--
    RFQ §3.1.8 — editable field-level permission matrix.

    Per resource, one table: rows are fields, columns are roles. The
    super_admin column is fixed (always RW). For admin / editor / viewer each
    cell exposes three checkboxes — R (read), W (write), H (hidden) — bound to
    the page state. "Save changes" persists them as overrides; "Reset to config
    defaults" drops every override. Source baseline: config/field_permissions.php.
--}}
@php
    $roles = \App\Filament\Pages\FieldPermissionMatrix::ROLES;
    $editable = \App\Filament\Pages\FieldPermissionMatrix::EDITABLE_ROLES;
@endphp

<x-filament-panels::page>
    <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
        <p>
            Adjust per-field access for each role, then <strong>Save changes</strong>.
            Edits are stored as overrides on top of the
            <code>config/field_permissions.php</code> baseline and take effect on
            each user's next page load. <strong>H</strong> (hidden) removes the
            field from the form &amp; table and wins over read/write.
            <code>super_admin</code> always has full access and is not editable.
        </p>
    </div>

    @foreach ($state as $resource => $fields)
        <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
            <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white capitalize">{{ $resource }}</h2>
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
                        @foreach ($fields as $field => $rolePerms)
                            <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                                <td class="px-4 py-2 font-mono text-xs text-gray-800 dark:text-gray-200">
                                    {{ $field === '_default' ? '(default — unlisted fields)' : $field }}
                                </td>

                                {{-- super_admin: fixed full access --}}
                                <td class="px-4 py-2 text-center">
                                    <span class="font-semibold text-success-600">RW</span>
                                </td>

                                @foreach ($editable as $role)
                                    <td class="px-4 py-2">
                                        <div class="flex items-center justify-center gap-3 text-xs">
                                            <label class="inline-flex items-center gap-1">
                                                <input type="checkbox"
                                                    wire:model="state.{{ $resource }}.{{ $field }}.{{ $role }}.read"
                                                    class="rounded border-gray-300 dark:border-gray-600" />
                                                <span>R</span>
                                            </label>
                                            <label class="inline-flex items-center gap-1">
                                                <input type="checkbox"
                                                    wire:model="state.{{ $resource }}.{{ $field }}.{{ $role }}.write"
                                                    class="rounded border-gray-300 dark:border-gray-600" />
                                                <span>W</span>
                                            </label>
                                            <label class="inline-flex items-center gap-1">
                                                <input type="checkbox"
                                                    wire:model="state.{{ $resource }}.{{ $field }}.{{ $role }}.hidden"
                                                    class="rounded border-gray-300 dark:border-gray-600" />
                                                <span>H</span>
                                            </label>
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
