import {
    defineStore
} from 'pinia'
import {
    ref,
    computed
} from 'vue'

export const useMapStore = defineStore('map', () => {
    // State
    const selectedRegion = ref(null)
    const selectedDistrict = ref(null)
    const selectedYear = ref(new Date().getFullYear())
    const visibleLayers = ref(['base', 'erosion'])
    const drawingMode = ref(null)
    const mapInstance = ref(null)
    const statistics = ref(null)
    const isLoading = ref(false)

    // Getters
    const hasSelection = computed(() => selectedRegion.value || selectedDistrict.value)
    const selectedArea = computed(() => selectedDistrict.value || selectedRegion.value)
    const canAnalyze = computed(() => hasSelection.value || drawingMode.value === 'geometry')

    // Actions
    const setSelectedRegion = (region) => {
        selectedRegion.value = region
        selectedDistrict.value = null // Reset district when region changes
    }

    const setSelectedDistrict = (district) => {
        selectedDistrict.value = district
    }

    const setSelectedYear = (year) => {
        selectedYear.value = year
    }

    const toggleLayer = (layerName) => {
        const index = visibleLayers.value.indexOf(layerName)
        if (index > -1) {
            visibleLayers.value.splice(index, 1)
        } else {
            visibleLayers.value.push(layerName)
        }
    }

    const setDrawingMode = (mode) => {
        drawingMode.value = mode
    }

    const setMapInstance = (map) => {
        mapInstance.value = map
    }

    const setStatistics = (stats) => {
        statistics.value = stats
    }

    const setLoading = (loading) => {
        isLoading.value = loading
    }

    const clearSelection = () => {
        selectedRegion.value = null
        selectedDistrict.value = null
        drawingMode.value = null
        statistics.value = null
    }

    const resetMap = () => {
        clearSelection()
        visibleLayers.value = ['base', 'erosion']
        selectedYear.value = new Date().getFullYear()
    }

    return {
        // State
        selectedRegion,
        selectedDistrict,
        selectedYear,
        visibleLayers,
        drawingMode,
        mapInstance,
        statistics,
        isLoading,

        // Getters
        hasSelection,
        selectedArea,
        canAnalyze,

        // Actions
        setSelectedRegion,
        setSelectedDistrict,
        setSelectedYear,
        toggleLayer,
        setDrawingMode,
        setMapInstance,
        setStatistics,
        setLoading,
        clearSelection,
        resetMap,
    }
})