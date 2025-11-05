#!/bin/bash
# Quick deployment script for Tile System
# Run as: sudo bash deploy-tile-system.sh

echo "========================================="
echo "Tile System - Quick Deployment"
echo "========================================="
echo ""

# Step 1: Run installation script
if [ -f "/var/www/rusle-icarda/install-tile-system.sh" ]; then
    echo "Running installation script..."
    bash /var/www/rusle-icarda/install-tile-system.sh
else
    echo "ERROR: install-tile-system.sh not found!"
    exit 1
fi

# Step 2: Test Celery
echo ""
echo "========================================="
echo "Testing Celery..."
echo "========================================="
cd /var/www/rusle-icarda/python-gee-service
source venv/bin/activate
python3 << 'EOF'
from tasks import test_task
result = test_task.delay(10, 5)
print(f"✓ Test task queued: {result.id}")
import time
time.sleep(3)
print(f"✓ Result: {result.get(timeout=10)}")
EOF

# Step 3: Display status
echo ""
echo "========================================="
echo "System Status"
echo "========================================="
systemctl status redis-server --no-pager | head -3
systemctl status python-gee-service --no-pager | head -3
systemctl status rusle-celery-worker --no-pager | head -3

echo ""
echo "========================================="
echo "Next Steps"
echo "========================================="
echo ""
echo "1. Start precomputation:"
echo "   cd /var/www/rusle-icarda"
echo "   php artisan erosion:precompute-all --years=2020,2020 --type=region"
echo ""
echo "2. Monitor progress:"
echo "   sudo tail -f /var/log/rusle-celery-worker.log"
echo ""
echo "3. Check completed maps:"
echo "   php artisan tinker --execute=\"echo 'Completed: ' . \App\Models\PrecomputedErosionMap::where('status', 'completed')->count();\"" 
echo ""
echo "✓ Deployment complete!"

