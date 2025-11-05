<template>
  <div class="space-y-4">
    <h3 class="text-lg font-semibold text-gray-900">Area Selection</h3>

    <!-- Region Selector - Always Visible -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Select Regions (Viloyat)
      </label>
      <div class="overflow-y-auto border border-gray-300 rounded-md p-2 space-y-2">
        <div
          v-for="region in props.regions"
          :key="region.id"
          class="flex items-center space-x-2"
        >
          <input
            type="checkbox"
            :id="`region-${region.id}`"
            :value="region.id"
            v-model="selectedRegionIds"
            @change="handleRegionSelectionChange"
            class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
          />
          <label :for="`region-${region.id}`" class="text-sm text-gray-700 cursor-pointer">
            {{ region.name_en }} ({{ region.name_tj }})
          </label>
        </div>
      </div>
    </div>

    <!-- District Selector - Only shown when exactly one region is selected -->
    <div v-if="selectedRegionIds.length === 1 && filteredDistricts.length > 0">
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Select Districts (Nohiya) from {{ getSelectedRegionName() }}
      </label>
      <div class="max-h-48 overflow-y-auto border border-gray-300 rounded-md p-2 space-y-2">
        <div
          v-for="district in filteredDistricts"
          :key="district.id"
          class="flex items-center space-x-2"
        >
          <input
            type="checkbox"
            :id="`district-${district.id}`"
            :value="district.id"
            v-model="selectedDistrictIds"
            @change="handleDistrictSelectionChange"
            class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
          />
          <label :for="`district-${district.id}`" class="text-sm text-gray-700 cursor-pointer">
            {{ district.name_en }} ({{ district.name_tj }})
          </label>
        </div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="flex space-x-2">
      <button
        @click="loadRegions"
        class="flex-1 bg-blue-600 text-white px-3 py-2 rounded-md hover:bg-blue-700 text-sm"
      >
        Refresh Regions
      </button>
      <button
        @click="clearSelection"
        class="flex-1 bg-gray-600 text-white px-3 py-2 rounded-md hover:bg-gray-700 text-sm"
      >
        Clear Selection
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'

// Props
const props = defineProps({
  selectedRegion: Object,
  selectedDistrict: Object,
  selectedAreas: {
    type: Array,
    default: () => []
  },
  regions: {
    type: Array,
    default: () => []
  },
  districts: {
    type: Array,
    default: () => []
  }
})

// Emits
const emit = defineEmits(['update:selectedRegion', 'update:selectedDistrict', 'region-change', 'district-change', 'areas-change'])

// Reactive data
const loading = ref(false)
const selectedRegionIds = ref([])
const selectedDistrictIds = ref([])

// Computed properties
const selectedAreas = computed(() => {
  const areas = []
  
  // Add selected regions (only if no districts selected)
  if (selectedDistrictIds.value.length === 0) {
    selectedRegionIds.value.forEach(regionId => {
      const region = props.regions.find(r => r.id === regionId)
      if (region) {
        areas.push({ ...region, type: 'region' })
      }
    })
  }
  
  // Add selected districts (takes priority over regions)
  selectedDistrictIds.value.forEach(districtId => {
    const district = props.districts.find(d => d.id === districtId)
    if (district) {
      areas.push({ ...district, type: 'district' })
    }
  })
  
  return areas
})

const filteredDistricts = computed(() => {
  // Only show districts when exactly one region is selected
  if (selectedRegionIds.value.length !== 1) return []
  
  return props.districts.filter(district => 
    district.region_id === selectedRegionIds.value[0]
  )
})

// Watchers to sync with external changes
watch(() => props.selectedRegion, (newRegion) => {
  if (newRegion) {
    selectedRegionIds.value = [newRegion.id]
  } else if (!props.selectedDistrict) {
    selectedRegionIds.value = []
  }
}, { immediate: true })

watch(() => props.selectedDistrict, (newDistrict) => {
  if (newDistrict) {
    selectedDistrictIds.value = [newDistrict.id]
    // Also select the parent region
    if (newDistrict.region_id) {
      selectedRegionIds.value = [newDistrict.region_id]
    }
  } else if (!props.selectedRegion) {
    selectedDistrictIds.value = []
  }
}, { immediate: true })

// Watch external selectedAreas from map clicks
watch(() => props.selectedAreas, (newSelectedAreas) => {
  if (!newSelectedAreas || newSelectedAreas.length === 0) {
    selectedRegionIds.value = []
    selectedDistrictIds.value = []
    return
  }

  // Extract region and district IDs from selected areas
  const regionIds = []
  const districtIds = []
  
  newSelectedAreas.forEach(area => {
    if (area.type === 'region' || (!area.region_id && !area.district_id && area.id)) {
      regionIds.push(area.id)
    } else if (area.type === 'district' || area.region_id) {
      districtIds.push(area.id)
    }
  })

  // Update checkbox states to match
  selectedRegionIds.value = regionIds
  selectedDistrictIds.value = districtIds
}, { deep: true })

// Methods
const getSelectedRegionName = () => {
  if (selectedRegionIds.value.length !== 1) return ''
  const region = props.regions.find(r => r.id === selectedRegionIds.value[0])
  return region ? region.name_en : ''
}

const loadRegions = () => {
  // Regions are provided via props, no need to reload
  console.log('Regions are loaded from props')
}

const handleRegionSelectionChange = () => {
  console.log('Region selection changed:', selectedRegionIds.value)
  
  // Clear districts when changing regions
  if (selectedRegionIds.value.length !== 1) {
    selectedDistrictIds.value = []
  }
  
  // Emit the list of selected areas
  emit('areas-change', selectedAreas.value)
}

const handleDistrictSelectionChange = () => {
  console.log('District selection changed:', selectedDistrictIds.value)
  
  // Emit the list of selected areas
  emit('areas-change', selectedAreas.value)
}

const clearSelection = () => {
  selectedRegionIds.value = []
  selectedDistrictIds.value = []
  
  emit('update:selectedRegion', null)
  emit('update:selectedDistrict', null)
  emit('region-change', null)
  emit('district-change', null)
  emit('areas-change', [])
}

// Auto-select Dushanbe region on component mount
onMounted(async () => {
  // Find Dushanbe region by name
  const dushanbeRegion = props.regions.find(r => r.name_en === 'Dushanbe')
  if (dushanbeRegion && selectedRegionIds.value.length === 0) {
    selectedRegionIds.value = [dushanbeRegion.id]
    // Wait for computed property to update
    await nextTick()
    // Emit the selection
    emit('areas-change', selectedAreas.value)
    console.log('Auto-selected Dushanbe region:', selectedAreas.value)
  }
})
</script>
