<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_listings', function (Blueprint $table): void {
            $table->timestamp('checked_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('car_listings', function (Blueprint $table): void {
            $table->dropIndex(['checked_at']);
            $table->dropColumn('checked_at');
        });
    }
};
