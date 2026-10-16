<?php

declare(strict_types=1);

namespace App\Providers;

use App\Payroll\Application\PayrollQueries;
use App\Payroll\Application\PayrollRepository;
use App\Payroll\Application\PayrollStateRebuilder;
use App\Payroll\Infrastructure\EventSerializer;
use App\Payroll\Infrastructure\MySqlPayrollEventStream;
use App\Payroll\Infrastructure\MySqlPayrollQueries;
use App\Payroll\Infrastructure\MySqlPayrollRepository;
use App\Payroll\Infrastructure\MySqlPayrollStateRebuilder;
use App\Payroll\Infrastructure\PayrollStateMapper;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MySqlPayrollEventStream::class, function (Application $app): MySqlPayrollEventStream {
            return new MySqlPayrollEventStream(
                connection: $app['db']->connection(),
                serializer: $app->make(EventSerializer::class),
            );
        });

        $this->app->bind(PayrollRepository::class, function (Application $app): MySqlPayrollRepository {
            return new MySqlPayrollRepository(
                connection: $app['db']->connection(),
                serializer: $app->make(EventSerializer::class),
                states: $app->make(PayrollStateMapper::class),
            );
        });

        $this->app->bind(PayrollQueries::class, function (Application $app): MySqlPayrollQueries {
            return new MySqlPayrollQueries(
                connection: $app['db']->connection(),
                states: $app->make(PayrollStateMapper::class),
                stream: $app->make(MySqlPayrollEventStream::class),
            );
        });

        $this->app->bind(PayrollStateRebuilder::class, function (Application $app): MySqlPayrollStateRebuilder {
            return new MySqlPayrollStateRebuilder(
                connection: $app['db']->connection(),
                states: $app->make(PayrollStateMapper::class),
                stream: $app->make(MySqlPayrollEventStream::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
