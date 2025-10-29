<template>
    <div class="h-screen w-screen bg-slate-50 flex flex-col overflow-hidden">
        <!-- Progress Bar -->
        <ProgressBar :visible="loading" :progress="progress" :message="loadingMessage" />

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
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>

                <div class="p-6">
                    <h2 class="text-xl font-bold mb-4 text-gray-800">Soil Erosion Analysis</h2>

                    <!-- Region Selector -->
                    <RegionSelector 
                        v-model:selectedRegion="selectedRegion" 
                        v-model:selectedDistrict="selectedDistrict" 
                        v-model:selectedAreaType="selectedAreaType"
                        :regions="regions" 
                        :districts="filteredDistricts" 
                        @region-change="handleRegionChange" 
                        @district-change="handleDistrictChange" 
                        @area-type-change="handleAreaTypeChange"
                        @areas-change="handleAreasChange"
                    />

                    <!-- Time Series Slider -->
                    <TimeSeriesSlider 
                        v-model:year="selectedYear" 
                        :start-year="2016" 
                        :end-year="2024" 
                        :selected-area="selectedArea"
                        @year-change="handleYearChange" 
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
                    
                    <!-- Test Button -->
                    <div class="mt-4">
                        <button 
                            @click="testVisualization" 
                            class="px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600"
                        >
                            Test Visualization
                        </button>
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
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </button>

            <!-- Map and Statistics Container -->
            <div class="flex-1 flex flex-col overflow-hidden relative">
                <!-- Export Toolbar -->
                <div class="absolute top-4 right-4 z-30 flex space-x-2">
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
                    <div v-if="!mapInstance" class="flex items-center justify-center h-full">
                        <div class="text-center">
                            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4"></div>
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
                        :selected-year="selectedYear" 
                        :visible-layers="visibleLayers" 
                        @map-ready="handleMapReady" 
                        @statistics-updated="handleStatisticsUpdated" 
                        @district-clicked="handleDistrictClicked" 
                        @region-clicked="handleRegionClicked"
                        @geojson-loaded="handleGeoJSONLoaded" 
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
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                        </svg>
                        <span>Show Statistics & Charts</span>
                    </button>
                </div>

                <!-- Bottom Statistics Panel - Fits in flex layout -->
                <div
                    v-show="bottomPanelVisible"
                    :style="{ height: bottomPanelHeight + 'px', minHeight: '200px', maxHeight: 'calc(100vh - 100px)' }"
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
                    <div class="flex-1 overflow-y-auto p-6 pt-8">
                        <!-- Comprehensive Statistics Panel -->
                        <StatisticsPanel 
                            :selected-area="selectedArea"
                            :statistics="statistics"
                            :time-series-data="timeSeriesData"
                        />
                        
                        <!-- Erosion Risk Legend -->
                        <div class="mt-6 border-t pt-4">
                            <h4 class="text-sm font-bold mb-3 text-gray-700">Erosion Risk Classification (RUSLE)</h4>
                            <div class="grid grid-cols-5 gap-2 text-xs">
                                <div class="text-center">
                                    <div class="h-6 rounded mb-1" style="background-color: rgba(34, 139, 34, 0.6)"></div>
                                    <div class="font-medium text-green-700">Very Low</div>
                                    <div class="text-gray-600">0-5 t/ha/yr</div>
                                </div>
                                <div class="text-center">
                                    <div class="h-6 rounded mb-1" style="background-color: rgba(255, 215, 0, 0.6)"></div>
                                    <div class="font-medium text-yellow-700">Low</div>
                                    <div class="text-gray-600">5-15 t/ha/yr</div>
                                </div>
                                <div class="text-center">
                                    <div class="h-6 rounded mb-1" style="background-color: rgba(255, 140, 0, 0.6)"></div>
                                    <div class="font-medium text-orange-700">Moderate</div>
                                    <div class="text-gray-600">15-30 t/ha/yr</div>
                                </div>
                                <div class="text-center">
                                    <div class="h-6 rounded mb-1" style="background-color: rgba(220, 20, 60, 0.6)"></div>
                                    <div class="font-medium text-red-700">Severe</div>
                                    <div class="text-gray-600">30-50 t/ha/yr</div>
                                </div>
                                <div class="text-center">
                                    <div class="h-6 rounded mb-1" style="background-color: rgba(139, 0, 0, 0.8)"></div>
                                    <div class="font-medium text-red-900">Excessive</div>
                                    <div class="text-gray-600">&gt; 50 t/ha/yr</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Login Modal -->
        <div v-if="showLogin" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @click="showLogin = false">
            <div class="bg-white rounded-lg p-6 w-96" @click.stop>
                <h2 class="text-xl font-bold mb-4">Admin Login</h2>
                <form @submit.prevent="login">
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="email">
                            Email
                        </label>
                        <input id="email" v-model="loginForm.email" type="email" class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required />
                    </div>
                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="password">
                            Password
                        </label>
                        <input id="password" v-model="loginForm.password" type="password" class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required />
                    </div>
                    <div class="flex justify-end space-x-3">
                        <button type="button" @click="showLogin = false" class="px-4 py-2 text-gray-600 hover:text-gray-800">
                            Cancel
                        </button>
                        <button type="submit" :disabled="loginLoading" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 disabled:opacity-50">
                            {{ loginLoading ? 'Logging in...' : 'Login' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import MapView from '@/Components/Map/MapView.vue'
import RegionSelector from '@/Components/Map/RegionSelector.vue'
import TimeSeriesSlider from '@/Components/Map/TimeSeriesSlider.vue'
import LayerControl from '@/Components/Map/LayerControl.vue'
import ChartPanel from '@/Components/Map/ChartPanel.vue'
import StatisticsPanel from '@/Components/Map/StatisticsPanel.vue'
import MapLegend from '@/Components/Map/MapLegend.vue'
import ProgressBar from '@/Components/UI/ProgressBar.vue'
import ToastNotification from '@/Components/UI/ToastNotification.vue'
import { GeoJSONService } from '@/Services/GeoJSONService.js'

// Props
const props = defineProps({
    user: Object,
    regions: Array,
    districts: Array,
})

// Reactive data
const selectedAreaType = ref('') // 'country', 'region', 'district', or ''
const selectedRegion = ref(null)
const selectedDistrict = ref(null)
const selectedYear = ref(2020)
const selectedArea = ref(null)
const selectedAreas = ref([]) // Multiple selected areas
const visibleLayers = ref([]) // Start with no layers selected (country-wide default)
const showLabels = ref(true) // Show map labels by default
const mapInstance = ref(null)
const mapView = ref(null)
const statistics = ref(null)
const timeSeriesData = ref([])
const loading = ref(false)
const progress = ref(0)
const loadingMessage = ref('')
const showLogin = ref(false)
const loginLoading = ref(false)

// Toast notification
const toast = reactive({
    show: false,
    type: 'info',
    title: '',
    message: '',
    details: '',
})

// Panel state
const leftSidebarVisible = ref(true)
const leftSidebarWidth = ref(320)
const bottomPanelVisible = ref(true)
const bottomPanelHeight = ref(300)

// Local copies of regions and districts that can be updated with GeoJSON data
const regions = ref(props.regions || [])
const districts = ref(props.districts || [])

// Computed property to filter districts based on selected region
const filteredDistricts = computed(() => {
    if (!selectedRegion.value) {
        return []
    }
    return districts.value.filter(district => district.region_id === selectedRegion.value.id)
})

// Login form
const loginForm = reactive({
    email: '',
    password: '',
})

// Available layers
const availableLayers = ref([
    { id: 'erosion', name: 'Soil Erosion Hazard', description: 'Annual soil loss rate (A = R×K×LS×C×P)', metadata: { unit: 't/ha/yr' } },
    { id: 'rainfall_slope', name: 'Rainfall Trend', description: 'Temporal change in rainfall (% per year)', metadata: { colorScheme: 'diverging' } },
    { id: 'rainfall_cv', name: 'Rainfall Variability', description: 'Coefficient of variation (%)', metadata: { colorScheme: 'sequential' } },
    { id: 'r_factor', name: 'R-Factor (Rainfall Erosivity)', description: 'Rainfall erosivity factor', metadata: { unit: 'MJ mm/(ha h yr)' } },
    { id: 'k_factor', name: 'K-Factor (Soil Erodibility)', description: 'Soil erodibility factor', metadata: { unit: 't ha h/(ha MJ mm)' } },
    { id: 'ls_factor', name: 'LS-Factor (Topographic)', description: 'Slope length and steepness factor', metadata: { unit: 'dimensionless' } },
    { id: 'c_factor', name: 'C-Factor (Cover Management)', description: 'Cover and management factor', metadata: { unit: 'dimensionless', range: '0-1' } },
    { id: 'p_factor', name: 'P-Factor (Support Practice)', description: 'Support practice factor', metadata: { unit: 'dimensionless', range: '0-1' } },
    { id: 'bare_soil', name: 'Bare Soil Frequency', description: 'Frequency of bare soil exposure' },
    { id: 'sustainability', name: 'Sustainability Factor', description: 'Land management sustainability' },
    { id: 'custom', name: 'Custom Datasets', description: 'User uploaded data' },
])

// Computed properties
const isAuthenticated = computed(() => props.user && props.user.role === 'admin')

// Methods
const showToast = (type, title, message, details = '') => {
    toast.type = type
    toast.title = title
    toast.message = message
    toast.details = details
    toast.show = true
}

const handleMapReady = (map) => {
    mapInstance.value = map
    console.log('Map is ready')
}

const handleGeoJSONLoaded = async (geoJsonPath) => {
    console.log('GeoJSON loaded, now loading districts data...')
    try {
        // Load districts from GeoJSON and merge with existing data
        await loadDistrictsFromGeoJSON(geoJsonPath)
    } catch (error) {
        console.warn('Could not load districts from GeoJSON:', error.message)
    }
}

// Load districts from GeoJSON and merge with existing data
const loadDistrictsFromGeoJSON = async (geoJsonPath) => {
    try {
        console.log('Loading districts from GeoJSON for select boxes...')

        const geoJsonData = await GeoJSONService.loadDistrictsFromGeoJSON(geoJsonPath, regions.value)

        // Merge GeoJSON districts with existing districts
        const existingDistricts = districts.value || []
        const geoJsonDistricts = geoJsonData.districts || []

        // Create a combined list, avoiding duplicates
        const combinedDistricts = [...existingDistricts]

        geoJsonDistricts.forEach(geoDistrict => {
            const exists = combinedDistricts.some(existing =>
                existing.name_en === geoDistrict.name_en ||
                existing.name_tj === geoDistrict.name_tj
            )

            if (!exists) {
                combinedDistricts.push(geoDistrict)
            }
        })

        districts.value = combinedDistricts
        console.log(`Total districts available: ${combinedDistricts.length}`)

        // Also merge regions if needed
        const existingRegions = regions.value || []
        const geoJsonRegions = geoJsonData.regions || []

        const combinedRegions = [...existingRegions]
        geoJsonRegions.forEach(geoRegion => {
            const exists = combinedRegions.some(existing =>
                existing.name_en === geoRegion.name_en
            )

            if (!exists) {
                combinedRegions.push(geoRegion)
            }
        })

        regions.value = combinedRegions
        console.log(`Total regions available: ${combinedRegions.length}`)

    } catch (error) {
        console.warn('Could not load districts from GeoJSON:', error.message)
    }
}

const handleAreaTypeChange = (areaType) => {
    selectedAreaType.value = areaType
    selectedRegion.value = null
    selectedDistrict.value = null
    selectedAreas.value = []
    
    if (areaType === 'country') {
        selectedArea.value = { id: 0, name_en: 'Tajikistan', name_tj: 'Тоҷикистон', area_type: 'country' }
    } else {
        selectedArea.value = null
    }
    
    // Clear highlights and layer colors on the map
    if (mapView.value) {
        mapView.value.clearAreaHighlights()
        mapView.value.clearAllLayerColors()
    }
    
    // Only load data if a layer is active
    if (visibleLayers.value.length > 0) {
        loadErosionData()
    }

    // Reset district highlighting when area type changes
    if (mapView.value) {
        mapView.value.resetDistrictHighlighting()
    }
}

const handleRegionChange = (region) => {
    selectedDistrict.value = null
    selectedArea.value = region
    
    // Only load data if a layer is active
    if (visibleLayers.value.length > 0) {
        loadErosionData()
    }

    // Reset district highlighting when region is selected
    if (mapView.value) {
        mapView.value.resetDistrictHighlighting()
    }
}

const handleDistrictChange = (district) => {
    selectedArea.value = district
    
    // Only load data if a layer is active
    if (visibleLayers.value.length > 0) {
        loadErosionData()
    }

    // Highlight the selected district on the map
    if (mapView.value && district) {
        mapView.value.highlightDistrict(district.name_en || district.name)
    }
}

const handleDistrictClicked = (districtData) => {
    console.log('District clicked:', districtData)

    // Find the district in our districts list
    const district = districts.value.find(d =>
        d.id === districtData.id ||
        d.name_en === districtData.name_en ||
        d.name_en === districtData.shapeName ||
        d.name_tj === districtData.shapeName
    )

    if (district) {
        selectedDistrict.value = district
        selectedRegion.value = null // Clear region selection when district is selected
        selectedArea.value = district
        
        // Update selected areas for highlighting
        selectedAreas.value = [district]
        
        // Show bottom panel if hidden
        if (!bottomPanelVisible.value) {
            bottomPanelVisible.value = true
        }
        
        // Only load data if a layer is active
        if (visibleLayers.value.length > 0) {
            loadErosionData()
        } else {
            // Only highlight the selected district if no data layers are active
            if (mapView.value) {
                mapView.value.highlightSelectedAreas([district])
            }
        }
    }
}

const handleRegionClicked = (regionData) => {
    console.log('Region clicked:', regionData)
    
    // Find the region in our regions list
    const region = regions.value.find(r =>
        r.id === regionData.id ||
        r.name_en === regionData.name_en ||
        r.name_tj === regionData.name_tj
    )
    
    if (region) {
        selectedRegion.value = region
        selectedDistrict.value = null // Clear district selection when region is selected
        selectedArea.value = region
        
        // Update selected areas for highlighting
        selectedAreas.value = [region]
        
        // Show bottom panel if hidden
        if (!bottomPanelVisible.value) {
            bottomPanelVisible.value = true
        }
        
        // Only load data if a layer is active
        if (visibleLayers.value.length > 0) {
            loadErosionData()
        } else {
            // Only highlight the selected region if no data layers are active
            if (mapView.value) {
                mapView.value.highlightSelectedAreas([region])
            }
        }
        
        // Update the map view to highlight the selected region
        if (mapView.value) {
            mapView.value.updateRegionLayer(region)
        }
    }
}

const handleYearChange = (year) => {
    loadErosionData()
}

const handleLayerToggle = (layerId, visible) => {
    if (visible) {
        // Clear all previous layer colors and area highlights first
        if (mapView.value) {
            console.log('Clearing previous layer colors and area highlights before showing new layer')
            mapView.value.clearAllLayerColors()
            mapView.value.clearAreaHighlights()
        }
        
        // Show only the selected layer - clear all others first
        visibleLayers.value = [layerId]
        
        // If no area is selected, automatically select all available areas
        if (!selectedArea.value && selectedAreas.value.length === 0) {
            console.log('No area selected, auto-selecting all available areas')
            selectAllAvailableAreas()
        }
        
        // Load data for the selected area (if any) or country-wide
        loadErosionData()
    } else {
        // Hide the layer
        visibleLayers.value = []
        
        // Clear all layer colors from the map
        if (mapView.value) {
            mapView.value.clearAllLayerColors()
        }
        
        // Restore area highlights when no layers are active
        if (selectedAreas.value.length > 0) {
            console.log('Restoring area highlights since no layers are active')
            mapView.value.highlightSelectedAreas(selectedAreas.value)
        }
    }
}

const handleLabelsToggle = (visible) => {
    showLabels.value = visible
    
    // Toggle labels on the map
    if (mapView.value) {
        mapView.value.toggleLabels(visible)
    }
}

const testVisualization = () => {
    console.log('Testing visualization from Map.vue')
    if (mapView.value) {
        mapView.value.testVisualization()
    }
}

const selectAllAvailableAreas = () => {
    console.log('Auto-selecting all available areas from GeoJSON data')
    
    const allAreas = []
    
    // Add all regions
    if (regions.value && regions.value.length > 0) {
        regions.value.forEach(region => {
            if (region.geometry) {
                allAreas.push({ ...region, type: 'region' })
            }
        })
        console.log(`Added ${regions.value.length} regions`)
    }
    
    // Add all districts
    if (districts.value && districts.value.length > 0) {
        districts.value.forEach(district => {
            if (district.geometry) {
                allAreas.push({ ...district, type: 'district' })
            }
        })
        console.log(`Added ${districts.value.length} districts`)
    }
    
    // Update selected areas
    selectedAreas.value = allAreas
    
    // Update selectedArea to the first area for backward compatibility
    if (allAreas.length > 0) {
        selectedArea.value = allAreas[0]
    }
    
    console.log(`Auto-selected ${allAreas.length} total areas`)
    
    // Highlight all selected areas on the map
    if (mapView.value) {
        mapView.value.highlightSelectedAreas(allAreas)
    }
}

const handleAreasChange = (selectedAreas) => {
    console.log('Multiple areas selected:', selectedAreas)
    
    // Update selectedArea to the first area for backward compatibility
    if (selectedAreas.length > 0) {
        selectedArea.value = selectedAreas[0]
    } else {
        selectedArea.value = null
    }
    
    // Store all selected areas
    selectedAreas.value = selectedAreas
    
    // Only load data if a layer is active
    if (visibleLayers.value.length > 0) {
        loadErosionData()
    } else {
        // Only highlight selected areas if no data layers are active
        if (mapView.value) {
            mapView.value.highlightSelectedAreas(selectedAreas)
        }
    }
}




const handleStatisticsUpdated = (stats) => {
    statistics.value = stats
}

// Helper function to get erosion rate color class
const getErosionRateClass = (rate) => {
    const erosionRate = parseFloat(rate)
    if (erosionRate < 5) return 'text-green-600'      // Very Low
    if (erosionRate < 15) return 'text-yellow-600'    // Low
    if (erosionRate < 30) return 'text-orange-600'    // Moderate
    if (erosionRate < 50) return 'text-red-600'       // Severe
    return 'text-red-900'                              // Excessive
}

// Helper function to get risk level background class
const getRiskLevelBgClass = (level) => {
    switch (level) {
        case 'Very Low': return 'bg-green-100 text-green-800'
        case 'Low': return 'bg-yellow-100 text-yellow-800'
        case 'Moderate': return 'bg-orange-100 text-orange-800'
        case 'Severe': return 'bg-red-100 text-red-800'
        case 'Excessive': return 'bg-red-900 text-white'
        default: return 'bg-gray-100 text-gray-800'
    }
}

// Determine risk level from erosion rate
const getRiskLevel = (rate) => {
    const erosionRate = parseFloat(rate)
    if (erosionRate < 5) return 'Very Low'
    if (erosionRate < 15) return 'Low'
    if (erosionRate < 30) return 'Moderate'
    if (erosionRate < 50) return 'Severe'
    return 'Excessive'
}

const loadErosionData = async () => {
    // Only load data if a layer is active
    if (visibleLayers.value.length === 0) {
        console.log('No layers active, skipping data load')
        return
    }
    
    // Clear all previous layer colors and area highlights before loading new data
    if (mapView.value) {
        console.log('Clearing previous layer colors and area highlights before loading new data')
        mapView.value.clearAllLayerColors()
        mapView.value.clearAreaHighlights()
    }
    
    loading.value = true
    progress.value = 0
    loadingMessage.value = 'Loading erosion data...'

    try {
        // Simulate progress updates
        progress.value = 25
        loadingMessage.value = 'Preparing analysis...'

        let requestBody
        if (selectedArea.value) {
            if (selectedArea.value.area_type === 'country') {
                // Country selected
                requestBody = {
                    area_type: 'country',
                    area_id: 0,
                    year: selectedYear.value,
                    period: 'annual',
                }
            } else {
                // Specific area selected (region or district)
                requestBody = {
                    area_type: selectedArea.value.region_id ? 'district' : 'region',
                    area_id: selectedArea.value.id,
                    year: selectedYear.value,
                    period: 'annual',
                }
            }
        } else {
            // No area selected - country-wide data
            requestBody = {
                area_type: 'country',
                area_id: 0,
                year: selectedYear.value,
                period: 'annual',
            }
        }

        const response = await fetch('/api/erosion/compute', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify(requestBody),
        })

        progress.value = 75
        loadingMessage.value = 'Processing data...'

        const data = await response.json()
        
        // Check for GEE configuration error
        if (!data.success) {
            if (response.status === 503) {
                showToast('error', 'GEE Not Configured', data.error, data.details)
            } else {
                showToast('error', 'Computation Error', data.error, data.details)
            }
            return
        }
        
        if (data.success) {
            // Update map with new data
            mapView.value?.updateErosionData(data.data)
            
            // Extract erosion rate and update district coloring
            if (data.data && data.data.statistics) {
                const stats = data.data.statistics
                const erosionRate = parseFloat(stats.mean_erosion_rate) || 0
                
                // Update the district color based on erosion rate (only for specific areas)
                if (selectedArea.value && selectedArea.value.id && mapView.value) {
                    mapView.value.updateDistrictErosionData(selectedArea.value.id, erosionRate)
                }
                
                // Update statistics display with comprehensive data
                statistics.value = {
                    meanErosionRate: erosionRate.toFixed(2),
                    minErosionRate: (stats.min_erosion_rate || 0).toFixed(2),
                    maxErosionRate: (stats.max_erosion_rate || 0).toFixed(2),
                    erosionCV: (stats.erosion_cv || 0).toFixed(1),
                    bareSoilFrequency: (stats.bare_soil_frequency || 0).toFixed(1),
                    sustainabilityFactor: (stats.sustainability_factor || 0).toFixed(2),
                    rainfallSlope: (stats.rainfall_slope || 0).toFixed(2),
                    rainfallCV: (stats.rainfall_cv || 0).toFixed(1),
                    districtCount: selectedArea.value ? (selectedArea.value.region_id ? 1 : null) : null,
                    riskLevel: getRiskLevel(erosionRate),
                    severityDistribution: stats.severity_distribution || [],
                    rusleFactors: stats.rusle_factors || {},
                    topErodingAreas: stats.top_eroding_areas || [],
                    areaType: selectedArea.value ? (selectedArea.value.region_id ? 'district' : 'region') : 'country',
                    areaName: selectedArea.value ? selectedArea.value.name_en : 'Tajikistan',
                }
            }
            
            progress.value = 100
            loadingMessage.value = 'Complete!'
        }
    } catch (error) {
        console.error('Failed to load erosion data:', error)
        showToast('error', 'Data Loading Failed', 'Could not load erosion data for the selected area.', error.message)
    } finally {
        // Keep progress bar visible for a moment to show completion
        setTimeout(() => {
            loading.value = false
            progress.value = 0
            loadingMessage.value = ''
        }, 500)
    }
}

const analyzeGeometry = async (geometry) => {
    loading.value = true
    progress.value = 0
    loadingMessage.value = 'Analyzing drawn shape...'

    try {
        progress.value = 50
        loadingMessage.value = 'Computing RUSLE factors...'

        const response = await fetch('/api/erosion/analyze-geometry', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({
                geometry,
                year: selectedYear.value,
            }),
        })

        progress.value = 100
        loadingMessage.value = 'Analysis complete!'

        const data = await response.json()
        if (data.success && data.data && data.data.statistics) {
            const stats = data.data.statistics
            const erosionRate = parseFloat(stats.mean_erosion_rate) || 0
            
            // Update statistics with comprehensive data
            statistics.value = {
                meanErosionRate: erosionRate.toFixed(2),
                minErosionRate: (stats.min_erosion_rate || 0).toFixed(2),
                maxErosionRate: (stats.max_erosion_rate || 0).toFixed(2),
                erosionCV: (stats.erosion_cv || 0).toFixed(1),
                bareSoilFrequency: (stats.bare_soil_frequency || 0).toFixed(1),
                sustainabilityFactor: (stats.sustainability_factor || 0).toFixed(2),
                rainfallSlope: (stats.rainfall_slope || 0).toFixed(2),
                rainfallCV: (stats.rainfall_cv || 0).toFixed(1),
                riskLevel: getRiskLevel(erosionRate),
                severityDistribution: stats.severity_distribution || [],
                rusleFactors: stats.rusle_factors || {},
                topErodingAreas: stats.top_eroding_areas || [],
            }
            
            // Set selected area to the drawn shape
            selectedArea.value = {
                name: 'Custom Drawn Area',
                name_en: 'Custom Drawn Area',
            }
        }
    } catch (error) {
        console.error('Failed to analyze geometry:', error)
        showToast('error', 'Analysis Failed', 'Could not analyze the drawn shape.', error.message)
    } finally {
        setTimeout(() => {
            loading.value = false
            progress.value = 0
            loadingMessage.value = ''
        }, 500)
    }
}

const login = async () => {
    loginLoading.value = true
    try {
        const response = await fetch('/api/login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify(loginForm),
        })

        if (response.ok) {
            showLogin.value = false
            router.reload()
        }
    } catch (error) {
        console.error('Login failed:', error)
    } finally {
        loginLoading.value = false
    }
}

const logout = async () => {
    try {
        await fetch('/api/logout', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
        })
        router.reload()
    } catch (error) {
        console.error('Logout failed:', error)
    }
}

// Export map as PNG
const exportMapAsPNG = () => {
    if (!mapInstance.value) {
        showToast('warning', 'Map Not Ready', 'Please wait for the map to load before exporting.')
        return
    }

    loading.value = true
    loadingMessage.value = 'Preparing map export...'

    mapInstance.value.once('rendercomplete', () => {
        try {
            const mapCanvas = document.createElement('canvas')
            const size = mapInstance.value.getSize()
            mapCanvas.width = size[0]
            mapCanvas.height = size[1]
            const mapContext = mapCanvas.getContext('2d')

            // Get all canvas elements from the map
            const canvases = mapInstance.value.getViewport().querySelectorAll('.ol-layer canvas, canvas.ol-layer')
            
            canvases.forEach((canvas) => {
                if (canvas.width > 0) {
                    const opacity = canvas.parentNode.style.opacity || canvas.style.opacity
                    mapContext.globalAlpha = opacity === '' ? 1 : parseFloat(opacity)
                    
                    const transform = canvas.style.transform
                    const matrix = transform.match(/^matrix\(([^\(]*)\)$/)?.[1].split(',').map(Number)
                    
                    if (matrix) {
                        mapContext.setTransform(...matrix)
                    }
                    
                    mapContext.drawImage(canvas, 0, 0)
                    mapContext.setTransform(1, 0, 0, 1, 0, 0)
                }
            })

            // Add title and metadata overlay
            mapContext.globalAlpha = 1
            mapContext.fillStyle = 'rgba(255, 255, 255, 0.9)'
            mapContext.fillRect(10, 10, 400, 80)
            mapContext.fillStyle = '#000'
            mapContext.font = 'bold 18px Arial'
            mapContext.fillText('RUSLE Soil Erosion Map - Tajikistan', 20, 35)
            mapContext.font = '14px Arial'
            const areaName = selectedArea.value?.name || selectedArea.value?.name_en || 'Country-wide'
            mapContext.fillText(`Area: ${areaName}`, 20, 55)
            mapContext.fillText(`Year: ${selectedYear.value} | Date: ${new Date().toLocaleDateString()}`, 20, 75)

            // Convert to blob and download
            mapCanvas.toBlob((blob) => {
                const link = document.createElement('a')
                link.href = URL.createObjectURL(blob)
                link.download = `rusle-map-${selectedArea.value?.name_en || 'tajikistan'}-${selectedYear.value}-${new Date().toISOString().split('T')[0]}.png`
                document.body.appendChild(link)
                link.click()
                document.body.removeChild(link)
                
                loading.value = false
                loadingMessage.value = ''
            })
        } catch (error) {
            console.error('Error exporting map:', error)
            showToast('error', 'Export Failed', 'Could not export map as PNG.', error.message)
            loading.value = false
            loadingMessage.value = ''
        }
    })

    // Trigger a render
    mapInstance.value.renderSync()
}

// Export statistics as CSV
const exportStatisticsCSV = () => {
    if (!statistics.value) return

    const csvData = []
    
    // Header
    csvData.push(['RUSLE Soil Erosion Statistics'])
    csvData.push(['Generated:', new Date().toLocaleString()])
    csvData.push([])
    
    // Area Information
    csvData.push(['Area Information'])
    csvData.push(['Name', selectedArea.value?.name || selectedArea.value?.name_en || 'N/A'])
    csvData.push(['Type', selectedArea.value?.region_id ? 'District' : 'Region'])
    csvData.push(['Year', selectedYear.value])
    csvData.push([])
    
    // Erosion Statistics
    csvData.push(['Erosion Statistics'])
    csvData.push(['Metric', 'Value', 'Unit'])
    csvData.push(['Mean Erosion Rate', statistics.value.meanErosionRate || 0, 't/ha/yr'])
    csvData.push(['Min Erosion Rate', statistics.value.minErosionRate || 0, 't/ha/yr'])
    csvData.push(['Max Erosion Rate', statistics.value.maxErosionRate || 0, 't/ha/yr'])
    csvData.push(['Coefficient of Variation', statistics.value.erosionCV || 0, '%'])
    csvData.push(['Risk Level', statistics.value.riskLevel || 'N/A', ''])
    csvData.push([])
    
    // Additional Metrics
    if (statistics.value.bareSoilFrequency) {
        csvData.push(['Bare Soil Frequency', statistics.value.bareSoilFrequency, '%'])
    }
    if (statistics.value.sustainabilityFactor) {
        csvData.push(['Sustainability Factor', statistics.value.sustainabilityFactor, ''])
    }
    if (statistics.value.rainfallSlope) {
        csvData.push(['Rainfall Trend', statistics.value.rainfallSlope, '% per year'])
    }
    if (statistics.value.rainfallCV) {
        csvData.push(['Rainfall Variability', statistics.value.rainfallCV, '%'])
    }
    csvData.push([])
    
    // Severity Distribution
    if (statistics.value.severityDistribution) {
        csvData.push(['Severity Class Distribution'])
        csvData.push(['Class', 'Area (ha)', 'Percentage'])
        statistics.value.severityDistribution.forEach(item => {
            csvData.push([item.class, item.area, `${item.percentage.toFixed(1)}%`])
        })
        csvData.push([])
    }
    
    // RUSLE Factors
    if (statistics.value.rusleFactors) {
        csvData.push(['RUSLE Factors'])
        csvData.push(['Factor', 'Value', 'Unit'])
        const factors = statistics.value.rusleFactors
        csvData.push(['R-Factor (Rainfall Erosivity)', factors.r || 0, 'MJ mm/(ha h yr)'])
        csvData.push(['K-Factor (Soil Erodibility)', factors.k || 0, 't ha h/(ha MJ mm)'])
        csvData.push(['LS-Factor (Topographic)', factors.ls || 0, 'dimensionless'])
        csvData.push(['C-Factor (Cover Management)', factors.c || 0, '0-1'])
        csvData.push(['P-Factor (Support Practice)', factors.p || 0, '0-1'])
    }
    
    // Convert to CSV string
    const csvString = csvData.map(row => row.join(',')).join('\n')
    
    // Create blob and download
    const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' })
    const link = document.createElement('a')
    const url = URL.createObjectURL(blob)
    link.setAttribute('href', url)
    link.setAttribute('download', `rusle-statistics-${selectedArea.value?.name_en || 'tajikistan'}-${selectedYear.value}-${new Date().toISOString().split('T')[0]}.csv`)
    link.style.visibility = 'hidden'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
}

// Panel Resize Functions
const startLeftResize = (event) => {
    event.preventDefault()
    const startX = event.clientX
    const startWidth = leftSidebarWidth.value

    const onMouseMove = (e) => {
        const deltaX = e.clientX - startX
        const maxWidth = window.innerWidth * 0.5 // Max 50% of screen width
        const newWidth = Math.max(250, Math.min(maxWidth, startWidth + deltaX))
        leftSidebarWidth.value = newWidth
        
        // Update map size
        if (mapInstance.value) {
            setTimeout(() => mapInstance.value.updateSize(), 100)
        }
    }

    const onMouseUp = () => {
        document.removeEventListener('mousemove', onMouseMove)
        document.removeEventListener('mouseup', onMouseUp)
    }

    document.addEventListener('mousemove', onMouseMove)
    document.addEventListener('mouseup', onMouseUp)
}

const startBottomResize = (event) => {
    event.preventDefault()
    const startY = event.clientY
    const startHeight = bottomPanelHeight.value

    const onMouseMove = (e) => {
        const deltaY = startY - e.clientY
        const maxHeight = window.innerHeight - 100 // Leave 100px for map visibility
        const newHeight = Math.max(200, Math.min(maxHeight, startHeight + deltaY))
        bottomPanelHeight.value = newHeight
        
        // Update map size since panel affects available space
        if (mapInstance.value) {
            setTimeout(() => mapInstance.value.updateSize(), 50)
        }
    }

    const onMouseUp = () => {
        document.removeEventListener('mousemove', onMouseMove)
        document.removeEventListener('mouseup', onMouseUp)
        
        // Final map size update
        if (mapInstance.value) {
            setTimeout(() => mapInstance.value.updateSize(), 100)
        }
    }

    document.addEventListener('mousemove', onMouseMove)
    document.addEventListener('mouseup', onMouseUp)
}

// Watchers for panel visibility changes
watch(leftSidebarVisible, () => {
    // Update map size when sidebar is toggled
    if (mapInstance.value) {
        setTimeout(() => mapInstance.value.updateSize(), 200)
    }
})

watch(bottomPanelVisible, () => {
    // Update map size when bottom panel is toggled
    if (mapInstance.value) {
        setTimeout(() => mapInstance.value.updateSize(), 200)
    }
})

// Load erosion data for all districts (whole country)
const loadCountryWideData = async () => {
    if (!districts.value || districts.value.length === 0) {
        console.warn('No districts available for country-wide analysis')
        return
    }
    
    loading.value = true
    progress.value = 0
    loadingMessage.value = 'Loading country-wide erosion data...'
    
    try {
        let totalErosion = 0
        let totalBareSoil = 0
        let totalSustainability = 0
        let validDistrictCount = 0
        let processedCount = 0
        
        // Load erosion data for each district
        const districtPromises = districts.value.map(async (district) => {
            try {
                const response = await fetch('/api/erosion/compute', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        area_type: 'district',
                        area_id: district.id,
                        year: selectedYear.value,
                        period: 'annual',
                    }),
                })
                
                const data = await response.json()
                
                if (data.success && data.data && data.data.statistics) {
                    const stats = data.data.statistics
                    const erosionRate = parseFloat(stats.mean_erosion_rate) || 0
                    
                    // Update district color on map
                    if (mapView.value) {
                        mapView.value.updateDistrictErosionData(district.id, erosionRate)
                    }
                    
                    // Aggregate statistics
                    totalErosion += erosionRate
                    totalBareSoil += parseFloat(stats.bare_soil_frequency) || 0
                    totalSustainability += parseFloat(stats.sustainability_factor) || 0
                    validDistrictCount++
                }
                
                processedCount++
                progress.value = Math.round((processedCount / districts.value.length) * 100)
                loadingMessage.value = `Processing district ${processedCount}/${districts.value.length}...`
                
                return data
            } catch (error) {
                console.error(`Failed to load data for district ${district.name_en}:`, error)
                processedCount++
                return null
            }
        })
        
        // Wait for all districts to complete
        await Promise.all(districtPromises)
        
        // Calculate country-wide averages
        if (validDistrictCount > 0) {
            const avgErosion = (totalErosion / validDistrictCount).toFixed(2)
            
            statistics.value = {
                meanErosionRate: avgErosion,
                bareSoilFrequency: (totalBareSoil / validDistrictCount).toFixed(1),
                sustainabilityFactor: (totalSustainability / validDistrictCount).toFixed(2),
                districtCount: validDistrictCount,
                riskLevel: getRiskLevel(avgErosion),
            }
        }
        
        selectedArea.value = {
            name: 'Tajikistan (Country-wide)',
            name_en: 'Tajikistan',
        }
        
        progress.value = 100
        loadingMessage.value = 'Complete!'
        
        console.log(`Loaded erosion data for ${validDistrictCount} districts`)
        
    } catch (error) {
        console.error('Failed to load country-wide data:', error)
    } finally {
        setTimeout(() => {
            loading.value = false
            progress.value = 0
            loadingMessage.value = ''
        }, 800)
    }
}

// Lifecycle
onMounted(() => {
    // Prevent body scrolling
    document.body.style.overflow = 'hidden'
    document.documentElement.style.overflow = 'hidden'
    
    // Don't load data by default - wait for user to select a layer
    console.log('Application started - no layers active by default')
})

onUnmounted(() => {
    // Restore body scrolling
    document.body.style.overflow = ''
    document.documentElement.style.overflow = ''
})
</script>

<style scoped>
/* Ensure no overflow on main container */
:deep(body) {
    overflow: hidden;
}
</style>
