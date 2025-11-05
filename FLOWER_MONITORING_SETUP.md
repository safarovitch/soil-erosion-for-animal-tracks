# Flower - Celery Monitoring Dashboard

## ✅ Installation Complete

**Flower** has been successfully installed and configured to monitor your Celery workers and tasks.

---

## 🔗 Access URLs

### Web Interface (via Nginx)
- **URL**: `http://37.27.195.104/flower`
- **Direct Port**: `http://37.27.195.104:5555` (if firewall allows)

---

## 📊 Features

Flower provides real-time monitoring for:

### 1. **Workers Dashboard** (`/flower/workers`)
- ✅ Active worker nodes
- ✅ Worker status (online/offline)
- ✅ CPU and memory usage per worker
- ✅ Task pool information
- ✅ Worker uptime and statistics

### 2. **Tasks Dashboard** (`/flower/tasks`)
- ✅ **Active tasks** - Currently running erosion map generation
- ✅ **Scheduled tasks** - Queued jobs waiting to execute
- ✅ **Task history** - Completed and failed tasks
- ✅ **Task details** - Arguments, results, execution time, tracebacks
- ✅ **Task filtering** - By name, worker, state, date range

### 3. **Broker Dashboard** (`/flower/broker`)
- ✅ Redis queue statistics
- ✅ Message counts per queue
- ✅ Broker connection status

### 4. **Monitoring** (`/flower/monitor`)
- ✅ Task rate (tasks per second)
- ✅ Success/failure rates
- ✅ Worker load averages
- ✅ Real-time graphs

---

## 🎯 Key Monitoring Pages

| Page | URL | Purpose |
|------|-----|---------|
| **Workers** | `/flower/workers` | View all Celery workers and their status |
| **Tasks** | `/flower/tasks` | Monitor active, scheduled, and completed tasks |
| **Broker** | `/flower/broker` | Redis queue statistics |
| **Monitor** | `/flower/monitor` | Real-time performance graphs |

---

## 📈 What You Can Monitor

### Current Precomputation Jobs
1. Visit `/flower/tasks`
2. Filter by task name: `generate_erosion_map`
3. See all active precomputation jobs in real-time

### Worker Health
1. Visit `/flower/workers`
2. Check CPU/memory usage
3. See how many tasks each worker is processing

### Task Progress
1. Click any task in `/flower/tasks`
2. View full task details:
   - Arguments (area_type, area_id, year, geometry)
   - Execution time
   - Current step
   - Progress percentage
   - Error messages (if failed)

### Queue Status
1. Visit `/flower/broker`
2. See how many jobs are queued
3. Monitor Redis connection health

---

## 🚀 Service Management

### Start/Stop/Restart Flower
```bash
sudo systemctl start rusle-flower
sudo systemctl stop rusle-flower
sudo systemctl restart rusle-flower
sudo systemctl status rusle-flower
```

### Check Flower Logs
```bash
sudo journalctl -u rusle-flower -f
```

### Service Status
Flower is configured to:
- ✅ Auto-start on system reboot
- ✅ Restart automatically if it crashes
- ✅ Run on port 5555
- ✅ Connect to Redis broker

---

## ⚙️ Configuration

### Service File
- **Location**: `/etc/systemd/system/rusle-flower.service`
- **Port**: `5555`
- **Broker**: `redis://localhost:6379/0`

### Nginx Proxy
- **Path**: `/flower`
- **Proxy**: `http://127.0.0.1:5555`
- **WebSocket**: Enabled for real-time updates

---

## 🔍 Example Use Cases

### Monitor Active Precomputation Tasks
```
1. Visit: http://37.27.195.104/flower/tasks
2. Filter: State = "STARTED" or "RECEIVED"
3. See all currently processing erosion maps
4. Click any task to see full details
```

### Check Worker Performance
```
1. Visit: http://37.27.195.104/flower/workers
2. View CPU and memory for each worker
3. See active task count per worker
4. Identify if workers are overloaded
```

### Debug Failed Tasks
```
1. Visit: http://37.27.195.104/flower/tasks
2. Filter: State = "FAILURE"
3. Click failed task to see error traceback
4. View task arguments and execution time
```

### Monitor Queue Depth
```
1. Visit: http://37.27.195.104/flower/broker
2. See "celery" queue message count
3. Monitor if queue is backing up
```

---

## 📊 Real-Time Updates

Flower uses **WebSockets** for real-time updates:
- Task status updates automatically
- No need to refresh the page
- Worker status changes instantly
- Queue counts update in real-time

---

## 🛠️ Advanced Features

### Task Filtering
- Filter by task name: `generate_erosion_map`
- Filter by worker: `celery@ubuntu-4gb-hel1-1-RUSLE`
- Filter by state: STARTED, SUCCESS, FAILURE, PENDING
- Filter by date range

### Task Actions
- **Revoke** - Cancel a pending/running task
- **Terminate** - Force kill a running task
- **Retry** - Re-run a failed task
- **View Traceback** - See full error details

### Export Data
- Export task list as JSON
- Export worker statistics
- Download task results

---

## 🔐 Security Note

**Current Setup**: Flower is accessible without authentication.

For production, consider adding:
1. **Basic Auth** in nginx:
```nginx
auth_basic "Flower Admin";
auth_basic_user_file /etc/nginx/.htpasswd;
```

2. **Flower Authentication**:
```bash
celery -A celery_app flower --basic_auth=admin:password
```

3. **IP Whitelist** in nginx:
```nginx
allow 192.168.1.0/24;
deny all;
```

---

## 📝 Troubleshooting

### Flower Not Starting
```bash
# Check service status
sudo systemctl status rusle-flower

# Check logs
sudo journalctl -u rusle-flower -n 50

# Verify Celery connection
cd /var/www/rusle-icarda/python-gee-service
source venv/bin/activate
celery -A celery_app inspect ping
```

### Can't Access via Web
```bash
# Check if Flower is running on port 5555
sudo netstat -tlnp | grep 5555

# Test direct access
curl http://localhost:5555

# Check nginx config
sudo nginx -t
sudo systemctl status nginx
```

### No Tasks Showing
- Ensure Celery workers are running: `sudo systemctl status rusle-celery-worker`
- Check Redis connection: `redis-cli ping`
- Verify broker URL in Flower service file

---

## 🎉 Quick Start

1. **Access Flower**: `http://37.27.195.104/flower`
2. **View Workers**: Click "Workers" in navbar
3. **View Tasks**: Click "Tasks" in navbar
4. **Monitor Precomputation**: Filter tasks by `generate_erosion_map`

---

## 📚 Summary

✅ **Flower v2.0.1** - Installed  
✅ **Service Created** - `/etc/systemd/system/rusle-flower.service`  
✅ **Auto-start Enabled** - Starts on boot  
✅ **Nginx Proxy** - Accessible at `/flower`  
✅ **WebSocket Support** - Real-time updates enabled  
✅ **Monitoring Active** - Ready to use!  

**Next Steps:**
1. Visit `http://37.27.195.104/flower`
2. Explore Workers, Tasks, and Broker dashboards
3. Monitor your Celery precomputation jobs in real-time!



