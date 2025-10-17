<template>
    <div class="min-h-screen bg-slate-50">
        <!-- Progress Bar -->
        <ProgressBar :visible="loading" :progress="progress" :message="loadingMessage" />

        <!-- Header -->


        <!-- Main Content -->
        <div class="flex h-[100vh]">
            <!-- Sidebar -->
            <div class="w-80 bg-white border-r border-gray-200 shadow-lg overflow-y-auto">
                <div class="p-6">
                    <!-- Region Selector -->
                    <RegionSelector v-model:selectedRegion="selectedRegion" v-model:selectedDistrict="selectedDistrict" :regions="regions" :districts="filteredDistricts" @region-change="handleRegionChange" @district-change="handleDistrictChange" />

                    <!-- Time Series Slider -->
                    <TimeSeriesSlider v-model:year="selectedYear" :start-year="2016" :end-year="2024" @year-change="handleYearChange" class="mt-6" />

                    <!-- Layer Controls -->
                    <LayerControl :visible-layers="visibleLayers" :available-layers="availableLayers" @layer-toggle="handleLayerToggle" class="mt-6" />

                    <!-- Drawing Tools -->
                    <DrawingTools v-model:drawing-mode="drawingMode" :map="mapInstance" @geometry-drawn="handleGeometryDrawn" class="mt-6" />

                    <!-- Statistics Panel -->
                    <div v-if="statistics" class="mt-6 bg-gray-50 rounded-lg p-4">
                        <h3 class="text-lg font-semibold mb-3">Statistics</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Mean Erosion Rate:</span>
                                <span class="font-medium">{{ statistics.meanErosionRate }} t/ha/yr</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Bare Soil Frequency:</span>
                                <span class="font-medium">{{ statistics.bareSoilFrequency }}%</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Sustainability Factor:</span>
                                <span class="font-medium">{{ statistics.sustainabilityFactor }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Panel -->
                    <ChartPanel :time-series-data="timeSeriesData" :selected-area="selectedArea" class="mt-6" />
                </div>
            </div>

            <!-- Map Container -->
            <div class="flex-1 relative h-full bg-gray-100">
                <div v-if="!mapInstance" class="flex items-center justify-center h-full">
                    <div class="text-center">
                        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4"></div>
                        <p class="text-gray-700">Loading map...</p>
                    </div>
                </div>

                <MapView ref="mapView" :selected-region="selectedRegion" :selected-district="selectedDistrict" :selected-year="selectedYear" :visible-layers="visibleLayers" :drawing-mode="drawingMode" @map-ready="handleMapReady" @statistics-updated="handleStatisticsUpdated" @district-clicked="handleDistrictClicked" @geojson-loaded="handleGeoJSONLoaded" />

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
import { ref, reactive, computed, onMounted } from 'vue'
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
        d.name_en === districtData.shapeName ||
        d.name_tj === districtData.shapeName
    )

    if (district) {
        selectedDistrict.value = district
        selectedRegion.value = null // Clear region selection when district is selected
        selectedArea.value = district
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

// Lifecycle
onMounted(() => {
    // Initialize with default region if available
    if (props.regions && props.regions.length > 0) {
        selectedRegion.value = props.regions[0]
        selectedArea.value = props.regions[0]
        loadErosionData()
    }
})
</script>
