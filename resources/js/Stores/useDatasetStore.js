import {
    defineStore
} from 'pinia'
import {
    ref,
    computed
} from 'vue'
import axios from 'axios'

export const useDatasetStore = defineStore('dataset', () => {
    // State
    const datasets = ref([])
    const isLoading = ref(false)
    const error = ref(null)

    // Getters
    const availableDatasets = computed(() =>
        datasets.value.filter(dataset => dataset.status === 'ready')
    )

    const datasetsByType = computed(() => {
        return datasets.value.reduce((acc, dataset) => {
            if (!acc[dataset.type]) {
                acc[dataset.type] = []
            }
            acc[dataset.type].push(dataset)
            return acc
        }, {})
    })

    const rainfallDatasets = computed(() =>
        datasets.value.filter(dataset => dataset.type === 'rainfall' && dataset.status === 'ready')
    )

    const customDatasets = computed(() =>
        datasets.value.filter(dataset => dataset.type === 'custom' && dataset.status === 'ready')
    )

    // Actions
    const fetchDatasets = async () => {
        try {
            isLoading.value = true
            error.value = null

            const response = await axios.get('/api/datasets')
            datasets.value = response.data.data || []
        } catch (err) {
            error.value = err.response?.data?.message || 'Failed to fetch datasets'
            console.error('Error fetching datasets:', err)
        } finally {
            isLoading.value = false
        }
    }

    const fetchAvailableDatasets = async () => {
        try {
            isLoading.value = true
            error.value = null

            const response = await axios.get('/api/datasets/available')
            datasets.value = response.data.data || []
        } catch (err) {
            error.value = err.response?.data?.message || 'Failed to fetch available datasets'
            console.error('Error fetching available datasets:', err)
        } finally {
            isLoading.value = false
        }
    }

    const uploadDataset = async (formData) => {
        try {
            isLoading.value = true
            error.value = null

            const response = await axios.post('/api/admin/datasets/upload', formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            })

            // Refresh datasets after successful upload
            await fetchDatasets()

            return response.data
        } catch (err) {
            error.value = err.response?.data?.message || 'Failed to upload dataset'
            throw err
        } finally {
            isLoading.value = false
        }
    }

    const deleteDataset = async (datasetId) => {
        try {
            isLoading.value = true
            error.value = null

            await axios.delete(`/api/admin/datasets/${datasetId}`)

            // Remove from local state
            datasets.value = datasets.value.filter(dataset => dataset.id !== datasetId)

            return true
        } catch (err) {
            error.value = err.response?.data?.message || 'Failed to delete dataset'
            throw err
        } finally {
            isLoading.value = false
        }
    }

    const getDatasetTileUrl = (datasetId, z, x, y) => {
        return `/api/datasets/${datasetId}/tiles/${z}/${x}/${y}.png`
    }

    const clearError = () => {
        error.value = null
    }

    return {
        // State
        datasets,
        isLoading,
        error,

        // Getters
        availableDatasets,
        datasetsByType,
        rainfallDatasets,
        customDatasets,

        // Actions
        fetchDatasets,
        fetchAvailableDatasets,
        uploadDataset,
        deleteDataset,
        getDatasetTileUrl,
        clearError,
    }
})
