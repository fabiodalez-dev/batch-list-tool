<?php

declare(strict_types=1);

use App\Models\User;
use App\Settings\AuditSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * 2026-11-12: the Audit settings page saved a retention threshold in days that
 * nothing ever applied. nra:prune-audits applies it every night.
 */
uses(RefreshDatabase::class);

/**
 * Insert one audit row dated $daysAgo days back and return its id.
 */
function auditRowDaysAgo(int $daysAgo): int
{
    $at = now()->subDays($daysAgo);

    return (int) DB::table('audits')->insertGetId([
        'user_type' => User::class,
        'user_id' => null,
        'event' => 'updated',
        'auditable_type' => 'App\\Models\\Box',
        'auditable_id' => 1,
        'old_values' => '[]',
        'new_values' => '[]',
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

function setAuditThreshold(int $days): void
{
    $settings = app(AuditSettings::class);
    $settings->threshold = $days;
    $settings->save();
}

it('deletes nothing when the threshold is 0 (keep forever)', function (): void {
    setAuditThreshold(0);
    auditRowDaysAgo(4000);
    auditRowDaysAgo(1);

    $this->artisan('nra:prune-audits')
        ->expectsOutputToContain('nothing deleted')
        ->assertSuccessful();

    expect(DB::table('audits')->count())->toBe(2);
});

it('deletes the rows older than the threshold and keeps the newer ones', function (): void {
    setAuditThreshold(30);
    $old = [auditRowDaysAgo(31), auditRowDaysAgo(400), auditRowDaysAgo(90)];
    $kept = [auditRowDaysAgo(29), auditRowDaysAgo(0)];

    Log::spy();

    // A chunk of 2 forces more than one delete statement for the 3 old rows.
    $this->artisan('nra:prune-audits', ['--chunk' => 2])
        ->expectsOutputToContain('Deleted 3 audit rows older than 30 days')
        ->assertSuccessful();

    expect(DB::table('audits')->pluck('id')->sort()->values()->all())->toBe($kept)
        ->and(DB::table('audits')->whereIn('id', $old)->count())->toBe(0);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'nra:prune-audits')
            && $context['deleted'] === 3
            && $context['threshold_days'] === 30)
        ->once();
});

it('only counts in a dry run', function (): void {
    setAuditThreshold(10);
    auditRowDaysAgo(11);
    auditRowDaysAgo(50);
    auditRowDaysAgo(2);

    $this->artisan('nra:prune-audits', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run: 2 audit rows')
        ->assertSuccessful();

    expect(DB::table('audits')->count())->toBe(3);
});

it('is scheduled once a day', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e): bool => str_contains((string) $e->command, 'nra:prune-audits'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 3 * * *');
});
