<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_listings', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint')->unique(); // The deduplication key
            $table->text('description')->nullable();
            $table->string('title')->nullable();
            $table->integer('year');
            $table->decimal('price', 10, 2);
            $table->integer('mileage');
            $table->string('fuel_type')->nullable();
            $table->string('transmission')->nullable();
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
