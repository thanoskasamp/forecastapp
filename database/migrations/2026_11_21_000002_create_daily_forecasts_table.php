<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_forecasts', function (Blueprint $table) {
            $table->increments('id');
            $table->timestampTz('collected', precision: 6);
            $table->string('provider', 50);
            $table->decimal('lat', 9, 6);
            $table->decimal('lon', 9, 6);
            $table->date('time');
            $table->decimal('temp', 8, 2)->nullable();
            $table->decimal('prec', 8, 2)->nullable();

            $table->unique(['collected', 'provider', 'lat', 'lon', 'time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_forecasts');
    }
};
