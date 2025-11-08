#!/bin/bash
# Setup Laravel Scheduler for Automated Erosion Map Precomputation

echo "========================================="
echo "Laravel Scheduler Setup"
echo "========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "Please run as root (use sudo)"
    exit 1
fi

echo "This will set up automated precomputation of erosion maps"
echo "to run twice per year (June 1st and December 1st)."
echo ""

# Ask for confirmation
read -p "Continue? (y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Cancelled."
    exit 0
fi

echo ""
echo "Step 1: Adding cron job for www-data user..."

# Create cron entry
CRON_ENTRY="* * * * * cd /var/www/rusle-icarda && php artisan schedule:run >> /var/log/laravel-scheduler.log 2>&1"

# Check if cron entry already exists
if sudo crontab -l -u www-data 2>/dev/null | grep -q "schedule:run"; then
    echo "⚠️  Cron entry already exists. Skipping..."
else
    # Add cron entry
    (sudo crontab -l -u www-data 2>/dev/null; echo "$CRON_ENTRY") | sudo crontab -u www-data -
    echo "✓ Cron entry added"
fi

echo ""
echo "Step 2: Creating log file..."
sudo touch /var/log/laravel-scheduler.log
sudo chown www-data:www-data /var/log/laravel-scheduler.log
sudo chmod 664 /var/log/laravel-scheduler.log
echo "✓ Log file created"

echo ""
echo "Step 3: Verifying cron installation..."
if sudo crontab -l -u www-data | grep -q "schedule:run"; then
    echo "✓ Cron job installed successfully"
else
    echo "✗ Cron job installation failed"
    exit 1
fi

echo ""
echo "Step 4: Testing scheduler..."
cd /var/www/rusle-icarda
sudo -u www-data php artisan schedule:list

echo ""
echo "========================================="
echo "Setup Complete!"
echo "========================================="
echo ""
echo "Scheduler Configuration:"
echo "  • Runs: June 1st & December 1st at 2:00 AM UTC"
echo "  • Command: erosion:precompute-latest-year --type=all"
echo "  • Areas: All regions and districts"
echo "  • Duration: ~5-6 hours per execution"
echo ""
echo "View scheduled tasks:"
echo "  php artisan schedule:list"
echo ""
echo "Monitor scheduler logs:"
echo "  tail -f /var/log/laravel-scheduler.log"
echo ""
echo "Test manually:"
echo "  php artisan erosion:precompute-latest-year"
echo ""
echo "Next scheduled run:"
php artisan schedule:list | grep "erosion:precompute-latest-year"
echo ""












