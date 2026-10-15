<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if ($app->configurationIsCached()
            || $app['config']->get('database.default') !== 'payroll_testing'
            || $app['config']->get('database.connections.payroll_testing.database') !== 'payroll_adjustments_test'
            || $app['config']->get('database.connections.payroll_testing.driver') !== 'mysql') {
            throw new RuntimeException('Tests require uncached configuration and the isolated payroll_adjustments_test MySQL database.');
        }

        return $app;
    }
}
