@php
    /**
     * Topbar repository switcher (RFQ Wave 2 Task 10 — Submission §4.3.3).
     *
     * Lets the user narrow the active scope to a single repository, or back to
     * "All repositories" (the EXPAND-NEVER-RESTRICT default = today's behaviour).
     *
     * Visible only when the user can see more than one repository — there is
     * nothing to switch between otherwise.
     *
     * Plain <form> + auto-submitting <select>: no Livewire, no JS bundle, CSP
     * compliant (the inline onchange is a same-origin form submit).
     */
    use App\Models\Repository;
    use App\Support\ActiveRepository;

    $user = auth()->user();

    $repositories = collect();
    if ($user) {
        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['super_admin', 'admin'])) {
            // Privileged users may scope to any repository.
            $repositories = Repository::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']);
        } elseif (method_exists($user, 'repositories')) {
            $repositories = $user->repositories()
                ->orderBy('name')
                ->get(['repositories.id', 'repositories.name', 'repositories.code']);
        }
    }

    $activeId = app(ActiveRepository::class)->id();
@endphp

@if ($repositories->count() > 1)
    <form
        method="POST"
        action="{{ route('active-repository.update') }}"
        class="fi-topbar-repo-switcher flex items-center gap-2"
        title="{{ __('Active repository') }}"
    >
        @csrf

        <label for="active-repository-select" class="sr-only">
            {{ __('Active repository') }}
        </label>

        <x-filament::input.wrapper>
            <x-filament::input.select
                id="active-repository-select"
                name="repository_id"
                onchange="this.form.submit()"
            >
                <option value="all" @selected($activeId === null)>
                    {{ __('All repositories') }}
                </option>

                @foreach ($repositories as $repository)
                    <option
                        value="{{ $repository->id }}"
                        @selected($activeId === (int) $repository->id)
                    >
                        {{ $repository->name ?? $repository->code }}
                    </option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>

        {{-- No-JS fallback: a submit button for clients without scripting. --}}
        <noscript>
            <x-filament::button type="submit" size="sm" color="gray">
                {{ __('Switch') }}
            </x-filament::button>
        </noscript>
    </form>
@endif
