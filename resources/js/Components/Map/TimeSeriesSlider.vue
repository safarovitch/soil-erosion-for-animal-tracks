<template>
  <div class="space-y-4">
    <h3 class="text-lg font-semibold text-gray-900">Year Range</h3>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Select 10-Year Period
      </label>

      <div
        role="radiogroup"
        class="grid gap-2"
      >
        <button
          v-for="period in periods"
          :key="period.id"
          type="button"
          :aria-pressed="period.id === selectedPeriodId"
          :class="[
            'w-full px-3 py-2 rounded-lg border transition-colors duration-200 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2',
            period.id === selectedPeriodId
              ? 'border-blue-500 bg-blue-50 text-blue-700'
              : 'border-gray-300 bg-white hover:bg-gray-50 text-gray-700'
          ]"
          @click="selectPeriod(period)"
        >
          <div class="flex items-center justify-between">
            <span class="font-medium">{{ period.label }}</span>
            <span
              v-if="period.id === selectedPeriodId"
              class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-blue-500 text-white text-xs"
            >
              ✓
            </span>
          </div>
          <p class="text-xs text-gray-500 mt-1">
            {{ period.startYear }} – {{ period.endYear }}
          </p>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { YEAR_PERIODS, DEFAULT_YEAR_PERIOD, findYearPeriodById } from '@/constants/yearPeriods.js'

const props = defineProps({
  selectedPeriod: {
    type: Object,
    default: () => DEFAULT_YEAR_PERIOD,
  },
})

const emit = defineEmits(['update:period', 'period-change'])

const periods = computed(() => YEAR_PERIODS)
const selectedPeriodId = ref((props.selectedPeriod && props.selectedPeriod.id) || DEFAULT_YEAR_PERIOD.id)

const selectPeriod = (period) => {
  selectedPeriodId.value = period.id
  emit('period-change', period)
}

watch(
  () => props.selectedPeriod,
  (newPeriod) => {
    if (newPeriod && newPeriod.id !== selectedPeriodId.value) {
      selectedPeriodId.value = newPeriod.id
    }
  },
  { immediate: true, deep: true }
)

watch(
  () => selectedPeriodId.value,
  (newId, oldId) => {
    if (newId !== oldId) {
      const period = findYearPeriodById(newId)
      emit('update:period', period)
    }
  }
)
</script>