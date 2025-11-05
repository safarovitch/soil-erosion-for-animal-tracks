# Scheduler Quick Reference

## ⚡ Quick Setup (One Command)

```bash
sudo /var/www/rusle-icarda/setup-scheduler.sh
```

This automatically:
- Sets up cron job for www-data user
- Creates log file
- Verifies installation
- Shows next scheduled run

## 📅 Schedule Overview

**Automatic precomputation runs:**
- **June 1st at 2:00 AM UTC** ← Mid-year update
- **December 1st at 2:00 AM UTC** ← End-of-year update

**What it computes:**
- All regions (~31)
- All districts (~30)
- Current year only
- Takes ~5-6 hours

## 🎯 Common Commands

### View Scheduled Tasks
```bash
php artisan schedule:list
```

### Run Manually (Current Year)
```bash
php artisan erosion:precompute-latest-year
```

### Run for Specific Year
```bash
php artisan erosion:precompute-latest-year --year=2024
```

### Run Only Regions or Districts
```bash
php artisan erosion:precompute-latest-year --type=region
php artisan erosion:precompute-latest-year --type=district
```

### Force Recompute
```bash
php artisan erosion:precompute-latest-year --force
```

## 📊 Monitoring

### Check Scheduler Logs
```bash
tail -f /var/log/laravel-scheduler.log
```

### Check Laravel Logs
```bash
tail -f storage/logs/laravel.log | grep erosion
```

### Check Celery Worker
```bash
sudo tail -f /var/log/rusle-celery-worker.log
```

### Check Completion Status
```bash
php artisan tinker --execute="
\$year = date('Y');
echo 'Year ' . \$year . ': ';
echo \App\Models\PrecomputedErosionMap::where('year', \$year)->where('status', 'completed')->count();
echo ' / ';
echo \App\Models\PrecomputedErosionMap::where('year', \$year)->count();
echo ' completed';
"
```

## 🔧 Troubleshooting

### Check Cron is Installed
```bash
sudo crontab -l -u www-data | grep schedule:run
```

### Manually Trigger Scheduler (for testing)
```bash
php artisan schedule:run --verbose
```

### Check Services
```bash
sudo systemctl status cron
sudo systemctl status rusle-celery-worker
redis-cli ping
```

## 📝 Files

- **Command**: `app/Console/Commands/PrecomputeLatestYear.php`
- **Schedule**: `routes/console.php`
- **Setup Script**: `setup-scheduler.sh`
- **Documentation**: `AUTOMATED_PRECOMPUTATION_SETUP.md`

## ✅ Verification Checklist

```bash
# 1. Check scheduler is configured
php artisan schedule:list

# 2. Check cron is installed
sudo crontab -l -u www-data

# 3. Test command works
php artisan erosion:precompute-latest-year --year=2024 --type=region

# 4. Check Celery worker is running
sudo systemctl status rusle-celery-worker
```

---

**Status**: ✅ Configured and ready  
**Next Run**: June 1st or December 1st at 2:00 AM UTC







