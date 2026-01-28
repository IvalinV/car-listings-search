<?php

namespace App\Services\Scrapers;

use Carbon\Carbon;

class Scraper implements ScraperInterface
{
    public array $fuels = [
        'Бензин' => 'Petrol',
        'Дизел' => 'Diesel',
        'Газ' => 'Gas',
        'Хибрид' => 'Hybrid',
        'Електрически' => 'Electric',
    ];

    public $max_pages_to_scrape = 100;

    public function scrape()
    {
        // TODO: Implement scrape() method.
    }

    /**
     * Bulgarian month names for date parsing.
     */
    public array $bulgarianMonths = [
        'Януари', 'Февруари', 'Март', 'Април', 'Май', 'Юни',
        'Юли', 'Август', 'Септември', 'Октомври', 'Ноември', 'Декември',
    ];

    /**
     * Extract listing parameters from scraped HTML.
     */
    public function extractListingParams($input): array
    {
        /**
         * 1. Extract Production Year
         * Handles formats: "2021 г.", "Октомври 2021", or standalone "2021,"
         */
        $productionYear = null;
        $monthsPattern = implode('|', $this->bulgarianMonths);

        if (preg_match('/(\d{4})\s*г\./u', $input, $yearMatch)) {
            $productionYear = (int) $yearMatch[1];
        } elseif (preg_match('/(?:'.$monthsPattern.')\s+(\d{4})/u', $input, $yearMatch)) {
            $productionYear = (int) $yearMatch[1];
        } elseif (preg_match('/^(\d{4})\s*,/u', $input, $yearMatch)) {
            $productionYear = (int) $yearMatch[1];
        }

        /**
         * 2. Extract Mileage
         * Handles numbers with spaces (e.g., "48 900") followed by "км"
         */
        $mileage = null;
        if (preg_match('/([\d\s]+)\s*км/u', $input, $mileageMatch)) {
            // Remove spaces to get a clean integer
            $mileage = (int) str_replace(' ', '', $mileageMatch[1]);
        }

        /**
         * 3. Extract Horsepower (HP)
         */
        $horsepower = null;
        if (preg_match('/(\d+)\s*к\.с\./u', $input, $hpMatch)) {
            $horsepower = (int) $hpMatch[1];
        }

        /**
         * 4. Extract Engine Displacement (CC)
         */
        $engineCc = null;
        if (preg_match('/(\d+)\s*куб\.см/u', $input, $ccMatch)) {
            $engineCc = (int) $ccMatch[1];
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
        $fuelType = $this->determineFuelType($input);

        /**
         * 8. Detect Updated Date
         * Handles "днес 12:00" or "20.01.2026 12:00"
         */
        $updatedAt = null;

        // Check for absolute date format (dd.mm.yyyy hh:mm)
        if (preg_match('/(\d{2}\.\d{2}\.\d{4})\s+(\d{2}:\d{2})/', $input, $dateMatch)) {
            $updatedAt = Carbon::createFromFormat('d.m.Y H:i', $dateMatch[1].' '.$dateMatch[2], 'Europe/Sofia');
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

    public function determineFuelType($input)
    {
        foreach ($this->fuels as $bg => $en) {
            if (\Str::contains($input, $bg, true)) {
                return $en;
            }
        }

        return null;
    }

    /**
     * Extract price information from a price string.
     *
     * Handles formats like:
     * - "15 990 EUR - 31 273,72 лв."
     * - "6 770 €32 799.27 лв. Не се начислява ДДС"
     * - "3,150 6,160.86 EUR BGN"
     *
     * @return array{eur: float|null, bgn: float|null}
     */
    public function extractPrice(string $input): array
    {
        $result = [
            'eur' => null,
            'bgn' => null,
        ];

        // Normalize the input: remove extra whitespace
        $input = preg_replace('/\s+/', ' ', trim($input));

        // Currency patterns
        $eurPatterns = ['EUR', '€', 'евро'];
        $bgnPatterns = ['лв\.?', 'лева', 'BGN'];

        // Build currency regex parts
        $eurRegex = implode('|', $eurPatterns);
        $bgnRegex = implode('|', $bgnPatterns);

        // Number pattern without spaces (for strict matching)
        $numberPatternStrict = '[\d][\d,\.]*[\d]|[\d]+';

        // Number pattern with spaces (for formats like "15 990")
        $numberPatternWithSpaces = '[\d][\d\s,\.]*[\d]|[\d]+';

        // FIRST: Handle case where currencies are listed at the end: "3,150 6,160.86 EUR BGN"
        if (preg_match('/('.$numberPatternStrict.')\s+('.$numberPatternStrict.')\s+(?:'.$eurRegex.')\s+(?:'.$bgnRegex.')/iu', $input, $match)) {
            $result['eur'] = $this->parseNumber($match[1]);
            $result['bgn'] = $this->parseNumber($match[2]);

            return $result;
        }

        // Try to extract EUR price
        // Pattern 1: Number followed by EUR symbol
        if (preg_match('/('.$numberPatternWithSpaces.')\s*(?:'.$eurRegex.')/iu', $input, $match)) {
            $result['eur'] = $this->parseNumber($match[1]);
        }
        // Pattern 2: EUR symbol followed by number (e.g., "€6 770")
        elseif (preg_match('/(?:'.$eurRegex.')\s*('.$numberPatternWithSpaces.')/iu', $input, $match)) {
            $result['eur'] = $this->parseNumber($match[1]);
        }

        // Try to extract BGN price
        // Pattern 1: Number followed by BGN symbol
        if (preg_match('/('.$numberPatternWithSpaces.')\s*(?:'.$bgnRegex.')/iu', $input, $match)) {
            $result['bgn'] = $this->parseNumber($match[1]);
        }
        // Pattern 2: BGN symbol followed by number
        elseif (preg_match('/(?:'.$bgnRegex.')\s*('.$numberPatternWithSpaces.')/iu', $input, $match)) {
            $result['bgn'] = $this->parseNumber($match[1]);
        }

        return $result;
    }

    /**
     * Parse a number string with various formats into a float.
     *
     * Handles:
     * - "15 990" -> 15990.0
     * - "31 273,72" -> 31273.72
     * - "32 799.27" -> 32799.27
     * - "6,160.86" -> 6160.86
     * - "3,150" -> 3150.0 (comma as thousand separator when no decimal)
     */
    public function parseNumber(string $number): float
    {
        // Remove all spaces
        $number = str_replace(' ', '', $number);

        // Determine decimal separator by checking the last occurrence
        $lastComma = strrpos($number, ',');
        $lastPeriod = strrpos($number, '.');

        if ($lastComma === false && $lastPeriod === false) {
            // No separators, just digits
            return (float) $number;
        }

        if ($lastComma !== false && $lastPeriod !== false) {
            // Both exist - the last one is likely the decimal separator
            if ($lastComma > $lastPeriod) {
                // Comma is decimal (European format): 1.234,56
                $number = str_replace('.', '', $number);
                $number = str_replace(',', '.', $number);
            } else {
                // Period is decimal (US format): 1,234.56
                $number = str_replace(',', '', $number);
            }
        } elseif ($lastComma !== false) {
            // Only comma exists
            // Check if it's a decimal separator (less than 3 digits after)
            $afterComma = substr($number, $lastComma + 1);
            if (strlen($afterComma) <= 2) {
                // Likely a decimal separator: 1234,56
                $number = str_replace(',', '.', $number);
            } else {
                // Likely a thousand separator: 1,234
                $number = str_replace(',', '', $number);
            }
        } elseif ($lastPeriod !== false) {
            // Only period exists
            // Check if it's a decimal separator (less than 3 digits after)
            $afterPeriod = substr($number, $lastPeriod + 1);
            if (strlen($afterPeriod) <= 2) {
                // Likely a decimal separator: 1234.56 - keep as is
            } else {
                // Likely a thousand separator: 1.234 (European)
                $number = str_replace('.', '', $number);
            }
        }

        return (float) $number;
    }

    /**
     * Ensures that a URL starting with 'www.' is converted to a 'https://' link.
     * @param string $url
     * @return string
     */
    function formatListingUrl(string $url): string
    {
        // We check if the string starts specifically with 'www.'
        // str_starts_with is available in PHP 8.0+ (standard for Laravel 12)
        if (str_starts_with($url, 'www.')) {
            return 'https://' . substr($url, 4);
        }

        return $url;
    }

    public function getUserAgents(): array
    {
        return [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/121.0',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
        ];
    }
}
