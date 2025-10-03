<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\GiovanniService;
use Illuminate\Support\Facades\Response;
use Carbon\Carbon;

class WeatherController extends Controller
{
    protected $giovanni;

    public function __construct(GiovanniService $giovanni)
    {
        $this->giovanni = $giovanni;
    }

    /**
     * If API_KEY is set in .env, enforce it via header X-API-Key or query param api_key.
     * Returns a Response on failure, or null on success.
     */
    private function enforceApiKey(Request $request)
    {
        $required = env('API_KEY');
        if (empty($required)) {
            return null; // no key required
        }
        $provided = $request->header('X-API-Key') ?? $request->query('api_key');
        if (!hash_equals((string)$required, (string)$provided)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        return null;
    }

    /**
     * POST /api/v1/query
     * Main query endpoint: returns JSON with stats + probabilities
     */
    public function query(Request $request)
    {
        if ($resp = $this->enforceApiKey($request)) return $resp;
        $validated = $request->validate([
            'lat' => 'required|numeric',
            'lon' => 'required|numeric',
            'day_of_year' => 'required|integer|min:1|max:366',
            'start_year' => 'nullable|integer',
            'end_year' => 'nullable|integer',
            'variables' => 'required|array|min:1',
            'variables.*' => 'string',
            'window_days' => 'nullable|integer|min:0|max:15'
        ]);

        $lat   = $validated['lat'];
        $lon   = $validated['lon'];
        $day   = $validated['day_of_year'];
        $start = $validated['start_year'] ?? config('nasa.defaults.start_year');
        $end   = $validated['end_year'] ?? config('nasa.defaults.end_year');
        $vars  = $validated['variables'];
        $win   = $validated['window_days'] ?? 3;

        $results = [];

        foreach ($vars as $var) {
            // Call Giovanni (or mock) to get CSV
            $csv = $this->giovanni->timeSeriesCsv($var, $lat, $lon, $start, $end);

            if (!$csv) {
                return response()->json([
                    'error' => "No data for variable {$var}"
                ], 404);
            }

            // Parse CSV
            $rows = $this->giovanni->parseCsv($csv);

            // Compute per-year values (near day_of_year ± window_days)
            $yearly = $this->giovanni->extractByDayOfYear($rows, $day, $win);

            // Compute statistics and probabilities
            $stats = $this->giovanni->computeStats($yearly);
            $probs = $this->giovanni->computeProbabilities($yearly, $var);
            $trend = $this->giovanni->computeTrend($yearly);
            $percentiles = $this->giovanni->computePercentiles($yearly);

            $results[$var] = [
                'years' => $yearly,
                'stats' => $stats,
                'probabilities' => $probs,
                'trend' => $trend,
                'percentiles' => $percentiles,
            ];
        }

        return response()->json([
            'location' => ['lat' => $lat, 'lon' => $lon],
            'period' => ['start' => $start, 'end' => $end],
            'day_of_year' => $day,
            'results' => $results
        ]);
    }

    /**
     * POST /api/v1/forecast
     * Seasonal projection using historical DOY and linear trend to target year.
     */
    public function forecast(Request $request)
    {
        if ($resp = $this->enforceApiKey($request)) return $resp;
        $validated = $request->validate([
            'lat' => 'required|numeric',
            'lon' => 'required|numeric',
            'date' => 'required|date',
            'variables' => 'required|array|min:1',
            'variables.*' => 'string',
            'window_days' => 'nullable|integer|min:0|max:15',
            'start_year' => 'nullable|integer',
            'end_year' => 'nullable|integer',
        ]);

        $lat   = (float)$validated['lat'];
        $lon   = (float)$validated['lon'];
        $date  = Carbon::parse($validated['date'])->utc();
        $day   = (int)$date->dayOfYear;
        $targetYear = (int)$date->year;
        $start = $validated['start_year'] ?? config('nasa.defaults.start_year');
        $end   = $validated['end_year'] ?? config('nasa.defaults.end_year');
        $vars  = $validated['variables'];
        $win   = $validated['window_days'] ?? 3;

        $results = [];

        foreach ($vars as $var) {
            $csv = $this->giovanni->timeSeriesCsv($var, $lat, $lon, (int)$start, (int)$end);
            if (!$csv) {
                return response()->json([
                    'error' => "No data for variable {$var}"
                ], 404);
            }

            $rows = $this->giovanni->parseCsv($csv);
            $yearly = $this->giovanni->extractByDayOfYear($rows, $day, (int)$win);

            // Baseline
            $baselineStats = $this->giovanni->computeStats($yearly);
            $baselinePercentiles = $this->giovanni->computePercentiles($yearly);
            $trend = $this->giovanni->computeTrend($yearly);

            // Projection to target year
            $projected = $this->giovanni->projectToYear($yearly, $targetYear);
            $projectedStats = $this->giovanni->computeStats($projected);
            $projectedPercentiles = $this->giovanni->computePercentiles($projected);
            $projectedProbs = $this->giovanni->computeProbabilities($projected, $var);

            $results[$var] = [
                'baseline' => [
                    'years' => $yearly,
                    'stats' => $baselineStats,
                    'percentiles' => $baselinePercentiles,
                ],
                'trend' => $trend,
                'projected' => [
                    'target_year' => $targetYear,
                    'years' => $projected,
                    'stats' => $projectedStats,
                    'percentiles' => $projectedPercentiles,
                    'probabilities' => $projectedProbs,
                ],
            ];
        }

        return response()->json([
            'location' => ['lat' => $lat, 'lon' => $lon],
            'baseline_period' => ['start' => (int)$start, 'end' => (int)$end],
            'date' => $date->toDateString(),
            'day_of_year' => $day,
            'results' => $results,
            'notes' => 'Seasonal projection from historical DOY using linear trend; not a deterministic weather forecast.'
        ]);
    }

    /**
     * GET /api/v1/download
     * Download CSV of results for given query
     */
    public function downloadCsv(Request $request)
    {
        if ($resp = $this->enforceApiKey($request)) return $resp;
        $lat   = $request->query('lat');
        $lon   = $request->query('lon');
        $day   = $request->query('day_of_year');
        $start = $request->query('start_year', config('nasa.defaults.start_year'));
        $end   = $request->query('end_year', config('nasa.defaults.end_year'));
        $vars  = explode(',', $request->query('variables', 'precipitation'));
        $win   = $request->query('window_days', 3);

        $csvOut = "variable,year,value,mean,median,std,probabilities\n";

        foreach ($vars as $var) {
            $csv = $this->giovanni->timeSeriesCsv($var, $lat, $lon, $start, $end);
            $rows = $this->giovanni->parseCsv($csv);
            $yearly = $this->giovanni->extractByDayOfYear($rows, $day, $win);
            $stats = $this->giovanni->computeStats($yearly);
            $probs = $this->giovanni->computeProbabilities($yearly, $var);

            foreach ($yearly as $yr => $val) {
                $csvOut .= "{$var},{$yr},{$val},{$stats['mean']},{$stats['median']},{$stats['std']}," . json_encode($probs) . "\n";
            }
        }

        return Response::make($csvOut, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="nasa_weather.csv"'
        ]);
    }

    /**
     * GET /api/v1/variables
     * Return the available variables and dataset mapping
     */
    public function variables()
    {
        if ($resp = $this->enforceApiKey(request())) return $resp;
        return response()->json([
            'data_map' => config('nasa.data_map', []),
        ]);
    }

    /**
     * GET /api/v1/thresholds
     * Return configured probability thresholds per variable
     */
    public function thresholds()
    {
        if ($resp = $this->enforceApiKey(request())) return $resp;
        return response()->json([
            'thresholds' => config('nasa.thresholds', []),
        ]);
    }
}

