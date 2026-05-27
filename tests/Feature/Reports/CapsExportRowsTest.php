<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\Concerns\CapsExportRows;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait-level coverage for {@see CapsExportRows}.
 *
 * Anonymous test subject exposes the protected trait methods as `public`
 * wrappers and overrides the notifier so we observe the truncation
 * side-effect without booting Filament's Livewire-backed Notification
 * stack. The subject is built inside each test (not via a shared helper)
 * to avoid edge-cases with global-function evaluation under Pest.
 */
function caps_subject_factory(int $cap = 3): object
{
    $obj = new class extends stdClass
    {
        use CapsExportRows {
            CapsExportRows::capExportRows as protected traitCapExportRows;
            CapsExportRows::fetchExportRowsWithCap as protected traitFetchExportRowsWithCap;
        }

        public int $truncationCount = 0;

        public int $lastCap = 0;

        public function callCap(iterable $rows): array
        {
            return $this->traitCapExportRows($rows);
        }

        public function callFetch(Builder $query): Collection
        {
            return $this->traitFetchExportRowsWithCap($query);
        }

        public function setCap(int $cap): void
        {
            self::$exportRowCap = $cap;
        }

        protected function notifyExportTruncated(int $cap): void
        {
            $this->truncationCount++;
            $this->lastCap = $cap;
        }
    };

    $obj->setCap($cap);

    return $obj;
}

test('capExportRows leaves the input untouched when count is under or equal to cap', function () {
    $s = caps_subject_factory(3);
    $r = $s->callCap(['a', 'b', 'c']);

    expect($r)->toBe(['a', 'b', 'c'])
        ->and($s->truncationCount)->toBe(0);
});

test('capExportRows slices and notifies when count exceeds cap', function () {
    $s = caps_subject_factory(3);
    $r = $s->callCap(['a', 'b', 'c', 'd', 'e']);

    expect($r)->toBe(['a', 'b', 'c'])
        ->and($s->truncationCount)->toBe(1)
        ->and($s->lastCap)->toBe(3);
});

test('fetchExportRowsWithCap calls limit(cap + 1) and truncates plus notifies on overflow', function () {
    $s = caps_subject_factory(3);

    $models = [Mockery::mock(Model::class), Mockery::mock(Model::class), Mockery::mock(Model::class), Mockery::mock(Model::class)];

    $builder = Mockery::mock(Builder::class);
    $builder->shouldReceive('limit')->once()->with(4)->andReturnSelf();
    $builder->shouldReceive('get')->once()->andReturn(new Collection($models));

    $r = $s->callFetch($builder);

    expect($r)->toBeInstanceOf(Collection::class)->toHaveCount(3)
        ->and($s->truncationCount)->toBe(1);
});

test('fetchExportRowsWithCap does NOT notify when result fits inside the cap', function () {
    $s = caps_subject_factory(3);

    $models = [Mockery::mock(Model::class), Mockery::mock(Model::class)];

    $builder = Mockery::mock(Builder::class);
    $builder->shouldReceive('limit')->once()->with(4)->andReturnSelf();
    $builder->shouldReceive('get')->once()->andReturn(new Collection($models));

    $r = $s->callFetch($builder);

    expect($r)->toHaveCount(2)
        ->and($s->truncationCount)->toBe(0);
});
