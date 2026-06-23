<?php

namespace App\Services\Scrapers;

class MakeNormalizer
{
    /**
     * Known cross-platform variants and abbreviations mapped to their canonical
     * full name. Keyed on the lowercased, whitespace-collapsed input.
     *
     * @var array<string, string>
     */
    private array $aliases = [
        'vw' => 'Volkswagen',
        'mercedes' => 'Mercedes-Benz',
        'mercedes benz' => 'Mercedes-Benz',
        'alfa' => 'Alfa Romeo',
    ];

    public function canonicalize(string $name): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $name));

        return $this->aliases[mb_strtolower($clean)] ?? $clean;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    public function dedupe(array $names): array
    {
        $seen = [];

        foreach ($names as $name) {
            $canonical = $this->canonicalize($name);

            if ($canonical === '') {
                continue;
            }

            $seen[mb_strtolower($canonical)] ??= $canonical;
        }

        return array_values($seen);
    }
}