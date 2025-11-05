# Erosion Calculation Command

## Overview

The `erosion:calculate` command allows you to calculate RUSLE factors (R, K, LS, C, P) and soil erosion for a specific region or district and year.

## Usage

### Basic Usage

```bash
# Calculate all factors for a region
php artisan erosion:calculate --region_id=1 --year=2024

# Calculate all factors for a district
php artisan erosion:calculate --district_id=5 --year=2024

# Calculate specific factors only
php artisan erosion:calculate --region_id=1 --year=2024 --factors=r,k,ls

# Calculate and also trigger precomputation (tile generation)
php artisan erosion:calculate --region_id=1 --year=2024 --precompute
```

## Command Options

| Option | Required | Description | Example |
|--------|----------|-------------|---------|
| `--region_id` | Either this or `--district_id` | Region ID to calculate for | `--region_id=1` |
| `--district_id` | Either this or `--region_id` | District ID to calculate for | `--district_id=5` |
| `--year` | Yes | Year to calculate (1993-2025) | `--year=2024` |
| `--factors` | No | Which factors to compute | `--factors=all` or `--factors=r,k,ls` |
| `--precompute` | No | Also trigger precomputation (generate tiles) | `--precompute` |

## Factor Options

### Available Factors

- `r` - R-Factor (Rainfall Erosivity) - MJ mm/(ha h yr)
- `k` - K-Factor (Soil Erodibility) - t ha h/(ha MJ mm)
- `ls` - LS-Factor (Topographic) - dimensionless
- `c` - C-Factor (Cover Management) - 0-1
- `p` - P-Factor (Support Practice) - 0-1

### Factor Values

- `all` - Compute all factors (default)
- `r,k,ls` - Compute only specified factors (comma-separated)
- `r` - Compute only R-factor

## Examples

### Example 1: Calculate all factors for a region

```bash
php artisan erosion:calculate --region_id=1 --year=2024
```

**Output:**
```
========================================
 RUSLE Factors Calculation
========================================

Area Information:
  Type: REGION
  ID: 1
  Name: Dushanbe
  Year: 2024
  Factors: all

Computing RUSLE factors...

========================================
 Calculation Results
========================================

RUSLE Factors:

  R-Factor (Rainfall Erosivity):
    Mean:   125.45 MJ mm/(ha h yr)
    Min:    98.23 MJ mm/(ha h yr)
    Max:    156.78 MJ mm/(ha h yr)
    StdDev: 12.34 MJ mm/(ha h yr)
    Description: Rainfall Erosivity

  K-Factor (Soil Erodibility):
    Mean:   0.245 t ha h/(ha MJ mm)
    Min:    0.180 t ha h/(ha MJ mm)
    Max:    0.320 t ha h/(ha MJ mm)
    StdDev: 0.035 t ha h/(ha MJ mm)
    Description: Soil Erodibility

  ... (LS, C, P factors)

Soil Erosion (A = R × K × LS × C × P):
  Mean:   15.23 t/ha/yr
  Min:    5.45 t/ha/yr
  Max:    28.67 t/ha/yr
  StdDev: 6.12 t/ha/yr

✓ Calculation completed successfully!
```

### Example 2: Calculate only R and K factors

```bash
php artisan erosion:calculate --region_id=1 --year=2024 --factors=r,k
```

### Example 3: Calculate and precompute tiles

```bash
php artisan erosion:calculate --region_id=1 --year=2024 --precompute
```

This will:
1. Calculate all RUSLE factors
2. Display results
3. Queue a background task to generate GeoTIFF and tiles
4. Return a task ID for monitoring

## Output Details

### R-Factor (Rainfall Erosivity)
- **Unit**: MJ mm/(ha h yr)
- **Source**: CHIRPS daily precipitation data
- **Description**: Measures the erosive power of rainfall

### K-Factor (Soil Erodibility)
- **Unit**: t ha h/(ha MJ mm)
- **Source**: SoilGrids (clay, silt, sand, organic carbon)
- **Description**: Measures how easily soil is eroded

### LS-Factor (Topographic)
- **Unit**: dimensionless
- **Source**: SRTM DEM (slope length and steepness)
- **Description**: Accounts for slope length and steepness effects

### C-Factor (Cover Management)
- **Unit**: 0-1 (dimensionless)
- **Source**: Sentinel-2 NDVI time series
- **Description**: Measures vegetation cover protection

### P-Factor (Support Practice)
- **Unit**: 0-1 (dimensionless)
- **Source**: ESA WorldCover land use classification
- **Description**: Accounts for conservation practices

### Soil Erosion (A)
- **Unit**: t/ha/yr (tons per hectare per year)
- **Formula**: A = R × K × LS × C × P
- **Description**: Final soil loss estimate

## Integration with Precomputation

The command can trigger full precomputation (tile generation) using the `--precompute` flag:

```bash
php artisan erosion:calculate --region_id=1 --year=2024 --precompute
```

This will:
1. Calculate all factors (quick computation)
2. Queue a Celery task for full precomputation
3. Generate GeoTIFF and map tiles in the background

Monitor precomputation progress:
```bash
# Check task status
php artisan tinker --execute="echo 'Task ID: YOUR_TASK_ID';"

# Or check logs
tail -f /var/log/rusle-celery-worker.log
```

## Troubleshooting

### Error: "Either --region_id or --district_id must be provided"
- Provide one of these options (not both)

### Error: "--year is required"
- Always provide the year: `--year=2024`

### Error: "Year must be between 1993 and 2025"
- Valid years are 1993-2025

### Error: "Failed to compute factors"
- Check Python GEE service is running: `curl http://localhost:5000/api/health`
- Check logs: `tail -f /var/log/rusle-python-service.log`
- Verify GEE credentials are configured

### Slow Computation
- Large areas may take 5-10 minutes
- Use `--factors=r,k` to compute only specific factors for faster results
- The command uses 100m resolution by default for faster computation

## API Endpoint

The command uses the Python GEE service endpoint:
- **URL**: `POST /api/rusle/factors`
- **Request**: `{area_geometry: GeoJSON, year: int, factors: 'all'|['r','k','ls','c','p']}`
- **Response**: `{factors: {...}, soil_erosion: {...}, year: int, scale: int}`

## Related Commands

- `erosion:precompute-all` - Precompute all regions/districts for year range
- `erosion:precompute-latest-year` - Precompute latest year for all areas


