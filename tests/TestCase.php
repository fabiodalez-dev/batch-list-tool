<?php

namespace Tests;

use App\Support\CustomFields\CustomFieldResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Flush request-memoised static caches before each test so that tests
     * running in the same process do not bleed state between scenarios.
     *
     * This is necessary for any class that maintains a static array as a
     * "request-level" memo (e.g. CustomFieldResolver), because PHP static
     * variables survive across test cases within one phpunit/pest process
     * even when RefreshDatabase truncates the database.
     */
    protected function setUp(): void
    {
        parent::setUp();
        CustomFieldResolver::flush();
    }
}
