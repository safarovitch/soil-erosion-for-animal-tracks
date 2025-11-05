#!/usr/bin/env python3
"""
Convert existing .npy files to proper GeoTIFF files
Fixes the broken erosion.tif files that contain text instead of raster data
"""

import pickle
import sys
from pathlib import Path
import numpy as np
import rasterio
from rasterio.transform import from_bounds

def numpy_to_geotiff(data, bounds, output_path):
    """
    Convert numpy array to proper GeoTIFF using rasterio
    
    Args:
        data: numpy array (height, width)
        bounds: [min_lon, min_lat, max_lon, max_lat]
        output_path: Path for output file
    """
    try:
        height, width = data.shape
        min_lon, min_lat, max_lon, max_lat = bounds
        
        # Create affine transform
        transform = from_bounds(min_lon, min_lat, max_lon, max_lat, width, height)
        
        # Write GeoTIFF
        with rasterio.open(
            str(output_path),
            'w',
            driver='GTiff',
            height=height,
            width=width,
            count=1,
            dtype=data.dtype,
            crs='EPSG:4326',
            transform=transform,
            compress='lzw'
        ) as dst:
            dst.write(data, 1)
        
        return True
        
    except Exception as e:
        print(f"ERROR creating GeoTIFF: {str(e)}")
        import traceback
        traceback.print_exc()
        return False

def convert_npy_file(npy_path):
    """Convert a single .npy file to GeoTIFF"""
    try:
        # Load the .npy file
        with open(npy_path, 'rb') as f:
            data_dict = pickle.load(f)
        
        data = data_dict['data']
        bounds = data_dict['bounds']
        
        # Get corresponding .tif path
        tif_path = npy_path.with_suffix('.tif')
        
        # Check if .tif exists and is broken
        if tif_path.exists():
            # Check if it's the broken text file
            try:
                with open(tif_path, 'r') as f:
                    content = f.read()
                    if 'Sampled raster' in content:
                        print(f"  → Found broken file: {tif_path.name}")
                        is_broken = True
                    else:
                        # Try to open with rasterio to verify
                        try:
                            with rasterio.open(str(tif_path)) as ds:
                                print(f"  ✓ Valid GeoTIFF: {tif_path.name}")
                                return False  # Already valid, skip
                        except:
                            print(f"  → Invalid GeoTIFF: {tif_path.name}")
                            is_broken = True
            except:
                is_broken = True
        else:
            is_broken = True
            print(f"  → Missing file: {tif_path.name}")
        
        if is_broken:
            # Convert to proper GeoTIFF
            print(f"  → Converting...")
            success = numpy_to_geotiff(data, bounds, tif_path)
            
            if success:
                # Verify
                try:
                    with rasterio.open(str(tif_path)) as ds:
                        print(f"  ✓ Converted successfully: {tif_path}")
                        print(f"    Size: {ds.width}x{ds.height}")
                        print(f"    Min/Max: {data.min():.2f} / {data.max():.2f} t/ha/yr")
                        return True
                except Exception as e:
                    print(f"  ✗ Verification failed: {str(e)}")
                    return False
            else:
                return False
        
        return False
        
    except Exception as e:
        print(f"  ✗ ERROR: {str(e)}")
        return False

def main():
    """Find and convert all .npy files"""
    storage_path = Path('/var/www/rusle-icarda/storage/rusle-tiles/geotiff')
    
    print("=" * 60)
    print(" Converting .npy files to proper GeoTIFFs")
    print("=" * 60)
    print()
    
    # Find all .npy files
    npy_files = list(storage_path.rglob('*.npy'))
    
    if not npy_files:
        print("No .npy files found!")
        return
    
    print(f"Found {len(npy_files)} .npy files\n")
    
    converted = 0
    skipped = 0
    failed = 0
    
    for npy_file in npy_files:
        relative_path = npy_file.relative_to(storage_path)
        print(f"Processing: {relative_path}")
        
        try:
            if convert_npy_file(npy_file):
                converted += 1
            else:
                skipped += 1
        except Exception as e:
            print(f"  ✗ Failed: {str(e)}")
            failed += 1
        
        print()
    
    print("=" * 60)
    print(" Summary")
    print("=" * 60)
    print(f"Total files:  {len(npy_files)}")
    print(f"✓ Converted:  {converted}")
    print(f"⏭  Skipped:    {skipped} (already valid)")
    print(f"✗ Failed:     {failed}")
    print()
    
    if converted > 0:
        print("✅ Conversion complete!")
    else:
        print("ℹ️  No files needed conversion")

if __name__ == '__main__':
    main()

