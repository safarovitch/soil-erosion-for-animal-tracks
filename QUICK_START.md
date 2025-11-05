# 🚀 QUICK START - Tile System Deployment

## ⚡ Super Fast Setup (15 minutes)

```bash
cd /var/www/rusle-icarda
sudo ./deploy-tile-system.sh
```

That's it! The script will:
- ✅ Install Redis & GDAL
- ✅ Install Python dependencies  
- ✅ Setup Celery worker
- ✅ Create storage directories
- ✅ Run migrations
- ✅ Start all services
- ✅ Run test task

## 📋 Verify Installation

```bash
# All services running?
sudo systemctl status redis-server python-gee-service rusle-celery-worker

# Test a single region
php artisan tinker
>>> \$service = new \App\Services\ErosionTileService();
>>> \$result = \$service->getOrQueueMap('region', 1, 2020);
>>> print_r(\$result);
```

## 🎯 Start Precomputation

```bash
# Test with one year first
php artisan erosion:precompute-all --years=2020,2020 --type=region

# Then run full precomputation (48+ hours)
php artisan erosion:precompute-all --years=2015,2024 --type=all
```

## 👀 Monitor Progress

```bash
# Watch Celery worker logs
sudo tail -f /var/log/rusle-celery-worker.log

# Check completion count
php artisan tinker --execute="
echo 'Completed: ' . \App\Models\PrecomputedErosionMap::where('status', 'completed')->count() . PHP_EOL;
"
```

## 📚 Full Documentation

- `IMPLEMENTATION_COMPLETE.md` - Complete implementation details
- `TILE_SYSTEM_COMPLETION_GUIDE.md` - Step-by-step guide
- `TILE_SYSTEM_PROGRESS.md` - Progress tracking

## 🆘 Quick Troubleshooting

**Service not starting?**
```bash
sudo systemctl restart rusle-celery-worker
sudo journalctl -u rusle-celery-worker -n 50
```

**Tasks not processing?**
```bash
redis-cli ping  # Should return PONG
ps aux | grep celery  # Should show worker process
```

**Need help?**
Check logs: `/var/log/rusle-celery-worker.log`

---

**Status**: ✅ READY TO DEPLOY  
**Time to deploy**: ~15 min  
**Time to precompute**: ~48 hours  
**Performance gain**: 60-70% faster (10 min → <1 sec)

