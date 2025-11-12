<template>
    <div class="h-screen w-screen bg-slate-50 flex flex-col overflow-hidden">
        <!-- Progress Bar -->
        <ProgressBar
            :visible="loading"
            :progress="progress"
            :message="loadingMessage"
        />

        <!-- Toast Notification -->
        <ToastNotification
            :show="toast.show"
            :type="toast.type"
            :title="toast.title"
            :message="toast.message"
            :details="toast.details"
            @close="toast.show = false"
        />

        <!-- Main Content -->
        <div class="flex flex-1 overflow-hidden relative">
            <!-- Left Sidebar -->
            <div
                v-show="leftSidebarVisible"
                :style="{ width: leftSidebarWidth + 'px', maxWidth: '50vw' }"
                class="h-full bg-white border-r border-gray-200 shadow-lg overflow-y-auto flex-shrink-0 relative"
            >
                <!-- Collapse Button -->
                <button
                    @click="leftSidebarVisible = false"
                    class="absolute top-2 right-2 z-10 p-1 rounded hover:bg-gray-100"
                    title="Collapse sidebar"
                >
                    <svg
                        class="w-5 h-5"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M15 19l-7-7 7-7"
                        />
                    </svg>
                </button>

                <div class="p-6">
                    <h2 class="text-xl font-bold mb-4 text-gray-800">
                        Soil Erosion Analysis
                    </h2>

                    <!-- Region Selector -->
                    <RegionSelector
                        v-model:selectedRegion="selectedRegion"
                        v-model:selectedDistrict="selectedDistrict"
                        :selected-areas="selectedAreas"
                        :regions="regions"
                        :districts="districts"
                        @region-change="handleRegionChange"
                        @district-change="handleDistrictChange"
                        @areas-change="handleAreasChange"
                    />

                    <!-- Time Series Slider -->
                    <TimeSeriesSlider
                        v-model:period="selectedPeriod"
                        @period-change="handlePeriodChange"
                        class="mt-6"
                    />

                    <!-- Layer Controls -->
                    <LayerControl
                        :visible-layers="visibleLayers"
                        :available-layers="availableLayers"
                        :show-labels="showLabels"
                        @layer-toggle="handleLayerToggle"
                        @labels-toggle="handleLabelsToggle"
                        class="mt-6"
                    />

                    <!-- Drawing Tools -->

                    <div class="mt-8 space-y-2">
                        <div class="flex space-x-2">
                            <button
                                @click="applySelection"
                                :disabled="!canApply"
                                class="flex-1 px-4 py-2 rounded-md text-white text-sm font-semibold transition-colors"
                                :class="[
                                    canApply
                                        ? 'bg-blue-600 hover:bg-blue-700'
                                        : 'bg-gray-400 cursor-not-allowed'
                                ]"
                            >
                                Apply Selection
                            </button>
                            <button
                                @click="clearSelection"
                                class="flex-1 px-4 py-2 rounded-md text-white text-sm font-semibold transition-colors bg-gray-600 hover:bg-gray-700"
                            >
                                Clear Selection
                            </button>
                        </div>
                        <p
                            v-if="needsApply && canApply"
                            class="text-xs text-amber-600 bg-amber-100 border border-amber-200 rounded-md px-3 py-2"
                        >
                            Changes pending. Click "Apply Selection" to update statistics and layers.
                        </p>
                    </div>
                </div>

                <!-- Resize Handle -->
                <div
                    class="absolute top-0 right-0 w-1 h-full cursor-col-resize hover:bg-blue-500 bg-gray-300"
                    @mousedown="startLeftResize"
                ></div>
            </div>

            <!-- Expand Button (when sidebar is collapsed) -->
            <button
                v-show="!leftSidebarVisible"
                @click="leftSidebarVisible = true"
                class="absolute top-4 left-4 z-20 p-2 bg-white rounded-lg shadow-lg hover:bg-gray-50"
                title="Expand sidebar"
            >
                <svg
                    class="w-6 h-6"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M9 5l7 7-7 7"
                    />
                </svg>
            </button>

            <!-- Map and Statistics Container -->
            <div class="flex-1 flex flex-col overflow-hidden relative">
                <!-- Export Toolbar -->
                <div class="absolute top-4 right-4 z-30 flex space-x-2">
                    <div
                        class="px-3 py-2 bg-white rounded-lg shadow-lg flex items-center space-x-2"
                        title="Change base map style"
                    >
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Map
                        </span>
                        <select
                            v-model="selectedBaseMapType"
                            :disabled="!mapInstance || baseMapOptions.length <= 1"
                            class="text-sm font-medium text-gray-700 border border-gray-300 rounded-md px-2 py-1 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <option
                                v-for="option in baseMapOptions"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                    <button
                        @click="exportMapAsPNG"
                        class="px-3 py-2 bg-white rounded-lg shadow-lg hover:bg-gray-50 flex items-center space-x-2"
                        title="Export map as PNG"
                    >
                        <span class="text-lg">📷</span>
                        <span class="text-sm font-medium">Export PNG</span>
                    </button>
                    <button
                        @click="exportStatisticsCSV"
                        :disabled="!statistics"
                        class="px-3 py-2 bg-white rounded-lg shadow-lg hover:bg-gray-50 flex items-center space-x-2 disabled:opacity-50 disabled:cursor-not-allowed"
                        title="Export statistics as CSV"
                    >
                        <span class="text-lg">📊</span>
                        <span class="text-sm font-medium">Export CSV</span>
                    </button>
                </div>

                <!-- Map Container - Fits remaining space -->
                <div class="flex-1 relative bg-gray-100 overflow-hidden">
                    <div
                        v-if="!mapInstance"
                        class="flex items-center justify-center h-full"
                    >
                        <div class="text-center">
                            <div
                                class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4"
                            ></div>
                            <p class="text-gray-700">Loading map...</p>
                        </div>
                    </div>

                    <MapView
                        ref="mapView"
                        :regions="regions"
                        :districts="districts"
                        :selected-region="selectedRegion"
                        :selected-district="selectedDistrict"
                        :selected-areas="selectedAreas"
                        :selected-period="selectedPeriod"
                        :visible-layers="visibleLayers"
                        :analysis-trigger="analysisTrigger"
                        @map-ready="handleMapReady"
                        @statistics-updated="handleStatisticsUpdated"
                        @district-clicked="handleDistrictClicked"
                        @region-clicked="handleRegionClicked"
                        @geojson-loaded="handleGeoJSONLoaded"
                        @area-toggle-selection="handleAreaToggleSelection"
                        @area-replace-selection="handleAreaReplaceSelection"
                        @boundary-violation="handleBoundaryViolation"
                        @layer-warning="handleLayerWarning"
                    />

                    <!-- Map Legend -->
                    <MapLegend
                        :visible-layers="visibleLayers"
                        :available-layers="availableLayers"
                    />

                    <!-- Expand Bottom Panel Button (when collapsed) -->
                    <button
                        v-show="!bottomPanelVisible"
                        @click="bottomPanelVisible = true"
                        class="absolute bottom-4 left-1/2 transform -translate-x-1/2 z-30 px-4 py-2 bg-white rounded-lg shadow-xl hover:bg-gray-50 flex items-center space-x-2"
                        title="Show statistics"
                    >
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M5 15l7-7 7 7"
                            />
                        </svg>
                        <span>Show Statistics & Charts</span>
                    </button>
                </div>

                <!-- Bottom Statistics Panel - Fits in flex layout -->
                <div
                    v-show="bottomPanelVisible"
                    :style="{
                        height: bottomPanelHeight + 'px',
                        minHeight: '200px',
                        maxHeight: 'calc(100vh - 100px)',
                    }"
                    class="bg-white/95 backdrop-blur-sm border-t border-gray-200 shadow-2xl overflow-hidden flex-shrink-0 flex flex-col relative"
                >
                    <!-- Resize Handle -->
                    <div
                        class="absolute top-0 left-0 w-full h-1 cursor-row-resize hover:bg-blue-500 bg-gray-300 z-30"
                        @mousedown="startBottomResize"
                    ></div>

                    <!-- Collapse Button -->
                    <button
                        @click="bottomPanelVisible = false"
                        class="absolute top-2 right-2 z-30 p-1 rounded hover:bg-gray-100 bg-white shadow"
                        title="Collapse statistics"
                    >
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M19 9l-7 7-7-7"
                            />
                        </svg>
                    </button>

                    <!-- Scrollable Content Area -->
                    <div class="flex-1 overflow-y-auto p-6 pt-8">
                        <!-- Comprehensive Statistics Panel -->
                        <StatisticsPanel
                            :selected-area="selectedArea"
                            :statistics="statistics"
                            :area-statistics="areaStatistics"
                            :time-series-data="timeSeriesData"
                        />

                        <!-- Erosion Risk Legend -->
                        <div class="mt-6 border-t pt-4">
                            <h4 class="text-sm font-bold mb-3 text-gray-700">
                                Erosion Risk Classification (RUSLE)
                            </h4>
                            <div class="grid grid-cols-5 gap-2 text-xs">
                                <div class="text-center">
                                    <div
                                        class="h-6 rounded mb-1"
                                        style="
                                            background-color: rgba(
                                                34,
                                                139,
                                                34,
                                                0.6
                                            );
                                        "
                                    ></div>
                                    <div class="font-medium text-green-700">
                                        Very Low
                                    </div>
                                    <div class="text-gray-600">0-5 t/ha/yr</div>
                                </div>
                                <div class="text-center">
                                    <div
                                        class="h-6 rounded mb-1"
                                        style="
                                            background-color: rgba(
                                                255,
                                                215,
                                                0,
                                                0.6
                                            );
                                        "
                                    ></div>
                                    <div class="font-medium text-yellow-700">
                                        Low
                                    </div>
                                    <div class="text-gray-600">
                                        5-15 t/ha/yr
                                    </div>
                                </div>
                                <div class="text-center">
                                    <div
                                        class="h-6 rounded mb-1"
                                        style="
                                            background-color: rgba(
                                                255,
                                                140,
                                                0,
                                                0.6
                                            );
                                        "
                                    ></div>
                                    <div class="font-medium text-orange-700">
                                        Moderate
                                    </div>
                                    <div class="text-gray-600">
                                        15-30 t/ha/yr
                                    </div>
                                </div>
                                <div class="text-center">
                                    <div
                                        class="h-6 rounded mb-1"
                                        style="
                                            background-color: rgba(
                                                220,
                                                20,
                                                60,
                                                0.6
                                            );
                                        "
                                    ></div>
                                    <div class="font-medium text-red-700">
                                        Severe
                                    </div>
                                    <div class="text-gray-600">
                                        30-50 t/ha/yr
                                    </div>
                                </div>
                                <div class="text-center">
                                    <div
                                        class="h-6 rounded mb-1"
                                        style="
                                            background-color: rgba(
                                                139,
                                                0,
                                                0,
                                                0.8
                                            );
                                        "
                                    ></div>
                                    <div class="font-medium text-red-900">
                                        Excessive
                                    </div>
                                    <div class="text-gray-600">
                                        &gt; 50 t/ha/yr
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Login Modal -->
        <div
            v-if="showLogin"
            class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
            @click="showLogin = false"
        >
            <div class="bg-white rounded-lg p-6 w-96" @click.stop>
                <h2 class="text-xl font-bold mb-4">Admin Login</h2>
                <form @submit.prevent="login">
                    <div class="mb-4">
                        <label
                            class="block text-gray-700 text-sm font-bold mb-2"
                            for="email"
                        >
                            Email
                        </label>
                        <input
                            id="email"
                            v-model="loginForm.email"
                            type="email"
                            class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            required
                        />
                    </div>
                    <div class="mb-6">
                        <label
                            class="block text-gray-700 text-sm font-bold mb-2"
                            for="password"
                        >
                            Password
                        </label>
                        <input
                            id="password"
                            v-model="loginForm.password"
                            type="password"
                            class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            required
                        />
                    </div>
                    <div class="flex justify-end space-x-3">
                        <button
                            type="button"
                            @click="showLogin = false"
                            class="px-4 py-2 text-gray-600 hover:text-gray-800"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="loginLoading"
                            class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 disabled:opacity-50"
                        >
                            {{ loginLoading ? "Logging in..." : "Login" }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted } from "vue";
import { router } from "@inertiajs/vue3";
import MapView from "@/Components/Map/MapView.vue";
import RegionSelector from "@/Components/Map/RegionSelector.vue";
import TimeSeriesSlider from "@/Components/Map/TimeSeriesSlider.vue";
import LayerControl from "@/Components/Map/LayerControl.vue";
import ChartPanel from "@/Components/Map/ChartPanel.vue";
import StatisticsPanel from "@/Components/Map/StatisticsPanel.vue";
import MapLegend from "@/Components/Map/MapLegend.vue";
import ProgressBar from "@/Components/UI/ProgressBar.vue";
import ToastNotification from "@/Components/UI/ToastNotification.vue";
import { GeoJSONService } from "@/Services/GeoJSONService.js";
import { DEFAULT_YEAR_PERIOD } from "@/constants/yearPeriods.js";

// Props
const props = defineProps({
    user: Object,
    regions: Array,
    districts: Array,
});

// Reactive data
const selectedRegion = ref(null);
const selectedDistrict = ref(null);
const selectedPeriod = ref({ ...DEFAULT_YEAR_PERIOD });
const currentPeriod = computed(() => selectedPeriod.value || DEFAULT_YEAR_PERIOD);
const currentStartYear = computed(() => currentPeriod.value.startYear);
const currentEndYear = computed(() => currentPeriod.value.endYear);
const currentPeriodLabel = computed(() => currentPeriod.value.label);
const selectedArea = ref(null);
const selectedAreas = ref([]); // Multiple selected areas
const visibleLayers = ref([]); // Start with no layers selected (country-wide default)
const showLabels = ref(true); // Show map labels by default
const mapInstance = ref(null);
const mapView = ref(null);
const baseMapOptions = ref([{ id: "osm", label: "OpenStreetMap" }]);
const selectedBaseMapType = ref("osm");
const statistics = ref(null);
const areaStatistics = ref([]);
const timeSeriesData = ref([]);
const loading = ref(false);
const progress = ref(0);
const loadingMessage = ref("");
const showLogin = ref(false);
const loginLoading = ref(false);
const analysisTrigger = ref(0);
const needsApply = ref(true);

// Toast notification
const toast = reactive({
    show: false,
    type: "info",
    title: "",
    message: "",
    details: "",
});

// Panel state
const leftSidebarVisible = ref(true);
const leftSidebarWidth = ref(320);
const bottomPanelVisible = ref(true);
const bottomPanelHeight = ref(300);

// Local copies of regions and districts that can be updated with GeoJSON data
// Filter out "Unknown" regions from props
const regions = ref(
    (props.regions || []).filter((region) => {
        const name = (region.name_en || region.name || '').toLowerCase();
        return name && !name.includes('unknown') && !name.includes('dushanbe');
    })
);
const districts = ref(props.districts || []);

const canApply = computed(() => selectedAreas.value.length > 0 && !loading.value);

// Login form
const loginForm = reactive({
    email: "",
    password: "",
});

const buildDetailedLayerKey = (areaType, areaId, startYear, endYear) =>
    `${areaType}-${areaId}-${startYear}-${endYear}`;

// Available layers
const availableLayers = ref([
    {
        id: "erosion",
        name: "Soil Erosion Hazard",
        description: "Annual soil loss rate (A = R×K×LS×C×P)",
        metadata: { unit: "t/ha/yr" },
    },
    {
        id: "rainfall_cv",
        name: "Rainfall Variability",
        description: "Coefficient of variation (%)",
        metadata: { colorScheme: "sequential" },
    },
    {
        id: "r_factor",
        name: "R-Factor (Rainfall Erosivity)",
        description: "Rainfall erosivity factor",
        metadata: { unit: "MJ mm/(ha h yr)" },
    },
    {
        id: "k_factor",
        name: "K-Factor (Soil Erodibility)",
        description: "Soil erodibility factor",
        metadata: { unit: "t ha h/(ha MJ mm)" },
    },
    {
        id: "ls_factor",
        name: "LS-Factor (Topographic)",
        description: "Slope length and steepness factor",
        metadata: { unit: "dimensionless" },
    },
    {
        id: "c_factor",
        name: "C-Factor (Cover Management)",
        description: "Cover and management factor",
        metadata: { unit: "dimensionless", range: "0-1" },
    },
    {
        id: "p_factor",
        name: "P-Factor (Support Practice)",
        description: "Support practice factor",
        metadata: { unit: "dimensionless", range: "0-1" },
    },
]);

// Computed properties
const isAuthenticated = computed(
    () => props.user && props.user.role === "admin"
);

// Methods
const showToast = (type, title, message, details = "") => {
    toast.type = type;
    toast.title = title;
    toast.message = message;
    toast.details = details;
    toast.show = true;
};

const markAnalysisDirty = () => {
    needsApply.value = true;
};

const applySelection = async () => {
    let areasToApply = selectedAreas.value;

    if (mapView.value && selectedAreas.value.length > 0) {
        const countryBoundary = mapView.value.getCountryBoundary?.();
        if (countryBoundary) {
            let changed = false;
            areasToApply = selectedAreas.value.map((area) => {
                if (
                    (area.area_type === "country" || area.type === "country") &&
                    !area.geometry
                ) {
                    changed = true;
                    return {
                        ...area,
                        geometry: countryBoundary,
                    };
                }
                return area;
            });

            if (changed) {
                selectedAreas.value = areasToApply;
            }
        }
    }

    analysisTrigger.value += 1;
    needsApply.value = false;

    areaStatistics.value = [];
    statistics.value = null;
    timeSeriesData.value = [];

    if (mapView.value?.clearAreaHighlights) {
        mapView.value.clearAreaHighlights();
    }

    if (areasToApply.length > 0) {
        if (!bottomPanelVisible.value) {
            bottomPanelVisible.value = true;
        }

        let analysisAreas = [...areasToApply];

        const totalRegions = regions.value.length;
        const selectedRegionsOnly =
            analysisAreas.length > 0 &&
            analysisAreas.every((area) => {
                if (area.area_type === "country" || area.type === "country") {
                    return false;
                }

                const areaType =
                    area.area_type ||
                    (area.region_id
                        ? "district"
                        : area.type === "region"
                        ? "region"
                        : null);
                return areaType === "region";
            });

        if (
            selectedRegionsOnly &&
            totalRegions > 0 &&
            analysisAreas.length === totalRegions
        ) {
            const countryBoundary =
                mapView.value?.getCountryBoundary?.() || null;

            analysisAreas = [
                {
                    id: 0,
                    type: "country",
                    area_type: "country",
                    name_en: "Tajikistan",
                    name_tj: "Тоҷикистон",
                    geometry: countryBoundary,
                },
            ];
        }

        const primaryArea = analysisAreas[0] || null;
        selectedArea.value = primaryArea || null;

        loading.value = true;
        progress.value = 0;
        loadingMessage.value = "Calculating RUSLE statistics...";

        const results = [];

        try {
            for (let index = 0; index < analysisAreas.length; index++) {
                const result = await loadAreaStatistics(
                    analysisAreas[index],
                    index,
                    analysisAreas.length
                );

                if (result) {
                    const { components = [], ...primaryResult } = result;
                    results.push(primaryResult);

                    if (Array.isArray(components) && components.length) {
                        results.push(...components);
                    }
                }

                progress.value = Math.round(
                    ((index + 1) / analysisAreas.length) * 100
                );
            }

            areaStatistics.value = results;
            const primaryEntry = results.find(
                (entry) => entry && entry.isComponent !== true
            );
            statistics.value = primaryEntry?.statistics || null;
        } finally {
            setTimeout(() => {
                loading.value = false;
                progress.value = 0;
                loadingMessage.value = "";
            }, 400);
        }
    } else {
        bottomPanelVisible.value = false;
    }

    if (mapView.value) {
        if (selectedAreas.value.length > 0) {
            mapView.value.highlightSelectedAreas(selectedAreas.value);
        } else {
            mapView.value.clearAreaHighlights();
        }
    }
};

const clearSelection = () => {
    // Clear all selections
    selectedRegion.value = null;
    selectedDistrict.value = null;
    selectedAreas.value = [];
    selectedArea.value = null;
    
    // Clear visible layers
    visibleLayers.value = [];
    
    // Clear statistics
    statistics.value = null;
    
    // Reset needs apply flag
    needsApply.value = false;
    
    // Clear map highlights
    if (mapView.value) {
        mapView.value.clearAreaHighlights();
    }
    
    // Hide bottom panel if visible
    bottomPanelVisible.value = false;
    
    console.log('All selections cleared');
};

const handleMapReady = (map) => {
    mapInstance.value = map;
    console.log("Map is ready");

    if (mapView.value?.getAvailableBaseMapTypes) {
        const options = mapView.value.getAvailableBaseMapTypes();
        if (Array.isArray(options) && options.length > 0) {
            baseMapOptions.value = options;
        }
    }

    if (mapView.value?.getBaseMapType) {
        const currentType = mapView.value.getBaseMapType();
        if (currentType) {
            selectedBaseMapType.value = currentType;
        }
    }

    if (mapView.value?.toggleLabels) {
        mapView.value.toggleLabels(showLabels.value);
    }
};

const handleGeoJSONLoaded = async (geoJsonPath) => {
    console.log("GeoJSON loaded, now loading districts data...");
    try {
        // Load districts from GeoJSON and merge with existing data
        await loadDistrictsFromGeoJSON(geoJsonPath);
    } catch (error) {
        console.warn("Could not load districts from GeoJSON:", error.message);
    }
};

// Load districts from GeoJSON and merge with existing data
const loadDistrictsFromGeoJSON = async (geoJsonPath) => {
    try {
        console.log("Loading districts from GeoJSON for select boxes...");

        const geoJsonData = await GeoJSONService.loadDistrictsFromGeoJSON(
            geoJsonPath,
            regions.value
        );

        // Merge GeoJSON districts with existing districts
        const existingDistricts = districts.value || [];
        const geoJsonDistricts = geoJsonData.districts || [];

        // Create a combined list, avoiding duplicates
        const combinedDistricts = [...existingDistricts];

        geoJsonDistricts.forEach((geoDistrict) => {
            const exists = combinedDistricts.some(
                (existing) =>
                    existing.name_en === geoDistrict.name_en ||
                    existing.name_tj === geoDistrict.name_tj
            );

            if (!exists) {
                combinedDistricts.push(geoDistrict);
            }
        });

        districts.value = combinedDistricts;
        console.log(`Total districts available: ${combinedDistricts.length}`);

        // Also merge regions if needed
        const existingRegions = regions.value || [];
        const geoJsonRegions = geoJsonData.regions || [];

        const combinedRegions = [...existingRegions];
        geoJsonRegions.forEach((geoRegion) => {
            const exists = combinedRegions.some(
                (existing) => existing.name_en === geoRegion.name_en
            );

            if (!exists) {
                combinedRegions.push(geoRegion);
            }
        });

        // Filter out "Unknown" region
        regions.value = combinedRegions.filter((region) => {
            const name = (region.name_en || region.name || '').toLowerCase();
            return name && !name.includes('unknown') && !name.includes('dushanbe');
        });
        console.log(`Total regions available: ${regions.value.length}`);
    } catch (error) {
        console.warn("Could not load districts from GeoJSON:", error.message);
    }
};

const handleAreaTypeChange = (areaType) => {
    // This function is deprecated - area type is no longer used
    // Areas are now managed through region/district selection only
    console.log("handleAreaTypeChange called but no longer used");
};

// Load statistics for selected area
const loadAreaStatistics = async (area, index = 0, total = 1) => {
    if (!area) {
        return null;
    }

    const createStatisticsPayload = (rawStats, slopeValue, cvValue) => {
        const severityDistribution = Array.isArray(rawStats.severity_distribution)
            ? rawStats.severity_distribution
            : [];
        const rusleFactors = rawStats.rusle_factors || {};
        const topErodingAreas = Array.isArray(rawStats.top_eroding_areas)
            ? rawStats.top_eroding_areas
            : [];
        const mean = Number(rawStats.mean_erosion_rate ?? 0);

        return {
            meanErosionRate: mean,
            minErosionRate: Number(rawStats.min_erosion_rate ?? 0),
            maxErosionRate: Number(rawStats.max_erosion_rate ?? 0),
            erosionCV: Number(rawStats.erosion_cv ?? 0),
            bareSoilFrequency: Number(rawStats.bare_soil_frequency ?? 0),
            sustainabilityFactor: Number(rawStats.sustainability_factor ?? 0),
            rainfallSlope: slopeValue,
            rainfallCV: cvValue,
            riskLevel: getRiskLevel(mean),
            severityDistribution,
            rusleFactors,
            topErodingAreas,
        };
    };

    try {
        const startYear = currentStartYear.value;
        const endYear = currentEndYear.value;

        let areaType;
        let areaId = area.id;

        if (area.area_type === "country" || area.id === 0) {
            areaType = "country";
            areaId = 0;
        } else if (area.region_id) {
            areaType = "district";
        } else {
            areaType = "region";
        }

        loadingMessage.value = `Calculating RUSLE statistics (${index + 1}/${total})...`;

        let cachedStatistics = null;
        let cachedComponents = [];

        let cachedPeriodLabel = null;

        if (areaType !== "country") {
            try {
                const availabilityResponse = await axios.post(
                    "/api/erosion/check-availability",
                    {
                        area_type: areaType,
                        area_id: areaId,
                        start_year: startYear,
                        end_year: endYear,
                    }
                );

                const availability = availabilityResponse.data || {};

                if (
                    availability.status === "available" &&
                    availability.statistics
                ) {
                    cachedStatistics = availability.statistics;
                    cachedPeriodLabel =
                        availability.period_label ||
                        (startYear === endYear
                            ? `${startYear}`
                            : `${startYear}-${endYear}`);
                    loadingMessage.value = `Loading cached RUSLE statistics (${index + 1}/${total})...`;
                    if (Array.isArray(availability.components)) {
                        cachedComponents = availability.components;
                    }
                }
            } catch (error) {
                console.warn(
                    "Cached statistics check failed, falling back to live computation:",
                    error?.message || error
                );
            }
        }

        if (cachedStatistics) {
            const rainfallSlope = Number(cachedStatistics.rainfallSlope ?? cachedStatistics.rainfall_slope ?? 0);
            const rainfallCV = Number(cachedStatistics.rainfallCV ?? cachedStatistics.rainfall_cv ?? 0);

            const statisticsPayload = createStatisticsPayload(
                {
                    mean_erosion_rate:
                        cachedStatistics.meanErosionRate ??
                        cachedStatistics.mean_erosion_rate ??
                        cachedStatistics.mean ??
                        0,
                    min_erosion_rate:
                        cachedStatistics.minErosionRate ??
                        cachedStatistics.min_erosion_rate ??
                        cachedStatistics.min ??
                        0,
                    max_erosion_rate:
                        cachedStatistics.maxErosionRate ??
                        cachedStatistics.max_erosion_rate ??
                        cachedStatistics.max ??
                        0,
                    erosion_cv:
                        cachedStatistics.erosionCV ??
                        cachedStatistics.erosion_cv ??
                        cachedStatistics.cv ??
                        0,
                    bare_soil_frequency:
                        cachedStatistics.bareSoilFrequency ??
                        cachedStatistics.bare_soil_frequency ??
                        null,
                    sustainability_factor:
                        cachedStatistics.sustainabilityFactor ??
                        cachedStatistics.sustainability_factor ??
                        null,
                    rainfall_slope:
                        cachedStatistics.rainfall_slope ??
                        cachedStatistics.rainfallSlope ??
                        rainfallSlope,
                    rainfall_cv:
                        cachedStatistics.rainfall_cv ??
                        cachedStatistics.rainfallCV ??
                        rainfallCV,
                    severity_distribution:
                        cachedStatistics.severityDistribution ??
                        cachedStatistics.severity_distribution ??
                        [],
                    rusle_factors:
                        cachedStatistics.rusleFactors ??
                        cachedStatistics.rusle_factors ??
                        {},
                    top_eroding_areas:
                        cachedStatistics.topErodingAreas ??
                        cachedStatistics.top_eroding_areas ??
                        [],
                },
                rainfallSlope,
                rainfallCV
            );

            const componentEntries = (cachedComponents || [])
                .map((component) => {
                    if (
                        !component ||
                        !component.statistics ||
                        typeof component.area_id === "undefined"
                    ) {
                        return null;
                    }

                    const componentSlope = Number(
                        component.statistics.rainfall_slope ??
                            component.rainfall_slope ??
                            0
                    );
                    const componentCv = Number(
                        component.statistics.rainfall_cv ??
                            component.rainfall_cv ??
                            0
                    );
                    const componentAreaType =
                        component.area_type ||
                        (component.region_id ? "district" : areaType);

                    const componentArea = {
                        id: component.area_id,
                        area_type: componentAreaType,
                        region_id: component.region_id ?? area.id,
                        name_en: component.name || component.name_en || area.name_en,
                        name_tj: component.name_tj || null,
                    };

                    return {
                        key: `${buildDetailedLayerKey(
                            componentAreaType,
                            component.area_id,
                            startYear,
                            endYear
                        )}::component`,
                        area: componentArea,
                        areaType: componentAreaType,
                        periodLabel:
                            cachedPeriodLabel ||
                            (startYear === endYear
                                ? `${startYear}`
                                : `${startYear}-${endYear}`),
                        statistics: createStatisticsPayload(
                            component.statistics,
                            componentSlope,
                            componentCv
                        ),
                        isComponent: true,
                        parentAreaId:
                            component.parent_area_id ??
                            area.id ??
                            component.region_id ??
                            null,
                        parentAreaName:
                            component.parent_area_name ??
                            area.name_en ??
                            area.name ??
                            null,
                    };
                })
                .filter(Boolean);

            return {
                key: buildDetailedLayerKey(areaType, areaId, startYear, endYear),
                area,
                areaType,
                periodLabel:
                    cachedPeriodLabel ||
                    (startYear === endYear
                        ? `${startYear}`
                        : `${startYear}-${endYear}`),
                statistics: statisticsPayload,
                components: componentEntries,
            };
        }

        const response = await fetch("/api/erosion/compute", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({
                area_type: areaType,
                area_id: areaId,
                start_year: startYear,
                end_year: endYear,
                period: "annual",
            }),
        });

        const data = await response.json();

        if (!data.success || !data.data || !data.data.statistics) {
            throw new Error(data.error || "Failed to compute statistics");
        }

        const stats = data.data.statistics;
        const meanErosionRate = Number(stats.mean_erosion_rate ?? 0);
        let rainfallSlope = Number(stats.rainfall_slope ?? 0);
        let rainfallCV = Number(stats.rainfall_cv ?? 0);

        if ((!rainfallSlope && !rainfallCV) || rainfallSlope === 0) {
            try {
                const rainfallResponse = await fetch("/api/erosion/layers/rainfall-slope", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        area_type: areaType,
                        area_id: areaId,
                        start_year: startYear,
                        end_year: endYear,
                    }),
                });

                const rainfallData = await rainfallResponse.json();
                if (rainfallData.success && rainfallData.data) {
                    rainfallSlope = Number(rainfallData.data.mean ?? rainfallSlope);
                }

                const cvResponse = await fetch("/api/erosion/layers/rainfall-cv", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        area_type: areaType,
                        area_id: areaId,
                        start_year: startYear,
                        end_year: endYear,
                    }),
                });

                const cvData = await cvResponse.json();
                if (cvData.success && cvData.data) {
                    rainfallCV = Number(cvData.data.mean ?? rainfallCV);
                }
            } catch (err) {
                console.warn("Failed to fetch rainfall statistics:", err);
            }
        }

        const statisticsPayload = createStatisticsPayload(
            stats,
            rainfallSlope,
            rainfallCV
        );

        const componentSummaries = Array.isArray(data.data.components)
            ? data.data.components
            : [];

        const componentEntries = componentSummaries
            .map((component) => {
                if (
                    !component ||
                    !component.statistics ||
                    typeof component.area_id === "undefined"
                ) {
                    return null;
                }

                const componentAreaType =
                    component.area_type || (component.region_id ? "district" : areaType);
                const componentAreaId = component.area_id;
                const componentSlope = Number(
                    component.statistics.rainfall_slope ?? component.rainfall_slope ?? 0
                );
                const componentCv = Number(
                    component.statistics.rainfall_cv ?? component.rainfall_cv ?? 0
                );

                const componentArea = {
                    id: componentAreaId,
                    area_type: componentAreaType,
                    region_id: component.region_id ?? area.id,
                    name_en: component.name || component.name_en || area.name_en,
                    name_tj: component.name_tj || null,
                };

                return {
                    key: `${buildDetailedLayerKey(
                        componentAreaType,
                        componentAreaId,
                        startYear,
                        endYear
                    )}::component`,
                    area: componentArea,
                    areaType: componentAreaType,
                    periodLabel: currentPeriodLabel.value,
                    statistics: createStatisticsPayload(
                        component.statistics,
                        componentSlope,
                        componentCv
                    ),
                    isComponent: true,
                    parentAreaId:
                        component.parent_area_id ?? area.id ?? component.region_id ?? null,
                    parentAreaName:
                        component.parent_area_name ?? area.name_en ?? area.name ?? null,
                };
            })
            .filter(Boolean);

        return {
            key: buildDetailedLayerKey(areaType, areaId, startYear, endYear),
            area,
            areaType,
            periodLabel: currentPeriodLabel.value,
            statistics: statisticsPayload,
            components: componentEntries,
        };
    } catch (error) {
        console.error("Failed to load area statistics:", error);
        showToast(
            "error",
            "Statistics Calculation Failed",
            `Could not calculate statistics for ${area.name_en || area.name}.`,
            error.message
        );
        return null;
    }
};

const handleRegionChange = (region) => {
    selectedRegion.value = region;
    selectedDistrict.value = null;
    selectedArea.value = region;
    markAnalysisDirty();

    if (mapView.value && region?.area_type !== 'country') {
        mapView.value.resetDistrictHighlighting();
    }
};

const handleDistrictChange = (district) => {
    selectedArea.value = district;
    markAnalysisDirty();

    if (mapView.value && district) {
        mapView.value.highlightDistrict(district.name_en || district.name);
    }
};

const handleDistrictClicked = (districtData) => {
    console.log("District clicked:", districtData);

    // Find the district in our districts list
    const district = districts.value.find(
        (d) =>
            d.id === districtData.id ||
            d.name_en === districtData.name_en ||
            d.name_en === districtData.shapeName ||
            d.name_tj === districtData.shapeName
    );

    if (district) {
        selectedDistrict.value = district;
        selectedRegion.value = null; // Clear region selection when district is selected
        selectedArea.value = district;

        // Update selected areas for highlighting
        selectedAreas.value = [district];

        markAnalysisDirty();

        // Show bottom panel if hidden
        if (!bottomPanelVisible.value) {
            bottomPanelVisible.value = true;
        }

        if (mapView.value) {
            mapView.value.highlightSelectedAreas([district]);
        }
    }
};

const handleRegionClicked = (regionData) => {
    console.log("Region clicked:", regionData);

    // Find the region in our regions list
    const region = regions.value.find(
        (r) =>
            r.id === regionData.id ||
            r.name_en === regionData.name_en ||
            r.name_tj === regionData.name_tj
    );

    if (region) {
        selectedRegion.value = region;
        selectedDistrict.value = null; // Clear district selection when region is selected
        selectedArea.value = region;

        // Update selected areas for highlighting
        selectedAreas.value = [region];

        markAnalysisDirty();

        // Show bottom panel if hidden
        if (!bottomPanelVisible.value) {
            bottomPanelVisible.value = true;
        }

        if (mapView.value) {
            mapView.value.highlightSelectedAreas([region]);
            mapView.value.updateRegionLayer(region);
        }
    }
};

const handleAreaToggleSelection = (areaData) => {
    console.log("Area toggle selection:", areaData);

    // Determine if this is a district or region
    const isDistrict = areaData.region_id !== undefined
    
    // Find the area in our lists
    const area = isDistrict
        ? districts.value.find(
            (d) =>
                d.id === areaData.id ||
                d.name_en === areaData.name_en ||
                d.name_tj === areaData.name_tj
        )
        : regions.value.find(
            (r) =>
                r.id === areaData.id ||
                r.name_en === areaData.name_en ||
                r.name_tj === areaData.name_tj
        );

    if (area) {
        // Check if area is already selected
        const isAlreadySelected = selectedAreas.value.some(
            (a) => a.id === area.id && a.region_id === area.region_id
        );

        if (isAlreadySelected) {
            // Remove from selection
            selectedAreas.value = selectedAreas.value.filter(
                (a) => !(a.id === area.id && a.region_id === area.region_id)
            );
            
            // Update individual selections
            if (isDistrict) {
                selectedDistrict.value = null;
            } else {
                selectedRegion.value = null;
            }
        } else {
            // Add to selection
            selectedAreas.value = [...selectedAreas.value, area];
            
            // Update individual selections (use the last selected as primary)
            if (isDistrict) {
                selectedDistrict.value = area;
                selectedRegion.value = null;
            } else {
                selectedRegion.value = area;
                selectedDistrict.value = null;
            }
        }

        // Update selectedArea to first selected for backward compatibility
        if (selectedAreas.value.length > 0) {
            selectedArea.value = selectedAreas.value[0];
        } else {
            selectedArea.value = null;
            statistics.value = null;
        }

        markAnalysisDirty();

        // Highlight selected areas
        if (mapView.value) {
            mapView.value.highlightSelectedAreas(selectedAreas.value);
        }
    }
};

const handleAreaReplaceSelection = (areaData) => {
    // This behaves same as regular click without shift
    const isDistrict = areaData.region_id !== undefined
    
    if (isDistrict) {
        handleDistrictClicked(areaData);
    } else {
        handleRegionClicked(areaData);
    }
};

const handleBoundaryViolation = () => {
    console.log("Boundary violation detected");
    showToast(
        "warning",
        "Selection Outside Boundary",
        "Please select areas within Tajikistan boundaries.",
        ""
    );
};

const handleLayerWarning = (warningData) => {
    console.log("Layer warning:", warningData);
    showToast(
        warningData.type || "info",
        warningData.title || "Warning",
        warningData.message || "",
        warningData.details || ""
    );
};

const handlePeriodChange = (period) => {
    if (period) {
        selectedPeriod.value = { ...period };
    }
    markAnalysisDirty();
};

const handleLayerToggle = (layerId, visible) => {
    if (visible) {
        // Add the layer to visible layers (support multiple layers)
        if (!visibleLayers.value.includes(layerId)) {
            visibleLayers.value = [...visibleLayers.value, layerId];
        }
    } else {
        // Remove the layer from visible layers
        visibleLayers.value = visibleLayers.value.filter(id => id !== layerId);
    }

    markAnalysisDirty();
};

const handleLabelsToggle = (visible) => {
    showLabels.value = visible;

    // Toggle labels on the map
    if (mapView.value) {
        mapView.value.toggleLabels(visible);
    }
};

const selectAllAvailableAreas = () => {
    console.log("Auto-selecting all available areas from GeoJSON data");

    const allAreas = [];

    // Add all regions
    if (regions.value && regions.value.length > 0) {
        regions.value.forEach((region) => {
            if (region.geometry) {
                allAreas.push({ ...region, type: "region" });
            }
        });
        console.log(`Added ${regions.value.length} regions`);
    }

    // Add all districts
    if (districts.value && districts.value.length > 0) {
        districts.value.forEach((district) => {
            if (district.geometry) {
                allAreas.push({ ...district, type: "district" });
            }
        });
        console.log(`Added ${districts.value.length} districts`);
    }

    // Update selected areas
    selectedAreas.value = allAreas;

    // Update selectedArea to the first area for backward compatibility
    if (allAreas.length > 0) {
        selectedArea.value = allAreas[0];
    }

    console.log(`Auto-selected ${allAreas.length} total areas`);

    markAnalysisDirty();

    // Highlight all selected areas on the map
    if (mapView.value) {
        mapView.value.highlightSelectedAreas(allAreas);
    }
};

const handleAreasChange = (newSelectedAreas) => {
    console.log("Multiple areas selected:", newSelectedAreas);

    // Update selectedArea to the first area for backward compatibility
    if (newSelectedAreas.length > 0) {
        selectedArea.value = newSelectedAreas[0];
    } else {
        selectedArea.value = null;
        statistics.value = null;
    }

    // Store all selected areas
    selectedAreas.value = newSelectedAreas;
    markAnalysisDirty();

    // Highlight selected areas immediately for visual feedback
    if (mapView.value) {
        if (newSelectedAreas.length > 0) {
            mapView.value.highlightSelectedAreas(newSelectedAreas);
        } else {
            mapView.value.clearAreaHighlights();
        }
    }
};

const handleStatisticsUpdated = (stats) => {
    statistics.value = stats;
};

// Helper function to get erosion rate color class
const getErosionRateClass = (rate) => {
    const erosionRate = parseFloat(rate);
    if (erosionRate < 5) return "text-green-600"; // Very Low
    if (erosionRate < 15) return "text-yellow-600"; // Low
    if (erosionRate < 30) return "text-orange-600"; // Moderate
    if (erosionRate < 50) return "text-red-600"; // Severe
    return "text-red-900"; // Excessive
};

// Helper function to get risk level background class
const getRiskLevelBgClass = (level) => {
    switch (level) {
        case "Very Low":
            return "bg-green-100 text-green-800";
        case "Low":
            return "bg-yellow-100 text-yellow-800";
        case "Moderate":
            return "bg-orange-100 text-orange-800";
        case "Severe":
            return "bg-red-100 text-red-800";
        case "Excessive":
            return "bg-red-900 text-white";
        default:
            return "bg-gray-100 text-gray-800";
    }
};

// Determine risk level from erosion rate
const getRiskLevel = (rate) => {
    const erosionRate = parseFloat(rate);
    if (erosionRate < 5) return "Very Low";
    if (erosionRate < 15) return "Low";
    if (erosionRate < 30) return "Moderate";
    if (erosionRate < 50) return "Severe";
    return "Excessive";
};

const loadErosionData = async () => {
    // Only load data if a layer is active
    if (visibleLayers.value.length === 0) {
        console.log("No layers active, skipping data load");
        return;
    }

    // Clear all previous layer colors and area highlights before loading new data
    if (mapView.value) {
        console.log(
            "Clearing previous layer colors and area highlights before loading new data"
        );
        mapView.value.clearAllLayerColors();
        mapView.value.clearAreaHighlights();
    }

    loading.value = true;
    progress.value = 0;
    loadingMessage.value = "Loading erosion data...";

    try {
        // Simulate progress updates
        progress.value = 25;
        loadingMessage.value = "Preparing analysis...";

        let requestBody;
        if (selectedArea.value) {
            if (selectedArea.value.area_type === "country") {
                // Country selected
                requestBody = {
                    area_type: "country",
                    area_id: 0,
                    start_year: currentStartYear.value,
                    end_year: currentEndYear.value,
                    period: "annual",
                };
            } else {
                // Specific area selected (region or district)
                requestBody = {
                    area_type: selectedArea.value.region_id
                        ? "district"
                        : "region",
                    area_id: selectedArea.value.id,
                    start_year: currentStartYear.value,
                    end_year: currentEndYear.value,
                    period: "annual",
                };
            }
        } else {
            // No area selected - country-wide data
            requestBody = {
                area_type: "country",
                area_id: 0,
                start_year: currentStartYear.value,
                end_year: currentEndYear.value,
                period: "annual",
            };
        }

        const response = await fetch("/api/erosion/compute", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector(
                    'meta[name="csrf-token"]'
                ).content,
            },
            body: JSON.stringify(requestBody),
        });

        progress.value = 75;
        loadingMessage.value = "Processing data...";

        const data = await response.json();

        // Check for GEE configuration error
        if (!data.success) {
            if (response.status === 503) {
                showToast(
                    "error",
                    "GEE Not Configured",
                    data.error,
                    data.details
                );
            } else {
                showToast(
                    "error",
                    "Computation Error",
                    data.error,
                    data.details
                );
            }
            return;
        }

        if (data.success) {
            // Update map with new data
            mapView.value?.updateErosionData(data.data);

            // Extract erosion rate and update district coloring
            if (data.data && data.data.statistics) {
                const stats = data.data.statistics;
                const erosionRate = parseFloat(stats.mean_erosion_rate) || 0;

                // Update the district color based on erosion rate (only for specific areas)
                if (
                    selectedArea.value &&
                    selectedArea.value.id &&
                    mapView.value
                ) {
                    mapView.value.updateDistrictErosionData(
                        selectedArea.value.id,
                        erosionRate
                    );
                }

                // Update statistics display with comprehensive data
                statistics.value = {
                    meanErosionRate: erosionRate.toFixed(2),
                    minErosionRate: (stats.min_erosion_rate || 0).toFixed(2),
                    maxErosionRate: (stats.max_erosion_rate || 0).toFixed(2),
                    erosionCV: (stats.erosion_cv || 0).toFixed(1),
                    bareSoilFrequency: (stats.bare_soil_frequency || 0).toFixed(
                        1
                    ),
                    sustainabilityFactor: (
                        stats.sustainability_factor || 0
                    ).toFixed(2),
                    rainfallSlope: (stats.rainfall_slope || 0).toFixed(2),
                    rainfallCV: (stats.rainfall_cv || 0).toFixed(1),
                    districtCount: selectedArea.value
                        ? selectedArea.value.region_id
                            ? 1
                            : null
                        : null,
                    riskLevel: getRiskLevel(erosionRate),
                    severityDistribution: stats.severity_distribution || [],
                    rusleFactors: stats.rusle_factors || {},
                    topErodingAreas: stats.top_eroding_areas || [],
                    areaType: selectedArea.value
                        ? selectedArea.value.region_id
                            ? "district"
                            : "region"
                        : "country",
                    areaName: selectedArea.value
                        ? selectedArea.value.name_en
                        : "Tajikistan",
                };
            }

            progress.value = 100;
            loadingMessage.value = "Complete!";
        }
    } catch (error) {
        console.error("Failed to load erosion data:", error);
        showToast(
            "error",
            "Data Loading Failed",
            "Could not load erosion data for the selected area.",
            error.message
        );
    } finally {
        // Keep progress bar visible for a moment to show completion
        setTimeout(() => {
            loading.value = false;
            progress.value = 0;
            loadingMessage.value = "";
        }, 500);
    }
};

const analyzeGeometry = async (geometry) => {
    loading.value = true;
    progress.value = 0;
    loadingMessage.value = "Analyzing drawn shape...";

    try {
        progress.value = 50;
        loadingMessage.value = "Computing RUSLE factors...";

        const response = await fetch("/api/erosion/analyze-geometry", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector(
                    'meta[name="csrf-token"]'
                ).content,
            },
            body: JSON.stringify({
                geometry,
                start_year: currentStartYear.value,
                end_year: currentEndYear.value,
            }),
        });

        progress.value = 100;
        loadingMessage.value = "Analysis complete!";

        const data = await response.json();
        if (data.success && data.data && data.data.statistics) {
            const stats = data.data.statistics;
            const erosionRate = parseFloat(stats.mean_erosion_rate) || 0;

            // Update statistics with comprehensive data
            statistics.value = {
                meanErosionRate: erosionRate.toFixed(2),
                minErosionRate: (stats.min_erosion_rate || 0).toFixed(2),
                maxErosionRate: (stats.max_erosion_rate || 0).toFixed(2),
                erosionCV: (stats.erosion_cv || 0).toFixed(1),
                bareSoilFrequency: (stats.bare_soil_frequency || 0).toFixed(1),
                sustainabilityFactor: (
                    stats.sustainability_factor || 0
                ).toFixed(2),
                rainfallSlope: (stats.rainfall_slope || 0).toFixed(2),
                rainfallCV: (stats.rainfall_cv || 0).toFixed(1),
                riskLevel: getRiskLevel(erosionRate),
                severityDistribution: stats.severity_distribution || [],
                rusleFactors: stats.rusle_factors || {},
                topErodingAreas: stats.top_eroding_areas || [],
            };

            // Set selected area to the drawn shape
            selectedArea.value = {
                name: "Custom Drawn Area",
                name_en: "Custom Drawn Area",
            };
        }
    } catch (error) {
        console.error("Failed to analyze geometry:", error);
        showToast(
            "error",
            "Analysis Failed",
            "Could not analyze the drawn shape.",
            error.message
        );
    } finally {
        setTimeout(() => {
            loading.value = false;
            progress.value = 0;
            loadingMessage.value = "";
        }, 500);
    }
};

const login = async () => {
    loginLoading.value = true;
    try {
        const response = await fetch("/api/login", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector(
                    'meta[name="csrf-token"]'
                ).content,
            },
            body: JSON.stringify(loginForm),
        });

        if (response.ok) {
            showLogin.value = false;
            router.reload();
        }
    } catch (error) {
        console.error("Login failed:", error);
    } finally {
        loginLoading.value = false;
    }
};

const logout = async () => {
    try {
        await fetch("/api/logout", {
            method: "POST",
            headers: {
                "X-CSRF-TOKEN": document.querySelector(
                    'meta[name="csrf-token"]'
                ).content,
            },
        });
        router.reload();
    } catch (error) {
        console.error("Logout failed:", error);
    }
};

// Export map as PNG
const exportMapAsPNG = () => {
    if (!mapInstance.value) {
        showToast(
            "warning",
            "Map Not Ready",
            "Please wait for the map to load before exporting."
        );
        return;
    }

    loading.value = true;
    loadingMessage.value = "Preparing map export...";

    mapInstance.value.once("rendercomplete", () => {
        try {
            const mapCanvas = document.createElement("canvas");
            const size = mapInstance.value.getSize();
            mapCanvas.width = size[0];
            mapCanvas.height = size[1];
            const mapContext = mapCanvas.getContext("2d");

            // Get all canvas elements from the map
            const canvases = mapInstance.value
                .getViewport()
                .querySelectorAll(".ol-layer canvas, canvas.ol-layer");

            canvases.forEach((canvas) => {
                if (canvas.width > 0) {
                    const opacity =
                        canvas.parentNode.style.opacity || canvas.style.opacity;
                    mapContext.globalAlpha =
                        opacity === "" ? 1 : parseFloat(opacity);

                    const transform = canvas.style.transform;
                    const matrix = transform
                        .match(/^matrix\(([^\(]*)\)$/)?.[1]
                        .split(",")
                        .map(Number);

                    if (matrix) {
                        mapContext.setTransform(...matrix);
                    }

                    mapContext.drawImage(canvas, 0, 0);
                    mapContext.setTransform(1, 0, 0, 1, 0, 0);
                }
            });

            // Add title and metadata overlay
            mapContext.globalAlpha = 1;
            mapContext.fillStyle = "rgba(255, 255, 255, 0.9)";
            mapContext.fillRect(10, 10, 400, 80);
            mapContext.fillStyle = "#000";
            mapContext.font = "bold 18px Arial";
            mapContext.fillText("RUSLE Soil Erosion Map - Tajikistan", 20, 35);
            mapContext.font = "14px Arial";
            const areaName =
                selectedArea.value?.name ||
                selectedArea.value?.name_en ||
                "Country-wide";
            mapContext.fillText(`Area: ${areaName}`, 20, 55);
            mapContext.fillText(
                `Period: ${
                    currentPeriodLabel.value
                } | Date: ${new Date().toLocaleDateString()}`,
                20,
                75
            );

            // Convert to blob and download
            mapCanvas.toBlob((blob) => {
                const link = document.createElement("a");
                link.href = URL.createObjectURL(blob);
                link.download = `rusle-map-${
                    selectedArea.value?.name_en || "tajikistan"
                }-${currentPeriodLabel.value.replace(/\s+/g, "-")}-${
                    new Date().toISOString().split("T")[0]
                }.png`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

                loading.value = false;
                loadingMessage.value = "";
            });
        } catch (error) {
            console.error("Error exporting map:", error);
            showToast(
                "error",
                "Export Failed",
                "Could not export map as PNG.",
                error.message
            );
            loading.value = false;
            loadingMessage.value = "";
        }
    });

    // Trigger a render
    mapInstance.value.renderSync();
};

// Export statistics as CSV
const exportStatisticsCSV = () => {
    if (!areaStatistics.value.length) return;

    const csvData = [];

    csvData.push(["RUSLE Soil Erosion Statistics"]);
    csvData.push(["Generated:", new Date().toLocaleString()]);
    csvData.push([]);

    areaStatistics.value.forEach((entry, index) => {
        const areaName =
            entry.area?.name ||
            entry.area?.name_en ||
            `Area ${index + 1}`;

        csvData.push([`Area ${index + 1}`]);
        csvData.push(["Name", areaName]);
        csvData.push([
            "Type",
            entry.areaType
                ? entry.areaType.charAt(0).toUpperCase() + entry.areaType.slice(1)
                : "N/A",
        ]);
        csvData.push(["Period", entry.periodLabel || currentPeriodLabel.value]);

        if (entry.statistics?.riskLevel) {
            csvData.push(["Risk Level", entry.statistics.riskLevel]);
        }

        csvData.push([]);

        if (entry.statistics) {
            const stats = entry.statistics;
            const formatNumber = (value, digits = 2) => {
                if (value === null || value === undefined || isNaN(value)) {
                    return "0";
                }
                return Number(value).toFixed(digits);
            };

            csvData.push(["Erosion Metrics"]);
            csvData.push(["Metric", "Value", "Unit"]);
            csvData.push([
                "Mean Erosion Rate",
                formatNumber(stats.meanErosionRate, 2),
                "t/ha/yr",
            ]);
            csvData.push([
                "Min Erosion Rate",
                formatNumber(stats.minErosionRate, 2),
                "t/ha/yr",
            ]);
            csvData.push([
                "Max Erosion Rate",
                formatNumber(stats.maxErosionRate, 2),
                "t/ha/yr",
            ]);
            csvData.push([
                "Coefficient of Variation",
                formatNumber(stats.erosionCV, 1),
                "%",
            ]);
            csvData.push([]);

            csvData.push(["Rainfall Metrics"]);
            csvData.push(["Metric", "Value", "Unit"]);
            csvData.push([
                "Rainfall Trend",
                formatNumber(stats.rainfallSlope, 2),
                "% per year",
            ]);
            csvData.push([
                "Rainfall Variability",
                formatNumber(stats.rainfallCV, 1),
                "%",
            ]);
            csvData.push([]);

            if (stats.bareSoilFrequency !== undefined) {
                csvData.push([
                    "Bare Soil Frequency",
                    formatNumber(stats.bareSoilFrequency, 1),
                    "%",
                ]);
            }
            if (stats.sustainabilityFactor !== undefined) {
                csvData.push([
                    "Sustainability Factor",
                    formatNumber(stats.sustainabilityFactor, 2),
                    "",
                ]);
            }
            csvData.push([]);

            if (Array.isArray(stats.severityDistribution) && stats.severityDistribution.length) {
                csvData.push(["Area by Severity Class"]);
                csvData.push(["Class", "Area (ha)", "Percentage"]);
                stats.severityDistribution.forEach((item) => {
                    csvData.push([
                        item.class,
                        item.area ?? 0,
                        item.percentage !== undefined
                            ? `${formatNumber(item.percentage, 1)}%`
                            : "0.0%",
                    ]);
                });
                csvData.push([]);
            }

            if (Array.isArray(stats.topErodingAreas) && stats.topErodingAreas.length) {
                csvData.push(["Top Eroding Areas"]);
                csvData.push(["Name", "Erosion (t/ha/yr)"]);
                stats.topErodingAreas.forEach((area) => {
                    csvData.push([
                        area.name || area.name_en || area.name_tj || "Unknown",
                        area.erosion || area.erosion_rate || area.mean_erosion_rate || 0,
                    ]);
                });
                csvData.push([]);
            }

            if (stats.rusleFactors) {
                const factors = stats.rusleFactors;
                csvData.push(["RUSLE Factors"]);
                csvData.push(["Factor", "Value", "Unit"]);
                csvData.push([
                    "R-Factor (Rainfall Erosivity)",
                    factors.r ?? 0,
                    "MJ mm/(ha h yr)",
                ]);
                csvData.push([
                    "K-Factor (Soil Erodibility)",
                    factors.k ?? 0,
                    "t ha h/(ha MJ mm)",
                ]);
                csvData.push([
                    "LS-Factor (Topographic)",
                    factors.ls ?? 0,
                    "dimensionless",
                ]);
                csvData.push([
                    "C-Factor (Cover Management)",
                    factors.c ?? 0,
                    "0-1",
                ]);
                csvData.push([
                    "P-Factor (Support Practice)",
                    factors.p ?? 0,
                    "0-1",
                ]);
                csvData.push([]);
            }
        }

        if (index < areaStatistics.value.length - 1) {
            csvData.push([]);
        }
    });

    const csvString = csvData.map((row) => row.join(",")).join("\n");

    const blob = new Blob([csvString], { type: "text/csv;charset=utf-8;" });
    const link = document.createElement("a");
    const url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    link.setAttribute(
        "download",
        `rusle-statistics-${currentPeriodLabel.value.replace(/\s+/g, "-")}-${new Date()
            .toISOString()
            .split("T")[0]}.csv`
    );
    link.style.visibility = "hidden";
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
};

// Panel Resize Functions
const startLeftResize = (event) => {
    event.preventDefault();
    const startX = event.clientX;
    const startWidth = leftSidebarWidth.value;

    const onMouseMove = (e) => {
        const deltaX = e.clientX - startX;
        const maxWidth = window.innerWidth * 0.5; // Max 50% of screen width
        const newWidth = Math.max(250, Math.min(maxWidth, startWidth + deltaX));
        leftSidebarWidth.value = newWidth;

        // Update map size
        if (mapInstance.value) {
            setTimeout(() => mapInstance.value.updateSize(), 100);
        }
    };

    const onMouseUp = () => {
        document.removeEventListener("mousemove", onMouseMove);
        document.removeEventListener("mouseup", onMouseUp);
    };

    document.addEventListener("mousemove", onMouseMove);
    document.addEventListener("mouseup", onMouseUp);
};

const startBottomResize = (event) => {
    event.preventDefault();
    const startY = event.clientY;
    const startHeight = bottomPanelHeight.value;

    const onMouseMove = (e) => {
        const deltaY = startY - e.clientY;
        const maxHeight = window.innerHeight - 100; // Leave 100px for map visibility
        const newHeight = Math.max(
            200,
            Math.min(maxHeight, startHeight + deltaY)
        );
        bottomPanelHeight.value = newHeight;

        // Update map size since panel affects available space
        if (mapInstance.value) {
            setTimeout(() => mapInstance.value.updateSize(), 50);
        }
    };

    const onMouseUp = () => {
        document.removeEventListener("mousemove", onMouseMove);
        document.removeEventListener("mouseup", onMouseUp);

        // Final map size update
        if (mapInstance.value) {
            setTimeout(() => mapInstance.value.updateSize(), 100);
        }
    };

    document.addEventListener("mousemove", onMouseMove);
    document.addEventListener("mouseup", onMouseUp);
};

// Watchers for panel visibility changes
watch(leftSidebarVisible, () => {
    // Update map size when sidebar is toggled
    if (mapInstance.value) {
        setTimeout(() => mapInstance.value.updateSize(), 200);
    }
});

watch(bottomPanelVisible, () => {
    // Update map size when bottom panel is toggled
    if (mapInstance.value) {
        setTimeout(() => mapInstance.value.updateSize(), 200);
    }
});

watch(
    selectedBaseMapType,
    (type) => {
        if (!type || !mapInstance.value || !mapView.value?.setBaseMapType) {
            return;
        }

        const currentType = mapView.value?.getBaseMapType
            ? mapView.value.getBaseMapType()
            : null;

        if (currentType !== type) {
            mapView.value.setBaseMapType(type);
        }

        if (mapView.value?.toggleLabels) {
            mapView.value.toggleLabels(showLabels.value);
        }
    },
    { flush: "post" }
);

// Load erosion data for all districts (whole country)
const loadCountryWideData = async () => {
    if (!districts.value || districts.value.length === 0) {
        console.warn("No districts available for country-wide analysis");
        return;
    }

    loading.value = true;
    progress.value = 0;
    loadingMessage.value = "Loading country-wide erosion data...";

    try {
        let totalErosion = 0;
        let totalBareSoil = 0;
        let totalSustainability = 0;
        let validDistrictCount = 0;
        let processedCount = 0;

        // Load erosion data for each district
        const districtPromises = districts.value.map(async (district) => {
            try {
                const response = await fetch("/api/erosion/compute", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": document.querySelector(
                            'meta[name="csrf-token"]'
                        ).content,
                    },
                    body: JSON.stringify({
                        area_type: "district",
                        area_id: district.id,
                        start_year: currentStartYear.value,
                        end_year: currentEndYear.value,
                        period: "annual",
                    }),
                });

                const data = await response.json();

                if (data.success && data.data && data.data.statistics) {
                    const stats = data.data.statistics;
                    const erosionRate =
                        parseFloat(stats.mean_erosion_rate) || 0;

                    // Update district color on map
                    if (mapView.value) {
                        mapView.value.updateDistrictErosionData(
                            district.id,
                            erosionRate
                        );
                    }

                    // Aggregate statistics
                    totalErosion += erosionRate;
                    totalBareSoil += parseFloat(stats.bare_soil_frequency) || 0;
                    totalSustainability +=
                        parseFloat(stats.sustainability_factor) || 0;
                    validDistrictCount++;
                }

                processedCount++;
                progress.value = Math.round(
                    (processedCount / districts.value.length) * 100
                );
                loadingMessage.value = `Processing district ${processedCount}/${districts.value.length}...`;

                return data;
            } catch (error) {
                console.error(
                    `Failed to load data for district ${district.name_en}:`,
                    error
                );
                processedCount++;
                return null;
            }
        });

        // Wait for all districts to complete
        await Promise.all(districtPromises);

        // Calculate country-wide averages
        if (validDistrictCount > 0) {
            const avgErosion = (totalErosion / validDistrictCount).toFixed(2);

            statistics.value = {
                meanErosionRate: avgErosion,
                bareSoilFrequency: (totalBareSoil / validDistrictCount).toFixed(
                    1
                ),
                sustainabilityFactor: (
                    totalSustainability / validDistrictCount
                ).toFixed(2),
                districtCount: validDistrictCount,
                riskLevel: getRiskLevel(avgErosion),
            };
        }

        selectedArea.value = {
            name: "Tajikistan (Country-wide)",
            name_en: "Tajikistan",
        };

        progress.value = 100;
        loadingMessage.value = "Complete!";

        console.log(`Loaded erosion data for ${validDistrictCount} districts`);
    } catch (error) {
        console.error("Failed to load country-wide data:", error);
    } finally {
        setTimeout(() => {
            loading.value = false;
            progress.value = 0;
            loadingMessage.value = "";
        }, 800);
    }
};

// Lifecycle
onMounted(() => {
    // Prevent body scrolling
    document.body.style.overflow = "hidden";
    document.documentElement.style.overflow = "hidden";

    // Dushanbe will be auto-selected by RegionSelector component
    // Don't load data by default - wait for user to select a layer
    console.log("Application started - Dushanbe will be auto-selected, no layers active by default");
});

onUnmounted(() => {
    // Restore body scrolling
    document.body.style.overflow = "";
    document.documentElement.style.overflow = "";
});
</script>

<style scoped>
/* Ensure no overflow on main container */
:deep(body) {
    overflow: hidden;
}
</style>
