<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->string('id', 128)->collation('ascii_bin')->primary();
            $table->bigInteger('current_amount_minor');
            $table->char('currency', 3)->collation('ascii_bin');
            $table->bigInteger('frozen_system_amount_minor')->nullable();
            $table->unsignedBigInteger('version');
            $table->dateTime('updated_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
