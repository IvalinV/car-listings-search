<?php

namespace App\Services;

use App\Models\CarMake;
use App\Models\CarModel;
use App\Services\Scrapers\MakeNormalizer;
use Illuminate\Support\Str;

class ListingMakeModelResolver
{
    public function __construct(private readonly MakeNormalizer $normalizer) {}

    /**
     * @return array{make: ?CarMake, model: ?CarModel}
     */
    public function resolve(?string $title): array
    {
        $clean = trim(preg_replace('/\s+/u', ' ', (string) $title));

        if ($clean === '') {
            return ['make' => null, 'model' => null];
        }

        $tokens = explode(' ', $clean);

        [$make, $consumed] = $this->matchMake($tokens);

        if ($make === null) {
            return ['make' => null, 'model' => null];
        }

        $model = $this->matchModel($make, array_slice($tokens, $consumed));

        return ['make' => $make, 'model' => $model];
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array{0: ?CarMake, 1: int}  the matched make and the number of leading tokens it consumed
     */
    private function matchMake(array $tokens): array
    {
        for ($n = min(2, count($tokens)); $n >= 1; $n--) {
            $candidate = $this->normalizer->canonicalize(implode(' ', array_slice($tokens, 0, $n)));
            $make = CarMake::whereRaw('LOWER(name) = ?', [mb_strtolower($candidate)])->first();

            if ($make !== null) {
                return [$make, $n];
            }
        }

        $canonical = $this->normalizer->canonicalize($tokens[0]);
        $make = CarMake::firstOrCreate(
            ['name' => $canonical],
            ['slug' => Str::slug($canonical)],
        );

        return [$make, 1];
    }

    /**
     * @param  array<int, string>  $rest
     */
    private function matchModel(CarMake $make, array $rest): ?CarModel
    {
        $token = trim($rest[0] ?? '');

        if ($token === '' || preg_match('/^\d+[.,]\d+/', $token) === 1) {
            return null;
        }

        $existing = $make->models()->whereRaw('LOWER(name) = ?', [mb_strtolower($token)])->first();

        if ($existing !== null) {
            return $existing;
        }

        return $make->models()->create([
            'name' => $token,
            'slug' => Str::slug($token),
        ]);
    }
}
