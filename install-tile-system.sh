#!/bin/bash
# Installation script for Precomputed Tile-Based Erosion Maps
# Run as: sudo bash install-tile-system.sh

set -e  # Exit on error

echo "========================================="
echo "Installing Tile-Based Erosion Map System"
echo "========================================="

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}Please run as root (use sudo)${NC}"
    exit 1
fi

echo -e "${YELLOW}Step 1: Installing Redis${NC}"
apt update
apt install -y redis-server
systemctl enable redis-server
systemctl start redis-server
echo -e "${GREEN}✓ Redis installed${NC}"

echo -e "${YELLOW}Step 2: Installing GDAL${NC}"
apt install -y gdal-bin python3-gdal libgdal-dev
echo -e "${GREEN}✓ GDAL installed${NC}"

echo -e "${YELLOW}Step 3: Installing Python dependencies${NC}"
cd /var/www/rusle-icarda/python-gee-service
source venv/bin/activate
pip install --upgrade pip
pip install -r requirements.txt
echo -e "${GREEN}✓ Python dependencies installed${NC}"

echo -e "${YELLOW}Step 4: Creating storage directories${NC}"
mkdir -p /var/www/rusle-icarda/storage/rusle-tiles/geotiff
mkdir -p /var/www/rusle-icarda/storage/rusle-tiles/tiles
chown -R www-data:www-data /var/www/rusle-icarda/storage/rusle-tiles
chmod -R 775 /var/www/rusle-icarda/storage/rusle-tiles
echo -e "${GREEN}✓ Storage directories created${NC}"

echo -e "${YELLOW}Step 5: Setting up Celery worker service${NC}"
cat > /etc/systemd/system/rusle-celery-worker.service <<'EOF'
[Unit]
Description=RUSLE Celery Worker
After=network.target redis.service python-gee-service.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/rusle-icarda/python-gee-service
Environment="PATH=/var/www/rusle-icarda/python-gee-service/venv/bin"
ExecStart=/var/www/rusle-icarda/python-gee-service/venv/bin/celery -A celery_app worker --loglevel=info --concurrency=4 --logfile=/var/log/rusle-celery-worker.log
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable rusle-celery-worker
echo -e "${GREEN}✓ Celery service configured${NC}"

echo -e "${YELLOW}Step 6: Running Laravel migrations${NC}"
cd /var/www/rusle-icarda
sudo -u www-data php artisan migrate --force
echo -e "${GREEN}✓ Migrations completed${NC}"

echo -e "${YELLOW}Step 7: Starting services${NC}"
systemctl restart python-gee-service
systemctl start rusle-celery-worker
echo -e "${GREEN}✓ Services started${NC}"

echo ""
echo -e "${GREEN}========================================="
echo "Installation Complete!"
echo "=========================================${NC}"
echo ""
echo "Next steps:"
echo "1. Check Celery worker status:"
echo "   sudo systemctl status rusle-celery-worker"
echo ""
echo "2. Monitor Celery logs:"
echo "   sudo tail -f /var/log/rusle-celery-worker.log"
echo ""
echo "3. Test Celery:"
echo "   cd /var/www/rusle-icarda/python-gee-service"
echo "   source venv/bin/activate"
echo "   python3 -c \"from tasks import test_task; result = test_task.delay(5, 3); print(f'Task ID: {result.id}')\""
echo ""
echo "4. Start precomputation (this will take hours):"
echo "   cd /var/www/rusle-icarda"
echo "   php artisan erosion:precompute-all --years=2015,2024 --type=all"
echo ""







