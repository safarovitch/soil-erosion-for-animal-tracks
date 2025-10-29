<template>
  <div class="space-y-4">
    <h3 class="text-lg font-semibold text-gray-900">Time Series</h3>

    <!-- Year Range Selection -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Year Range
      </label>
      <div class="flex space-x-2">
        <div class="flex-1">
          <label class="block text-xs text-gray-500 mb-1">Start Year</label>
          <select
            :value="startYear"
            @change="handleStartYearChange"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors duration-200"
            :disabled="isLoadingYears"
          >
            <option
              v-for="year in availableYears"
              :key="year"
              :value="year"
            >
              {{ year }}
            </option>
          </select>
        </div>
        <div class="flex-1">
          <label class="block text-xs text-gray-500 mb-1">End Year</label>
          <select
            :value="endYear"
            @change="handleEndYearChange"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors duration-200"
            :disabled="isLoadingYears"
          >
            <option
              v-for="year in availableYears"
              :key="year"
              :value="year"
            >
              {{ year }}
            </option>
          </select>
        </div>
      </div>
      <div v-if="isLoadingYears" class="text-xs text-blue-600 mt-1">
        Loading years...
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import axios from 'axios'

// Props
const props = defineProps({
  selectedYear: {
    type: Number,
    default: 2020
  },
  selectedArea: {
    type: Object,
    default: null
  }
})

// Emits
const emit = defineEmits(['update:year', 'year-change'])

// Reactive data
const isLoadingYears = ref(false)
const availableYears = ref([])
const startYear = ref(2016)
const endYear = ref(2024)

// Computed properties
const oldestYear = computed(() => Math.min(...availableYears.value))
const newestYear = computed(() => Math.max(...availableYears.value))

// Methods
const loadAvailableYears = async () => {
  if (!props.selectedArea) return
  
  isLoadingYears.value = true
  
  try {
    const response = await axios.get('/api/erosion/available-years', {
      params: {
        area_type: props.selectedArea.area_type || (props.selectedArea.region_id ? 'district' : 'region'),
        area_id: props.selectedArea.id
      }
    })
    
    if (response.data.success) {
      availableYears.value = response.data.years
      startYear.value = Math.min(...availableYears.value)
      endYear.value = Math.max(...availableYears.value)
    }
  } catch (error) {
    console.error('Error loading available years:', error)
    // Fallback to default years
    availableYears.value = Array.from({ length: 9 }, (_, i) => 2016 + i)
  } finally {
    isLoadingYears.value = false
  }
}

const handleStartYearChange = (event) => {
  const year = parseInt(event.target.value)
  startYear.value = year
  
  // Ensure end year is not before start year
  if (endYear.value < year) {
    endYear.value = year
  }
  
  // Emit the start year as the selected year
  emit('update:year', year)
  emit('year-change', year)
}

const handleEndYearChange = (event) => {
  const year = parseInt(event.target.value)
  endYear.value = year
  
  // Ensure start year is not after end year
  if (startYear.value > year) {
    startYear.value = year
  }
  
  // Emit the end year as the selected year
  emit('update:year', year)
  emit('year-change', year)
}

// Watchers
watch(() => props.selectedArea, () => {
  loadAvailableYears()
}, { immediate: true })

// Lifecycle
onMounted(() => {
  // Initialize with default years if no area is selected
  if (!props.selectedArea) {
    availableYears.value = Array.from({ length: 9 }, (_, i) => 2016 + i)
  }
})
</script>

<style scoped>
/* Custom styles for better appearance */
select:focus {
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
</style>