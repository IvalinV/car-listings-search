<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_listings', function (Blueprint $table): void {
            $table->index('car_make_id');
            $table->index('car_model_id');
        });
    }

    public function down(): void
    {
        Schema::table('car_listings', function (Blueprint $table): void {
            $table->dropIndex(['car_make_id']);
            $table->dropIndex(['car_model_id']);
        });
    }
};
