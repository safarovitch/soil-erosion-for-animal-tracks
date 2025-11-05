# Troubleshooting Python GEE Service Connection Issues

## Problem
Connection errors or timeouts when running `erosion:calculate` command:
```
Connection error: Cannot connect to Python GEE service at http://127.0.0.1:5000
Failed to compute factors: Computation timed out.
```

## Common Causes & Solutions

### 1. Service Not Running
**Symptoms**: Connection refused errors

**Check**:
```bash
sudo systemctl status python-gee-service
```

**Fix**:
```bash
sudo systemctl start python-gee-service
sudo systemctl enable python-gee-service  # Auto-start on boot
```

---

### 2. All Workers Busy
**Symptoms**: Service is running, but connection fails or times out

**Cause**: All 17 workers are processing long-running requests (600-second timeout each)

**Check Active Requests**:
```bash
# Count active workers
ps aux | grep gunicorn | wc -l

# Check service logs for active requests
sudo tail -f /var/log/python-gee-service-access.log
```

**Solutions**:
- **Wait**: If workers are busy, wait for them to complete (up to 10 minutes)
- **Restart Service**: If workers are stuck, restart the service:
  ```bash
  sudo systemctl restart python-gee-service
  ```
- **Increase Workers**: Edit `/etc/systemd/system/python-gee-service.service`:
  ```
  --workers 25  # Increase from 17
  ```
  Then restart:
  ```bash
  sudo systemctl daemon-reload
  sudo systemctl restart python-gee-service
  ```

---

### 3. Timeout Issues
**Symptoms**: "Computation timed out" errors

**Cause**: Large areas take longer than 600 seconds (10 minutes) to compute

**Solutions**:
- **Use Larger Scale**: Edit command to use larger scale (faster but less precise):
  - Currently uses 100m resolution
  - Can modify code to use 200m or 500m for faster computation
- **Compute Factors Separately**: Instead of all factors at once:
  ```bash
  php artisan erosion:calculate --district_id=106 --year=2024 --factors=r
  php artisan erosion:calculate --district_id=106 --year=2024 --factors=k
  # etc.
  ```
- **Increase Timeout**: Edit `python-gee-service/config.py`:
  ```python
  GEE_API_TIMEOUT = 900  # 15 minutes
  ```
  Then restart service.

---

### 4. Port/Connection Issues
**Symptoms**: Connection refused or connection timeout

**Check**:
```bash
# Test if service is listening
netstat -tlnp | grep :5000
# or
ss -tlnp | grep :5000

# Test health endpoint
curl http://127.0.0.1:5000/api/health
```

**Fix**:
- If port not listening, check service logs:
  ```bash
  sudo tail -50 /var/log/python-gee-service-error.log
  ```
- Verify service configuration in `/etc/systemd/system/python-gee-service.service`

---

### 5. Service Overloaded
**Symptoms**: Intermittent connection failures, timeouts

**Check Load**:
```bash
# Check system load
top
htop

# Check memory usage
free -h

# Check Python service memory
ps aux | grep gunicorn | awk '{sum+=$6} END {print sum/1024 " MB"}'
```

**Solutions**:
- **Reduce Workers**: If system is low on memory, reduce workers:
  ```
  --workers 10  # Reduce from 17
  ```
- **Add System Resources**: Increase server RAM/CPU
- **Optimize Computation**: Use larger scale values for faster computation

---

## Improved Error Handling

The command now includes:
- ✅ Health check before computation
- ✅ Connection timeout (10 seconds) separate from request timeout (600 seconds)
- ✅ Better error messages with troubleshooting steps
- ✅ Detailed logging for debugging

---

## Quick Diagnostic Commands

### Check Service Status
```bash
sudo systemctl status python-gee-service
```

### Test Service Endpoint
```bash
curl http://127.0.0.1:5000/api/health
```

### Check Service Logs
```bash
# Error log
sudo tail -f /var/log/python-gee-service-error.log

# Access log
sudo tail -f /var/log/python-gee-service-access.log
```

### Check Active Workers
```bash
ps aux | grep gunicorn | grep -v grep | wc -l
```

### Test Simple Request
```bash
curl -X POST http://127.0.0.1:5000/api/rusle/factors \
  -H "Content-Type: application/json" \
  -d '{"area_geometry":{"type":"Polygon","coordinates":[[[71.5,38.5],[71.6,38.5],[71.6,38.6],[71.5,38.6],[71.5,38.5]]]},"year":2024,"factors":"r","scale":500}'
```

---

## Prevention

### 1. Monitor Worker Usage
Set up monitoring to alert when workers are consistently busy:
```bash
# Create monitoring script
watch -n 30 'ps aux | grep gunicorn | wc -l'
```

### 2. Queue System
For large batch operations, use the precomputation system which queues tasks:
```bash
php artisan erosion:precompute-all --years=2024 --type=district
```
This queues tasks in Celery, preventing worker overload.

### 3. Rate Limiting
Avoid running multiple `erosion:calculate` commands simultaneously for large areas.

---

## When to Restart Service

Restart the service if:
- Workers appear stuck (same request for >15 minutes)
- Multiple connection errors occur
- Service becomes unresponsive
- After configuration changes

**Restart Command**:
```bash
sudo systemctl restart python-gee-service
```

---

## Expected Behavior

### Normal Operation
- Service responds to health checks immediately
- Small areas compute in <30 seconds
- Medium areas compute in 1-5 minutes
- Large areas compute in 5-10 minutes

### Warning Signs
- Health check takes >5 seconds
- Multiple timeout errors
- Workers consistently at 100% CPU
- Memory usage >90%

---

## Date
November 5, 2025

