<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Shared ordering log for the restore safety-snapshot-first test.
 *
 * Lives in its own PSR-4 file (Tests\Support) rather than inline in the test
 * so the autoloader does not emit a "class declared in a non-autoloadable
 * file" warning.
 */
class SnapshotOrderProbe
{
    /** @var array<int, string> */
    public static array $log = [];
}
