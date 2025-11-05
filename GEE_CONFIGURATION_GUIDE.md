# Google Earth Engine Configuration Guide

## ⚠️ IMPORTANT
The RUSLE system now requires Google Earth Engine to be properly configured. All mock data has been removed to ensure 100% real data quality.

---

## Current Status

**GEE Configured**: ❌ NO  
**Error**: Google Earth Engine is not configured  
**Impact**: API returns 503 Service Unavailable for all erosion computations

---

## Quick Setup (5 Steps)

### Step 1: Create Google Cloud Project
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Note your **Project ID**

### Step 2: Enable Earth Engine API
1. Go to [APIs & Services](https://console.cloud.google.com/apis/library)
2. Search for "Earth Engine API"
3. Click "Enable"

### Step 3: Create Service Account
1. Go to [IAM & Admin > Service Accounts](https://console.cloud.google.com/iam-admin/serviceaccounts)
2. Click "Create Service Account"
3. Name: `rusle-app-service`
4. Description: `Service account for RUSLE erosion mapping`
5. Click "Create and Continue"
6. Grant role: **Earth Engine Resource Writer**
7. Click "Done"

### Step 4: Generate Private Key
1. Click on the created service account
2. Go to "Keys" tab
3. Click "Add Key" > "Create new key"
4. Select "JSON" format
5. Click "Create"
6. **Save the downloaded JSON file** - you'll need it!

### Step 5: Configure Laravel Application
```bash
# 1. Create GEE directory
mkdir -p /var/www/rusle-icarda/storage/gee
chmod 755 /var/www/rusle-icarda/storage/gee

# 2. Copy the JSON key file
cp ~/Downloads/your-project-xxxxx.json /var/www/rusle-icarda/storage/gee/private-key.json
chmod 600 /var/www/rusle-icarda/storage/gee/private-key.json

# 3. Update .env file
nano /var/www/rusle-icarda/.env
```

Add these lines to `.env`:
```env
# Google Earth Engine Configuration
GEE_SERVICE_ACCOUNT_EMAIL=rusle-app-service@your-project.iam.gserviceaccount.com
GEE_PRIVATE_KEY_PATH=gee/private-key.json
GEE_PROJECT_ID=your-project-id
```

**Important**: Replace with YOUR actual values from the JSON key file:
- `GEE_SERVICE_ACCOUNT_EMAIL`: Look for `client_email` in JSON
- `GEE_PROJECT_ID`: Look for `project_id` in JSON

### Step 6: Test Configuration
```bash
# Clear caches
cd /var/www/rusle-icarda
php artisan config:clear
php artisan cache:clear

# Test GEE availability
php artisan tinker
```

In tinker:
```php
$service = app(App\Services\GoogleEarthEngineService::class);
var_dump($service->isAvailable()); // Should return: bool(true)
exit
```

---

## Verification

### Test API Endpoint:
```bash
curl -X POST http://your-domain/api/erosion/compute \
  -H "Content-Type: application/json" \
  -H "X-CSRF-TOKEN: your-csrf-token" \
  -d '{
    "area_type": "district",
    "area_id": 1,
    "year": 2024,
    "period": "annual"
  }'
```

**Expected Result**:
```json
{
  "success": true,
  "data": {
    "statistics": {
      "mean_erosion_rate": 15.2,
      "severity_distribution": [...],
      "rusle_factors": {...}
    }
  }
}
```

**If GEE Not Configured**:
```json
{
  "success": false,
  "error": "Google Earth Engine is not configured.",
  "details": "Contact administrator..."
}
```

---

## Troubleshooting

### Error: "GEE private key file not found"
**Solution**:
```bash
# Check if file exists
ls -la /var/www/rusle-icarda/storage/gee/private-key.json

# Check permissions
chmod 600 /var/www/rusle-icarda/storage/gee/private-key.json
chown www-data:www-data /var/www/rusle-icarda/storage/gee/private-key.json
```

### Error: "Failed to authenticate with Google Earth Engine"
**Possible Causes**:
1. **Service Account Email Wrong**: Check `client_email` in JSON key
2. **Project ID Wrong**: Check `project_id` in JSON key
3. **Key Expired**: Generate new key
4. **API Not Enabled**: Enable Earth Engine API in Cloud Console
5. **Network Issue**: Check internet connectivity

**Check Logs**:
```bash
tail -f /var/www/rusle-icarda/storage/logs/laravel.log
```

### Error: "Earth Engine Resource Writer role missing"
**Solution**:
1. Go to [IAM & Admin > IAM](https://console.cloud.google.com/iam-admin/iam)
2. Find your service account
3. Click "Edit principal"
4. Add role: "Earth Engine Resource Writer"
5. Save

### Error: "GEE computation failed"
**Check**:
1. GEE quota limits
2. Network connectivity
3. Dataset availability (CHIRPS, SoilGrids, etc.)
4. Geometry validity

---

## Security Best Practices

### 1. Protect Private Key
```bash
# Never commit to git
echo "storage/gee/*.json" >> .gitignore

# Restrict permissions
chmod 600 storage/gee/private-key.json

# Owned by web server user
chown www-data:www-data storage/gee/private-key.json
```

### 2. Environment Variables
```bash
# Never commit .env
echo ".env" >> .gitignore

# Use Laravel encryption for sensitive data
php artisan config:cache  # In production
```

### 3. Rotate Keys Regularly
- Generate new service account keys every 90 days
- Delete old keys after rotation
- Test before deleting old key

---

## Performance Optimization

### 1. Enable Caching
```bash
# Install Redis (recommended)
sudo apt install redis-server
php artisan cache:clear
```

Update `.env`:
```env
CACHE_DRIVER=redis
```

### 2. Queue Jobs (Optional)
For long-running GEE computations:
```env
QUEUE_CONNECTION=database
```

Run queue worker:
```bash
php artisan queue:work
```

### 3. Monitor Usage
- Check [GEE usage dashboard](https://code.earthengine.google.com/)
- Monitor quota limits
- Set up alerts for quota exceeded

---

## Cost Considerations

### Google Earth Engine Pricing:
- **Free Tier**: 
  - Earth Engine API: Free (subject to usage limits)
  - Cloud Storage: First 5GB free
  
- **Paid Tier**:
  - Commercial use may require licensing
  - Contact Google for enterprise pricing

### Usage Optimization:
- ✅ **Caching**: 30-day cache reduces GEE calls by ~95%
- ✅ **Spatial Resolution**: 30m optimal (not 10m)
- ✅ **Date Ranges**: Annual (not daily) reduces data volume
- ✅ **Geometry Simplification**: Reduce complex polygons

---

## Data Sources Used

### All require GEE access:
1. **CHIRPS** (UCSB-CHG/CHIRPS/DAILY)
   - Precipitation data
   - 1981-present
   - 0.05° resolution

2. **SoilGrids** (projects/soilgrids-isric)
   - Soil properties
   - Global coverage
   - 250m resolution

3. **SRTM** (USGS/SRTMGL1_003)
   - Elevation data
   - 30m resolution
   - Near-global coverage

4. **Sentinel-2** (COPERNICUS/S2_SR_HARMONIZED)
   - Optical imagery
   - 10m resolution
   - 2016-present

5. **ESA WorldCover** (ESA/WorldCover/v100/2020)
   - Land cover classification
   - 10m resolution
   - Year 2020

---

## Alternative: Demo Mode (Not Recommended)

If you absolutely cannot configure GEE, you would need to re-add mock data (which we just removed). This is **NOT RECOMMENDED** for production use.

---

## Support

### Need Help?
1. **GEE Forum**: https://groups.google.com/g/google-earth-engine-developers
2. **Laravel Docs**: https://laravel.com/docs
3. **Check Logs**: `storage/logs/laravel.log`
4. **Error 503**: GEE not configured
5. **Error 500**: GEE computation failed

### Contact:
- GEE Support: earthengine@google.com
- System Administrator: [Your contact]

---

## Quick Reference

### Files to Configure:
- `.env` - Add GEE_* variables
- `storage/gee/private-key.json` - Service account key

### Commands to Run:
```bash
# After configuration
php artisan config:clear
php artisan cache:clear

# Test
php artisan tinker
# Then: app(App\Services\GoogleEarthEngineService::class)->isAvailable()

# View logs
tail -f storage/logs/laravel.log
```

### Expected Behavior:
- ✅ **Configured**: API returns real GEE data
- ❌ **Not Configured**: API returns 503 with clear error message
- ⚠️ **Computation Fails**: API returns 500 with error details

---

**Status**: 🔴 GEE NOT CONFIGURED  
**Action Required**: Follow steps above to configure GEE credentials  
**Estimated Setup Time**: 15-20 minutes  
**Difficulty**: Easy (just follow steps)

**Once configured, all features will work with 100% real satellite data! 🛰️🌍📊**












