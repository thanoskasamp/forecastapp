<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app->make('db')->connection();

        // Check before RefreshDatabase can recreate any tables.
        if (! $app->environment('testing')
            || $connection->getDriverName() !== 'pgsql'
            || $connection->getDatabaseName() !== 'forecastapp_test'
            || $connection->selectOne('SELECT current_database() AS name')->name !== 'forecastapp_test') {
            throw new RuntimeException('Tests must use the forecastapp_test PostgreSQL database.');
        }

        return $app;
    }
}
