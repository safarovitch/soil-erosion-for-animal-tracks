<template>
    <div class="h-screen w-screen bg-slate-50 flex flex-col overflow-hidden">
        <!-- Progress Bar -->
        <ProgressBar
            :visible="loading"
            :progress="progress"
            :message="loadingMessage"
        />

        <!-- Toast Notifications (stacked) -->
        <div class="fixed top-16 right-4 z-50 flex flex-col space-y-3 pointer-events-none">
            <ToastNotification
                v-for="toastItem in toasts"
                :key="toastItem.id"
                :show="toastItem.show"
                :type="toastItem.type"
                :title="toastItem.title"
                :message="toastItem.message"
                :details="toastItem.details"
                :duration="toastItem.duration"
                @close="handleToastClose(toastItem.id)"
            />
        </div>

        <!-- Horizontal Toolbar -->
        <div class="bg-white border-b border-gray-200 shadow-sm px-4 py-2 flex items-center justify-between gap-4 flex-shrink-0 z-40">
            <!-- Left Section: Custom Area & Year Range -->
            <div class="flex items-center gap-3">
                <!-- Custom Area Button -->
                <button
                    type="button"
                    @click="handleCustomAreaToggle(!customAreaDrawing)"
                    :class="[
                        'px-4 py-2 rounded-lg text-sm font-medium transition-colors duration-200 flex items-center gap-2',
                        customAreaDrawing
                            ? 'bg-blue-600 text-white hover:bg-blue-700'
                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200 border border-gray-300'
                    ]"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l11.563-11.563z" />
                    </svg>
                    <span>{{ customAreaDrawing ? __('Drawing...') : __('Draw Area') }}</span>
                </button>

                <!-- Year Range Selector -->
                <div class="flex items-center gap-2">
                    <label class="text-sm font-medium text-gray-600">{{ __('Period') }}:</label>
                    <select
                        v-model="selectedPeriodId"
                        @change="handlePeriodSelectChange"
                        class="text-sm font-medium text-gray-700 border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white min-w-[160px]"
                    >
                        <option
                            v-for="period in yearPeriods"
                            :key="period.id"
                            :value="period.id"
                        >
                            {{ period.label }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Center Section: Action Buttons -->
            <div class="flex items-center gap-2">
                <button
                    @click="applySelection"
                    :disabled="!canApply"
                    class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors flex items-center gap-2"
                    :class="[
                        canApply
                            ? 'bg-blue-600 text-white hover:bg-blue-700'
                            : 'bg-gray-300 text-gray-500 cursor-not-allowed'
                    ]"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    {{ __('Apply') }}
                </button>
                <button
                    @click="clearSelection"
                    class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors bg-gray-100 text-gray-700 hover:bg-gray-200 border border-gray-300 flex items-center gap-2"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                    {{ __('Clear') }}
                </button>
                <button
                    @click="exportStatisticsReport"
                    :disabled="isExporting || !hasStatistics"
                    class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors flex items-center gap-2"
                    :class="[
                        hasStatistics && !isExporting
                            ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                            : 'bg-gray-300 text-gray-500 cursor-not-allowed'
                    ]"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    <span v-if="isExporting">{{ __('Exporting...') }}</span>
                    <span v-else>{{ __('Export PDF') }}</span>
                </button>
            </div>

            <!-- Right Section: Map & Language -->
            <div class="flex items-center gap-3">
                <!-- Map Style Selector -->
                <div class="flex items-center gap-2">
                    <label class="text-sm font-medium text-gray-600">{{ __('Map') }}:</label>
                    <select
                        v-model="selectedBaseMapType"
                        :disabled="!mapInstance || baseMapOptions.length <= 1"
                        class="text-sm font-medium text-gray-700 border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white disabled:opacity-50"
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

                <!-- Language Selector -->
                <div class="flex items-center gap-2">
                    <label class="text-sm font-medium text-gray-600">{{ __('Lang') }}:</label>
                    <select
                        v-model="selectedLanguage"
                        :disabled="isChangingLocale || !mapInstance || languageOptions.length <= 1"
                        class="text-sm font-medium text-gray-700 border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white disabled:opacity-50"
                    >
                        <option
                            v-for="option in languageOptions"
                            :key="option.id"
                            :value="option.id"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Main Content: Map and Statistics -->
        <div class="flex-1 flex flex-col overflow-hidden relative">
            <!-- Map Container -->
            <div class="flex-1 relative bg-gray-100 overflow-hidden">
                <div
                    v-if="!mapInstance"
                    class="flex items-center justify-center h-full"
                >
                    <div class="text-center">
                        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4"></div>
                        <p class="text-gray-700">{{ __('Loading map...') }}</p>
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
                    :custom-layers="customLayerDefinitions"
                    :custom-area-drawing="customAreaDrawing"
                    @map-ready="handleMapReady"
                    @statistics-updated="handleStatisticsUpdated"
                    @district-clicked="handleDistrictClicked"
                    @region-clicked="handleRegionClicked"
                    @geojson-loaded="handleGeoJSONLoaded"
                    @area-toggle-selection="handleAreaToggleSelection"
                    @area-replace-selection="handleAreaReplaceSelection"
                    @boundary-violation="handleBoundaryViolation"
                    @layer-warning="handleLayerWarning"
                    @custom-polygon-drawn="handleCustomPolygonDrawn"
                />

                <!-- Map Legend (Erosion layer is always visible) -->
                <MapLegend
                    :visible-layers="['erosion']"
                    :available-layers="availableLayers"
                />

                <!-- Expand Bottom Panel Button (when collapsed) -->
                <button
                    v-show="!bottomPanelVisible && hasStatistics"
                    @click="bottomPanelVisible = true"
                    class="absolute bottom-4 left-1/2 transform -translate-x-1/2 z-30 px-4 py-2 bg-white rounded-lg shadow-xl hover:bg-gray-50 flex items-center space-x-2"
                    title="Show statistics"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                    </svg>
                    <span>{{ __('Show Statistics') }}</span>
                </button>
            </div>

            <!-- Bottom Statistics Panel -->
            <div
                v-show="bottomPanelVisible"
                :style="{
                    height: bottomPanelHeight + 'px',
                    minHeight: '200px',
                    maxHeight: 'calc(100vh - 150px)',
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
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <!-- Scrollable Content Area -->
                <div ref="statisticsPanelRef" class="flex-1 overflow-y-auto p-6 pt-8">
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
                            {{ __('Erosion Risk Classification (RUSLE)') }}
                        </h4>
                        <div class="grid grid-cols-5 gap-2 text-xs">
                            <div class="text-center">
                                <div class="h-6 rounded mb-1" style="background-color: rgba(34, 139, 34, 0.6);"></div>
                                <div class="font-medium text-green-700">{{ __('Very Low') }}</div>
                                <div class="text-gray-600">{{ __('0-5 t/ha/yr') }}</div>
                            </div>
                            <div class="text-center">
                                <div class="h-6 rounded mb-1" style="background-color: rgba(255, 215, 0, 0.6);"></div>
                                <div class="font-medium text-yellow-700">{{ __('Low') }}</div>
                                <div class="text-gray-600">{{ __('5-15 t/ha/yr') }}</div>
                            </div>
                            <div class="text-center">
                                <div class="h-6 rounded mb-1" style="background-color: rgba(255, 140, 0, 0.6);"></div>
                                <div class="font-medium text-orange-700">{{ __('Moderate') }}</div>
                                <div class="text-gray-600">{{ __('15-30 t/ha/yr') }}</div>
                            </div>
                            <div class="text-center">
                                <div class="h-6 rounded mb-1" style="background-color: rgba(220, 20, 60, 0.6);"></div>
                                <div class="font-medium text-red-700">{{ __('Severe') }}</div>
                                <div class="text-gray-600">{{ __('30-50 t/ha/yr') }}</div>
                            </div>
                            <div class="text-center">
                                <div class="h-6 rounded mb-1" style="background-color: rgba(139, 0, 0, 0.8);"></div>
                                <div class="font-medium text-red-900">{{ __('Excessive') }}</div>
                                <div class="text-gray-600">{{ __('>50 t/ha/yr') }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted, nextTick, inject } from "vue";
import axios from "axios";
import { router, usePage } from "@inertiajs/vue3";
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
import { YEAR_PERIODS, DEFAULT_YEAR_PERIOD, findYearPeriodById } from "@/constants/yearPeriods.js";

// Props
const props = defineProps({
    user: Object,
    regions: Array,
    districts: Array,
});

const page = usePage();
const i18n = inject("i18n", null);
const inertiaLocale = computed(() => page.props?.locale || "en");

// Reactive data
const selectedRegion = ref(null);
const selectedDistrict = ref(null);
const selectedPeriod = ref({ ...DEFAULT_YEAR_PERIOD });
const selectedPeriodId = ref(DEFAULT_YEAR_PERIOD.id);
const yearPeriods = computed(() => YEAR_PERIODS);
const currentPeriod = computed(() => selectedPeriod.value || DEFAULT_YEAR_PERIOD);

// Handle period selection from dropdown
const handlePeriodSelectChange = () => {
    const period = findYearPeriodById(selectedPeriodId.value);
    selectedPeriod.value = period;
    handlePeriodChange(period);
};
const currentStartYear = computed(() => currentPeriod.value.startYear);
const currentEndYear = computed(() => currentPeriod.value.endYear);
const currentPeriodLabel = computed(() => currentPeriod.value.label);
const selectedArea = ref(null);
const selectedAreas = ref([]); // Multiple selected areas
const customAreaDrawing = ref(false);
const customAreaGeometry = ref(null);
const visibleLayers = ref(['erosion']); // Erosion layer always visible by default
const showLabels = ref(true); // Show map labels by default
const mapInstance = ref(null);
const mapView = ref(null);
const isChangingLocale = ref(false);
const baseMapOptions = ref([{ id: "osm", label: "OpenStreetMap" }]);
const selectedBaseMapType = ref("osm");
const statistics = ref(null);
const areaStatistics = ref([]);
const hasStatistics = computed(
    () => Boolean(statistics.value) || (Array.isArray(areaStatistics.value) && areaStatistics.value.length > 0)
);
const timeSeriesData = ref([]);
const statisticsPanelRef = ref(null);
const isExporting = ref(false);
const loading = ref(false);
const progress = ref(0);
const loadingMessage = ref("");
const showLogin = ref(false);
const loginLoading = ref(false);
const analysisTrigger = ref(0);
const needsApply = ref(true);

// Toast notifications (stacked)
const toasts = ref([]);
let toastCounter = 0;

const delay = (ms = 0) =>
    new Promise((resolve) => {
        setTimeout(resolve, ms);
    });

// Panel state
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

const languageOptions = ref([
    {
        id: "en",
        label: "English",
        keys: ["name_en", "name_tj", "name_ru", "name"],
    },
    {
        id: "ru",
        label: "Русский",
        keys: ["name_ru", "name_tj", "name_en", "name"],
    },
    {
        id: "tg",
        label: "Тоҷикӣ",
        keys: ["name_tj", "name_ru", "name_en", "name"],
    },
]);

const storedLanguage = typeof window !== "undefined" ? window.localStorage?.getItem("map_language") : null;
const resolveInitialLanguage = () => {
    const candidates = [
        storedLanguage,
        inertiaLocale.value,
        languageOptions.value[0]?.id,
    ];

    for (const candidate of candidates) {
        if (
            typeof candidate === "string" &&
            languageOptions.value.some((option) => option.id === candidate)
        ) {
            return candidate;
        }
    }

    return languageOptions.value[0]?.id || "en";
};
const selectedLanguage = ref(resolveInitialLanguage());

const currentLanguage = computed(() => {
    return languageOptions.value.find((option) => option.id === selectedLanguage.value) || languageOptions.value[0] || {
        id: "en",
        label: "English",
        keys: ["name_en", "name_tj", "name_ru", "name"],
    };
});

const languageKeys = computed(() => currentLanguage.value?.keys || ["name_en", "name_tj", "name_ru", "name"]);

const getLocalizedName = (entity, keys = languageKeys.value) => {
    if (!entity || typeof entity !== "object") {
        return "";
    }

    for (const key of keys) {
        const value = entity[key];
        if (typeof value === "string" && value.trim().length > 0) {
            return value.trim();
        }
    }

    return (
        entity.display_name ||
        entity.name_en ||
        entity.name_tj ||
        entity.name_ru ||
        entity.name ||
        (typeof entity.id !== "undefined" ? `Area ${entity.id}` : "Area")
    );
};

const applyDisplayName = (entity) => {
    if (entity && typeof entity === "object") {
        entity.display_name = getLocalizedName(entity);
    }
    return entity;
};

const refreshDisplayNames = () => {
    (regions.value || []).forEach((region) => applyDisplayName(region));
    (districts.value || []).forEach((district) => applyDisplayName(district));

    if (selectedArea.value) {
        applyDisplayName(selectedArea.value);
    }

    selectedAreas.value = selectedAreas.value.map((area) => applyDisplayName(area));

    areaStatistics.value = areaStatistics.value.map((entry) => {
        if (entry && typeof entry === "object") {
            if (entry.area) {
                applyDisplayName(entry.area);
            }
            if (Array.isArray(entry.components)) {
                entry.components.forEach((component) => {
                    if (component?.area) {
                        applyDisplayName(component.area);
                    }
                });
            }
        }
        return entry;
    });
};

watch(
    languageKeys,
    () => {
        refreshDisplayNames();
    },
    { immediate: true }
);


const canApply = computed(() => selectedAreas.value.length > 0 && !loading.value);

// Login form
const loginForm = reactive({
    email: "",
    password: "",
});

const buildDetailedLayerKey = (areaType, areaId, startYear, endYear) =>
    `${areaType}-${areaId}-${startYear}-${endYear}`;

const CUSTOM_LAYER_PREFIX = "custom_dataset_";

const baseAvailableLayers = Object.freeze([
    {
        id: "erosion",
        name: __("Soil Erosion Hazard"),
        description: "Annual soil loss rate (A = R×K×LS×C×P)",
        metadata: { unit: "t/ha/yr" },
    },
    {
        id: "rainfall_cv",
        name: __("Rainfall Variability"),
        description: "Coefficient of variation (%)",
        metadata: { colorScheme: "sequential" },
    },
    {
        id: "r_factor",
        name: __("R-Factor (Rainfall Erosivity)"),
        description: "Rainfall erosivity factor",
        metadata: { unit: "MJ mm/(ha h yr)" },
    },
    {
        id: "k_factor",
        name: __("K-Factor (Soil Erodibility)"),
        description: "Soil erodibility factor",
        metadata: { unit: "t ha h/(ha MJ mm)" },
    },
    {
        id: "ls_factor",
        name: __("LS-Factor (Topographic)"),
        description: "Slope length and steepness factor",
        metadata: { unit: "dimensionless" },
    },
    {
        id: "c_factor",
        name: __("C-Factor (Cover Management)"),
        description: "Cover and management factor",
        metadata: { unit: "dimensionless", range: "0-1" },
    },
    {
        id: "p_factor",
        name: __("P-Factor (Support Practice)"),
        description: "Support practice factor",
        metadata: { unit: "dimensionless", range: "0-1" },
    },
]);

const customDatasets = ref([]);

const customLayerDefinitions = computed(() =>
    customDatasets.value
        .filter((dataset) => dataset && dataset.tile_url_template)
        .map((dataset) => {
            const metadata =
                dataset.metadata && typeof dataset.metadata === "object"
                    ? dataset.metadata
                    : {};

            return {
                id: `${CUSTOM_LAYER_PREFIX}${dataset.id}`,
                datasetId: dataset.id,
                name: dataset.name || __(`Custom Dataset ${dataset.id}`),
                description: dataset.description || "User uploaded raster dataset",
                metadata,
                tileUrlTemplate: dataset.tile_url_template,
            };
        })
);

const availableLayers = computed(() => [
    ...baseAvailableLayers,
    ...customLayerDefinitions.value.map(
        ({ id, name, description, metadata }) => ({
            id,
            name,
            description,
            metadata,
        })
    ),
]);

watch(
    availableLayers,
    () => {
        if (!Array.isArray(availableLayers.value)) {
            return;
        }

        visibleLayers.value = visibleLayers.value.filter((layerId) =>
            availableLayers.value.some((layer) => layer.id === layerId)
        );
    },
    { deep: true }
);

const loadCustomDatasets = async () => {
    if (!props.user || props.user.role !== "admin") {
        customDatasets.value = [];
        return;
    }

    try {
        const response = await axios.get("/api/datasets");
        const payload = response.data?.data;
        customDatasets.value = Array.isArray(payload) ? payload : [];
    } catch (error) {
        if (error.response?.status === 401 || error.response?.status === 403) {
            customDatasets.value = [];
        } else {
            console.error(__("Failed to load custom datasets:", error));
        }
    }
};

watch(
    () => (props.user ? props.user.role : null),
    () => {
        loadCustomDatasets();
    },
    { immediate: true }
);

// Computed properties
const isAuthenticated = computed(
    () => props.user && props.user.role === "admin"
);

// Methods
const showToast = (type, title, message, details = "", options = {}) => {
    toastCounter += 1;
    const id = `toast-${Date.now()}-${toastCounter}`;

    const toastEntry = {
        id,
        type,
        title,
        message,
        details,
        duration: typeof options.duration === "number" ? options.duration : 0,
        show: true,
    };

    toasts.value = [...toasts.value, toastEntry];

    const maxToasts = options.maxVisible ?? 5;
    if (maxToasts > 0 && toasts.value.length > maxToasts) {
        const excess = toasts.value.length - maxToasts;
        const staleToasts = toasts.value.slice(0, excess);
        staleToasts.forEach((stale) => handleToastClose(stale.id));
    }
};

const handleToastClose = (id) => {
    const index = toasts.value.findIndex((toastItem) => toastItem.id === id);
    if (index === -1) {
        return;
    }

    toasts.value[index].show = false;

    // Allow leave transition to play before removing from array
    setTimeout(() => {
        toasts.value = toasts.value.filter((toastItem) => toastItem.id !== id);
    }, 350);
};

const changeApplicationLanguage = async (targetLocale, force = false) => {
    if (!i18n || !targetLocale) {
        return;
    }

    if (!force && i18n.locale === targetLocale) {
        return;
    }

    isChangingLocale.value = true;

    try {
        const response = await axios.post("/locale", { locale: targetLocale });
        const payload = response?.data || {};
        const resolvedLocale = payload.locale || targetLocale;
        const nextTranslations =
            payload.translations && typeof payload.translations === "object"
                ? payload.translations
                : {};

        if (typeof i18n.setLocale === "function") {
            i18n.setLocale(resolvedLocale, nextTranslations);
        } else if (typeof i18n.setTranslations === "function") {
            i18n.setTranslations(nextTranslations);
        }
    } catch (error) {
        console.error("Failed to switch locale:", error);
        showToast(
            "error",
            __("Language Switch Failed"),
            __("Unable to change the application language right now.")
        );
    } finally {
        isChangingLocale.value = false;
    }
};

watch(
    selectedLanguage,
    (newValue) => {
        if (typeof newValue === "string" && typeof window !== "undefined" && window.localStorage) {
            window.localStorage.setItem("map_language", newValue);
        }
    },
    { immediate: true }
);

let hasAttemptedLocaleSync = false;
watch(
    selectedLanguage,
    (newValue, oldValue) => {
        if (!newValue || !i18n) {
            return;
        }

        const currentLocale = i18n.locale || inertiaLocale.value;

        if (!hasAttemptedLocaleSync) {
            hasAttemptedLocaleSync = true;

            if (newValue !== currentLocale) {
                changeApplicationLanguage(newValue, true);
            }

            return;
        }

        if (newValue === oldValue) {
            return;
        }

        changeApplicationLanguage(newValue);
    },
    { immediate: true }
);

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
        analysisAreas.forEach((area) => applyDisplayName(area));

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
                    name_ru: "Таджикистан",
                    geometry: countryBoundary,
                },
            ];
            analysisAreas.forEach((area) => applyDisplayName(area));
        }

        const primaryArea = analysisAreas[0] || null;
        selectedArea.value = primaryArea ? applyDisplayName(primaryArea) : null;

        const customArea = analysisAreas.find(
            (area) => (area.area_type ?? area.type) === "custom"
        );

        if (customArea) {
            const areaWithGeometry = {
                ...customArea,
                geometry: customArea.geometry || customAreaGeometry.value || null,
            };

            if (!areaWithGeometry.geometry) {
                showToast(
                    "error",
                    __("Custom Area Missing Geometry"),
                    __("Please draw a polygon before running the analysis."),
                    "",
                    { duration: 7000 }
                );
                loading.value = false;
                progress.value = 0;
                loadingMessage.value = "";
                return;
            }

            applyDisplayName(areaWithGeometry);

            await computeStatisticsForCustomArea(areaWithGeometry);

            return;
        }

        loading.value = true;
        progress.value = 0;
        loadingMessage.value = __("Calculating RUSLE statistics...");

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

            results.forEach((entry) => {
                if (entry && typeof entry === "object") {
                    if (entry.area) {
                        applyDisplayName(entry.area);
                    }
                    if (Array.isArray(entry.components)) {
                        entry.components.forEach((component) => {
                            if (component?.area) {
                                applyDisplayName(component.area);
                            }
                        });
                    }
                }
            });

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

const waitForStatisticsPanelRender = async (panelEl, timeoutMs = 4000) => {
    if (!panelEl) {
        return false;
    }

    const start = performance.now();
    while (performance.now() - start < timeoutMs) {
        await nextTick();

        const contentReady =
            (panelEl.textContent || "").trim().length > 0 ||
            panelEl.querySelector("[data-statistics-card], table, .chart-container");

        const canvases = panelEl.querySelectorAll("canvas");
        const canvasesReady =
            canvases.length === 0 ||
            Array.from(canvases).every(
                (canvas) => canvas.width > 0 && canvas.height > 0
            );

        if (panelEl.offsetHeight > 0 && contentReady && canvasesReady) {
            return true;
        }

        await delay(150);
    }

    console.warn(__("Statistics panel did not finish rendering before export."));
    return false;
};

const exportStatisticsReport = async () => {
    if (isExporting.value) {
        return;
    }

    if (!hasStatistics.value) {
        showToast(
            "warning",
            __("Nothing to export"),
            __("Run a calculation first to generate statistics before exporting the report.")
        );
        return;
    }

    if (!mapView.value) {
        showToast("error", __("Export Failed"), __("The map is not ready yet. Please try again in a moment."));
        return;
    }

    let restoreBottomPanel = false;
    try {
        isExporting.value = true;

        if (!bottomPanelVisible.value) {
            bottomPanelVisible.value = true;
            restoreBottomPanel = true;
            await nextTick();
            await delay(300);
        }

        await nextTick();
        await new Promise((resolve) => window.requestAnimationFrame(resolve));

        const [{ jsPDF }, html2canvasModule, autoTableModule] = await Promise.all([
            import("jspdf"),
            import("html2canvas"),
            import("jspdf-autotable"),
        ]);
        const autoTable = autoTableModule.default ?? autoTableModule;
        const html2canvas = html2canvasModule.default ?? html2canvasModule;

        const mapCapture = await mapView.value.captureMapAsImage();
        if (!mapCapture?.dataUrl) {
            throw new Error(__("Unable to capture the map view."));
        }

        const panelEl = statisticsPanelRef.value;
        if (!panelEl) {
            throw new Error(__("Statistics panel is not available for export.") );
        }

        await waitForStatisticsPanelRender(panelEl);

        const captureStatisticsCanvas = async () => {
            const cloneWrapper = document.createElement("div");
            cloneWrapper.style.position = "fixed";
            cloneWrapper.style.top = "-99999px";
            cloneWrapper.style.left = "-99999px";
            cloneWrapper.style.width = "1400px";
            cloneWrapper.style.padding = "24px";
            cloneWrapper.style.background = "#ffffff";
            cloneWrapper.style.zIndex = "-1";
            cloneWrapper.style.opacity = "0";
            cloneWrapper.style.pointerEvents = "none";

            const clonedPanel = panelEl.cloneNode(true);
            clonedPanel.style.width = "100%";
            clonedPanel.style.maxWidth = "100%";
            cloneWrapper.appendChild(clonedPanel);
            document.body.appendChild(cloneWrapper);

            const originalCanvases = panelEl.querySelectorAll("canvas");
            const clonedCanvases = clonedPanel.querySelectorAll("canvas");
            clonedCanvases.forEach((canvas, index) => {
                const source = originalCanvases[index];
                if (!source || !canvas) return;
                const ctx = canvas.getContext("2d");
                if (ctx) {
                    ctx.drawImage(source, 0, 0, canvas.width || source.width, canvas.height || source.height);
                }
            });

            await new Promise((resolve) => window.requestAnimationFrame(resolve));

            const canvas = await html2canvas(cloneWrapper, {
                scale: 2,
                useCORS: true,
                backgroundColor: "#ffffff",
                scrollX: 0,
                scrollY: 0,
                width: cloneWrapper.offsetWidth,
                height: cloneWrapper.scrollHeight,
            });

            document.body.removeChild(cloneWrapper);
            return canvas;
        };

        const statsCanvas = await captureStatisticsCanvas();

        const statsImageData = statsCanvas.toDataURL("image/png");

        const doc = new jsPDF({
            orientation: "landscape",
            unit: "mm",
            format: "a4",
        });

        const now = new Date();
        const timestamp = new Intl.DateTimeFormat(undefined, {
            dateStyle: "medium",
            timeStyle: "short",
        }).format(now);

        const areaNames =
            selectedAreas.value.length > 0
                ? selectedAreas.value.map((area) => getLocalizedName(area)).join(", ")
                : selectedArea.value
                ? getLocalizedName(selectedArea.value)
                : "Not specified";

        const layerNames =
            visibleLayers.value.length > 0
                ? visibleLayers.value
                      .map(
                          (layerId) =>
                              availableLayers.value.find((layer) => layer.id === layerId)?.name ||
                              layerId
                      )
                      .join(", ")
                : __("Erosion (default)");

        const baseMapLabel =
            selectedBaseMapType.value === "terrain" ? __("MapTiler Terrain") : __("OpenStreetMap");

        const title = `${__("Tajikistan Soil Erosion Calculation")} – ${timestamp}`;
        const infoLines = [
            __("Calculation Parameters:"),
            __("• Selected Area(s):") + areaNames,
            __("• Period:") + currentPeriodLabel.value,
            __("• Resolution: 1 km"),
            __("• Visible Layer(s):") + layerNames,
            __("• Base Map:") + baseMapLabel,
        ];
        let pageWidth = doc.internal.pageSize.getWidth();
        let pageHeight = doc.internal.pageSize.getHeight();

        const primaryStatistics =
            statistics.value ||
            areaStatistics.value.find((entry) => entry?.statistics)?.statistics ||
            null;

        const rusleFactorsRaw =
            primaryStatistics?.rusleFactors ||
            primaryStatistics?.rusle_factors ||
            null;

        const factorEntries = rusleFactorsRaw
            ? Object.entries(rusleFactorsRaw)
                  .map(([key, factor]) => {
                      if (factor == null || typeof factor !== "object") {
                          return null;
                      }
                      const label = factor.label || key.toUpperCase();
                      const meanValue =
                          factor.mean ?? factor.value ?? factor.default_value ?? null;
                      const mean =
                          typeof meanValue === "number"
                              ? Number(meanValue).toFixed(
                                    Math.abs(meanValue) >= 1 ? 2 : 3
                                )
                              : meanValue ?? "—";
                      return {
                          label,
                          mean: mean.toString(),
                          unit: factor.unit || "",
                          description: factor.description || "",
                      };
                  })
                  .filter(Boolean)
            : [];

        const margin = 15;
        let cursorY = margin;

        doc.setFontSize(16);
        doc.text(title, margin, cursorY);

        doc.setFontSize(11);
        cursorY += 9;
        infoLines.forEach((line) => {
            doc.text(line, margin, cursorY);
            cursorY += 6;
        });

        const formatStatValue = (value, digits = 2) => {
            if (value === null || value === undefined) {
                return "—";
            }
            const num = Number(value);
            if (!Number.isFinite(num)) {
                return "—";
            }
            const absolute = Math.abs(num);
            const precision = absolute >= 100 ? 0 : absolute >= 10 ? 1 : digits;
            return num.toFixed(precision);
        };

        const collectStatisticsTableRows = () => {
            const rows = [];
            const seen = new Set();

            const pushRow = (entry) => {
                if (!entry?.statistics) {
                    return;
                }

                const stats = entry.statistics;
                const rawName =
                    entry.area?.display_name ||
                    getLocalizedName(entry.area) ||
                    entry.area?.name_en ||
                    entry.area?.name ||
                    entry.label ||
                    __("Area");

                const name =
                    typeof rawName === "string"
                        ? rawName.trim()
                        : String(rawName || __("Area")).trim();
                if (seen.has(name)) {
                    return;
                }
                seen.add(name);

                const mean =
                    stats.meanErosionRate ??
                    stats.mean_erosion_rate ??
                    stats.mean ??
                    null;
                const min =
                    stats.minErosionRate ??
                    stats.min_erosion_rate ??
                    stats.min ??
                    null;
                const max =
                    stats.maxErosionRate ??
                    stats.max_erosion_rate ??
                    stats.max ??
                    null;
                const rainfallTrend =
                    stats.rainfallSlope ??
                    stats.rainfall_slope ??
                    stats.rainfallTrend ??
                    stats.rainfall_trend ??
                    null;
                const rainfallCv =
                    stats.rainfallCV ??
                    stats.rainfall_cv ??
                    stats.rainfallVariability ??
                    stats.rainfall_variability ??
                    null;

                rows.push([
                    name,
                    formatStatValue(mean, 2),
                    formatStatValue(min, 2),
                    formatStatValue(max, 2),
                    formatStatValue(rainfallTrend, 2),
                    formatStatValue(rainfallCv, 1),
                ]);
            };

            areaStatistics.value.forEach((entry) => {
                pushRow(entry);
                if (Array.isArray(entry?.components)) {
                    entry.components.forEach((component) => pushRow(component));
                }
            });

            if (!rows.length && statistics.value) {
                pushRow({
                    area: selectedArea.value,
                    statistics: statistics.value,
                    label: __("Selected Area"),
                });
            }

            return rows;
        };

        const statisticsTableRows = collectStatisticsTableRows();

        pageWidth = doc.internal.pageSize.getWidth();
        pageHeight = doc.internal.pageSize.getHeight();
        const maxMapWidth = pageWidth - margin * 2;
        const mapAspectRatio =
            mapCapture.width && mapCapture.height
                ? mapCapture.width / mapCapture.height
                : 1.5;

        let mapWidth = maxMapWidth;
        let mapHeight = mapWidth / mapAspectRatio;
        const availableHeight = pageHeight - cursorY - margin;
        if (mapHeight > availableHeight && availableHeight > margin) {
            mapHeight = availableHeight;
            mapWidth = mapHeight * mapAspectRatio;
        }

        const mapX = (pageWidth - mapWidth) / 2;
        doc.addImage(mapCapture.dataUrl, "PNG", mapX, cursorY, mapWidth, mapHeight);

        doc.addPage("landscape");
        pageWidth = doc.internal.pageSize.getWidth();
        pageHeight = doc.internal.pageSize.getHeight();
        cursorY = margin;

        if (statisticsTableRows.length) {
            doc.setFontSize(12);
            doc.text(__("Erosion Statistics Summary"), margin, cursorY);
            cursorY += 6;

            autoTable(doc, {
                startY: cursorY,
                head: [
                    [
                        __("Area"),
                        __("Mean (t/ha/yr)"),
                        __("Min"),
                        __("Max"),
                        __("Rainfall Trend (%)"),
                        __("Rainfall CV (%)"),
                    ],
                ],
                body: statisticsTableRows,
                margin: { left: margin, right: margin },
                styles: {
                    fontSize: 9,
                    cellPadding: 2.5,
                },
                headStyles: {
                    fillColor: [23, 63, 95],
                    textColor: 255,
                },
                alternateRowStyles: {
                    fillColor: [245, 248, 252],
                },
                theme: "striped",
                didDrawPage: () => {
                    pageWidth = doc.internal.pageSize.getWidth();
                    pageHeight = doc.internal.pageSize.getHeight();
                },
            });

            cursorY = (doc.lastAutoTable?.finalY ?? cursorY) + 8;
        }

        if (factorEntries.length) {
            if (cursorY + 40 > pageHeight - margin) {
                doc.addPage("landscape");
                cursorY = margin;
                pageWidth = doc.internal.pageSize.getWidth();
                pageHeight = doc.internal.pageSize.getHeight();
            }

            doc.setFontSize(12);
            doc.text(__("RUSLE Factors Summary"), margin, cursorY);
            cursorY += 7;

            const columnX = [margin, margin + 45, margin + 85, margin + 120];
            doc.setFontSize(10);
            doc.text(__("Factor"), columnX[0], cursorY);
            doc.text(__("Mean"), columnX[1], cursorY);
            doc.text(__("Unit"), columnX[2], cursorY);
            doc.text(__("Description"), columnX[3], cursorY);
            cursorY += 5;

            factorEntries.forEach((factor) => {
                if (cursorY + 10 > pageHeight - margin) {
                    doc.addPage("landscape");
                    cursorY = margin;
                    pageWidth = doc.internal.pageSize.getWidth();
                    pageHeight = doc.internal.pageSize.getHeight();
                    doc.setFontSize(10);
                    doc.text(__("Factor"), columnX[0], cursorY);
                    doc.text(__("Mean"), columnX[1], cursorY);
                    doc.text(__("Unit"), columnX[2], cursorY);
                    doc.text("Description", columnX[3], cursorY);
                    cursorY += 5;
                }

                doc.text(factor.label || "—", columnX[0], cursorY);
                doc.text(factor.mean || "—", columnX[1], cursorY);
                doc.text(factor.unit || "—", columnX[2], cursorY);

                if (factor.description) {
                    const descLines = doc.splitTextToSize(
                        factor.description,
                        pageWidth - columnX[3] - margin
                    );
                    doc.text(descLines, columnX[3], cursorY);
                    cursorY += Math.max(6, descLines.length * 5);
                } else {
                    doc.text("—", columnX[3], cursorY);
                    cursorY += 6;
                }
            });

            cursorY += 4;
        }

        doc.addPage("landscape");
        pageWidth = doc.internal.pageSize.getWidth();
        pageHeight = doc.internal.pageSize.getHeight();

        const statsAspectRatio =
            statsCanvas.width && statsCanvas.height
                ? statsCanvas.width / statsCanvas.height
                : 1.5;
        let statsWidth = pageWidth - margin * 2;
        let statsHeight = statsWidth / statsAspectRatio;
        const statsAvailableHeight = pageHeight - (margin + 10) - margin;
        if (statsHeight > statsAvailableHeight) {
            statsHeight = statsAvailableHeight;
            statsWidth = statsHeight * statsAspectRatio;
        }

        doc.addImage(
            statsImageData,
            "PNG",
            margin,
            margin + 8,
            statsWidth,
            statsHeight
        );

        const filename = `soil-erosion-report_${now
            .toISOString()
            .replace(/[:.]/g, "-")}.pdf`;
        doc.save(filename);

        showToast(
            "success",
            __("Export Ready"),
            __("PDF report downloaded successfully.")
        );
    } catch (error) {
        console.error(__("Failed to export PDF:"), error);
        showToast(
            "error",
            __("Export Failed"),
            error?.message || __("An unexpected error occurred while generating the report.")
        );
    } finally {
        if (restoreBottomPanel) {
            bottomPanelVisible.value = false;
        }

        isExporting.value = false;
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
    console.log(__("GeoJSON loaded, now loading districts data..."));
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
        console.log(__("Loading districts from GeoJSON for select boxes..."));

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
        console.log(`${__("Total districts available:")} ${combinedDistricts.length}`);

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
        console.log(`${__("Total regions available:")} ${regions.value.length}`);

        refreshDisplayNames();
    } catch (error) {
        console.warn(__("Could not load districts from GeoJSON:"), error.message);
    }
};

const handleAreaTypeChange = (areaType) => {
    // This function is deprecated - area type is no longer used
    // Areas are now managed through region/district selection only
    console.log(__("handleAreaTypeChange called but no longer used"));
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
        minErosionRate: Number(rawStats.min_erosion_rate ?? rawStats.min_erosion ?? rawStats.min ?? 0),
        maxErosionRate: Number(rawStats.max_erosion_rate ?? rawStats.max_erosion ?? rawStats.max ?? 0),
        erosionCV: Number(rawStats.erosion_cv ?? rawStats.cv ?? 0),
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

        loadingMessage.value = `${__("Calculating RUSLE statistics")} (${index + 1}/${total})...`;

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
                    loadingMessage.value = `${__("Loading cached RUSLE statistics")} (${index + 1}/${total})...`;
                    if (Array.isArray(availability.components)) {
                        cachedComponents = availability.components;
                    }
                }
            } catch (error) {
                console.warn(
                    __("Cached statistics check failed, falling back to live computation:"),
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
                        name_en: component.name || component.name_en || getLocalizedName(area),
                        name_tj: component.name_tj || null,
                    };
                    applyDisplayName(componentArea);

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
                            getLocalizedName(area) ??
                            null,
                    };
                })
                .filter(Boolean);

        applyDisplayName(area);

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

        const rawBody = await response.text();
        let data = {};

        if (rawBody) {
            try {
                data = JSON.parse(rawBody);
            } catch (parseError) {
                console.error(__("Statistics response was not valid JSON:"), {
                    status: response.status,
                    bodySnippet: rawBody.slice(0, 500),
                    error: parseError,
                });
                throw new Error(
                    `${__("Unexpected response (status")} ${response.status}).` +
                    __(" Server returned non-JSON content.")
                );
            }
        }

        if (!data.success || !data.data || !data.data.statistics) {
            throw new Error(data.error || __("Failed to compute statistics"));
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
                console.warn(__("Failed to fetch rainfall statistics:"), err);
            }
        }

        const statisticsPayload = createStatisticsPayload(
            stats,
            rainfallSlope,
            rainfallCV
        );

        applyDisplayName(area);

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
                    name_en: component.name || component.name_en || getLocalizedName(area),
                    name_tj: component.name_tj || null,
                };
                applyDisplayName(componentArea);

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
                        component.parent_area_name ?? getLocalizedName(area) ?? null,
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
        console.error(__("Failed to load area statistics:"), error);
        showToast(
            "error",
            __("Statistics Calculation Failed"),
            `${__("Could not calculate statistics for")} ${getLocalizedName(area)}.`,
            error.message
        );
        return null;
    }
};

const handleRegionChange = (region) => {
    selectedRegion.value = region ? applyDisplayName(region) : null;
    selectedDistrict.value = null;
    selectedArea.value = region ? applyDisplayName(region) : null;
    markAnalysisDirty();

    if (mapView.value && region?.area_type !== 'country') {
        mapView.value.resetDistrictHighlighting();
    }
};

const handleDistrictChange = (district) => {
    selectedArea.value = district ? applyDisplayName(district) : null;
    markAnalysisDirty();

    if (mapView.value && district) {
        mapView.value.highlightDistrict(district.name_en || district.name);
    }
};

const handleCustomAreaToggle = (isActive) => {
    customAreaDrawing.value = isActive;
    
    // Clear region/district selections when custom area is activated
    if (isActive) {
        selectedRegion.value = null;
        selectedDistrict.value = null;
        selectedArea.value = null;
        selectedAreas.value = [];
    }
    
    // Enable/disable polygon drawing in MapView
    if (mapView.value) {
        if (isActive) {
            mapView.value.enableCustomPolygonDrawing();
        } else {
            mapView.value.disableCustomPolygonDrawing();
            customAreaGeometry.value = null;
        }
    }
};

const handleCustomPolygonDrawn = (geometry) => {
    if (!geometry) return;

    customAreaGeometry.value = geometry;

    const customArea = {
        id: null,
        type: "custom",
        area_type: "custom",
        name_en: "Custom Area",
        name_tj: "Минтақаи фардӣ",
        geometry,
        cacheKey:
            window.crypto?.randomUUID?.() ??
            `custom-${Date.now().toString(36)}-${Math.random()
                .toString(36)
                .slice(2, 8)}`,
    };

    selectedArea.value = customArea;
    selectedAreas.value = [customArea];

    // Clear previous statistics until the user applies the new selection
    statistics.value = null;
    areaStatistics.value = [];
    timeSeriesData.value = [];

    markAnalysisDirty();

    showToast(
        "info",
        __("Custom Area Ready"),
        __("Polygon captured. Click Apply Selection to run the analysis."),
        "",
        { duration: 6000 }
    );
};

const computeStatisticsForCustomArea = async (customArea, options = {}) => {
    const { suppressToast = false } = options;

    if (!customArea || !customArea.geometry) return;
    
    loading.value = true;
    progress.value = 0;
    loadingMessage.value = __("Calculating RUSLE statistics for custom area...");
    
    try {
        const startYear = currentStartYear.value;
        const endYear = currentEndYear.value;
        
        // Call compute endpoint with custom geometry
        const response = await axios.post("/api/erosion/compute", {
            area_type: 'custom',
            area_id: null,
            geometry: customArea.geometry, // GeoJSON geometry
            start_year: startYear,
            end_year: endYear,
            period: "annual",
        });
        
        const data = response.data || {};
        
        if (!data.success || !data.data || !data.data.statistics) {
            throw new Error(data.error || "Failed to compute statistics");
        }
        
        const stats = data.data.statistics;
        const meanErosionRate = Number(stats.mean_erosion_rate ?? 0);
        let rainfallSlope = Number(stats.rainfall_slope ?? 0);
        let rainfallCV = Number(stats.rainfall_cv ?? 0);
        
        // Fetch rainfall statistics if not included
        if ((!rainfallSlope && !rainfallCV) || rainfallSlope === 0) {
            try {
                const rainfallResponse = await axios.post("/api/erosion/layers/rainfall-slope", {
                    area_type: 'custom',
                    area_id: null,
                    geometry: customArea.geometry,
                    start_year: startYear,
                    end_year: endYear,
                });
                
                const rainfallData = rainfallResponse.data || {};
                if (rainfallData.success && rainfallData.data) {
                    rainfallSlope = Number(rainfallData.data.mean ?? rainfallSlope);
                }
                
                const cvResponse = await axios.post("/api/erosion/layers/rainfall-cv", {
                    area_type: 'custom',
                    area_id: null,
                    geometry: customArea.geometry,
                    start_year: startYear,
                    end_year: endYear,
                });
                
                const cvData = cvResponse.data || {};
                if (cvData.success && cvData.data) {
                    rainfallCV = Number(cvData.data.mean ?? rainfallCV);
                }
            } catch (err) {
                console.warn("Failed to fetch rainfall statistics:", err);
            }
        }
        
        // Create statistics payload similar to loadAreaStatistics
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
                minErosionRate: Number(rawStats.min_erosion_rate ?? rawStats.min_erosion ?? rawStats.min ?? 0),
                maxErosionRate: Number(rawStats.max_erosion_rate ?? rawStats.max_erosion ?? rawStats.max ?? 0),
                erosionCV: Number(rawStats.erosion_cv ?? rawStats.cv ?? 0),
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
        
        const statisticsPayload = createStatisticsPayload(
            stats,
            rainfallSlope,
            rainfallCV
        );
        
        const periodLabel = startYear === endYear
            ? `${startYear}`
            : `${startYear}-${endYear}`;
        
        const areaEntry = {
            area: applyDisplayName({ ...customArea }),
            areaType: 'custom',
            periodLabel,
            statistics: statisticsPayload,
        };
        
        areaStatistics.value = [areaEntry];
        statistics.value = statisticsPayload;
        
        progress.value = 100;
        loadingMessage.value = "Statistics calculated successfully";
        
        if (!suppressToast) {
            showToast("success", __("Statistics Calculated"), 
                `${__("Erosion statistics calculated for custom area")} (${periodLabel})`,
                `${__("Mean erosion rate:")} ${statisticsPayload.meanErosionRate.toFixed(2)} t/ha/yr`,
                { duration: 5000 }
            );
        }

        needsApply.value = false;

        return statisticsPayload;
        
    } catch (error) {
        console.error("Failed to compute statistics for custom area:", error);
        showToast("error", __("Statistics Calculation Failed"),
            __("Could not calculate statistics for custom area."),
            error.message || "Unknown error occurred",
            { duration: 10000 }
        );
    } finally {
        loading.value = false;
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
        const localizedDistrict = applyDisplayName(district);
        selectedDistrict.value = localizedDistrict;
        selectedRegion.value = null; // Clear region selection when district is selected
        selectedArea.value = localizedDistrict;

        // Update selected areas for highlighting
        selectedAreas.value = [localizedDistrict];

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
        const localizedRegion = applyDisplayName(region);
        selectedRegion.value = localizedRegion;
        selectedDistrict.value = null; // Clear district selection when region is selected
        selectedArea.value = localizedRegion;

        // Update selected areas for highlighting
        selectedAreas.value = [localizedRegion];

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
        const localizedArea = applyDisplayName(area);

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
            selectedAreas.value = [...selectedAreas.value, localizedArea];
            
            // Update individual selections (use the last selected as primary)
            if (isDistrict) {
                selectedDistrict.value = localizedArea;
                selectedRegion.value = null;
            } else {
                selectedRegion.value = localizedArea;
                selectedDistrict.value = null;
            }
        }

        // Update selectedArea to first selected for backward compatibility
        if (selectedAreas.value.length > 0) {
            selectedArea.value = applyDisplayName(selectedAreas.value[0]);
        } else {
            selectedArea.value = null;
            statistics.value = null;
        }

        selectedAreas.value = selectedAreas.value.map((area) => applyDisplayName(area));

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
        warningData.title || __("Warning"),
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
                const localizedRegion = applyDisplayName({ ...region, type: "region" });
                allAreas.push(localizedRegion);
            }
        });
        console.log(`Added ${regions.value.length} regions`);
    }

    // Add all districts
    if (districts.value && districts.value.length > 0) {
        districts.value.forEach((district) => {
            if (district.geometry) {
                const localizedDistrict = applyDisplayName({
                    ...district,
                    type: "district",
                });
                allAreas.push(localizedDistrict);
            }
        });
        console.log(`Added ${districts.value.length} districts`);
    }

    // Update selected areas
    selectedAreas.value = allAreas.map((area) => applyDisplayName(area));

    // Update selectedArea to the first area for backward compatibility
    if (allAreas.length > 0) {
        selectedArea.value = applyDisplayName(allAreas[0]);
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
        selectedArea.value = applyDisplayName(newSelectedAreas[0]);
    } else {
        selectedArea.value = null;
        statistics.value = null;
    }

    // Store all selected areas
    selectedAreas.value = newSelectedAreas.map((area) => applyDisplayName(area));
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
        case __("Very Low"):
            return "bg-green-100 text-green-800";
        case __("Low"):
            return "bg-yellow-100 text-yellow-800";
        case __("Moderate"):
            return "bg-orange-100 text-orange-800";
        case __("Severe"):
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
    if (erosionRate < 5) return __("Very Low");
    if (erosionRate < 15) return __("Low");
    if (erosionRate < 30) return __("Moderate");
    if (erosionRate < 50) return __("Severe");
    return __("Excessive");
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
    loadingMessage.value = __("Loading erosion data...");

    try {
        // Simulate progress updates
        progress.value = 25;
        loadingMessage.value = __("Preparing analysis...");

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
        loadingMessage.value = __("Processing data...");

        const data = await response.json();

        // Check for GEE configuration error
        if (!data.success) {
            if (response.status === 503) {
                showToast(
                    "error",
                    __("GEE Not Configured"),
                    data.error,
                    data.details
                );
            } else {
                showToast(
                    "error",
                    __("Computation Error"),
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
        minErosionRate: (stats.min_erosion_rate ?? stats.min ?? 0).toFixed(2),
        maxErosionRate: (stats.max_erosion_rate ?? stats.max ?? 0).toFixed(2),
        erosionCV: (stats.erosion_cv ?? stats.cv ?? 0).toFixed(1),
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
            loadingMessage.value = __("Complete!");
        }
    } catch (error) {
        console.error(__("Failed to load erosion data:"), error);
        showToast(
            "error",
            __("Data Loading Failed"),
            __("Could not load erosion data for the selected area."),
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
    loadingMessage.value = __("Analyzing drawn shape...");

    try {
        progress.value = 50;
        loadingMessage.value = __("Computing RUSLE factors...");

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
        loadingMessage.value = __("Analysis complete!");

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
            selectedArea.value = applyDisplayName({
                name: __("Custom Drawn Area"),
                name_en: __("Custom Drawn Area"),
            });
        }
    } catch (error) {
        console.error("Failed to analyze geometry:", error);
        showToast(
            "error",
            __("Analysis Failed"),
            __("Could not analyze the drawn shape."),
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
            __("Map Not Ready"),
            __("Please wait for the map to load before exporting.")
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
                `${__("Period:")} ${
                    currentPeriodLabel.value
                } | ${__("Date:")} ${new Date().toLocaleDateString()}`,
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
                __("Export Failed"),
                __("Could not export map as PNG."),
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

    csvData.push([__("RUSLE Soil Erosion Statistics")]);
    csvData.push([__("Generated:") + new Date().toLocaleString()]);
    csvData.push([]);

    areaStatistics.value.forEach((entry, index) => {
        const areaName =
            entry.area?.name ||
            entry.area?.name_en ||
            `${__("Area")} ${index + 1}`;

        csvData.push([`${__("Area")} ${index + 1}`]);
        csvData.push(["Name", areaName]);
        csvData.push([
            __("Type"),
            entry.areaType
                ? entry.areaType.charAt(0).toUpperCase() + entry.areaType.slice(1)
                : "N/A",
        ]);
        csvData.push([__("Period"), entry.periodLabel || currentPeriodLabel.value]);

        if (entry.statistics?.riskLevel) {
            csvData.push([__("Risk Level"), entry.statistics.riskLevel]);
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

            csvData.push([__("Erosion Metrics")]);
            csvData.push([__("Metric"), __("Value"), __("Unit")]);
            csvData.push([
                __("Mean Erosion Rate"),
                formatNumber(stats.meanErosionRate, 2),
                "t/ha/yr",
            ]);
            csvData.push([
                __("Min Erosion Rate"),
                formatNumber(stats.minErosionRate, 2),
                "t/ha/yr",
            ]);
            csvData.push([
                __("Max Erosion Rate"),
                formatNumber(stats.maxErosionRate, 2),
                "t/ha/yr",
            ]);
            csvData.push([
                __("Coefficient of Variation"),
                formatNumber(stats.erosionCV, 1),
                "%",
            ]);
            csvData.push([]);

            csvData.push([__("Rainfall Metrics")]);
            csvData.push(["Metric", "Value", "Unit"]);
            csvData.push([
                __("Rainfall Trend"),
                formatNumber(stats.rainfallSlope, 2),
                "% per year",
            ]);
            csvData.push([
                __("Rainfall Variability"),
                formatNumber(stats.rainfallCV, 1),
                "%",
            ]);
            csvData.push([]);

            if (stats.bareSoilFrequency !== undefined) {
                csvData.push([
                    __("Bare Soil Frequency"),
                    formatNumber(stats.bareSoilFrequency, 1),
                    "%",
                ]);
            }
            if (stats.sustainabilityFactor !== undefined) {
                csvData.push([
                    __("Sustainability Factor"),
                    formatNumber(stats.sustainabilityFactor, 2),
                    "",
                ]);
            }
            csvData.push([]);

            if (Array.isArray(stats.severityDistribution) && stats.severityDistribution.length) {
                csvData.push([__("Area by Severity Class")]);
                csvData.push([__("Class"), __("Area (ha)"), __("Percentage")]);
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
                csvData.push([__("Top Eroding Areas")]);
                csvData.push([__("Name"), __("Erosion (t/ha/yr)")]);
                stats.topErodingAreas.forEach((area) => {
                    csvData.push([
                        area.display_name ||
                            area.name ||
                            area.name_en ||
                            area.name_tj ||
                            "Unknown",
                        area.erosion || area.erosion_rate || area.mean_erosion_rate || 0,
                    ]);
                });
                csvData.push([]);
            }

            if (stats.rusleFactors) {
                const factors = stats.rusleFactors;
                csvData.push([__("RUSLE Factors")]);
                csvData.push([__("Factor"), __("Value"), __("Unit")]);
                csvData.push([
                    __("R-Factor (Rainfall Erosivity)"),
                    factors.r ?? 0,
                    "MJ mm/(ha h yr)",
                ]);
                csvData.push([
                    __("K-Factor (Soil Erodibility)"),
                    factors.k ?? 0,
                    "t ha h/(ha MJ mm)",
                ]);
                csvData.push([
                    __("LS-Factor (Topographic)"),
                    factors.ls ?? 0,
                    "dimensionless",
                ]);
                csvData.push([
                    __("C-Factor (Cover Management)"),
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
    loadingMessage.value = __("Loading country-wide erosion data...");

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
                loadingMessage.value = `${__("Processing district")} ${processedCount}/${districts.value.length}...`;

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

        selectedArea.value = applyDisplayName({
            name: "Tajikistan (Country-wide)",
            name_en: "Tajikistan",
        });

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
