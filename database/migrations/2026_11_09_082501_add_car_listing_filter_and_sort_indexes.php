<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('car_listings', function (Blueprint $table): void {
                $table->index('fuel_type', 'car_listings_fuel_type_filter_index');
                $table->index('transmission', 'car_listings_transmission_filter_index');
                $table->index('location', 'car_listings_location_filter_index');
                $table->index('car_make_id', 'car_listings_make_counts_index');
                $table->index('published_at', 'car_listings_effective_published_at_index');
            });

            return;
        }

        DB::statement("CREATE INDEX car_listings_fuel_type_filter_index ON car_listings (fuel_type) WHERE fuel_type IS NOT NULL AND fuel_type <> ''");
        DB::statement("CREATE INDEX car_listings_transmission_filter_index ON car_listings (transmission) WHERE transmission IS NOT NULL AND transmission <> ''");
        DB::statement("CREATE INDEX car_listings_location_filter_index ON car_listings (location) WHERE location IS NOT NULL AND location <> ''");

        $validPrice = 'price NOT IN (99999999, 111111, 222222, 333333, 444444, 555555, 666666, 777777, 888888, 999999, 123456, 1111111, 2222222, 3333333, 4444444, 5555555, 6666666, 7777777, 8888888, 9999999, 1234567, 11111111, 22222222, 33333333, 44444444, 55555555, 66666666, 77777777, 88888888, 12345678)';

        DB::statement("CREATE INDEX car_listings_make_counts_index ON car_listings (car_make_id) WHERE is_active AND car_make_id IS NOT NULL AND {$validPrice}");
        DB::statement("CREATE INDEX car_listings_effective_published_at_index ON car_listings ((COALESCE(published_at, created_at)) DESC) WHERE is_active AND {$validPrice}");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('car_listings', function (Blueprint $table): void {
                $table->dropIndex('car_listings_fuel_type_filter_index');
                $table->dropIndex('car_listings_transmission_filter_index');
                $table->dropIndex('car_listings_location_filter_index');
                $table->dropIndex('car_listings_make_counts_index');
                $table->dropIndex('car_listings_effective_published_at_index');
            });

            return;
        }

        DB::statement('DROP INDEX IF EXISTS car_listings_fuel_type_filter_index');
        DB::statement('DROP INDEX IF EXISTS car_listings_transmission_filter_index');
        DB::statement('DROP INDEX IF EXISTS car_listings_location_filter_index');
        DB::statement('DROP INDEX IF EXISTS car_listings_make_counts_index');
        DB::statement('DROP INDEX IF EXISTS car_listings_effective_published_at_index');
    }
};
