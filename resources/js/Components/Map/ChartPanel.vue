<template>
  <div class="space-y-4">
    <h3 class="text-lg font-semibold text-gray-900">Charts & Analytics</h3>

    <!-- Time Series Chart -->
    <div v-if="timeSeriesData.length > 0" class="bg-white border rounded-lg p-4">
      <h4 class="text-md font-medium text-gray-900 mb-3">Erosion Trend Over Time</h4>
      <div class="h-48">
        <canvas ref="timeSeriesChart"></canvas>
      </div>
    </div>

    <!-- Area Statistics -->
    <div v-if="selectedArea" class="bg-white border rounded-lg p-4">
      <h4 class="text-md font-medium text-gray-900 mb-3">Area Statistics</h4>

      <!-- Pie Chart for Area Distribution -->
      <div class="h-48 mb-4">
        <canvas ref="areaChart"></canvas>
      </div>

      <!-- Summary Statistics -->
      <div class="grid grid-cols-2 gap-4 text-sm">
        <div class="bg-gray-50 p-3 rounded">
          <div class="text-gray-600">Total Area</div>
          <div class="font-semibold">{{ selectedArea.area_km2 }} km²</div>
        </div>
        <div class="bg-gray-50 p-3 rounded">
          <div class="text-gray-600">Mean Erosion Rate</div>
          <div class="font-semibold">{{ meanErosionRate }} t/ha/yr</div>
        </div>
        <div class="bg-gray-50 p-3 rounded">
          <div class="text-gray-600">Bare Soil Frequency</div>
          <div class="font-semibold">{{ meanBareSoilFreq }}%</div>
        </div>
        <div class="bg-gray-50 p-3 rounded">
          <div class="text-gray-600">Sustainability Factor</div>
          <div class="font-semibold">{{ meanSustainability }}</div>
        </div>
      </div>
    </div>

    <!-- Erosion Distribution Histogram -->
    <div class="bg-white border rounded-lg p-4">
      <h4 class="text-md font-medium text-gray-900 mb-3">Erosion Distribution</h4>
      <div class="h-48">
        <canvas ref="histogramChart"></canvas>
      </div>
    </div>

    <!-- Comparison Chart -->
    <div v-if="comparisonData.length > 0" class="bg-white border rounded-lg p-4">
      <h4 class="text-md font-medium text-gray-900 mb-3">Regional Comparison</h4>
      <div class="h-48">
        <canvas ref="comparisonChart"></canvas>
      </div>
    </div>

    <!-- Export Options -->
    <div class="bg-white border rounded-lg p-4">
      <h4 class="text-md font-medium text-gray-900 mb-3">Export Data</h4>
      <div class="flex space-x-2">
        <button
          @click="exportChartData"
          class="flex-1 bg-blue-600 text-white px-3 py-2 rounded-md hover:bg-blue-700 text-sm"
        >
          Export Charts
        </button>
        <button
          @click="exportStatistics"
          class="flex-1 bg-green-600 text-white px-3 py-2 rounded-md hover:bg-green-700 text-sm"
        >
          Export Statistics
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import {
  Chart,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  BarController,
  LineController,
  PieController,
  ArcElement,
  Title,
  Tooltip,
  Legend,
} from 'chart.js'

// Register Chart.js components
Chart.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  BarController,
  LineController,
  PieController,
  ArcElement,
  Title,
  Tooltip,
  Legend
)

// Props
const props = defineProps({
  timeSeriesData: {
    type: Array,
    default: () => [],
  },
  selectedArea: {
    type: Object,
    default: null,
  },
})

// Reactive data
const timeSeriesChart = ref(null)
const areaChart = ref(null)
const histogramChart = ref(null)
const comparisonChart = ref(null)

const charts = ref({})

// Computed properties
const meanErosionRate = computed(() => {
  if (!props.timeSeriesData.length) return 0
  const sum = props.timeSeriesData.reduce((acc, item) => acc + (item.mean_erosion_rate || 0), 0)
  return (sum / props.timeSeriesData.length).toFixed(3)
})

const meanBareSoilFreq = computed(() => {
  if (!props.timeSeriesData.length) return 0
  const sum = props.timeSeriesData.reduce((acc, item) => acc + (item.bare_soil_frequency || 0), 0)
  return (sum / props.timeSeriesData.length).toFixed(1)
})

const meanSustainability = computed(() => {
  if (!props.timeSeriesData.length) return 0
  const sum = props.timeSeriesData.reduce((acc, item) => acc + (item.sustainability_factor || 0), 0)
  return (sum / props.timeSeriesData.length).toFixed(3)
})

const comparisonData = computed(() => {
  // This would typically come from props or API
  return []
})

// Methods
const createTimeSeriesChart = () => {
  if (!timeSeriesChart.value || !props.timeSeriesData.length) return

  const ctx = timeSeriesChart.value.getContext('2d')

  // Destroy existing chart
  if (charts.value.timeSeries) {
    charts.value.timeSeries.destroy()
  }

  const data = props.timeSeriesData.map(item => ({
    year: item.year,
    erosion: item.mean_erosion_rate,
    bareSoil: item.bare_soil_frequency,
    sustainability: item.sustainability_factor,
  }))

  charts.value.timeSeries = new Chart(ctx, {
    type: 'line',
    data: {
      labels: data.map(d => d.year),
      datasets: [
        {
          label: 'Erosion Rate (t/ha/yr)',
          data: data.map(d => d.erosion),
          borderColor: 'rgb(239, 68, 68)',
          backgroundColor: 'rgba(239, 68, 68, 0.1)',
          tension: 0.1,
        },
        {
          label: 'Bare Soil Frequency (%)',
          data: data.map(d => d.bareSoil),
          borderColor: 'rgb(245, 158, 11)',
          backgroundColor: 'rgba(245, 158, 11, 0.1)',
          tension: 0.1,
          yAxisID: 'y1',
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          type: 'linear',
          display: true,
          position: 'left',
          title: {
            display: true,
            text: 'Erosion Rate (t/ha/yr)',
          },
        },
        y1: {
          type: 'linear',
          display: true,
          position: 'right',
          title: {
            display: true,
            text: 'Bare Soil Frequency (%)',
          },
          grid: {
            drawOnChartArea: false,
          },
        },
      },
    },
  })
}

const createAreaChart = () => {
  if (!areaChart.value) return

  const ctx = areaChart.value.getContext('2d')

  // Destroy existing chart
  if (charts.value.area) {
    charts.value.area.destroy()
  }

  // Sample data - in real app this would come from props
  const data = {
    labels: ['Low Risk', 'Moderate Risk', 'High Risk', 'Very High Risk'],
    datasets: [{
      data: [30, 35, 25, 10],
      backgroundColor: [
        'rgb(34, 197, 94)',
        'rgb(245, 158, 11)',
        'rgb(239, 68, 68)',
        'rgb(127, 29, 29)',
      ],
    }],
  }

  charts.value.area = new Chart(ctx, {
    type: 'pie',
    data,
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom',
        },
      },
    },
  })
}

const createHistogramChart = () => {
  if (!histogramChart.value) return

  const ctx = histogramChart.value.getContext('2d')

  // Destroy existing chart
  if (charts.value.histogram) {
    charts.value.histogram.destroy()
  }

  // Sample histogram data
  const data = {
    labels: ['0-1', '1-2', '2-5', '5-10', '10-20', '20+'],
    datasets: [{
      label: 'Area (km²)',
      data: [150, 200, 300, 180, 120, 50],
      backgroundColor: 'rgba(59, 130, 246, 0.5)',
      borderColor: 'rgb(59, 130, 246)',
      borderWidth: 1,
    }],
  }

  charts.value.histogram = new Chart(ctx, {
    type: 'bar',
    data,
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          title: {
            display: true,
            text: 'Area (km²)',
          },
        },
        x: {
          title: {
            display: true,
            text: 'Erosion Rate (t/ha/yr)',
          },
        },
      },
    },
  })
}

const createComparisonChart = () => {
  if (!comparisonChart.value || !comparisonData.value.length) return

  const ctx = comparisonChart.value.getContext('2d')

  // Destroy existing chart
  if (charts.value.comparison) {
    charts.value.comparison.destroy()
  }

  const data = comparisonData.value

  charts.value.comparison = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: data.map(d => d.name),
      datasets: [{
        label: 'Mean Erosion Rate',
        data: data.map(d => d.erosion),
        backgroundColor: 'rgba(239, 68, 68, 0.5)',
        borderColor: 'rgb(239, 68, 68)',
        borderWidth: 1,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          title: {
            display: true,
            text: 'Erosion Rate (t/ha/yr)',
          },
        },
      },
    },
  })
}

const exportChartData = () => {
  const exportData = {
    timeSeries: props.timeSeriesData,
    area: props.selectedArea,
    statistics: {
      meanErosionRate: meanErosionRate.value,
      meanBareSoilFreq: meanBareSoilFreq.value,
      meanSustainability: meanSustainability.value,
    },
    timestamp: new Date().toISOString(),
  }

  const blob = new Blob([JSON.stringify(exportData, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `soil-erosion-charts-${new Date().toISOString().split('T')[0]}.json`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

const exportStatistics = () => {
  const stats = {
    area: props.selectedArea,
    timeSeries: props.timeSeriesData,
    summary: {
      meanErosionRate: meanErosionRate.value,
      meanBareSoilFreq: meanBareSoilFreq.value,
      meanSustainability: meanSustainability.value,
      totalArea: props.selectedArea?.area_km2 || 0,
    },
    timestamp: new Date().toISOString(),
  }

  const blob = new Blob([JSON.stringify(stats, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `soil-erosion-statistics-${new Date().toISOString().split('T')[0]}.json`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

// Watch for data changes
watch(() => props.timeSeriesData, () => {
  nextTick(() => {
    createTimeSeriesChart()
  })
}, { deep: true })

watch(() => props.selectedArea, () => {
  nextTick(() => {
    createAreaChart()
  })
})

// Lifecycle
onMounted(() => {
  nextTick(() => {
    createTimeSeriesChart()
    createAreaChart()
    createHistogramChart()
    createComparisonChart()
  })
})

onUnmounted(() => {
  // Destroy all charts
  Object.values(charts.value).forEach(chart => {
    if (chart) chart.destroy()
  })
})
</script>
