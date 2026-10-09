<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs before setUpTraits(), so this aborts before RefreshDatabase can
     * reach for a database. Checking any later is too late: the trait has
     * already run migrate:fresh on whatever connection is configured.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || ! in_array($database, [':memory:', null, ''], true)) {
            throw new \RuntimeException(sprintf(
                'Refusing to run tests against connection [%s] database [%s]. '
                .'Run "php artisan config:clear" first.',
                $connection,
                (string) $database,
            ));
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
