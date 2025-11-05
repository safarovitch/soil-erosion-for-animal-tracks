# Laravel Telescope & Pulse Installation Guide

## ✅ Installation Completed

Both **Laravel Telescope** (debugging tool) and **Laravel Pulse** (monitoring dashboard) have been successfully installed and configured.

---

## 🔭 Laravel Telescope

### Access URL
- **URL**: `http://37.27.195.104/telescope`
- **Redirect**: Automatically redirects to `/telescope/requests`

### Features
- HTTP requests monitoring
- Database queries with execution time
- Cache operations
- Redis commands
- Queued jobs (Celery tasks)
- Exceptions and logs
- Model events
- Gate checks
- Scheduled commands
- Notifications

### Access Control
- **Local/Development**: Open access (current environment: `local`)
- **Production/Staging**: Requires admin authentication

### Key Monitoring Pages
- `/telescope/requests` - All HTTP requests
- `/telescope/queries` - Database queries
- `/telescope/jobs` - Queued jobs (including Celery)
- `/telescope/exceptions` - Application errors
- `/telescope/cache` - Cache operations
- `/telescope/redis` - Redis commands

---

## 📊 Laravel Pulse

### Access URL
- **URL**: `http://37.27.195.104/pulse`
- **Dashboard**: `/pulse/dashboard`

### Features
- Real-time server metrics
- Request rate and throughput
- Slow queries detection
- Cache hit rate
- Queue performance
- Exceptions tracking
- Active users
- Database connections
- Memory usage

### Data Collection
Pulse collects data via scheduled command:
```bash
php artisan pulse:check
```

This runs **every 5 minutes** via Laravel scheduler (configured in `routes/console.php`).

### Manual Commands
```bash
# Check and aggregate metrics
php artisan pulse:check

# Clear old Pulse data
php artisan pulse:clear

# Purge all Pulse data
php artisan pulse:purge

# Restart Pulse workers
php artisan pulse:restart

# Run continuous Pulse worker (alternative to scheduler)
php artisan pulse:work
```

---

## 🔧 Configuration Files

### Telescope
- **Config**: `config/telescope.php`
- **Migrations**: Database table `telescope_entries`
- **Provider**: `App\Providers\AppServiceProvider::register()`

### Pulse
- **Config**: `config/pulse.php`
- **Migrations**: `pulse_*` database tables
- **Dashboard**: `resources/views/vendor/pulse/dashboard.blade.php`

---

## 📅 Scheduler Setup

Both tools require Laravel scheduler to be running via cron:

```bash
* * * * * cd /var/www/rusle-icarda && php artisan schedule:run >> /dev/null 2>&1
```

### Check if cron is configured:
```bash
sudo crontab -l | grep schedule:run
```

### Add to cron if missing:
```bash
sudo crontab -e
# Add this line:
* * * * * cd /var/www/rusle-icarda && php artisan schedule:run >> /dev/null 2>&1
```

---

## 🎯 Use Cases

### Use Telescope For:
1. **Debugging API requests** - See exactly what requests are being made to GEE
2. **Query optimization** - Identify slow database queries
3. **Job monitoring** - Track Celery job execution via Laravel
4. **Exception tracking** - Catch and debug errors in real-time
5. **Cache debugging** - See cache hits/misses

### Use Pulse For:
1. **Production monitoring** - Real-time dashboard of app health
2. **Performance tracking** - Identify slow requests and queries
3. **Queue health** - Monitor Celery job throughput
4. **Resource usage** - Track memory and database connections
5. **User activity** - See active users and their actions

---

## 🚀 Quick Start

### View Recent API Requests (Telescope)
1. Visit: `http://37.27.195.104/telescope/requests`
2. Filter by `/api/erosion` to see RUSLE requests
3. Click any request to see full details including database queries

### Monitor Server Health (Pulse)
1. Visit: `http://37.27.195.104/pulse`
2. View real-time metrics dashboard
3. Check "Slow Queries" and "Exceptions" cards

### Monitor Celery Jobs
1. Telescope: `/telescope/jobs` - See queued Laravel jobs
2. Pulse: View queue performance metrics

---

## 🔐 Security Notes

- Telescope is **disabled in production** by default via `AppServiceProvider`
- Pulse requires **admin authentication** in production
- Both tools store sensitive data - ensure database is secured
- Consider adding IP whitelisting for additional security

---

## 📦 Database Tables

### Telescope Tables
- `telescope_entries` - All recorded events
- `telescope_entries_tags` - Entry tags for filtering
- `telescope_monitoring` - System monitoring data

### Pulse Tables
- `pulse_aggregates` - Aggregated metrics
- `pulse_entries` - Raw event data
- `pulse_values` - Metric values

---

## 🧹 Maintenance

### Clear old Telescope data:
```bash
php artisan telescope:clear
```

### Clear old Pulse data:
```bash
php artisan pulse:clear
```

### Prune Telescope entries older than 24 hours (add to scheduler):
```php
Schedule::command('telescope:prune --hours=24')->daily();
```

---

## 📝 Environment Configuration

Current environment: **local**

Set in `.env`:
```env
APP_ENV=local
APP_DEBUG=true
```

For production:
```env
APP_ENV=production
APP_DEBUG=false
```

---

## 🎉 Installation Summary

✅ Laravel Telescope v5.15.0 - Installed  
✅ Laravel Pulse v1.4.3 - Installed  
✅ Migrations run successfully  
✅ Routes configured  
✅ Access controls configured  
✅ Scheduler jobs added  
✅ Both tools accessible and working  

**Next Steps:**
1. Configure cron for `schedule:run`
2. Visit `/telescope` and `/pulse` to explore
3. Start monitoring your application!

