<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The cleanup sweep orders by "checked_at ASC NULLS FIRST" (least-recently
     * verified, never-checked first). The default btree index is NULLS LAST, so
     * the planner cannot use it for that ordering and falls back to a full-table
     * sort — which aborts under serverless Postgres statement/temp-file limits.
     * A NULLS FIRST index lets the LIMIT be served by an index scan instead.
     */
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS car_listings_checked_at_index');

        $ordering = DB::getDriverName() === 'pgsql' ? 'checked_at ASC NULLS FIRST' : 'checked_at ASC';

        DB::statement("CREATE INDEX car_listings_checked_at_index ON car_listings ($ordering)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS car_listings_checked_at_index');
        DB::statement('CREATE INDEX car_listings_checked_at_index ON car_listings (checked_at)');
    }
};
