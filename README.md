# KNMI Weather Data API

This project exposes historical KNMI daily weather data through `/api/weather`.
All API responses are JSON.

## Base URL

```text
/api/weather
```

If the application is installed in a subdirectory, prefix the examples with that
directory.

The underlying PHP file remains available as `/api/weather.php`.

## Response Envelope

Successful responses use this shape:

```json
{
  "success": true,
  "data": {},
  "timestamp": "2026-05-12T10:00:00+02:00"
}
```

Error responses use this shape:

```json
{
  "success": false,
  "error": {
    "code": 400,
    "message": "Date parameter required"
  },
  "timestamp": "2026-05-12T10:00:00+02:00"
}
```

## Common Query Parameters

| Parameter | Type | Default | Description |
| --- | --- | --- | --- |
| `station` | integer | `260` | Supported KNMI station number. Use `/api/weather/stations` to list them. The UI defaults to De Bilt. |

Dates must use `YYYY-MM-DD`.

Supported stations:

| Code | Station |
| --- | --- |
| `235` | De Kooy Airport |
| `240` | Schiphol Airport |
| `260` | De Bilt |
| `270` | Leeuwarden Airport |
| `275` | Deelen Airport |
| `277` | Lauwersoog |
| `280` | Groningen Airport Eelde |
| `286` | Nieuw Beerta |
| `290` | Twenthe Airport |
| `310` | Vlissingen |
| `319` | Westdorpe |
| `344` | Rotterdam Airport |
| `348` | Cabauw |
| `350` | Gilze-Rijen Airport |
| `370` | Eindhoven Airport |
| `380` | Maastricht Airport |

## Rate Limit

The API is rate limited per client before a database connection is opened.
The default limit is `120` requests per `60` seconds.

Responses include:

| Header | Description |
| --- | --- |
| `X-RateLimit-Limit` | Maximum requests in the current window. |
| `X-RateLimit-Remaining` | Requests left in the current window. |
| `X-RateLimit-Reset` | Unix timestamp when the current window resets. |
| `Retry-After` | Seconds to wait; only sent with `429` responses. |

The defaults can be changed with environment variables:

| Variable | Default |
| --- | --- |
| `KNMI_API_RATE_LIMIT_REQUESTS` | `120` |
| `KNMI_API_RATE_LIMIT_WINDOW_SECONDS` | `60` |

## Endpoints

### List Supported Stations

```http
GET /api/weather/stations
```

Returns the supported KNMI stations and the default station.

### Get One Day

```http
GET /api/weather/day?date=2024-01-15
GET /api/weather/day?date=2024-01-15&station=260
```

Required parameters:

| Parameter | Type | Description |
| --- | --- | --- |
| `date` | date | Day to retrieve in `YYYY-MM-DD` format. |

Returns one weather record with:

| Field | Description |
| --- | --- |
| `station` | KNMI station number. |
| `date`, `date_formatted`, `day_name`, `month_name`, `month`, `year` | Date metadata. |
| `temperature` | Average, minimum, maximum, ground minimum, and related hour/period values. Temperatures are Celsius. |
| `wind` | Direction, direction degrees, vector/average/min/max/gust speeds, Beaufort data, and related hour values. Speeds are km/h. |
| `precipitation` | Amount, duration, maximum hourly amount, and maximum-hour value. Amounts are mm, durations are hours. |
| `sunshine` | Sunshine duration, percentage, and global radiation in J/cm². |
| `pressure` | Average, minimum, maximum, and related hour values in hPa. |
| `visibility` | Minimum, maximum, and related hour values. |
| `humidity` | Average, minimum, maximum, and related hour values. |
| `cloud_cover` | KNMI cloud cover value. |
| `evaporation` | Evaporation in mm. |

Possible errors:

| Status | Message |
| --- | --- |
| `400` | `Date parameter required` |
| `400` | `Invalid date format. Use YYYY-MM-DD` |
| `400` | `Unsupported station` |
| `404` | `No data found for the specified date` |

### Get a Period

```http
GET /api/weather/period?start=2024-01-01&end=2024-01-07
GET /api/weather/period?start=2024-01-01&end=2024-01-07&station=260
```

Required parameters:

| Parameter | Type | Description |
| --- | --- | --- |
| `start` | date | First date in `YYYY-MM-DD` format. |
| `end` | date | Last date in `YYYY-MM-DD` format. |

Returns an array of chart-friendly records ordered by date:

| Field | Description |
| --- | --- |
| `date`, `date_short` | Date values for plotting. |
| `temp_avg`, `temp_min`, `temp_max` | Temperatures in Celsius. |
| `wind_speed` | Average wind speed in km/h. |
| `rain_amount`, `rain_duration` | Precipitation amount in mm and duration in hours. |
| `sun_duration` | Sunshine duration in hours. |
| `radiation` | Global radiation in J/cm². |
| `pressure` | Average pressure in hPa. |

Possible errors:

| Status | Message |
| --- | --- |
| `400` | `Start and end date parameters required` |
| `400` | `Invalid date format. Use YYYY-MM-DD` |

### Get Monthly Statistics

```http
GET /api/weather/stats?year=2024&month=1
GET /api/weather/stats?year=2024&month=1&station=260
```

Required parameters:

| Parameter | Type | Description |
| --- | --- | --- |
| `year` | integer | Year to summarize. |
| `month` | integer | Month number from `1` through `12`. |

Returns:

| Field | Description |
| --- | --- |
| `total_days` | Number of available days in the month. |
| `temperature.avg`, `temperature.min`, `temperature.max` | Monthly temperature summary in Celsius. |
| `precipitation.total`, `precipitation.days` | Total precipitation in mm and number of rain days. |
| `sunshine.total` | Total sunshine duration in hours. |
| `wind.avg` | Average wind speed in km/h. |
| `pressure.avg` | Average pressure in hPa. |
| `special_days.summer_days` | Days where maximum temperature is at least 20.0 C. |
| `special_days.frost_days` | Days where minimum temperature is below 0.0 C. |

Possible errors:

| Status | Message |
| --- | --- |
| `400` | `Year and month parameters required` |
| `400` | `Invalid year or month` |

### Get Available Date Range

```http
GET /api/weather/range
GET /api/weather/range?station=260
```

Returns:

| Field | Description |
| --- | --- |
| `first_date` | Earliest available date for the station. |
| `last_date` | Latest available date for the station. |

## Other Requests

```http
GET /api/weather
```

Returns `400` with `Endpoint required`. Use one of the endpoint paths above.

```http
OPTIONS /api/weather
```

Used for CORS preflight requests. The API returns `200` without a JSON body.

Unsupported HTTP methods return:

```json
{
  "success": false,
  "error": {
    "code": 405,
    "message": "Method not allowed"
  },
  "timestamp": "2026-05-12T10:00:00+02:00"
}
```

Unknown endpoints return `404` with `Endpoint not found`.

Rate limited requests return:

```json
{
  "success": false,
  "error": {
    "code": 429,
    "message": "Rate limit exceeded. Try again later."
  },
  "rate_limit": {
    "limit": 120,
    "remaining": 0,
    "reset": 1780400000,
    "retry_after": 42
  },
  "timestamp": "2026-06-02T10:00:00+02:00"
}
```
