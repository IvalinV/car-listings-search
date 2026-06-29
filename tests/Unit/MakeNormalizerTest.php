<?php

use App\Services\Scrapers\MakeNormalizer;

it('canonicalizes known abbreviations to full names', function (string $input, string $expected): void {
    expect((new MakeNormalizer)->canonicalize($input))->toBe($expected);
})->with([
    'VW -> Volkswagen' => ['VW', 'Volkswagen'],
    'Alfa -> Alfa Romeo' => ['Alfa', 'Alfa Romeo'],
    'spaced Mercedes' => ['Mercedes Benz', 'Mercedes-Benz'],
    'Range Rover -> Land Rover' => ['Range Rover', 'Land Rover'],
]);

it('trims and collapses internal whitespace', function (): void {
    expect((new MakeNormalizer)->canonicalize('  Alfa   Romeo '))->toBe('Alfa Romeo');
});

it('leaves unknown names unchanged apart from cleanup', function (): void {
    expect((new MakeNormalizer)->canonicalize('BMW'))->toBe('BMW');
});

it('deduplicates case-insensitively and merges aliases', function (): void {
    $result = (new MakeNormalizer)->dedupe(['BMW', 'bmw', 'VW', 'Volkswagen', 'Audi', '']);

    expect($result)->toEqualCanonicalizing(['BMW', 'Volkswagen', 'Audi']);
});
