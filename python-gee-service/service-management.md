# Check status
sudo systemctl status python-gee-service

# View logs (live)
sudo tail -f /var/log/python-gee-service.log

# View access logs
sudo tail -f /var/log/python-gee-service-access.log

# View error logs
sudo tail -f /var/log/python-gee-service-error.log

# Restart service
sudo systemctl restart python-gee-service

# Stop service
sudo systemctl stop python-gee-service

# Start service
sudo systemctl start python-gee-service

# Disable auto-start on boot
sudo systemctl disable python-gee-service

# Enable auto-start on boot
sudo systemctl enable python-gee-service