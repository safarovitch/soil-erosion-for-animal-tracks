<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomDataset;
use App\Services\GeoTiffProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DatasetController extends Controller
{
    public function __construct(
        private GeoTiffProcessor $processor
    ) {}

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
                'access_token' => Str::uuid()->toString(),
            ]);

            // Process the file asynchronously (you might want to use a job queue)
            $this->processDataset($dataset);

            $dataset->refresh();

            return response()->json([
                'success' => true,
                'dataset' => $this->datasetResponse($dataset),
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
        $userId = Auth::id();

        if (!$userId) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);
        }

        $query = CustomDataset::with('user')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc');

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $datasets = $query->paginate(20);
        $datasets->getCollection()->transform(function (CustomDataset $dataset) {
            return $this->datasetResponse($dataset);
        });

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
        $this->ensureOwner($dataset);
        $dataset->load('user');

        return response()->json([
            'success' => true,
            'data' => $this->datasetResponse($dataset),
        ]);
    }

    /**
     * Update a dataset.
     */
    public function update(Request $request, CustomDataset $dataset): JsonResponse
    {
        $this->ensureOwner($dataset);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'sometimes|required|in:rainfall,erosion,custom',
        ]);

        $dataset->update($request->only(['name', 'description', 'type']));
        $dataset->refresh();

        return response()->json([
            'success' => true,
            'data' => $this->datasetResponse($dataset),
            'message' => 'Dataset updated successfully.',
        ]);
    }

    /**
     * Delete a dataset.
     */
    public function destroy(CustomDataset $dataset): JsonResponse
    {
        $this->ensureOwner($dataset);

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
        $userId = Auth::id();

        if (!$userId) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $datasets = CustomDataset::ready()
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (CustomDataset $dataset) => $this->datasetResponse($dataset));

        return response()->json([
            'success' => true,
            'data' => $datasets,
        ]);
    }

    /**
     * Serve tiles for a dataset.
     */
    public function serveTiles(Request $request, CustomDataset $dataset, int $z, int $x, int $y): \Symfony\Component\HttpFoundation\Response
    {
        if (!$this->canAccessDataset($dataset, $request)) {
            abort(403, 'Unauthorized');
        }

        if (!$dataset->isReady()) {
            abort(404);
        }

        $tilesPath = $dataset->processed_path;

        if (!$tilesPath) {
            abort(404);
        }

        $tilesPath = rtrim($tilesPath, '/');
        $tilePath = "{$tilesPath}/{$z}/{$x}/{$y}.png";

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
                    'processed_path' => $result['tiles_path'],
                    'status' => 'ready',
                    'processed_at' => now(),
                    'metadata' => array_merge(
                        is_array($dataset->metadata) ? $dataset->metadata : [],
                        [
                            'cog_path' => $result['cog_path'],
                            'tiles_path' => $result['tiles_path'],
                        ]
                    ),
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

    /**
     * Ensure the current user owns the dataset.
     */
    private function ensureOwner(CustomDataset $dataset): void
    {
        if (Auth::id() !== $dataset->user_id) {
            abort(403, 'Unauthorized');
        }
    }

    /**
     * Determine whether the current request can access the dataset.
     */
    private function canAccessDataset(CustomDataset $dataset, Request $request): bool
    {
        $userId = Auth::id();

        if ($userId && $dataset->user_id === $userId) {
            return true;
        }

        $token = (string) $request->query('access_token', '');

        if ($token !== '' && hash_equals($dataset->access_token, $token)) {
            return true;
        }

        return false;
    }

    /**
     * Build a consistent dataset response payload.
     */
    private function datasetResponse(CustomDataset $dataset): array
    {
        return [
            'id' => $dataset->id,
            'name' => $dataset->name,
            'description' => $dataset->description,
            'type' => $dataset->type,
            'status' => $dataset->status,
            'metadata' => $dataset->metadata,
            'processed_at' => $dataset->processed_at?->toISOString(),
            'tile_url_template' => $this->buildTileUrlTemplate($dataset),
        ];
    }

    /**
     * Generate the absolute tile URL template for XYZ sources.
     */
    private function buildTileUrlTemplate(CustomDataset $dataset): string
    {
        $path = "/api/datasets/{$dataset->id}/tiles/{z}/{x}/{y}.png";

        return URL::to($path) . '?access_token=' . $dataset->access_token;
    }
}
