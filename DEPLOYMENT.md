# RUSLE-ICARDA Deployment Summary

## Deployment Date
October 17, 2025

## Server Details
- **Server IP**: 37.27.195.104
- **Web Server**: Nginx on port 80
- **PHP Version**: 8.3 (PHP-FPM)
- **Application URL**: http://37.27.195.104

## Completed Steps

### 1. Dependencies Installation
- ✅ Composer dependencies installed (production optimized)
- ✅ NPM dependencies installed
- ✅ Frontend assets built with Vite

### 2. Laravel Configuration
- ✅ Application key generated
- ✅ Storage linked to public directory
- ✅ Database migrations executed successfully
- ✅ Database seeded with:
  - Admin user (admin@soil-erosion.tj / admin123)
  - Regular user (user@soil-erosion.tj / user123)
  - 5 Tajikistan regions
  - 8 districts
- ✅ Configuration cached
- ✅ Routes cached
- ✅ Views cached

### 3. File Permissions
- ✅ Ownership set to www-data:www-data
- ✅ Storage directory permissions: 775
- ✅ Bootstrap cache permissions: 775
- ✅ Public directory permissions: 755

### 4. Nginx Configuration
- ✅ Created site configuration: /etc/nginx/sites-available/rusle-icarda
- ✅ Enabled site in sites-enabled
- ✅ Disabled default site
- ✅ PHP-FPM socket: /var/run/php/php8.3-fpm.sock
- ✅ Client max body size: 100MB (for GeoTIFF uploads)

### 5. Services
- ✅ Nginx running and listening on port 80 (IPv4 and IPv6)
- ✅ PHP 8.3-FPM running
- ✅ PostgreSQL database connected

## Application Status
✅ **LIVE** - Application is accessible at http://37.27.195.104

### Verified Features
- Main map interface loading correctly
- Inertia.js + Vue 3 working
- All 5 Tajikistan regions loaded
- All 8 districts loaded with geometry data
- Static assets served correctly from build directory

## Admin Access
- **Email**: admin@soil-erosion.tj
- **Password**: admin123
- **Note**: Change password in production!

## Next Steps
1. Configure Google Earth Engine credentials (if not done)
2. Set up SSL certificate for HTTPS
3. Configure firewall rules if needed
4. Set up automated backups for PostgreSQL database
5. Configure monitoring and logging
6. Update admin password

## Configuration Files
- Nginx config: `/etc/nginx/sites-available/rusle-icarda`
- Environment: `/var/www/rusle-icarda/.env`
- Application root: `/var/www/rusle-icarda`
- Public directory: `/var/www/rusle-icarda/public`

## Ports
- HTTP: 80 (Nginx)
- PostgreSQL: 5432 (localhost only)
- PHP-FPM: Unix socket

## Logs
- Nginx access: `/var/log/nginx/access.log`
- Nginx error: `/var/log/nginx/error.log`
- Laravel: `/var/www/rusle-icarda/storage/logs/laravel.log`
- PHP-FPM: `/var/log/php8.3-fpm.log`
