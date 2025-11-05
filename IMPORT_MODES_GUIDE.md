# Geometry Import Modes Guide

The `import-ubz-geometries.php` script now supports three different import modes to handle existing geometries.

## Import Modes

### 1. **Update Mode (Default)** - Overwrites existing geometries
```bash
php import-ubz-geometries.php update
# or simply:
php import-ubz-geometries.php
```

**Behavior:**
- ✅ Replaces ALL existing geometries with new ones from GeoJSON
- ✅ Updates districts that already have geometries
- ✅ Imports new geometries for districts without geometries
- ⚠️ **Warning:** This will overwrite your current geometries

**Use when:** You want to completely replace existing geometries with the new shapefile data.

---

### 2. **Skip Existing Mode** - Only imports missing geometries
```bash
php import-ubz-geometries.php skip-existing
```

**Behavior:**
- ✅ Only imports geometries for districts that don't have one yet
- ✅ Skips districts that already have geometries (preserves existing)
- ✅ Useful for adding missing geometries without touching existing ones

**Use when:** You want to fill in missing geometries without changing existing ones.

---

### 3. **Backup Mode** - Creates backup before updating
```bash
php import-ubz-geometries.php backup
```

**Behavior:**
- ✅ Creates a backup file with all existing geometries before updating
- ✅ Updates all geometries (same as update mode)
- ✅ Backup saved to: `storage/app/public/geometry-backup-YYYY-MM-DD-HHMMSS.json`
- ✅ You can restore from backup if needed

**Use when:** You want to update geometries but want a safety net to restore if something goes wrong.

---

## Current Database Status

Your database currently has:
- **58 districts** with geometries
- **5 regions** (geometries generated from districts)

## Recommendation

Since you already have geometries for all districts, I recommend:

1. **First time importing new shapefile:**
   ```bash
   php import-ubz-geometries.php backup
   ```
   This creates a backup and updates all geometries.

2. **If you only want to add missing geometries:**
   ```bash
   php import-ubz-geometries.php skip-existing
   ```
   This won't touch your existing 58 geometries.

3. **To completely replace with new data:**
   ```bash
   php import-ubz-geometries.php update
   ```
   This overwrites all existing geometries.

## Restoring from Backup

If you need to restore geometries from a backup:

```php
<?php
// restore-geometries.php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\District;

$backupFile = __DIR__ . '/storage/app/public/geometry-backup-YYYY-MM-DD-HHMMSS.json';
$backup = json_decode(file_get_contents($backupFile), true);

foreach ($backup as $item) {
    $district = District::find($item['id']);
    if ($district) {
        $district->geometry = $item['geometry'];
        $district->save();
        echo "Restored: {$item['name']}" . PHP_EOL;
    }
}
```

## Summary

- **Default behavior:** Overwrites existing geometries ✅
- **Skip mode:** Preserves existing, only adds missing ✅
- **Backup mode:** Creates backup, then updates ✅

Choose the mode based on whether you want to:
- Replace everything → `update` or `backup`
- Keep existing → `skip-existing`
- Safe replacement → `backup`


