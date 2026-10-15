<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_events', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->char('event_id', 32)->collation('ascii_bin')->primary();
            $table->string('aggregate_id', 128)->collation('ascii_bin');
            $table->unsignedBigInteger('version');
            $table->string('event_type', 64);
            $table->json('payload');
            $table->dateTime('occurred_at', 6);
            $table->unique(['aggregate_id', 'version'], 'payroll_events_stream_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_events');
    }
};
