<template>
  <div v-if="activeLayers.length > 0" class="absolute bottom-6 right-6 bg-white rounded-lg shadow-lg p-4 max-w-xs z-20">
    <div v-for="layer in activeLayers" :key="layer.id" class="mb-4 last:mb-0">
      <h4 class="font-semibold text-sm mb-2">{{ layer.name }}</h4>
      
      <!-- Erosion Legend -->
      <div v-if="layer.id === 'erosion'" class="space-y-1">
        <div v-for="(item, idx) in erosionLegend" :key="idx" class="flex items-center text-xs">
          <div class="w-6 h-4 rounded mr-2" :style="{ backgroundColor: item.color }"></div>
          <span>{{ item.label }}: {{ item.range }}</span>
        </div>
      </div>

      <!-- Rainfall Slope Legend (Diverging) -->
      <div v-else-if="layer.id === 'rainfall_slope'" class="space-y-1">
        <div class="text-xs mb-1">% change per year</div>
        <div class="flex items-center">
          <div class="flex-1 h-4 rounded" style="background: linear-gradient(to right, #dc2626, #ffffff, #16a34a)"></div>
        </div>
        <div class="flex justify-between text-xs mt-1">
          <span>-10% (Decreasing)</span>
          <span>0%</span>
          <span>+10% (Increasing)</span>
        </div>
      </div>

      <!-- Rainfall CV Legend (Sequential) -->
      <div v-else-if="layer.id === 'rainfall_cv'" class="space-y-1">
        <div class="text-xs mb-1">Coefficient of Variation</div>
        <div class="flex items-center">
          <div class="flex-1 h-4 rounded" style="background: linear-gradient(to right, #16a34a, #eab308, #dc2626)"></div>
        </div>
        <div class="flex justify-between text-xs mt-1">
          <span>Low (0%)</span>
          <span>Medium (25%)</span>
          <span>High (50%+)</span>
        </div>
      </div>

      <!-- RUSLE Factor Legends (Sequential) -->
      <div v-else-if="['r_factor', 'k_factor', 'ls_factor', 'c_factor', 'p_factor'].includes(layer.id)" class="space-y-1">
        <div class="text-xs mb-1">{{ getFactorUnit(layer.id) }}</div>
        <div class="flex items-center">
          <div class="flex-1 h-4 rounded" style="background: linear-gradient(to right, #eff6ff, #3b82f6, #1e3a8a)"></div>
        </div>
        <div class="flex justify-between text-xs mt-1">
          <span>Low</span>
          <span>Medium</span>
          <span>High</span>
        </div>
      </div>

      <!-- Generic Legend -->
      <div v-else class="text-xs text-gray-600">
        Layer active
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  visibleLayers: {
    type: Array,
    default: () => []
  },
  availableLayers: {
    type: Array,
    default: () => []
  }
})

// Filter to get active layers with details
const activeLayers = computed(() => {
  return props.visibleLayers
    .map(layerId => props.availableLayers.find(l => l.id === layerId))
    .filter(Boolean)
})

// Erosion legend data
const erosionLegend = [
  { label: 'Very Low', range: '0-5 t/ha/yr', color: 'rgba(34, 139, 34, 0.7)' },
  { label: 'Low', range: '5-15 t/ha/yr', color: 'rgba(255, 215, 0, 0.7)' },
  { label: 'Moderate', range: '15-30 t/ha/yr', color: 'rgba(255, 140, 0, 0.7)' },
  { label: 'Severe', range: '30-50 t/ha/yr', color: 'rgba(220, 20, 60, 0.7)' },
  { label: 'Excessive', range: '>50 t/ha/yr', color: 'rgba(139, 0, 0, 0.8)' },
]

// Get unit for RUSLE factors
const getFactorUnit = (layerId) => {
  const units = {
    r_factor: 'MJ mm/(ha h yr)',
    k_factor: 't ha h/(ha MJ mm)',
    ls_factor: 'dimensionless',
    c_factor: '0-1 (dimensionless)',
    p_factor: '0-1 (dimensionless)',
  }
  return units[layerId] || ''
}
</script>

<style scoped>
/* Ensure legend stays on top but below controls */
</style>

