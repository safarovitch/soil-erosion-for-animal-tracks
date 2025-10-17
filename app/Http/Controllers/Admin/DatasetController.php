<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomDataset;
use App\Services\GeoTiffProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DatasetController extends Controller
{
    public function __construct(
        private GeoTiffProcessor $processor
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('role:admin');
    }

    /**
     * Upload a new GeoTIFF dataset.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:tif,tiff|max:500000', // 500MB max
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'required|in:rainfall,erosion,custom',
        ]);

        try {
            $file = $request->file('file');

            // Validate file format
            if (!$this->processor->isSupportedFormat($file->getClientOriginalName())) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unsupported file format. Please upload a GeoTIFF file.',
                ], 400);
            }

            // Store the original file
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs('geotiff/uploads', $filename, 'geotiff');

            // Validate the GeoTIFF file
            $fullPath = Storage::disk('geotiff')->path($filePath);
            $validation = $this->processor->validateFile($fullPath);

            if (!$validation['valid']) {
                Storage::disk('geotiff')->delete($filePath);
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid GeoTIFF file: ' . $validation['error'],
                ], 400);
            }

            // Create dataset record
            $dataset = CustomDataset::create([
                'user_id' => Auth::id(),
                'name' => $request->name,
                'description' => $request->description,
                'type' => $request->type,
                'original_filename' => $file->getClientOriginalName(),
                'file_path' => $filePath,
                'metadata' => $validation,
                'status' => 'uploading',
            ]);

            // Process the file asynchronously (you might want to use a job queue)
            $this->processDataset($dataset);

            return response()->json([
                'success' => true,
                'dataset' => $dataset,
                'message' => 'File uploaded successfully. Processing will begin shortly.',
            ]);
        } catch (\Exception $e) {
            Log::error('Dataset upload failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Upload failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Get list of datasets.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CustomDataset::with('user')
            ->orderBy('created_at', 'desc');

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $datasets = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $datasets,
        ]);
    }

    /**
     * Get a specific dataset.
     */
    public function show(CustomDataset $dataset): JsonResponse
    {
        $dataset->load('user');

        return response()->json([
            'success' => true,
            'data' => $dataset,
        ]);
    }

    /**
     * Update a dataset.
     */
    public function update(Request $request, CustomDataset $dataset): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'sometimes|required|in:rainfall,erosion,custom',
        ]);

        $dataset->update($request->only(['name', 'description', 'type']));

        return response()->json([
            'success' => true,
            'data' => $dataset,
            'message' => 'Dataset updated successfully.',
        ]);
    }

    /**
     * Delete a dataset.
     */
    public function destroy(CustomDataset $dataset): JsonResponse
    {
        try {
            // Delete files
            if (Storage::disk('geotiff')->exists($dataset->file_path)) {
                Storage::disk('geotiff')->delete($dataset->file_path);
            }

            if ($dataset->processed_path && Storage::disk('geotiff')->exists($dataset->processed_path)) {
                Storage::disk('geotiff')->deleteDirectory(dirname($dataset->processed_path));
            }

            $dataset->delete();

            return response()->json([
                'success' => true,
                'message' => 'Dataset deleted successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Dataset deletion failed', [
                'dataset_id' => $dataset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to delete dataset.',
            ], 500);
        }
    }

    /**
     * Get available datasets for public use.
     */
    public function getAvailable(): JsonResponse
    {
        $datasets = CustomDataset::ready()
            ->select('id', 'name', 'description', 'type', 'metadata')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $datasets,
        ]);
    }

    /**
     * Serve tiles for a dataset.
     */
    public function serveTiles(CustomDataset $dataset, int $z, int $x, int $y): \Symfony\Component\HttpFoundation\Response
    {
        if (!$dataset->isReady()) {
            abort(404);
        }

        $tilePath = $dataset->processed_path . "/tiles/{$z}/{$x}/{$y}.png";

        if (!Storage::disk('geotiff')->exists($tilePath)) {
            abort(404);
        }

        $file = Storage::disk('geotiff')->get($tilePath);

        return response($file, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400', // Cache for 24 hours
        ]);
    }

    /**
     * Process a dataset (convert to tiles).
     */
    private function processDataset(CustomDataset $dataset): void
    {
        try {
            $dataset->update(['status' => 'processing']);

            $inputPath = Storage::disk('geotiff')->path($dataset->file_path);
            $outputDir = 'processed/' . $dataset->id;

            $result = $this->processor->processForWeb($inputPath, $outputDir);

            if ($result['success']) {
                $dataset->update([
                    'processed_path' => $result['cog_path'],
                    'status' => 'ready',
                    'processed_at' => now(),
                ]);
            } else {
                $dataset->update([
                    'status' => 'failed',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Dataset processing failed', [
                'dataset_id' => $dataset->id,
                'error' => $e->getMessage(),
            ]);

            $dataset->update(['status' => 'failed']);
        }
    }
}
