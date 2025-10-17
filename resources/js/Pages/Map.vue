<template>
    <div class="h-screen w-screen bg-slate-50 flex flex-col overflow-hidden">
        <!-- Progress Bar -->
        <ProgressBar :visible="loading" :progress="progress" :message="loadingMessage" />

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
                        :regions="regions" 
                        :districts="filteredDistricts" 
                        @region-change="handleRegionChange" 
                        @district-change="handleDistrictChange" 
                    />

                    <!-- Time Series Slider -->
                    <TimeSeriesSlider 
                        v-model:year="selectedYear" 
                        :start-year="2016" 
                        :end-year="2024" 
                        @year-change="handleYearChange" 
                        class="mt-6" 
                    />

                    <!-- Layer Controls -->
                    <LayerControl 
                        :visible-layers="visibleLayers" 
                        :available-layers="availableLayers" 
                        @layer-toggle="handleLayerToggle" 
                        class="mt-6" 
                    />

                    <!-- Drawing Tools -->
                    <DrawingTools 
                        v-model:drawing-mode="drawingMode" 
                        :map="mapInstance" 
                        @geometry-drawn="handleGeometryDrawn" 
                        class="mt-6" 
                    />
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
                        :selected-year="selectedYear" 
                        :visible-layers="visibleLayers" 
                        :drawing-mode="drawingMode" 
                        @map-ready="handleMapReady" 
                        @statistics-updated="handleStatisticsUpdated" 
                        @district-clicked="handleDistrictClicked" 
                        @geojson-loaded="handleGeoJSONLoaded" 
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
                        <div class="grid grid-cols-2 gap-6">
                            <!-- Statistics Panel -->
                            <div>
                                <h3 class="text-lg font-bold mb-4 text-gray-800">
                                    <span v-if="selectedArea">{{ selectedArea.name || selectedArea.name_en }}</span>
                                    <span v-else>Statistics</span>
                                </h3>
                                <div v-if="statistics" class="bg-gray-50 rounded-lg p-4">
                                    <div class="space-y-3">
                                        <div class="flex justify-between items-center border-b pb-2">
                                            <span class="text-gray-600 font-medium">Mean Erosion Rate:</span>
                                            <span class="font-bold text-lg" :class="getErosionRateClass(statistics.meanErosionRate)">
                                                {{ statistics.meanErosionRate }} t/ha/yr
                                            </span>
                                        </div>
                                        <div class="flex justify-between items-center border-b pb-2">
                                            <span class="text-gray-600 font-medium">Bare Soil Frequency:</span>
                                            <span class="font-bold text-lg text-orange-600">{{ statistics.bareSoilFrequency }}%</span>
                                        </div>
                                        <div class="flex justify-between items-center border-b pb-2">
                                            <span class="text-gray-600 font-medium">Sustainability Factor:</span>
                                            <span class="font-bold text-lg text-green-600">{{ statistics.sustainabilityFactor }}</span>
                                        </div>
                                        <div v-if="statistics.districtCount" class="flex justify-between items-center pt-2 text-sm">
                                            <span class="text-gray-600">Districts Analyzed:</span>
                                            <span class="font-medium text-blue-600">{{ statistics.districtCount }} of {{ districts.length }}</span>
                                        </div>
                                        <div v-if="statistics.riskLevel" class="mt-3 p-2 rounded" :class="getRiskLevelBgClass(statistics.riskLevel)">
                                            <div class="text-center font-bold">
                                                Risk Level: {{ statistics.riskLevel }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="bg-gray-50 rounded-lg p-4 text-center text-gray-500">
                                    Loading country-wide statistics...
                                </div>
                            </div>

                            <!-- Charts Panel -->
                            <div>
                                <h3 class="text-lg font-bold mb-4 text-gray-800">Time Series Analysis</h3>
                                <ChartPanel 
                                    :time-series-data="timeSeriesData" 
                                    :selected-area="selectedArea" 
                                />
                            </div>
                        </div>
                        
                        <!-- Erosion Risk Legend -->
                        <div class="mt-6 border-t pt-4">
                            <h4 class="text-sm font-bold mb-3 text-gray-700">Erosion Risk Classification (RUSLE)</h4>
                            <div class="grid grid-cols-5 gap-2 text-xs">
                                <div class="text-center">
                                    <div class="h-6 rounded mb-1" style="background-color: rgba(34, 139, 34, 0.6)"></div>
                                    <div class="font-medium text-green-700">Very Low</div>
                                    <div class="text-gray-600">&lt; 2 t/ha/yr</div>
                                </div>
                                <div class="text-center">
                                    <div class="h-6 rounded mb-1" style="background-color: rgba(154, 205, 50, 0.6)"></div>
                                    <div class="font-medium text-lime-700">Low</div>
                                    <div class="text-gray-600">2-5 t/ha/yr</div>
                                </div>
                                <div class="text-center">
                                    <div class="h-6 rounded mb-1" style="background-color: rgba(255, 215, 0, 0.6)"></div>
                                    <div class="font-medium text-yellow-700">Moderate</div>
                                    <div class="text-gray-600">5-10 t/ha/yr</div>
                                </div>
                                <div class="text-center">
                                    <div class="h-6 rounded mb-1" style="background-color: rgba(255, 140, 0, 0.6)"></div>
                                    <div class="font-medium text-orange-700">High</div>
                                    <div class="text-gray-600">10-20 t/ha/yr</div>
                                </div>
                                <div class="text-center">
                                    <div class="h-6 rounded mb-1" style="background-color: rgba(220, 20, 60, 0.6)"></div>
                                    <div class="font-medium text-red-700">Very High</div>
                                    <div class="text-gray-600">&gt; 20 t/ha/yr</div>
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
import DrawingTools from '@/Components/Map/DrawingTools.vue'
import ChartPanel from '@/Components/Map/ChartPanel.vue'
import ProgressBar from '@/Components/UI/ProgressBar.vue'
import { GeoJSONService } from '@/Services/GeoJSONService.js'

// Props
const props = defineProps({
    user: Object,
    regions: Array,
    districts: Array,
})

// Reactive data
const selectedRegion = ref(null)
const selectedDistrict = ref(null)
const selectedYear = ref(2020)
const selectedArea = ref(null)
const visibleLayers = ref(['erosion', 'bare_soil'])
const drawingMode = ref(null)
const mapInstance = ref(null)
const mapView = ref(null)
const statistics = ref(null)
const timeSeriesData = ref([])
const loading = ref(false)
const progress = ref(0)
const loadingMessage = ref('')
const showLogin = ref(false)
const loginLoading = ref(false)

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
    { id: 'erosion', name: 'Soil Erosion Hazard', description: 'Annual soil loss rate' },
    { id: 'bare_soil', name: 'Bare Soil Frequency', description: 'Frequency of bare soil exposure' },
    { id: 'sustainability', name: 'Sustainability Factor', description: 'Land management sustainability' },
    { id: 'custom', name: 'Custom Datasets', description: 'User uploaded data' },
])

// Computed properties
const isAuthenticated = computed(() => props.user && props.user.role === 'admin')

// Methods
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

const handleRegionChange = (region) => {
    selectedDistrict.value = null
    selectedArea.value = region
    loadErosionData()

    // Reset district highlighting when region is selected
    if (mapView.value) {
        mapView.value.resetDistrictHighlighting()
    }
}

const handleDistrictChange = (district) => {
    selectedArea.value = district
    loadErosionData()

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
        
        // Show bottom panel if hidden
        if (!bottomPanelVisible.value) {
            bottomPanelVisible.value = true
        }
        
        loadErosionData()
    }
}

const handleYearChange = (year) => {
    loadErosionData()
}

const handleLayerToggle = (layerId, visible) => {
    if (visible) {
        visibleLayers.value.push(layerId)
    } else {
        const index = visibleLayers.value.indexOf(layerId)
        if (index > -1) {
            visibleLayers.value.splice(index, 1)
        }
    }
}


const handleGeometryDrawn = (geometry) => {
    analyzeGeometry(geometry)
}

const handleStatisticsUpdated = (stats) => {
    statistics.value = stats
}

// Helper function to get erosion rate color class
const getErosionRateClass = (rate) => {
    const erosionRate = parseFloat(rate)
    if (erosionRate < 2) return 'text-green-600'      // Very Low
    if (erosionRate < 5) return 'text-lime-600'       // Low
    if (erosionRate < 10) return 'text-yellow-600'    // Moderate
    if (erosionRate < 20) return 'text-orange-600'    // High
    return 'text-red-600'                              // Very High
}

// Helper function to get risk level background class
const getRiskLevelBgClass = (level) => {
    switch (level) {
        case 'Very Low': return 'bg-green-100 text-green-800'
        case 'Low': return 'bg-lime-100 text-lime-800'
        case 'Moderate': return 'bg-yellow-100 text-yellow-800'
        case 'High': return 'bg-orange-100 text-orange-800'
        case 'Very High': return 'bg-red-100 text-red-800'
        default: return 'bg-gray-100 text-gray-800'
    }
}

// Determine risk level from erosion rate
const getRiskLevel = (rate) => {
    const erosionRate = parseFloat(rate)
    if (erosionRate < 2) return 'Very Low'
    if (erosionRate < 5) return 'Low'
    if (erosionRate < 10) return 'Moderate'
    if (erosionRate < 20) return 'High'
    return 'Very High'
}

const loadErosionData = async () => {
    if (!selectedArea.value) return

    loading.value = true
    progress.value = 0
    loadingMessage.value = 'Loading erosion data...'

    try {
        // Simulate progress updates
        progress.value = 25
        loadingMessage.value = 'Preparing analysis...'

        const response = await fetch('/api/erosion/compute', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({
                area_type: selectedArea.value.region_id ? 'district' : 'region',
                area_id: selectedArea.value.id,
                year: selectedYear.value,
                period: 'annual',
            }),
        })

        progress.value = 75
        loadingMessage.value = 'Processing data...'

        const data = await response.json()
        if (data.success) {
            // Update map with new data
            mapView.value?.updateErosionData(data.data)
            
            // Extract erosion rate and update district coloring
            if (data.data && data.data.statistics && selectedArea.value) {
                const erosionRate = parseFloat(data.data.statistics.mean_erosion_rate) || 0
                
                // Update the district color based on erosion rate
                if (selectedArea.value.id && mapView.value) {
                    mapView.value.updateDistrictErosionData(selectedArea.value.id, erosionRate)
                }
                
                // Update statistics display
                const avgErosion = erosionRate.toFixed(2)
                statistics.value = {
                    meanErosionRate: avgErosion,
                    bareSoilFrequency: (data.data.statistics.bare_soil_frequency || 0).toFixed(1),
                    sustainabilityFactor: (data.data.statistics.sustainability_factor || 0).toFixed(2),
                    districtCount: selectedArea.value.region_id ? 1 : null, // 1 district if it's a district
                    riskLevel: getRiskLevel(avgErosion),
                }
            }
            
            progress.value = 100
            loadingMessage.value = 'Complete!'
        }
    } catch (error) {
        console.error('Failed to load erosion data:', error)
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
    loadingMessage.value = 'Analyzing geometry...'

    try {
        progress.value = 50
        loadingMessage.value = 'Processing area...'

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
        if (data.success) {
            statistics.value = data.data
        }
    } catch (error) {
        console.error('Failed to analyze geometry:', error)
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
    
    // Load country-wide erosion data on startup
    setTimeout(() => {
        loadCountryWideData()
    }, 1000) // Wait for map to initialize
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
