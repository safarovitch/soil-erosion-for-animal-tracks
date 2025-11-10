<style scoped>
.region-select-wrapper,
.district-select-wrapper {
  min-height: 2.625rem;
}

.region-select :deep(.vs__dropdown-toggle),
.district-select :deep(.vs__dropdown-toggle) {
  border-color: #d1d5db;
  border-radius: 0.75rem;
  min-height: 2.625rem;
  padding: 0.25rem 0.75rem;
  background-color: #ffffff;
  display: flex;
  align-items: center;
}

.region-select :deep(.vs__selected),
.district-select :deep(.vs__selected) {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  background-color: #e5f0ff;
  color: #1d4ed8;
  border-radius: 9999px;
  font-size: 0.75rem;
  padding: 0.125rem 0.5rem;
}

.district-select :deep(.vs__selected) {
  margin-right: 0.25rem;
  margin-bottom: 0.25rem;
}

.region-select :deep(.vs__open-indicator),
.district-select :deep(.vs__open-indicator) {
  color: #1f2937;
}

.region-select :deep(.vs__dropdown-menu),
.district-select :deep(.vs__dropdown-menu) {
  font-size: 0.875rem;
  line-height: 1.25rem;
}

.region-select :deep(.vs__search),
.district-select :deep(.vs__search) {
  padding: 0.25rem 0;
}
</style>
<template>
  <div class="space-y-4">
    <h3 class="text-lg font-semibold text-gray-900">Area Selection</h3>

    <!-- Region Selector -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Select Region
      </label>
      <div class="region-select-wrapper">
        <v-select
          v-model="selectedRegionOption"
          :options="regionOptions"
          label="name_en"
          :clearable="false"
          :searchable="true"
          :multiple="isRegionMultiselect"
          :close-on-select="isRegionMultiselect ? false : true"
          :clear-on-select="false"
          class="region-select"
          :placeholder="isRegionMultiselect ? 'Choose one or more regions' : 'Choose a region'"
          :reduce="option => option"
        >
        <template #selected-option="{ option, deselect }">
          <div v-if="option" class="flex flex-col text-sm leading-snug">
            <span v-if="!isRegionMultiselect" class="font-medium text-gray-900">{{ option?.name_en || '' }}</span>
            <span v-else class="inline-flex items-center rounded-full bg-blue-100 text-blue-700 text-xs font-medium px-2 py-0.5 mr-1 mb-1">
              {{ option?.name_en || '' }}
              <button
                type="button"
                class="ml-1 text-blue-500 hover:text-blue-700 focus:outline-none"
                @click.stop="deselect(option)"
              >
                ×
              </button>
            </span>
            <span v-if="!isRegionMultiselect && option?.name_tj" class="text-xs text-gray-500">{{ option.name_tj }}</span>
          </div>
        </template>
        <template #option="{ option }">
          <div v-if="option" class="flex flex-col text-sm leading-snug">
            <span class="font-medium text-gray-900">{{ option?.name_en || '' }}</span>
            <span v-if="option?.name_tj" class="text-xs text-gray-500">{{ option.name_tj }}</span>
          </div>
        </template>
        <template #no-options>
          <div class="text-sm text-gray-500 py-2 text-center">
            No regions available.
          </div>
        </template>
      </v-select>
      </div>
    </div>

    <!-- District Selector -->
    <div v-if="showDistrictSelector">
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Select Districts (Nohiya)
      </label>
      <div class="district-select-wrapper">
        <v-select
          v-model="selectedDistrictOptions"
          :options="filteredDistricts"
          label="name_en"
          multiple
          :close-on-select="false"
          :clear-on-select="false"
          :searchable="true"
          class="district-select"
          placeholder="Pick districts or leave empty for entire region"
          :reduce="option => option"
        >
        <template #selected-option="{ option, deselect }">
          <span
            v-if="option"
            class="inline-flex items-center rounded-full bg-blue-100 text-blue-700 text-xs font-medium px-2 py-0.5 mr-1 mb-1"
          >
            {{ option?.name_en || '' }}
            <button
              type="button"
              class="ml-1 text-blue-500 hover:text-blue-700 focus:outline-none"
              @click.stop="deselect(option)"
            >
              ×
            </button>
          </span>
        </template>
        <template #option="{ option }">
          <div v-if="option" class="flex flex-col text-sm leading-snug">
            <span class="font-medium text-gray-900">{{ option?.name_en || '' }}</span>
            <span v-if="option?.name_tj" class="text-xs text-gray-500">{{ option.name_tj }}</span>
          </div>
        </template>
        <template #no-options>
          <div class="text-sm text-gray-500 py-2 text-center">
            No districts available for this region.
          </div>
        </template>
      </v-select>
      </div>
      <p class="text-xs text-gray-500 mt-2">
        Leave blank to analyze the entire region.
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import vSelect from 'vue-select'
import 'vue-select/dist/vue-select.css'

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
const emit = defineEmits([
  'update:selectedRegion',
  'update:selectedDistrict',
  'region-change',
  'district-change',
  'areas-change'
])

const countryOption = computed(() => ({
  id: 0,
  name_en: 'Tajikistan (Country-wide)',
  name_tj: 'Тоҷикистон',
  area_type: 'country'
}))

const regionOptions = computed(() => {
  if (!props.regions || props.regions.length === 0) {
    return [countryOption.value]
  }
  return [countryOption.value, ...props.regions]
})

const selectedRegionOption = ref(null)
const selectedDistrictOptions = ref([])
const suppressRegionEmit = ref(false)
const suppressDistrictEmit = ref(false)

watch(selectedRegionOption, (region) => {
  handleRegionChange(region)
}, { deep: true })

watch(selectedDistrictOptions, () => {
  handleDistrictChange()
}, { deep: true })

// Check if region selector should be multiselect
// Multiselect when: region is selected AND no district is selected
const isRegionMultiselect = computed(() => {
  if (!selectedRegionOption.value) return false
  if (Array.isArray(selectedRegionOption.value)) {
    // If multiple regions selected, check if any districts are selected
    return selectedDistrictOptions.value.length === 0
  }
  // Single region selected - multiselect if no districts selected
  return selectedDistrictOptions.value.length === 0 && 
         selectedRegionOption.value.id !== countryOption.value.id
})

// Show district selector only when single region is selected (not multiple)
const showDistrictSelector = computed(() => {
  if (!selectedRegionOption.value) return false
  
  // If multiple regions selected, don't show district selector
  if (Array.isArray(selectedRegionOption.value)) {
    return false
  }
  
  // Single region selected - show district selector if not country
  return selectedRegionOption.value.id !== countryOption.value.id
})

const filteredDistricts = computed(() => {
  if (!showDistrictSelector.value) {
    return []
  }

  // Handle single region selection
  if (!Array.isArray(selectedRegionOption.value)) {
    return props.districts.filter(district => district.region_id === selectedRegionOption.value.id)
  }

  // Multiple regions - return districts from all selected regions
  const selectedRegionIds = selectedRegionOption.value
    .filter(r => r.id !== countryOption.value.id)
    .map(r => r.id)
  
  return props.districts.filter(district => selectedRegionIds.includes(district.region_id))
})

const buildAreasPayload = () => {
  if (!selectedRegionOption.value) {
    return []
  }

  // Handle multiple regions selected
  if (Array.isArray(selectedRegionOption.value)) {
    return selectedRegionOption.value.map(region => {
      if (region.id === countryOption.value.id) {
        return {
          ...countryOption.value,
          type: 'country'
        }
      }
      return {
        ...region,
        type: 'region'
      }
    })
  }

  // Single region selected
  if (selectedRegionOption.value.id === countryOption.value.id) {
    return [{
      ...countryOption.value,
      type: 'country'
    }]
  }

  // If districts are selected, return districts
  if (selectedDistrictOptions.value.length > 0) {
    return selectedDistrictOptions.value.map(district => ({
      ...district,
      type: 'district'
    }))
  }

  // Single region, no districts selected
  return [{
    ...selectedRegionOption.value,
    type: 'region'
  }]
}

const handleRegionChange = (region) => {
  const shouldEmit = !suppressRegionEmit.value
  suppressRegionEmit.value = false

  if (!shouldEmit || !region) {
    suppressDistrictEmit.value = true
  }

  // Clear districts when region changes (unless we're suppressing)
  if (shouldEmit) {
    selectedDistrictOptions.value = []
  }

  if (!region || (Array.isArray(region) && region.length === 0)) {
    if (shouldEmit) {
      emit('update:selectedRegion', null)
      emit('update:selectedDistrict', null)
      emit('region-change', null)
      emit('district-change', null)
      emit('areas-change', [])
    }
    return
  }

  if (shouldEmit) {
    // Handle multiple regions selected
    if (Array.isArray(region)) {
      // Filter out country option if multiple regions selected
      const regionsOnly = region.filter(r => r.id !== countryOption.value.id)
      
      if (regionsOnly.length === 0) {
        // Only country selected
        emit('update:selectedRegion', { ...countryOption.value })
        emit('update:selectedDistrict', null)
        emit('region-change', { ...countryOption.value })
        emit('district-change', null)
        emit('areas-change', buildAreasPayload())
      } else if (regionsOnly.length === 1) {
        // Single region selected from multiselect
        emit('update:selectedRegion', regionsOnly[0])
        emit('update:selectedDistrict', null)
        emit('region-change', regionsOnly[0])
        emit('district-change', null)
        emit('areas-change', buildAreasPayload())
      } else {
        // Multiple regions selected
        emit('update:selectedRegion', null) // Clear single region selection
        emit('update:selectedDistrict', null)
        emit('region-change', null)
        emit('district-change', null)
        emit('areas-change', buildAreasPayload())
      }
    } else {
      // Single region selected
      if (region.id === countryOption.value.id) {
        emit('update:selectedRegion', { ...countryOption.value })
        emit('update:selectedDistrict', null)
        emit('region-change', { ...countryOption.value })
        emit('district-change', null)
      } else {
        emit('update:selectedRegion', region)
        emit('update:selectedDistrict', null)
        emit('region-change', region)
        emit('district-change', null)
      }
      emit('areas-change', buildAreasPayload())
    }
  }
}

const handleDistrictChange = () => {
  const shouldEmit = !suppressDistrictEmit.value
  suppressDistrictEmit.value = false

  if (!shouldEmit) {
    return
  }

  if (selectedDistrictOptions.value.length === 0) {
    emit('update:selectedDistrict', null)
    emit('district-change', null)
  } else if (selectedDistrictOptions.value.length === 1) {
    emit('update:selectedDistrict', selectedDistrictOptions.value[0])
    emit('district-change', selectedDistrictOptions.value[0])
  } else {
    emit('update:selectedDistrict', null)
    emit('district-change', null)
  }

  emit('areas-change', buildAreasPayload())
}

const clearSelection = () => {
  selectedRegionOption.value = null
}

watch(() => props.selectedRegion, (newRegion) => {
  if (!newRegion) {
    suppressRegionEmit.value = true
    selectedRegionOption.value = null
    suppressDistrictEmit.value = true
    selectedDistrictOptions.value = []
    return
  }

  if (newRegion.area_type === 'country' || newRegion.id === countryOption.value.id) {
    suppressRegionEmit.value = true
    selectedRegionOption.value = countryOption.value
    suppressDistrictEmit.value = true
    selectedDistrictOptions.value = []
  } else {
    suppressRegionEmit.value = true
    const region = props.regions.find(region => region.id === newRegion.id)
    if (region) {
      selectedRegionOption.value = region
    }
  }
}, { immediate: true })

watch(() => props.selectedDistrict, (newDistrict) => {
  if (!newDistrict) {
    suppressDistrictEmit.value = true
    selectedDistrictOptions.value = []
    return
  }

  if (!selectedRegionOption.value || selectedRegionOption.value.id !== newDistrict.region_id) {
    const parentRegion = props.regions.find(region => region.id === newDistrict.region_id)
    if (parentRegion) {
      suppressRegionEmit.value = true
      selectedRegionOption.value = parentRegion
    }
  }

  const districtOption = props.districts.find(district => district.id === newDistrict.id)
  suppressDistrictEmit.value = true
  selectedDistrictOptions.value = districtOption ? [districtOption] : []
}, { immediate: true })

watch(() => props.selectedAreas, (areas) => {
  if (!areas || areas.length === 0) {
    suppressRegionEmit.value = true
    selectedRegionOption.value = null
    suppressDistrictEmit.value = true
    selectedDistrictOptions.value = []
    return
  }

  // Check if we have multiple regions or districts from different regions
  const regions = areas.filter(a => 
    (a.type === 'region' || a.area_type === 'region' || (!a.region_id && a.id && a.id !== 0)) &&
    (a.type !== 'country' && a.area_type !== 'country')
  )
  const districts = areas.filter(a => 
    a.type === 'district' || a.area_type === 'district' || a.region_id
  )

  // If multiple regions selected, set as array
  if (regions.length > 1) {
    const regionObjects = regions.map(area => 
      props.regions.find(r => r.id === area.id)
    ).filter(Boolean)
    
    suppressRegionEmit.value = true
    selectedRegionOption.value = regionObjects.length > 0 ? regionObjects : null
    suppressDistrictEmit.value = true
    selectedDistrictOptions.value = []
    return
  }

  // Single region or district
  const primaryArea = areas[0]

  if (primaryArea.type === 'country' || primaryArea.area_type === 'country') {
    suppressRegionEmit.value = true
    selectedRegionOption.value = countryOption.value
    suppressDistrictEmit.value = true
    selectedDistrictOptions.value = []
  } else if (
    primaryArea.type === 'region' ||
    primaryArea.area_type === 'region' ||
    (!primaryArea.region_id && primaryArea.id)
  ) {
    const region = props.regions.find(r => r.id === primaryArea.id)
    suppressRegionEmit.value = true
    selectedRegionOption.value = region || null
    suppressDistrictEmit.value = true
    selectedDistrictOptions.value = []
  } else if (
    primaryArea.type === 'district' ||
    primaryArea.area_type === 'district' ||
    primaryArea.region_id
  ) {
    const region = props.regions.find(r => r.id === primaryArea.region_id)
    const districtObjects = props.districts.filter(d =>
      areas.some(area => area.id === d.id)
    )

    suppressRegionEmit.value = true
    selectedRegionOption.value = region || null
    suppressDistrictEmit.value = true
    selectedDistrictOptions.value = districtObjects
  }
}, { deep: true })

onMounted(() => {
  if (!selectedRegionOption.value) {
    selectedRegionOption.value = countryOption.value
  }
})
</script>

