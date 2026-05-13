# KNMI Weather Data API

This project exposes historical KNMI daily weather data through `api/weather.php`.
All API responses are JSON.

## Base URL

```text
/api/weather.php
```

If the application is installed in a subdirectory, prefix the examples with that
directory.

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
| `station` | integer | `260` | KNMI station number. The UI defaults to De Bilt. |

Dates must use `YYYY-MM-DD`.

## Endpoints

### Get One Day

```http
GET /api/weather.php/day?date=2024-01-15
GET /api/weather.php/day?date=2024-01-15&station=260
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
| `sunshine` | Sunshine duration, percentage, and global radiation. |
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
| `404` | `No data found for the specified date` |

### Get a Period

```http
GET /api/weather.php/period?start=2024-01-01&end=2024-01-07
GET /api/weather.php/period?start=2024-01-01&end=2024-01-07&station=260
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
| `pressure` | Average pressure in hPa. |

Possible errors:

| Status | Message |
| --- | --- |
| `400` | `Start and end date parameters required` |
| `400` | `Invalid date format. Use YYYY-MM-DD` |

### Get Monthly Statistics

```http
GET /api/weather.php/stats?year=2024&month=1
GET /api/weather.php/stats?year=2024&month=1&station=260
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
GET /api/weather.php/range
GET /api/weather.php/range?station=260
```

Returns:

| Field | Description |
| --- | --- |
| `first_date` | Earliest available date for the station. |
| `last_date` | Latest available date for the station. |

## Other Requests

```http
GET /api/weather.php
```

Returns `400` with `Endpoint required`. Use one of the endpoint paths above.

```http
OPTIONS /api/weather.php
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
