

## NASA Weather Likelihood API

This Laravel API computes historical likelihoods and seasonal projections for weather conditions using NASA data sources (Giovanni, with options to extend to OPeNDAP/Data Rods). It supports per-location, day-of-year queries and returns statistics, exceedance probabilities, trend, and percentiles. A seasonal forecast endpoint projects historical distributions to a target date using linear trend.

### Endpoints
- **POST** `api/v1/query` — historical likelihoods near a day-of-year.
- **POST** `api/v1/forecast` — seasonal projection to a target date (not deterministic forecast).
- **GET** `api/v1/download` — CSV export for a query.
- **GET** `api/v1/variables` — dataset mapping from `config/nasa.php`.
- **GET** `api/v1/thresholds` — probability thresholds per variable.
- **GET** `api/v1/health` — health check.

All `/api/v1/*` routes are throttled (`60/min`). If `API_KEY` is set in `.env`, include `X-API-Key` header or `api_key` query.

### Environment
- `GIOVANNI_TOKEN` — NASA Giovanni token. If missing, service uses mock CSV for local development.
- `API_KEY` — optional API key to protect endpoints.
- `CACHE_DRIVER=redis` (recommended for prod) or `file` for local.

### Config
Edit thresholds and dataset mapping in `config/nasa.php`:
- `data_map` — variable name -> dataset id (Giovanni variable).
- `thresholds` — per-variable thresholds used for exceedance probabilities.

### Run
```
cp .env.example .env
php artisan key:generate
php artisan serve
```

### Example cURL
Historical query:
```bash
curl -X POST http://localhost:8000/api/v1/query \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $API_KEY" \
  -d '{
    "lat": -1.2921,
    "lon": 36.8219,
    "day_of_year": 200,
    "start_year": 1995,
    "end_year": 2023,
    "variables": ["air_temperature","precipitation","windspeed"],
    "window_days": 3
  }'
```

Seasonal forecast:
```bash
curl -X POST http://localhost:8000/api/v1/forecast \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $API_KEY" \
  -d '{
    "lat": -1.2921,
    "lon": 36.8219,
    "date": "2026-07-19",
    "variables": ["air_temperature","precipitation","windspeed"],
    "window_days": 3,
    "start_year": 1995,
    "end_year": 2023
  }'
```

Download CSV:
```bash
curl "http://localhost:8000/api/v1/download?lat=-1.2921&lon=36.8219&day_of_year=200&start_year=1995&end_year=2023&variables=air_temperature,precipitation,windspeed&window_days=3" \
  -H "X-API-Key: $API_KEY" -o nasa_weather.csv
```

### OpenAPI spec
See `docs/openapi.yaml` for the machine-readable API definition.
