<?php

declare(strict_types=1);

namespace App\Providers;

use App\Payroll\Application\EventStore;
use App\Payroll\Infrastructure\EventSerializer;
use App\Payroll\Infrastructure\MySqlEventStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EventStore::class, function (Application $app): MySqlEventStore {
            return new MySqlEventStore($app['db']->connection(), $app->make(EventSerializer::class));
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
