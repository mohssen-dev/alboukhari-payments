<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // ── SAFETY GUARD — MUST RUN BEFORE parent::setUp() ──
        //
        // Tests use RefreshDatabase, which runs `migrate:fresh` (drops EVERY
        // table). RefreshDatabase is triggered from inside parent::setUp()
        // via setUpTraits(), so any check placed AFTER parent::setUp() fires
        // only once the real database has already been destroyed — that is
        // exactly what happened here twice, wiping 307 students locally.
        //
        // The actual trap is bootstrap/cache/config.php: when a cached config
        // exists, Laravel loads it verbatim and phpunit.xml's
        // DB_DATABASE=:memory: override is ignored, so migrate:fresh runs
        // against database/database.sqlite instead.
        $cachedConfig = dirname(__DIR__) . '/bootstrap/cache/config.php';
        if (file_exists($cachedConfig)) {
            self::fail(
                "REFUSING TO RUN: bootstrap/cache/config.php exists.\n" .
                "A cached config overrides phpunit.xml, so RefreshDatabase would\n" .
                "wipe the REAL database instead of an in-memory one.\n" .
                'Run: php artisan config:clear'
            );
        }

        parent::setUp();

        // Belt and braces: confirm we really landed on an in-memory database
        // (catches any other route to a real DB, e.g. an env var in the shell).
        $db = config('database.connections.' . config('database.default') . '.database');
        if ($db !== ':memory:') {
            self::fail("REFUSING TO CONTINUE: tests must use an in-memory SQLite DB, got [{$db}].");
        }
    }
}
