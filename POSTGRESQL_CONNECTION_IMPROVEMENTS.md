# PostgreSQL Connection Management Improvements

## Problem
Connection exhaustion errors occurring frequently:
```
SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: 
FATAL: remaining connection slots are reserved for roles with the SUPERUSER attribute
```

## Root Causes Identified

1. **Too Many PHP Processes**: 196 PHP processes (PHP-FPM workers + artisan commands)
2. **Low max_connections**: Only 100 connections (97 available after superuser reservation)
3. **Connection Pool**: Each PHP-FPM worker can hold a database connection
4. **Rapid Connection Recreation**: Connections being recreated immediately after cleanup

## Solutions Implemented

### 1. Increased PostgreSQL max_connections ✅
- **Before**: 100 connections (97 available)
- **After**: 200 connections (197 available)
- **Command**: `ALTER SYSTEM SET max_connections = 200;`
- **Restart**: PostgreSQL service restarted to apply changes

### 2. More Aggressive Cleanup Script ✅
**File**: `scripts/cleanup-idle-connections.sh`

**Changes**:
- **Frequency**: Changed from every 5 minutes to **every 1 minute**
- **Timeout**: Changed from 1 minute to **30 seconds** for idle connections
- **Threshold**: Lowered from 10 to **5 idle connections** triggers aggressive cleanup
- **Behavior**: If more than 5 idle connections exist, ALL idle connections are killed immediately

**Cron Schedule**:
```cron
*/1 * * * * /var/www/rusle-icarda/scripts/cleanup-idle-connections.sh
```

### 3. Enhanced Database Configuration ✅
**File**: `config/database.php`

**Added**:
- `PDO::ATTR_AUTOCOMMIT => true` - Ensures connections commit properly
- `'sticky' => false` - Prevents connection reuse across requests

### 4. Connection Monitoring ✅
- Cleanup script logs all cleanup activity
- Log file: `/var/log/postgres-connection-cleanup.log`
- Tracks before/after connection counts

## Current Configuration

### PostgreSQL Settings
- `max_connections`: 200
- `superuser_reserved_connections`: 3
- **Available for application**: 197 connections

### Cleanup Script Settings
- **Run frequency**: Every 1 minute
- **Idle timeout**: 30 seconds
- **Aggressive threshold**: 5 idle connections
- **Action**: Kill all idle connections if threshold exceeded

### Laravel Database Settings
- `PDO::ATTR_PERSISTENT`: false (no persistent connections)
- `PDO::ATTR_TIMEOUT`: 5 seconds
- `sticky`: false (no connection reuse)

## Monitoring

### Check Current Connections
```bash
sudo -u postgres psql -c "
SELECT count(*) as total, 
       count(*) FILTER (WHERE state = 'idle') as idle,
       count(*) FILTER (WHERE state = 'active') as active
FROM pg_stat_activity 
WHERE datname = 'rusle_icarda' AND usename = 'rusle_user';
"
```

### View Cleanup Logs
```bash
tail -f /var/log/postgres-connection-cleanup.log
```

### Check Cron Job
```bash
crontab -l | grep cleanup-idle-connections
```

## Expected Behavior

1. **Normal Operation**: 1-5 idle connections (acceptable)
2. **Threshold Triggered**: If >5 idle connections, all idle connections killed immediately
3. **Cleanup Frequency**: Runs every minute automatically
4. **Connection Limit**: Now 197 available connections (vs 97 before)

## If Issues Persist

### Option 1: Further Increase max_connections
```sql
ALTER SYSTEM SET max_connections = 300;
-- Then restart PostgreSQL
sudo systemctl restart postgresql
```

### Option 2: Reduce PHP-FPM Workers
Edit `/etc/php/8.3/fpm/pool.d/www.conf`:
```ini
pm.max_children = 50  # Reduce from current value
```

### Option 3: Add Connection Pooling (PgBouncer)
Install PgBouncer to pool connections:
```bash
sudo apt install pgbouncer
# Configure to pool connections from PHP-FPM
```

### Option 4: More Aggressive Cleanup
Edit `scripts/cleanup-idle-connections.sh`:
- Change `AGGRESSIVE_THRESHOLD=3` (even lower)
- Change `IDLE_TIMEOUT_SECONDS=15` (even shorter)

## Testing

### Test Cleanup Script
```bash
/var/www/rusle-icarda/scripts/cleanup-idle-connections.sh
```

### Monitor Connection Count
```bash
watch -n 5 'sudo -u postgres psql -c "SELECT count(*) FROM pg_stat_activity WHERE datname = '\''rusle_icarda'\'' AND usename = '\''rusle_user'\'' AND state = '\''idle'\'';"'
```

### Test Application
```bash
php artisan tinker --execute="echo \App\Models\Region::count();"
```

## Files Modified

1. `scripts/cleanup-idle-connections.sh` - More aggressive cleanup
2. `config/database.php` - Enhanced connection settings
3. PostgreSQL `postgresql.conf` - Increased max_connections to 200
4. Cron job - Changed to run every 1 minute

## Date
November 5, 2025

