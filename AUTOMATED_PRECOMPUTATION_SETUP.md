# Automated Erosion Map Precomputation

## Overview

Automatically precomputes erosion maps for the latest year **twice per year** to ensure data is always up-to-date.

## Schedule

- **June 1st at 2:00 AM UTC** - Mid-year update
- **December 1st at 2:00 AM UTC** - End-of-year update

## How It Works

### 1. Artisan Command

**Command:** `erosion:precompute-latest-year`

```bash
# Precompute current year for all areas
php artisan erosion:precompute-latest-year

# Precompute specific year
php artisan erosion:precompute-latest-year --year=2024

# Precompute only regions or districts
php artisan erosion:precompute-latest-year --type=region
php artisan erosion:precompute-latest-year --type=district

# Force recompute (even if already exists)
php artisan erosion:precompute-latest-year --force
```

### 2. Laravel Scheduler

Configured in `routes/console.php`:

```php
Schedule::command('erosion:precompute-latest-year --type=all')
    ->cron('0 2 1 6,12 *')  // June 1st and Dec 1st at 2 AM
    ->timezone('UTC')
    ->withoutOverlapping()
    ->runInBackground();
```

### 3. System Cron Job

**Required:** Set up system cron to trigger Laravel scheduler every minute.

## Setup Instructions

### Step 1: Set Up Laravel Scheduler Cron

Add this to your system crontab:

```bash
# Edit crontab
sudo crontab -e

# Add this line (runs Laravel scheduler every minute)
* * * * * cd /var/www/rusle-icarda && php artisan schedule:run >> /dev/null 2>&1
```

**For www-data user (recommended):**

```bash
# Edit www-data crontab
sudo crontab -e -u www-data

# Add this line
* * * * * cd /var/www/rusle-icarda && php artisan schedule:run >> /var/log/laravel-scheduler.log 2>&1
```

### Step 2: Verify Scheduler Setup

```bash
# List scheduled tasks
php artisan schedule:list

# Test the command manually
php artisan erosion:precompute-latest-year --year=2024

# Run scheduler manually (for testing)
php artisan schedule:run
```

### Step 3: Monitor Execution

```bash
# Check Laravel logs
tail -f storage/logs/laravel.log

# Check scheduler logs (if logging to file)
tail -f /var/log/laravel-scheduler.log

# Check Celery worker logs
sudo tail -f /var/log/rusle-celery-worker.log
```

## Cron Expression Breakdown

```
0 2 1 6,12 *
│ │ │ │   │
│ │ │ │   └─── Any day of week
│ │ │ └─────── Months: June (6) and December (12)
│ │ └───────── Day: 1st
│ └─────────── Hour: 2 AM
└───────────── Minute: 0

Result: Runs at 02:00 on the 1st day of June and December
```

## Manual Execution

### Run Immediately for Current Year

```bash
cd /var/www/rusle-icarda
php artisan erosion:precompute-latest-year
```

### Run for Specific Year

```bash
# Precompute 2024 data
php artisan erosion:precompute-latest-year --year=2024

# Precompute 2025 data
php artisan erosion:precompute-latest-year --year=2025
```

### Force Recompute

```bash
# Recompute even if already exists
php artisan erosion:precompute-latest-year --force
```

## What Gets Precomputed

For the latest year (e.g., 2024):
- All regions (~31 regions)
- All districts (~30 districts)
- Total: ~61 erosion maps

**Time:** ~5-6 hours total

## Monitoring

### Check Scheduled Tasks

```bash
php artisan schedule:list
```

Output:
```
0 2 1 6,12 * .......... erosion:precompute-latest-year --type=all  Next Due: 3 months from now
* * * * * .............. php artisan schedule:run       Next Due: Immediately
```

### Check Last Execution

```bash
# Check Laravel logs for scheduler execution
grep "erosion:precompute-latest-year" storage/logs/laravel.log

# Check for success/failure logs
grep "Automated erosion" storage/logs/laravel.log
```

### Check Precomputation Status

```bash
php artisan tinker
>>> $year = date('Y');
>>> $total = \App\Models\PrecomputedErosionMap::where('year', $year)->count();
>>> $completed = \App\Models\PrecomputedErosionMap::where('year', $year)->where('status', 'completed')->count();
>>> echo "Year {$year}: {$completed}/{$total} completed";
```

## Troubleshooting

### Scheduler Not Running

**Problem:** Scheduled tasks not executing

**Solution:**
```bash
# Verify cron is running
sudo systemctl status cron

# Check crontab exists
sudo crontab -l -u www-data

# Check Laravel scheduler
php artisan schedule:list

# Test manually
php artisan schedule:run
```

### Command Fails

**Problem:** Command execution fails

**Solution:**
```bash
# Run manually to see error
php artisan erosion:precompute-latest-year

# Check logs
tail -50 storage/logs/laravel.log

# Verify Celery worker is running
sudo systemctl status rusle-celery-worker
```

### Jobs Not Processing

**Problem:** Jobs queued but not processing

**Solution:**
```bash
# Check Celery worker
sudo systemctl status rusle-celery-worker
sudo tail -f /var/log/rusle-celery-worker.log

# Restart if needed
sudo systemctl restart rusle-celery-worker

# Check Redis
redis-cli ping  # Should return PONG
```

## Testing Schedule

### Test Scheduled Task

```bash
# Modify schedule temporarily in routes/console.php
Schedule::command('erosion:precompute-latest-year --type=region')
    ->everyMinute()  // For testing
    ->timezone('UTC');

# Wait a minute and check logs
tail -f storage/logs/laravel.log

# Don't forget to change it back to the original schedule!
```

### Simulate Schedule Run

```bash
# Run scheduler immediately (won't wait for scheduled time)
php artisan schedule:run --verbose
```

## Notifications (Optional)

### Add Email Notifications

Update `routes/console.php`:

```php
Schedule::command('erosion:precompute-latest-year --type=all')
    ->cron('0 2 1 6,12 *')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->runInBackground()
    ->onSuccess(function () {
        \Log::info('Automated erosion precomputation completed successfully');
        // Mail::to('admin@example.com')->send(new PrecomputationComplete());
    })
    ->onFailure(function () {
        \Log::error('Automated erosion precomputation failed');
        // Mail::to('admin@example.com')->send(new PrecomputationFailed());
    });
```

## Alternative Schedules

### Monthly (1st of every month)

```php
->cron('0 2 1 * *')  // At 02:00 on day 1 of every month
```

### Quarterly (Jan, Apr, Jul, Oct)

```php
->cron('0 2 1 1,4,7,10 *')  // At 02:00 on day 1 of Q1,Q2,Q3,Q4
```

### Weekly (Every Monday)

```php
->weekly()->mondays()->at('02:00')
```

### Daily (for testing)

```php
->daily()->at('02:00')
```

## Production Checklist

- [x] Command created: `erosion:precompute-latest-year`
- [x] Schedule configured in `routes/console.php`
- [ ] System cron job added (`crontab -e -u www-data`)
- [ ] Scheduler verified (`php artisan schedule:list`)
- [ ] Manual test successful
- [ ] Celery worker running
- [ ] Logs monitored
- [ ] Documentation reviewed

## Summary

✅ **Command:** `php artisan erosion:precompute-latest-year`  
✅ **Schedule:** June 1st & December 1st at 2:00 AM UTC  
✅ **Automatic:** Once cron is set up  
✅ **Duration:** ~5-6 hours per execution  
✅ **Coverage:** All regions & districts for latest year  

---

**Last Updated:** 2025-11-01  
**Status:** Ready for Production







