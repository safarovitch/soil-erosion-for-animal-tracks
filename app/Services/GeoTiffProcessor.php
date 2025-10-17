<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

class GeoTiffProcessor
{
    /**
     * Check if the file format is supported.
     */
    public function isSupportedFormat(string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, ['tif', 'tiff', 'geotiff']);
    }

    /**
     * Validate a GeoTIFF file using GDAL.
     */
    public function validateFile(string $filePath): array
    {
        try {
            // Check if GDAL is available
            $gdalVersion = Process::run('gdalinfo --version');
            if (!$gdalVersion->successful()) {
                return [
                    'valid' => false,
                    'error' => 'GDAL is not installed or not available in PATH',
                ];
            }

            // Get file information
            $result = Process::run("gdalinfo '{$filePath}'");

            if (!$result->successful()) {
                return [
                    'valid' => false,
                    'error' => 'Invalid GeoTIFF file: ' . $result->errorOutput(),
                ];
            }

            // Parse the output to extract metadata
            $info = $this->parseGdalInfo($result->output());

            return [
                'valid' => true,
                'metadata' => $info,
            ];
        } catch (Exception $e) {
            Log::error('GeoTIFF validation failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return [
                'valid' => false,
                'error' => 'Validation failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Process GeoTIFF for web use (convert to COG and generate tiles).
     */
    public function processForWeb(string $inputPath, string $outputDir): array
    {
        try {
            $outputPath = storage_path("app/geotiff/{$outputDir}");

            // Create output directory
            if (!is_dir($outputPath)) {
                mkdir($outputPath, 0755, true);
            }

            // Convert to Cloud Optimized GeoTIFF
            $cogPath = "{$outputPath}/data.tif";
            $cogResult = Process::run([
                'gdal_translate',
                '-of', 'COG',
                '-co', 'COMPRESS=LZW',
                '-co', 'TILED=YES',
                '-co', 'BLOCKSIZE=512',
                $inputPath,
                $cogPath,
            ]);

            if (!$cogResult->successful()) {
                return [
                    'success' => false,
                    'error' => 'COG conversion failed: ' . $cogResult->errorOutput(),
                ];
            }

            // Generate tiles
            $tilesPath = "{$outputPath}/tiles";
            $tilesResult = Process::run([
                'gdal2tiles.py',
                '--profile=mercator',
                '--zoom=0-18',
                '--processes=4',
                '--webviewer=none',
                '--resampling=bilinear',
                $cogPath,
                $tilesPath,
            ]);

            if (!$tilesResult->successful()) {
                return [
                    'success' => false,
                    'error' => 'Tile generation failed: ' . $tilesResult->errorOutput(),
                ];
            }

            return [
                'success' => true,
                'cog_path' => "{$outputDir}/data.tif",
                'tiles_path' => "{$outputDir}/tiles",
            ];
        } catch (Exception $e) {
            Log::error('GeoTIFF processing failed', [
                'input' => $inputPath,
                'output' => $outputDir,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Processing failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Generate XYZ tiles for a GeoTIFF file.
     */
    public function generateTiles(string $inputPath, string $outputPath, int $minZoom = 0, int $maxZoom = 18): array
    {
        try {
            $result = Process::run([
                'gdal2tiles.py',
                '--profile=mercator',
                "--zoom={$minZoom}-{$maxZoom}",
                '--processes=4',
                '--webviewer=none',
                '--resampling=bilinear',
                $inputPath,
                $outputPath,
            ]);

            if (!$result->successful()) {
                return [
                    'success' => false,
                    'error' => 'Tile generation failed: ' . $result->errorOutput(),
                ];
            }

            return [
                'success' => true,
                'tiles_path' => $outputPath,
            ];
        } catch (Exception $e) {
            Log::error('Tile generation failed', [
                'input' => $inputPath,
                'output' => $outputPath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Tile generation failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Parse GDAL info output to extract metadata.
     */
    private function parseGdalInfo(string $output): array
    {
        $metadata = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);

            // Extract coordinate system
            if (strpos($line, 'Coordinate System is:') !== false) {
                $metadata['coordinate_system'] = trim(str_replace('Coordinate System is:', '', $line));
            }

            // Extract corner coordinates
            if (strpos($line, 'Upper Left') !== false) {
                preg_match('/Upper Left\s+\(\s*([0-9.-]+),\s*([0-9.-]+)\)/', $line, $matches);
                if (count($matches) >= 3) {
                    $metadata['upper_left'] = [(float)$matches[1], (float)$matches[2]];
                }
            }

            if (strpos($line, 'Lower Right') !== false) {
                preg_match('/Lower Right\s+\(\s*([0-9.-]+),\s*([0-9.-]+)\)/', $line, $matches);
                if (count($matches) >= 3) {
                    $metadata['lower_right'] = [(float)$matches[1], (float)$matches[2]];
                }
            }

            // Extract pixel size
            if (strpos($line, 'Pixel Size') !== false) {
                preg_match('/Pixel Size = \(([0-9.-]+),([0-9.-]+)\)/', $line, $matches);
                if (count($matches) >= 3) {
                    $metadata['pixel_size'] = [(float)$matches[1], (float)$matches[2]];
                }
            }

            // Extract image size
            if (strpos($line, 'Size is') !== false) {
                preg_match('/Size is (\d+), (\d+)/', $line, $matches);
                if (count($matches) >= 3) {
                    $metadata['size'] = [(int)$matches[1], (int)$matches[2]];
                }
            }

            // Extract bands
            if (strpos($line, 'Band') !== false && strpos($line, 'Block=') !== false) {
                preg_match('/Band (\d+)/', $line, $matches);
                if (count($matches) >= 2) {
                    $metadata['bands'] = $metadata['bands'] ?? 0;
                    $metadata['bands'] = max($metadata['bands'], (int)$matches[1]);
                }
            }
        }

        return $metadata;
    }

    /**
     * Check if GDAL is available on the system.
     */
    public function isGdalAvailable(): bool
    {
        try {
            $result = Process::run('gdalinfo --version');
            return $result->successful();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get GDAL version information.
     */
    public function getGdalVersion(): ?string
    {
        try {
            $result = Process::run('gdalinfo --version');
            if ($result->successful()) {
                return trim($result->output());
            }
        } catch (Exception $e) {
            Log::warning('Failed to get GDAL version', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
