<template>
  <div class="space-y-4">
    <h3 class="text-lg font-semibold text-gray-900">Area Selection</h3>

    <!-- Region Selector -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Region (Viloyat)
      </label>
      <select
        :value="selectedRegion?.id || ''"
        @change="handleRegionChange"
        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
      >
        <option value="">Select a region</option>
        <option
          v-for="region in props.regions"
          :key="region.id"
          :value="region.id"
        >
          {{ region.name_en }} ({{ region.name_tj }})
        </option>
      </select>
    </div>

    <!-- District Selector -->
    <div v-if="selectedRegion">
      <label class="block text-sm font-medium text-gray-700 mb-2">
        District (Nohiya)
      </label>
      <select
        :value="selectedDistrict?.id || ''"
        @change="handleDistrictChange"
        :disabled="!props.districts.length"
        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-100"
      >
        <option value="">Select a district</option>
        <option
          v-for="district in props.districts"
          :key="district.id"
          :value="district.id"
        >
          {{ district.name_en }} ({{ district.name_tj }})
        </option>
      </select>
    </div>

    <!-- Area Information -->
    <div v-if="selectedArea" class="bg-gray-50 rounded-lg p-4">
      <h4 class="text-md font-medium text-gray-900 mb-2">Selected Area</h4>
      <div class="space-y-1 text-sm text-gray-600">
        <div class="flex justify-between">
          <span>Name:</span>
          <span class="font-medium">{{ selectedArea.name_en }}</span>
        </div>
        <div class="flex justify-between">
          <span>Tajik Name:</span>
          <span class="font-medium">{{ selectedArea.name_tj }}</span>
        </div>
        <div class="flex justify-between">
          <span>Code:</span>
          <span class="font-medium">{{ selectedArea.code }}</span>
        </div>
        <div v-if="selectedArea.area_km2" class="flex justify-between">
          <span>Area:</span>
          <span class="font-medium">{{ selectedArea.area_km2 }} km²</span>
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
const emit = defineEmits(['update:selectedRegion', 'update:selectedDistrict', 'region-change', 'district-change'])

// Reactive data
// Use props.regions and props.districts instead of local refs
const loading = ref(false)

// Computed properties
const selectedArea = computed(() => {
  return props.selectedDistrict || props.selectedRegion
})

// Methods
// Regions and districts are now provided via props from parent component
// No need to load from API since data comes from GeoJSON + existing API data

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

const clearSelection = () => {
  emit('update:selectedRegion', null)
  emit('update:selectedDistrict', null)
  emit('region-change', null)
  emit('district-change', null)
}

// No need to watch for region changes or load data on mount
// since regions and districts are provided via props from parent component
</script>
