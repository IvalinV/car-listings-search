<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->mergeDuplicateMakes();

        Schema::table('car_makes', function (Blueprint $table): void {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('car_makes', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
        });
    }

    /**
     * Collapse makes sharing a slug into the lowest-id canonical make,
     * remapping their models and listings first so unique(slug) applies
     * without collisions. Data loss in down() is acceptable (one-time merge).
     */
    private function mergeDuplicateMakes(): void
    {
        $dupSlugs = DB::table('car_makes')
            ->select('slug')
            ->groupBy('slug')
            ->havingRaw('count(*) > 1')
            ->pluck('slug');

        foreach ($dupSlugs as $slug) {
            $makeIds = DB::table('car_makes')->where('slug', $slug)->orderBy('id')->pluck('id');
            $canonicalMakeId = $makeIds->first();

            foreach ($makeIds->skip(1) as $dupMakeId) {
                $dupModels = DB::table('car_models')->where('car_make_id', $dupMakeId)->get(['id', 'slug']);

                foreach ($dupModels as $dupModel) {
                    $canonicalModelId = DB::table('car_models')
                        ->where('car_make_id', $canonicalMakeId)
                        ->where('slug', $dupModel->slug)
                        ->value('id');

                    if ($canonicalModelId !== null) {
                        DB::table('car_listings')
                            ->where('car_model_id', $dupModel->id)
                            ->update(['car_model_id' => $canonicalModelId]);

                        DB::table('car_models')->where('id', $dupModel->id)->delete();
                    } else {
                        DB::table('car_models')
                            ->where('id', $dupModel->id)
                            ->update(['car_make_id' => $canonicalMakeId]);
                    }
                }

                DB::table('car_listings')
                    ->where('car_make_id', $dupMakeId)
                    ->update(['car_make_id' => $canonicalMakeId]);

                DB::table('car_makes')->where('id', $dupMakeId)->delete();
            }
        }
    }
};
