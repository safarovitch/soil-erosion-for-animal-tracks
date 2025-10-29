<template>
  <div class="space-y-4">
    <h3 class="text-lg font-semibold text-gray-900">Area Selection</h3>

    <!-- Area Type Selector -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Area Selection
      </label>
      <div class="relative">
        <button
          @click="toggleAreaTypeDropdown"
          class="w-full px-4 py-3 text-left bg-white border border-gray-300 rounded-lg shadow-sm hover:border-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors duration-200 flex items-center justify-between"
        >
          <span class="text-gray-900">{{ getAreaTypeLabel(selectedAreaType) }}</span>
          <svg class="w-5 h-5 text-gray-400 transition-transform duration-200" :class="{ 'rotate-180': showAreaTypeDropdown }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
          </svg>
        </button>
        
        <div v-if="showAreaTypeDropdown" class="absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg">
          <div class="py-1">
            <button
              @click="selectAreaType('')"
              class="w-full px-4 py-2 text-left text-gray-700 hover:bg-gray-100 transition-colors duration-150"
            >
              Select area type
            </button>
            <button
              @click="selectAreaType('country')"
              class="w-full px-4 py-2 text-left text-gray-700 hover:bg-gray-100 transition-colors duration-150"
            >
              🇹🇯 Tajikistan (Country-wide)
            </button>
            <button
              @click="selectAreaType('region')"
              class="w-full px-4 py-2 text-left text-gray-700 hover:bg-gray-100 transition-colors duration-150"
            >
              🏛️ Region (Viloyat)
            </button>
            <button
              @click="selectAreaType('district')"
              :disabled="!selectedRegion"
              class="w-full px-4 py-2 text-left transition-colors duration-150"
              :class="selectedRegion ? 'text-gray-700 hover:bg-gray-100' : 'text-gray-400 cursor-not-allowed'"
            >
              🏘️ District (Nohiya)
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Region Selector -->
    <div v-if="selectedAreaType === 'region'">
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Regions (Viloyat) - Select Multiple
      </label>
      <div class="max-h-48 overflow-y-auto border border-gray-300 rounded-md p-2 space-y-2">
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

    <!-- District Selector -->
    <div v-if="selectedAreaType === 'district' && selectedRegionIds.length > 0">
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Districts (Nohiya) - Select Multiple
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

    <!-- Selected Areas Information -->
    <div v-if="selectedAreas.length > 0" class="bg-gray-50 rounded-lg p-4">
      <h4 class="text-md font-medium text-gray-900 mb-2">
        Selected Areas ({{ selectedAreas.length }})
        <span v-if="selectedAreas.length > 10" class="text-xs text-blue-600 ml-2">
          (All areas auto-selected)
        </span>
      </h4>
      <div class="space-y-2 max-h-32 overflow-y-auto">
        <div
          v-for="area in selectedAreas.slice(0, 10)"
          :key="`${area.type}-${area.id}`"
          class="flex items-center justify-between bg-white rounded p-2"
        >
          <div class="flex-1">
            <div class="text-sm font-medium text-gray-900">{{ area.name_en }}</div>
            <div class="text-xs text-gray-500">{{ area.name_tj }}</div>
          </div>
          <button
            @click="removeArea(area)"
            class="text-red-500 hover:text-red-700 text-sm"
            title="Remove area"
          >
            ✕
          </button>
        </div>
        <div v-if="selectedAreas.length > 10" class="text-xs text-blue-600 text-center py-2">
          ... and {{ selectedAreas.length - 10 }} more areas
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
import { ref, computed, onMounted, watch } from 'vue'

// Props
const props = defineProps({
  selectedRegion: Object,
  selectedDistrict: Object,
  selectedAreaType: String,
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
const emit = defineEmits(['update:selectedRegion', 'update:selectedDistrict', 'update:selectedAreaType', 'region-change', 'district-change', 'area-type-change', 'areas-change'])

// Reactive data
const loading = ref(false)
const selectedRegionIds = ref([])
const selectedDistrictIds = ref([])
const showAreaTypeDropdown = ref(false)

// Computed properties
const selectedAreas = computed(() => {
  const areas = []
  
  // Add country if selected
  if (props.selectedAreaType === 'country') {
    areas.push({ 
      id: 0, 
      name_en: 'Tajikistan', 
      name_tj: 'Тоҷикистон', 
      type: 'country',
      area_type: 'country' 
    })
  }
  
  // Add selected regions
  selectedRegionIds.value.forEach(regionId => {
    const region = props.regions.find(r => r.id === regionId)
    if (region) {
      areas.push({ ...region, type: 'region' })
    }
  })
  
  // Add selected districts
  selectedDistrictIds.value.forEach(districtId => {
    const district = props.districts.find(d => d.id === districtId)
    if (district) {
      areas.push({ ...district, type: 'district' })
    }
  })
  
  return areas
})

const filteredDistricts = computed(() => {
  if (selectedRegionIds.value.length === 0) return []
  
  return props.districts.filter(district => 
    selectedRegionIds.value.includes(district.region_id)
  )
})

// Watchers to sync with external changes
watch(() => props.selectedRegion, (newRegion) => {
  if (newRegion) {
    selectedRegionIds.value = [newRegion.id]
    selectedAreaType.value = 'region'
  } else if (!props.selectedDistrict) {
    selectedRegionIds.value = []
  }
}, { immediate: true })

watch(() => props.selectedDistrict, (newDistrict) => {
  if (newDistrict) {
    selectedDistrictIds.value = [newDistrict.id]
    selectedAreaType.value = 'district'
    // Also select the parent region
    if (newDistrict.region_id) {
      selectedRegionIds.value = [newDistrict.region_id]
    }
  } else if (!props.selectedRegion) {
    selectedDistrictIds.value = []
  }
}, { immediate: true })

watch(() => props.selectedAreaType, (newAreaType) => {
  selectedAreaType.value = newAreaType
}, { immediate: true })

// Methods
// Regions and districts are now provided via props from parent component
// No need to load from API since data comes from GeoJSON + existing API data

const toggleAreaTypeDropdown = () => {
  showAreaTypeDropdown.value = !showAreaTypeDropdown.value
}

const selectAreaType = (areaType) => {
  selectedAreaType.value = areaType
  emit('update:selectedAreaType', areaType)
  emit('area-type-change', areaType)
  
  // Clear selections when area type changes
  clearSelection()
  
  // Close dropdown
  showAreaTypeDropdown.value = false
}

const getAreaTypeLabel = (areaType) => {
  switch (areaType) {
    case 'country':
      return '🇹🇯 Tajikistan (Country-wide)'
    case 'region':
      return '🏛️ Region (Viloyat)'
    case 'district':
      return '🏘️ District (Nohiya)'
    default:
      return 'Select area type'
  }
}

const handleAreaTypeChange = (event) => {
  const areaType = event.target.value
  
  emit('update:selectedAreaType', areaType)
  emit('area-type-change', areaType)
  
  // Clear selections when changing area type
  emit('update:selectedRegion', null)
  emit('update:selectedDistrict', null)
  emit('region-change', null)
  emit('district-change', null)
}

const handleRegionChange = (event) => {
  const regionId = parseInt(event.target.value)
  const region = props.regions.find(r => r.id === regionId)

  emit('update:selectedRegion', region)
  emit('region-change', region)

  // Clear district selection when region changes
  emit('update:selectedDistrict', null)
  emit('district-change', null)

  // Districts are now filtered by the parent component based on the selected region
  // No need to load districts here since they come from props
}

const handleDistrictChange = (event) => {
  const districtId = parseInt(event.target.value)
  const district = props.districts.find(d => d.id === districtId)

  emit('update:selectedDistrict', district)
  emit('district-change', district)
}

const handleRegionSelectionChange = () => {
  console.log('Region selection changed:', selectedRegionIds.value)
  
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
  
  emit('update:selectedAreaType', '')
  emit('update:selectedRegion', null)
  emit('update:selectedDistrict', null)
  emit('area-type-change', '')
  emit('region-change', null)
  emit('district-change', null)
  emit('areas-change', [])
}

// No need to watch for region changes or load data on mount
// since regions and districts are provided via props from parent component
</script>
