<?php

return [

    // Optional mapping to dataset IDs (not strictly required for mock mode,
    // but handy if you later want to map friendly names -> Giovanni dataset ids)
    'data_map' => [
        'precipitation'   => 'GPM_3IMERGHH_06_precipitationCal',
        'air_temperature' => 'NOAA_NCEP_T2m',
        'windspeed'       => 'ERA5_hourly_wind_speed',
    ],

    // Thresholds per variable used by GiovanniService::computeProbabilities()
    // Keys are variable names (must match 'variables' you pass in the request).
    // Values are label => numeric-threshold
    'thresholds' => [
        'air_temperature' => [
            'very_hot_c' => 32.2,    // > 32.2 °C (90°F)
            'very_cold_c' => 0.0,    // < 0 °C (32°F)
            'very_uncomfortable_c' => 32.0,
        ],
        'precipitation' => [
            'very_wet_mm' => 10.0,   // >= 10 mm/day
        ],
        'windspeed' => [
            'very_windy_ms' => 10.0, // >= 10 m/s (~22 mph)
        ],
    ],

    // Defaults used by controller when start/end not provided
    'defaults' => [
        'start_year' => env('DEFAULT_START_YEAR', 1995),
        'end_year'   => env('DEFAULT_END_YEAR', date('Y') - 1),
    ],
];
