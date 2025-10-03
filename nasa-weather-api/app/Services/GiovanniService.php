<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class GiovanniService
{
    /**
     * Fetch a time series CSV from NASA Giovanni API
     * (If GIOVANNI_TOKEN missing, returns a mock CSV for local testing)
     */
    public function timeSeriesCsv(string $variable, float $lat, float $lon, int $startYear, int $endYear): ?string
    {
        $token = env('GIOVANNI_TOKEN');

        // If no token, return mock CSV for offline dev
        if (empty($token)) {
            Log::warning("Using mock CSV for {$variable} (no GIOVANNI_TOKEN set)");
            return $this->mockCsv($variable, $startYear, $endYear);
        }

        $cacheKey = sprintf('giovanni:%s:%0.4f:%0.4f:%d:%d', $variable, $lat, $lon, $startYear, $endYear);
        $ttl = now()->addHours(6);

        return Cache::remember($cacheKey, $ttl, function () use ($variable, $lat, $lon, $startYear, $endYear, $token) {
            try {
                $url = "https://giovanni.gsfc.nasa.gov/giovanni/giovanni-service/giovanni?" .
                    "service=TimeSeries&" .
                    "variable={$variable}&" .
                    "starttime={$startYear}-01-01T00:00:00Z&" .
                    "endtime={$endYear}-12-31T23:59:59Z&" .
                    "bbox={$lon},{$lat},{$lon},{$lat}&" .
                    "format=CSV";

                $resp = Http::timeout(30)
                    ->withToken($token)
                    ->get($url);

                if ($resp->successful()) {
                    return $resp->body();
                }

                Log::error("Giovanni API failed", [
                    'status' => $resp->status(),
                    'body'   => $resp->body()
                ]);
            } catch (\Exception $e) {
                Log::error("Giovanni exception: " . $e->getMessage());
            }

            return null;
        });

    }

    /**
     * Parse Giovanni CSV into [ [date, value], ... ]
     */
    public function parseCsv(string $csv): array
    {
        $rows = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));

        foreach ($lines as $line) {
            if (empty($line) || str_starts_with($line, "#") || str_contains($line, "time")) {
                continue;
            }
            $parts = str_getcsv($line);
            if (count($parts) >= 2) {
                $rows[] = [
                    'date'  => $parts[0],
                    'value' => is_numeric($parts[1]) ? floatval($parts[1]) : null
                ];
            }
        }
        return $rows;
    }

    /**
     * Extract nearest value per year around a target day-of-year
     */
    public function extractByDayOfYear(array $rows, int $dayOfYear, int $window = 3): array
    {
        $result = [];

        foreach ($rows as $r) {
            if (!$r['value']) continue;

            $time = strtotime($r['date']);
            $year = intval(date('Y', $time));
            $doy  = intval(date('z', $time)) + 1; // z = 0-indexed DOY

            if (abs($doy - $dayOfYear) <= $window) {
                // Take first match (could refine by min abs diff)
                if (!isset($result[$year])) {
                    $result[$year] = $r['value'];
                }
            }
        }

        return $result;
    }

    /**
     * Compute basic statistics (mean, median, std, count)
     */
    public function computeStats(array $yearly): array
    {
        if (empty($yearly)) {
            return ['count' => 0, 'mean' => null, 'median' => null, 'std' => null];
        }

        $values = array_values($yearly);
        sort($values);
        $count = count($values);
        $mean  = array_sum($values) / $count;
        $median = $values[intval($count / 2)];
        $variance = 0.0;
        foreach ($values as $v) {
            $variance += pow($v - $mean, 2);
        }
        $std = sqrt($variance / $count);

        return [
            'count'  => $count,
            'mean'   => round($mean, 2),
            'median' => round($median, 2),
            'std'    => round($std, 2)
        ];
    }

    /**
     * Compute probabilities of thresholds exceeded for variable
     */
    public function computeProbabilities(array $yearly, string $variable): array
    {
        $thresholds = config("nasa.thresholds.{$variable}", []);
        $results = [];

        if (empty($yearly) || empty($thresholds)) {
            return $results;
        }

        $values = array_values($yearly);
        $count = count($values);

        foreach ($thresholds as $label => $limit) {
            $exceed = array_filter($values, fn($v) => $v >= $limit);
            $results[$label] = round(count($exceed) / $count * 100, 1); // percentage
        }

        return $results;
    }

    /**
     * Compute simple linear trend over years.
     * Returns slope (per year), intercept, r2, n.
     */
    public function computeTrend(array $yearly): array
    {
        if (count($yearly) < 3) {
            return ['slope' => null, 'intercept' => null, 'r2' => null, 'n' => count($yearly)];
        }

        ksort($yearly);
        $x = array_keys($yearly);
        $y = array_values($yearly);
        $n = count($x);

        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = 0.0;
        $sumXX = 0.0;
        foreach (range(0, $n - 1) as $i) {
            $sumXY += $x[$i] * $y[$i];
            $sumXX += $x[$i] * $x[$i];
        }
        $den = ($n * $sumXX - $sumX * $sumX);
        if (abs($den) < 1e-9) {
            return ['slope' => 0.0, 'intercept' => $y[0], 'r2' => 0.0, 'n' => $n];
        }
        $slope = ($n * $sumXY - $sumX * $sumY) / $den;
        $intercept = ($sumY - $slope * $sumX) / $n;

        // r^2 computation
        $meanY = $sumY / $n;
        $ssTot = 0.0; $ssRes = 0.0;
        foreach (range(0, $n - 1) as $i) {
            $pred = $slope * $x[$i] + $intercept;
            $ssRes += ($y[$i] - $pred) ** 2;
            $ssTot += ($y[$i] - $meanY) ** 2;
        }
        $r2 = $ssTot > 0 ? 1 - ($ssRes / $ssTot) : 0.0;

        return [
            'slope' => round($slope, 5),
            'intercept' => round($intercept, 5),
            'r2' => round($r2, 4),
            'n' => $n,
        ];
    }

    /**
     * Compute percentiles of yearly values. Default: 50, 75, 90, 95
     */
    public function computePercentiles(array $yearly, array $percentiles = [50, 75, 90, 95]): array
    {
        $values = array_values($yearly);
        if (empty($values)) return [];
        sort($values);
        $n = count($values);
        $out = [];
        foreach ($percentiles as $p) {
            $rank = max(1, (int) ceil($p / 100 * $n));
            $rank = min($rank, $n);
            $out[(string)$p] = round($values[$rank - 1], 2);
        }
        return $out;
    }

    /**
     * Project historical yearly values to a target year using linear trend slope.
     * Returns a new array year => projectedValue for each historical year adjusted to targetYear.
     */
    public function projectToYear(array $yearly, int $targetYear): array
    {
        if (empty($yearly)) return [];
        $trend = $this->computeTrend($yearly);
        $slope = $trend['slope'];
        if ($slope === null) return $yearly; // not enough data; fallback to baseline

        $projected = [];
        foreach ($yearly as $year => $value) {
            if ($value === null) continue;
            $deltaYears = $targetYear - (int)$year;
            $projected[$year] = round($value + ($slope * $deltaYears), 4);
        }
        return $projected;
    }

    /**
     * Mock CSV (for offline dev without API token)
     */
    private function mockCsv(string $variable, int $startYear, int $endYear): string
    {
        $csv = "time,{$variable}\n";
        for ($y = $startYear; $y <= $endYear; $y++) {
            $value = match ($variable) {
                'precipitation'   => rand(0, 50) / 10,   // 0–5 mm
                'air_temperature' => rand(15, 40),       // 15–40 °C
                'windspeed'       => rand(0, 30),        // 0–30 m/s
                default           => rand(1, 100) / 10,
            };
            $csv .= "{$y}-07-19,{$value}\n";
        }
        return $csv;
    }
}

