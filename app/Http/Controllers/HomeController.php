<?php

namespace App\Http\Controllers;

use App\Models\District;
use App\Models\Region;
use Inertia\Inertia;

class HomeController extends Controller
{

    public function index()
    {
        // Load all regions and districts with their geometries
        $regions = Region::select('id', 'name_en', 'name_tj', 'code', 'area_km2', 'geometry')
            ->orderBy('name_en')
            ->get()
            ->map(function ($region) {
                return [
                    'id' => $region->id,
                    'name' => $region->name_en,
                    'name_en' => $region->name_en,
                    'name_tj' => $region->name_tj,
                    'code' => $region->code,
                    'area_km2' => $region->area_km2,
                    'geometry' => $region->getGeometryArray(),
                    'center' => $region->getCenterPoint(),
                    'bbox' => $region->getBoundingBox(),
                    'district_count' => $region->districts()->count(),
                ];
            });

        $districts = District::with('region')
            ->select('id', 'region_id', 'name_en', 'name_tj', 'code', 'area_km2', 'geometry')
            ->orderBy('name_en')
            ->get()
            ->map(function ($district) {
                return [
                    'id' => $district->id,
                    'region_id' => $district->region_id,
                    'region_name' => $district->region->name_en ?? null,
                    'region_code' => $district->region->code ?? null,
                    'name' => $district->name_en,
                    'name_en' => $district->name_en,
                    'name_tj' => $district->name_tj,
                    'code' => $district->code,
                    'area_km2' => $district->area_km2,
                    'geometry' => $district->getGeometryArray(),
                    'center' => $district->getCenterPoint(),
                    'bbox' => $district->getBoundingBox(),
                ];
            });

        return Inertia::render('Map', [
            'user' => auth()->user(),
            'regions' => $regions,
            'districts' => $districts,
            'initialData' => [
                'totalRegions' => $regions->count(),
                'totalDistricts' => $districts->count(),
                'appName' => config('app.name', 'Soil Erosion Watch'),
            ],
        ]);
    }
}
