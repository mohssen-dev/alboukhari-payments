<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // SAFETY GUARD: tests use RefreshDatabase (migrate:fresh — wipes ALL
        // tables). If a cached config (bootstrap/cache/config.php) exists,
        // Laravel ignores phpunit.xml's DB_DATABASE=:memory: override and the
        // suite silently runs against the REAL database, destroying it.
        // That exact accident wiped the dev DB twice. Never allow it again.
        $db = config('database.connections.' . config('database.default') . '.database');
        if ($db !== ':memory:') {
            self::fail(
                "REFUSING TO RUN: tests must use an in-memory SQLite DB, got [{$db}].\n" .
                'A cached config is probably overriding phpunit.xml — run: php artisan config:clear'
            );
        }
    }
}
