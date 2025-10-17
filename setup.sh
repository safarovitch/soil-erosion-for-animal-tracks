#!/bin/bash

# Soil Erosion App Setup Script
# This script helps set up the development environment

echo "🌍 Setting up Soil Erosion Watch - Tajikistan"
echo "=============================================="

# Check if we're in the right directory
if [ ! -f "artisan" ]; then
    echo "❌ Error: Please run this script from the project root directory"
    exit 1
fi

# Check PHP version
echo "📋 Checking PHP version..."
php_version=$(php -r "echo PHP_VERSION;")
echo "PHP version: $php_version"

if ! php -r "exit(version_compare(PHP_VERSION, '8.2.0', '>=') ? 0 : 1);"; then
    echo "❌ Error: PHP 8.2 or higher is required"
    exit 1
fi

# Check if Composer is installed
echo "📋 Checking Composer..."
if ! command -v composer &> /dev/null; then
    echo "❌ Error: Composer is not installed"
    echo "Please install Composer: https://getcomposer.org/download/"
    exit 1
fi

# Check if Node.js is installed
echo "📋 Checking Node.js..."
if ! command -v node &> /dev/null; then
    echo "❌ Error: Node.js is not installed"
    echo "Please install Node.js: https://nodejs.org/"
    exit 1
fi

# Check if PostgreSQL is installed
echo "📋 Checking PostgreSQL..."
if ! command -v psql &> /dev/null; then
    echo "❌ Error: PostgreSQL is not installed"
    echo "Please install PostgreSQL with PostGIS extension"
    exit 1
fi

# Check if GDAL is installed
echo "📋 Checking GDAL..."
if ! command -v gdalinfo &> /dev/null; then
    echo "⚠️  Warning: GDAL is not installed"
    echo "Please install GDAL for GeoTIFF processing:"
    echo "  macOS: brew install gdal"
    echo "  Ubuntu: sudo apt-get install gdal-bin"
    echo "  Windows: Download from OSGeo4W"
fi

# Install PHP dependencies
echo "📦 Installing PHP dependencies..."
composer install

# Install JavaScript dependencies
echo "📦 Installing JavaScript dependencies..."
npm install

# Generate application key if not exists
if [ ! -f ".env" ]; then
    echo "📝 Creating .env file..."
    cp .env.example .env
    php artisan key:generate
    echo "✅ .env file created. Please configure your database and GEE credentials."
else
    echo "✅ .env file already exists"
fi

# Create storage directories
echo "📁 Creating storage directories..."
mkdir -p storage/app/geotiff/uploads
mkdir -p storage/app/geotiff/processed
mkdir -p storage/app/private/gee

# Set permissions
echo "🔐 Setting permissions..."
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# Build frontend assets
echo "🏗️  Building frontend assets..."
npm run build

echo ""
echo "✅ Setup completed!"
echo ""
echo "Next steps:"
echo "1. Configure your .env file with database and GEE credentials"
echo "2. Create PostgreSQL database with PostGIS:"
echo "   createdb soil_erosion_app"
echo "   psql soil_erosion_app -c \"CREATE EXTENSION postgis;\""
echo "3. Run migrations: php artisan migrate"
echo "4. Seed database: php artisan db:seed"
echo "5. Start development server: php artisan serve"
echo ""
echo "Default admin credentials:"
echo "  Email: admin@soil-erosion.tj"
echo "  Password: admin123"
echo ""
echo "For more information, see README.md"
