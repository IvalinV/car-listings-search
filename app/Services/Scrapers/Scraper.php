<?php

namespace App\Services\Scrapers;

use App\Services\Scrapers\ScraperInterface;
use Carbon\Carbon;

class Scraper implements ScraperInterface
{
    public function scrape()
    {
        // TODO: Implement scrape() method.
    }

    /**
     * Extract listing parameters from scraped HTML.
     *
     * @param $input
     * @return array
     */
    public function extractListingParams($input): array
    {/**
     * 1. Extract Production Year
     * Looks for 4 digits followed by "г." (year abbreviation in Bulgarian)
     */
        $productionYear = null;
        if (preg_match('/(\d{4})\s*г\./u', $input, $yearMatch)) {
            $productionYear = (int)$yearMatch[1];
        }

        /**
         * 2. Extract Mileage
         * Handles numbers with spaces (e.g., "48 900") followed by "км"
         */
        $mileage = null;
        if (preg_match('/([\d\s]+)\s*км/u', $input, $mileageMatch)) {
            // Remove spaces to get a clean integer
            $mileage = (int)str_replace(' ', '', $mileageMatch[1]);
        }

        /**
         * 3. Extract Horsepower (HP)
         */
        $horsepower = null;
        if (preg_match('/(\d+)\s*к\.с\./u', $input, $hpMatch)) {
            $horsepower = (int)$hpMatch[1];
        }

        /**
         * 4. Extract Engine Displacement (CC)
         */
        $engineCc = null;
        if (preg_match('/(\d+)\s*куб\.см/u', $input, $ccMatch)) {
            $engineCc = (int)$ccMatch[1];
        }

        /**
         * 5. Extract Euro Standard
         */
        $euroStandard = null;
        if (preg_match('/Евро\s*(\d+)/u', $input, $euroMatch)) {
            $euroStandard = $euroMatch[1];
        }

        /**
         * 6. Detect Transmission
         */
        $transmission = null;
        if (mb_stripos($input, 'Автоматична') !== false) {
            $transmission = 'Automatic';
        } elseif (mb_stripos($input, 'Ръчна') !== false) {
            $transmission = 'Manual';
        }

        /**
         * 7. Detect Fuel Type
         */
        $fuelType = null;
        $fuels = ['Бензинов' => 'Petrol', 'Дизелов' => 'Diesel', 'Хибриден' => 'Hybrid', 'Електрически' => 'Electric'];
        foreach ($fuels as $bg => $en) {
            if (mb_stripos($input, $bg) !== false) {
                $fuelType = $en;
                break;
            }
        }

        /**
         * 8. Detect Updated Date
         * Handles "днес 12:00" or "20.01.2026 12:00"
         */
        $updatedAt = null;

        // Check for absolute date format (dd.mm.yyyy hh:mm)
        if (preg_match('/(\d{2}\.\d{2}\.\d{4})\s+(\d{2}:\d{2})/', $input, $dateMatch)) {
            $updatedAt = Carbon::createFromFormat('d.m.Y H:i', $dateMatch[1] . ' ' . $dateMatch[2], 'Europe/Sofia');
        }
        // Check for relative "today" format (днес hh:mm)
        elseif (preg_match('/днес\s+(\d{2}:\d{2})/u', $input, $todayMatch)) {
            $updatedAt = Carbon::now('Europe/Sofia')->setTimeFromTimeString($todayMatch[1]);
        }

        /**
         * Outputting the parsed data
         */
        return [
            'production_year' => $productionYear ?? null,
            'mileage' => $mileage,
            'horsepower"' => $horsepower ?? null,
            'fuel' => $fuelType ?? null,
            'engine_cc' => $engineCc ?? null,
            'euro_standard' => $euroStandard ?? null,
            'last_updated_at' => $updatedAt?->toDateTimeString() ?? null,
            'transmission' => $transmission ?? null,
        ];
    }
}
