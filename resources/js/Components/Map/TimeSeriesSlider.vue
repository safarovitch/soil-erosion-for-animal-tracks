<template>
  <div class="space-y-4">
    <h3 class="text-lg font-semibold text-gray-900">Year Selection</h3>

    <!-- Single Year Selection -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Select Year
      </label>
      <div class="flex items-center gap-2">
        <!-- Previous Year Button -->
        <button
          @click="goToPreviousYear"
          :disabled="!canGoPrevious || isLoadingYears"
          class="px-3 py-2 border border-gray-300 rounded-lg bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-white"
          title="Previous year"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        
        <!-- Year Selector -->
        <select
          :value="selectedYear"
          @change="handleYearChange"
          class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors duration-200"
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
        
        <!-- Next Year Button -->
        <button
          @click="goToNextYear"
          :disabled="!canGoNext || isLoadingYears"
          class="px-3 py-2 border border-gray-300 rounded-lg bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-white"
          title="Next year"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
          </svg>
        </button>
      </div>
      <div v-if="isLoadingYears" class="text-xs text-blue-600 mt-1">
        Loading available years...
      </div>
      <div v-else class="text-xs text-gray-500 mt-1">
        {{ availableYears.length }} years available ({{ oldestYear }} - {{ newestYear }})
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
    default: new Date().getFullYear()
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
const selectedYear = ref(props.selectedYear || new Date().getFullYear())

// Computed properties
const oldestYear = computed(() => {
  if (!availableYears.value || availableYears.value.length === 0) return 1993
  return Math.min(...availableYears.value)
})
const newestYear = computed(() => {
  if (!availableYears.value || availableYears.value.length === 0) return new Date().getFullYear()
  return Math.max(...availableYears.value)
})

// Check if we can navigate to previous/next year
const canGoPrevious = computed(() => {
  if (!availableYears.value || availableYears.value.length === 0) return false
  const currentIndex = availableYears.value.indexOf(selectedYear.value)
  return currentIndex > 0
})

const canGoNext = computed(() => {
  if (!availableYears.value || availableYears.value.length === 0) return false
  const currentIndex = availableYears.value.indexOf(selectedYear.value)
  return currentIndex >= 0 && currentIndex < availableYears.value.length - 1
})

// Methods
const loadAvailableYears = async () => {
  if (!props.selectedArea) return
  
  // Skip API call for country-level selection (backend doesn't support it)
  const areaType = props.selectedArea.area_type || (props.selectedArea.region_id ? 'district' : 'region')
  if (areaType === 'country' || props.selectedArea.area_type === 'country') {
    // Use default years for country-level selection
    availableYears.value = Array.from({ length: 51 }, (_, i) => 1993 + i)
    selectedYear.value = new Date().getFullYear()
    return
  }
  
  isLoadingYears.value = true
  
  try {
    const response = await axios.get('/api/erosion/available-years', {
      params: {
        area_type: areaType,
        area_id: props.selectedArea.id
      }
    })
    
    if (response.data.success && response.data.data) {
      // Extract available years from the nested data structure
      const yearsData = response.data.data.available_years || response.data.data.years || []
      availableYears.value = Array.isArray(yearsData) ? yearsData : []
      
      if (availableYears.value.length > 0) {
        // Set selected year to the most recent available year
        selectedYear.value = Math.max(...availableYears.value)
      } else {
        // Fallback to default years if empty
        availableYears.value = Array.from({ length: 51 }, (_, i) => 1993 + i)
        selectedYear.value = new Date().getFullYear()
      }
    } else {
      // Fallback to default years if response structure is unexpected
      availableYears.value = Array.from({ length: 51 }, (_, i) => 1993 + i)
      selectedYear.value = new Date().getFullYear()
    }
  } catch (error) {
    console.error('Error loading available years:', error)
    // Fallback to default years
    availableYears.value = Array.from({ length: 51 }, (_, i) => 1993 + i)
  } finally {
    isLoadingYears.value = false
  }
}

const handleYearChange = (event) => {
  const year = parseInt(event.target.value)
  changeYear(year)
}

const changeYear = (year) => {
  // Validate that the year exists in available years
  if (availableYears.value.length > 0 && !availableYears.value.includes(year)) {
    console.warn(`Year ${year} is not available, skipping`)
    return
  }
  
  selectedYear.value = year
  
  // Emit the selected year to trigger data loading and visualization
  emit('update:year', year)
  emit('year-change', year)
}

const goToPreviousYear = () => {
  if (!canGoPrevious.value) return
  
  const currentIndex = availableYears.value.indexOf(selectedYear.value)
  if (currentIndex > 0) {
    const previousYear = availableYears.value[currentIndex - 1]
    changeYear(previousYear)
  }
}

const goToNextYear = () => {
  if (!canGoNext.value) return
  
  const currentIndex = availableYears.value.indexOf(selectedYear.value)
  if (currentIndex < availableYears.value.length - 1) {
    const nextYear = availableYears.value[currentIndex + 1]
    changeYear(nextYear)
  }
}

// Watchers
watch(() => props.selectedArea, () => {
  loadAvailableYears()
}, { immediate: true })

// Lifecycle
onMounted(() => {
  // Initialize with default years if no area is selected
  if (!props.selectedArea) {
    availableYears.value = Array.from({ length: 51 }, (_, i) => 1993 + i)
    selectedYear.value = props.selectedYear || new Date().getFullYear()
  }
})

// Watch for prop changes
watch(() => props.selectedYear, (newYear) => {
  if (newYear && newYear !== selectedYear.value) {
    selectedYear.value = newYear
  }
})
</script>

<style scoped>
/* Custom styles for better appearance */
select:focus {
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
</style>