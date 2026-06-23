<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_listings', function (Blueprint $table): void {
            $table->foreignId('car_make_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('car_model_id')->nullable()->after('car_make_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('car_listings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('car_make_id');
            $table->dropConstrainedForeignId('car_model_id');
        });
    }
};
