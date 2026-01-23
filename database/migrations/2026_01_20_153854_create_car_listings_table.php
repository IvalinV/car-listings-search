<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('car_listings', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint')->unique(); // The deduplication key
            $table->string('make');
            $table->string('model');
            $table->integer('year');
            $table->integer('price');
            $table->integer('mileage');
            $table->string('engine_type');
            $table->string('transmission');
            $table->string('location')->nullable();
            $table->json('source_urls'); // Store multiple links (cars.bg, mobile.bg)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_listings');
    }
};
