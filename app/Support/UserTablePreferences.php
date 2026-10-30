<?php

namespace App\Support;

use App\Models\UserTablePreference;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Client 2026-10-30: "Is it possible to keep the order for the particular
 * user after logging out. So the order is associated with the user?"
 *
 * Filament keeps each list's column order and hidden columns in the session
 * (tables.<key>_columns and tables.<key>_has_reordered_columns) and reads
 * them back when a table boots. Rather than override that in every list
 * page and relation manager, this keeps the session as Filament's working
 * copy and mirrors it to user_table_preferences:
 *
 *  - before the first Livewire component of a session mounts, the user's
 *    saved layouts are put into the session, so Filament finds them;
 *  - after any table component runs, a layout that changed is saved, and a
 *    layout back at the code default (Filament's "Reset") is deleted.
 *
 * Wired from PresentationServiceProvider through Livewire's global
 * pre-mount and dehydrate events, so it covers every table at once.
 */
class UserTablePreferences
{
    /** The user whose saved layouts this session holds. */
    public const string OWNER_SESSION_KEY = 'user_table_preferences.owner';

    /** Hash of each layout as last saved, keyed by md5 of the table key. */
    public const string SAVED_SESSION_KEY = 'user_table_preferences.saved';

    public static function seedSession(): void
    {
        $userId = auth()->id();
        if ($userId === null || ! app()->bound('session')) {
            return;
        }

        $owner = session(self::OWNER_SESSION_KEY);
        if ($owner === $userId) {
            return;
        }

        // Another user's layouts in this session (impersonation, a login
        // without logout): never let them carry over. A session with no
        // owner yet keeps what it has, so a layout arranged before this
        // feature shipped is saved on the next visit instead of lost.
        if ($owner !== null) {
            self::forgetSessionLayouts();
        }

        $saved = [];
        foreach (UserTablePreference::query()->where('user_id', $userId)->get() as $preference) {
            session()->put($preference->table_key . '_columns', $preference->columns);
            session()->put($preference->table_key . '_has_reordered_columns', $preference->has_reordered);
            $saved[md5($preference->table_key)] = self::hash($preference->columns, $preference->has_reordered);
        }

        session()->put(self::SAVED_SESSION_KEY, $saved);
        session()->put(self::OWNER_SESSION_KEY, $userId);
    }

    public static function persist(Component $component): void
    {
        // Filament's HasColumnManager concern, which every table component
        // (list page, relation manager, table widget) carries.
        if (
            ! $component instanceof HasTable
            || ! method_exists($component, 'getDefaultTableColumnState')
            || ! method_exists($component, 'getTableColumnsSessionKey')
            || ! method_exists($component, 'getHasReorderedTableColumnsSessionKey')
        ) {
            return;
        }

        $userId = auth()->id();
        /** @var array<int, array<string, mixed>> $columns */
        $columns = $component->tableColumns ?? [];
        if ($userId === null || $columns === [] || ! app()->bound('session')) {
            return;
        }

        if (! $component->getTable()->persistsColumnsInSession()) {
            return;
        }

        // The session may have been seeded for someone else (a component
        // that mounted before the user was known); never write across.
        if (session(self::OWNER_SESSION_KEY) !== $userId) {
            return;
        }

        $tableKey = Str::beforeLast($component->getTableColumnsSessionKey(), '_columns');
        $hasReordered = (bool) session($component->getHasReorderedTableColumnsSessionKey(), false);
        $saved = (array) session(self::SAVED_SESSION_KEY, []);
        $slot = md5($tableKey);

        if (self::normalise($columns) === self::normalise($component->getDefaultTableColumnState())) {
            if (array_key_exists($slot, $saved)) {
                UserTablePreference::query()->where('user_id', $userId)->where('table_key', $tableKey)->delete();
                unset($saved[$slot]);
                session()->put(self::SAVED_SESSION_KEY, $saved);
            }

            return;
        }

        $hash = self::hash($columns, $hasReordered);
        if (($saved[$slot] ?? null) === $hash) {
            return;
        }

        UserTablePreference::query()->updateOrCreate(
            ['user_id' => $userId, 'table_key' => $tableKey],
            ['columns' => $columns, 'has_reordered' => $hasReordered],
        );

        $saved[$slot] = $hash;
        session()->put(self::SAVED_SESSION_KEY, $saved);
    }

    /**
     * Drop every table's column layout from the session, leaving its other
     * table state (filters, sort, search) alone.
     */
    public static function forgetSessionLayouts(): void
    {
        $tables = session('tables');
        if (is_array($tables)) {
            session()->put('tables', self::withoutLayouts($tables));
        }

        session()->forget([self::SAVED_SESSION_KEY, self::OWNER_SESSION_KEY]);
    }

    /**
     * @param array<mixed> $node
     * @return array<mixed>
     */
    private static function withoutLayouts(array $node): array
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && (str_ends_with($key, '_columns') || str_ends_with($key, '_has_reordered_columns'))) {
                unset($node[$key]);
            } elseif (is_array($value)) {
                $node[$key] = self::withoutLayouts($value);
            }
        }

        return $node;
    }

    /**
     * Order, visibility and nesting only: labels can change with the code
     * without the layout being any different.
     *
     * @param array<int, array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function normalise(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $entry = [
                't' => $item['type'] ?? null,
                'n' => $item['name'] ?? null,
                'v' => (bool) ($item['isToggled'] ?? true),
            ];
            if (isset($item['columns']) && is_array($item['columns'])) {
                $entry['c'] = self::normalise($item['columns']);
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     */
    private static function hash(array $columns, bool $hasReordered): string
    {
        return md5((string) json_encode([self::normalise($columns), $hasReordered]));
    }
}
