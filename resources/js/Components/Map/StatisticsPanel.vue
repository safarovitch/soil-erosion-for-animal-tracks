<template>
  <div class="bg-white rounded-lg shadow-lg p-6">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-bold text-gray-900">
        Detailed Statistics
        <span v-if="selectedArea" class="text-sm font-normal text-gray-600">
          - {{ selectedArea.name || selectedArea.name_en }}
        </span>
      </h3>
      <div class="flex space-x-2">
        <button
          @click="exportPNG"
          class="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700"
          title="Export as PNG"
        >
          📷 PNG
        </button>
        <button
          @click="exportCSV"
          class="px-3 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700"
          title="Export as CSV"
        >
          📊 CSV
        </button>
      </div>
    </div>

    <!-- Tabs for different stat views -->
    <div class="border-b border-gray-200 mb-4">
      <nav class="-mb-px flex space-x-4">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="activeTab = tab.id"
          :class="[
            'py-2 px-4 text-sm font-medium border-b-2 transition-colors',
            activeTab === tab.id
              ? 'border-blue-600 text-blue-600'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
          ]"
        >
          {{ tab.name }}
        </button>
      </nav>
    </div>

    <!-- Tab Content -->
    <div class="space-y-6">
      <!-- Overview Tab -->
      <div v-if="activeTab === 'overview'" class="space-y-4">
        <!-- Erosion Metrics -->
        <div class="bg-gray-50 rounded-lg p-4">
          <h4 class="font-semibold text-gray-900 mb-3">Erosion Metrics</h4>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="text-center">
              <div class="text-2xl font-bold text-blue-600">{{ stats.erosion?.mean || 0 }}</div>
              <div class="text-xs text-gray-600">Mean (t/ha/yr)</div>
            </div>
            <div class="text-center">
              <div class="text-2xl font-bold text-green-600">{{ stats.erosion?.min || 0 }}</div>
              <div class="text-xs text-gray-600">Min (t/ha/yr)</div>
            </div>
            <div class="text-center">
              <div class="text-2xl font-bold text-red-600">{{ stats.erosion?.max || 0 }}</div>
              <div class="text-xs text-gray-600">Max (t/ha/yr)</div>
            </div>
            <div class="text-center">
              <div class="text-2xl font-bold text-purple-600">{{ stats.erosion?.cv || 0 }}%</div>
              <div class="text-xs text-gray-600">CV</div>
            </div>
          </div>
        </div>

        <!-- Rainfall Metrics -->
        <div class="bg-gray-50 rounded-lg p-4">
          <h4 class="font-semibold text-gray-900 mb-3">Rainfall Metrics</h4>
          <div class="grid grid-cols-2 gap-4">
            <div class="text-center">
              <div class="text-2xl font-bold text-orange-600">{{ stats.rainfall?.slope || 0 }}%</div>
              <div class="text-xs text-gray-600">Trend (% decreasing)</div>
            </div>
            <div class="text-center">
              <div class="text-2xl font-bold text-teal-600">{{ stats.rainfall?.cv || 0 }}%</div>
              <div class="text-xs text-gray-600">Variability (CV)</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Area Distribution Tab -->
      <div v-if="activeTab === 'distribution'" class="space-y-4">
        <!-- Severity Class Table -->
        <div class="bg-gray-50 rounded-lg p-4">
          <h4 class="font-semibold text-gray-900 mb-3">Area by Severity Class</h4>
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b">
                <th class="text-left py-2">Class</th>
                <th class="text-right py-2">Area (ha)</th>
                <th class="text-right py-2">Percentage</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, index) in severityDistribution" :key="index" class="border-b">
                <td class="py-2">
                  <span :class="getSeverityColorClass(item.class)">{{ item.class }}</span>
                </td>
                <td class="text-right py-2">{{ item.area.toLocaleString() }}</td>
                <td class="text-right py-2">{{ item.percentage.toFixed(1) }}%</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pie Chart -->
        <div class="bg-gray-50 rounded-lg p-4">
          <h4 class="font-semibold text-gray-900 mb-3">Severity Distribution</h4>
          <canvas ref="pieChartCanvas" class="max-h-64"></canvas>
        </div>
      </div>

      <!-- Charts Tab -->
      <div v-if="activeTab === 'charts'" class="space-y-4">
        <!-- Top Sub-areas Bar Chart -->
        <div class="bg-gray-50 rounded-lg p-4">
          <h4 class="font-semibold text-gray-900 mb-3">Top Eroding Areas</h4>
          <canvas ref="barChartCanvas" class="max-h-64"></canvas>
        </div>

        <!-- Temporal Trend Line Chart -->
        <div v-if="timeSeriesData && timeSeriesData.length > 0" class="bg-gray-50 rounded-lg p-4">
          <h4 class="font-semibold text-gray-900 mb-3">Temporal Trend</h4>
          <canvas ref="lineChartCanvas" class="max-h-64"></canvas>
        </div>
      </div>

      <!-- RUSLE Factors Tab -->
      <div v-if="activeTab === 'factors'" class="space-y-4">
        <div class="bg-gray-50 rounded-lg p-4">
          <h4 class="font-semibold text-gray-900 mb-3">RUSLE Factors (R × K × LS × C × P)</h4>
          <div class="space-y-3">
            <div v-for="factor in rusleFactors" :key="factor.id" class="flex justify-between items-center border-b pb-2">
              <div>
                <div class="font-medium">{{ factor.name }}</div>
                <div class="text-xs text-gray-600">{{ factor.description }}</div>
              </div>
              <div class="text-right">
                <div class="font-bold">{{ factor.value }}</div>
                <div class="text-xs text-gray-600">{{ factor.unit }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { Chart, registerables } from 'chart.js'

Chart.register(...registerables)

// Props
const props = defineProps({
  selectedArea: Object,
  statistics: Object,
  timeSeriesData: Array,
})

// Reactive data
const activeTab = ref('overview')
const pieChartCanvas = ref(null)
const barChartCanvas = ref(null)
const lineChartCanvas = ref(null)
let pieChart = null
let barChart = null
let lineChart = null

// Tabs
const tabs = [
  { id: 'overview', name: 'Overview' },
  { id: 'distribution', name: 'Area Distribution' },
  { id: 'charts', name: 'Charts' },
  { id: 'factors', name: 'RUSLE Factors' },
]

// Computed properties
const stats = computed(() => {
  if (!props.statistics) {
    return {
      erosion: { mean: 0, min: 0, max: 0, cv: 0 },
      rainfall: { slope: 0, cv: 0 },
    }
  }

  return {
    erosion: {
      mean: parseFloat(props.statistics.meanErosionRate || 0).toFixed(2),
      min: parseFloat(props.statistics.minErosionRate || 0).toFixed(2),
      max: parseFloat(props.statistics.maxErosionRate || 0).toFixed(2),
      cv: parseFloat(props.statistics.erosionCV || 0).toFixed(1),
    },
    rainfall: {
      slope: parseFloat(props.statistics.rainfallSlope || 0).toFixed(2),
      cv: parseFloat(props.statistics.rainfallCV || 0).toFixed(1),
    },
  }
})

const severityDistribution = computed(() => {
  if (!props.statistics || !props.statistics.severityDistribution) {
    return [
      { class: 'Very Low', area: 0, percentage: 0 },
      { class: 'Low', area: 0, percentage: 0 },
      { class: 'Moderate', area: 0, percentage: 0 },
      { class: 'Severe', area: 0, percentage: 0 },
      { class: 'Excessive', area: 0, percentage: 0 },
    ]
  }

  return props.statistics.severityDistribution
})

const rusleFactors = computed(() => {
  if (!props.statistics || !props.statistics.rusleFactors) {
    return [
      { id: 'r', name: 'R-Factor', description: 'Rainfall Erosivity', value: '0.0', unit: 'MJ mm/(ha h yr)' },
      { id: 'k', name: 'K-Factor', description: 'Soil Erodibility', value: '0.0', unit: 't ha h/(ha MJ mm)' },
      { id: 'ls', name: 'LS-Factor', description: 'Topographic', value: '0.0', unit: 'dimensionless' },
      { id: 'c', name: 'C-Factor', description: 'Cover Management', value: '0.0', unit: '0-1' },
      { id: 'p', name: 'P-Factor', description: 'Support Practice', value: '0.0', unit: '0-1' },
    ]
  }

  const factors = props.statistics.rusleFactors
  return [
    { id: 'r', name: 'R-Factor', description: 'Rainfall Erosivity', value: factors.r?.toFixed(2) || '0.0', unit: 'MJ mm/(ha h yr)' },
    { id: 'k', name: 'K-Factor', description: 'Soil Erodibility', value: factors.k?.toFixed(3) || '0.0', unit: 't ha h/(ha MJ mm)' },
    { id: 'ls', name: 'LS-Factor', description: 'Topographic', value: factors.ls?.toFixed(2) || '0.0', unit: 'dimensionless' },
    { id: 'c', name: 'C-Factor', description: 'Cover Management', value: factors.c?.toFixed(3) || '0.0', unit: '0-1' },
    { id: 'p', name: 'P-Factor', description: 'Support Practice', value: factors.p?.toFixed(3) || '0.0', unit: '0-1' },
  ]
})

// Methods
const getSeverityColorClass = (className) => {
  const colorMap = {
    'Very Low': 'text-green-700 font-medium',
    'Low': 'text-yellow-700 font-medium',
    'Moderate': 'text-orange-700 font-medium',
    'Severe': 'text-red-700 font-medium',
    'Excessive': 'text-red-900 font-medium',
  }
  return colorMap[className] || 'text-gray-700'
}

const createPieChart = () => {
  if (!pieChartCanvas.value) return

  if (pieChart) {
    pieChart.destroy()
  }

  const ctx = pieChartCanvas.value.getContext('2d')
  const data = severityDistribution.value

  pieChart = new Chart(ctx, {
    type: 'pie',
    data: {
      labels: data.map(d => d.class),
      datasets: [{
        data: data.map(d => d.percentage),
        backgroundColor: [
          'rgba(34, 139, 34, 0.8)',
          'rgba(255, 215, 0, 0.8)',
          'rgba(255, 140, 0, 0.8)',
          'rgba(220, 20, 60, 0.8)',
          'rgba(139, 0, 0, 0.9)',
        ],
        borderWidth: 1,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: {
          position: 'right',
        },
        tooltip: {
          callbacks: {
            label: function(context) {
              return `${context.label}: ${context.parsed.toFixed(1)}%`
            }
          }
        }
      }
    }
  })
}

const createBarChart = () => {
  if (!barChartCanvas.value) return

  if (barChart) {
    barChart.destroy()
  }

  const ctx = barChartCanvas.value.getContext('2d')
  
  // Mock data for top eroding areas (would come from backend)
  const topAreas = props.statistics?.topErodingAreas || [
    { name: 'District 1', erosion: 45 },
    { name: 'District 2', erosion: 38 },
    { name: 'District 3', erosion: 32 },
    { name: 'District 4', erosion: 28 },
    { name: 'District 5', erosion: 25 },
  ]

  barChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: topAreas.map(d => d.name),
      datasets: [{
        label: 'Erosion Rate (t/ha/yr)',
        data: topAreas.map(d => d.erosion),
        backgroundColor: 'rgba(220, 20, 60, 0.7)',
        borderColor: 'rgba(220, 20, 60, 1)',
        borderWidth: 1,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      scales: {
        y: {
          beginAtZero: true,
          title: {
            display: true,
            text: 'Erosion Rate (t/ha/yr)'
          }
        }
      },
      plugins: {
        legend: {
          display: false,
        }
      }
    }
  })
}

const createLineChart = () => {
  if (!lineChartCanvas.value || !props.timeSeriesData) return

  if (lineChart) {
    lineChart.destroy()
  }

  const ctx = lineChartCanvas.value.getContext('2d')

  lineChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: props.timeSeriesData.map(d => d.year),
      datasets: [{
        label: 'Erosion Rate',
        data: props.timeSeriesData.map(d => d.erosionRate),
        borderColor: 'rgba(59, 130, 246, 1)',
        backgroundColor: 'rgba(59, 130, 246, 0.1)',
        tension: 0.3,
        fill: true,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      scales: {
        y: {
          beginAtZero: true,
          title: {
            display: true,
            text: 'Erosion Rate (t/ha/yr)'
          }
        },
        x: {
          title: {
            display: true,
            text: 'Year'
          }
        }
      }
    }
  })
}

const exportPNG = () => {
  // This would export the current view as PNG
  alert('PNG export feature - to be implemented with map canvas capture')
}

const exportCSV = () => {
  if (!props.statistics) return

  const csvData = []
  
  // Header
  csvData.push(['Statistic', 'Value', 'Unit'])
  
  // Basic stats
  csvData.push(['Area Name', props.selectedArea?.name || props.selectedArea?.name_en || 'N/A', ''])
  csvData.push(['Mean Erosion Rate', stats.value.erosion.mean, 't/ha/yr'])
  csvData.push(['Min Erosion Rate', stats.value.erosion.min, 't/ha/yr'])
  csvData.push(['Max Erosion Rate', stats.value.erosion.max, 't/ha/yr'])
  csvData.push(['Erosion CV', stats.value.erosion.cv, '%'])
  csvData.push(['Rainfall Slope', stats.value.rainfall.slope, '% per year'])
  csvData.push(['Rainfall CV', stats.value.rainfall.cv, '%'])
  csvData.push([])
  
  // Severity distribution
  csvData.push(['Severity Class', 'Area (ha)', 'Percentage'])
  severityDistribution.value.forEach(item => {
    csvData.push([item.class, item.area, `${item.percentage.toFixed(1)}%`])
  })
  csvData.push([])
  
  // RUSLE factors
  csvData.push(['RUSLE Factor', 'Value', 'Unit'])
  rusleFactors.value.forEach(factor => {
    csvData.push([factor.name, factor.value, factor.unit])
  })
  
  // Convert to CSV string
  const csvString = csvData.map(row => row.join(',')).join('\n')
  
  // Create blob and download
  const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' })
  const link = document.createElement('a')
  const url = URL.createObjectURL(blob)
  link.setAttribute('href', url)
  link.setAttribute('download', `rusle-statistics-${new Date().toISOString().split('T')[0]}.csv`)
  link.style.visibility = 'hidden'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

// Watchers
watch(() => activeTab.value, async (newTab) => {
  await nextTick()
  
  if (newTab === 'distribution') {
    createPieChart()
  } else if (newTab === 'charts') {
    createBarChart()
    createLineChart()
  }
})

watch(() => props.statistics, () => {
  if (activeTab.value === 'distribution') {
    nextTick(() => createPieChart())
  } else if (activeTab.value === 'charts') {
    nextTick(() => {
      createBarChart()
      createLineChart()
    })
  }
}, { deep: true })

// Lifecycle
onMounted(() => {
  if (activeTab.value === 'distribution') {
    nextTick(() => createPieChart())
  }
})
</script>

<style scoped>
canvas {
  max-width: 100%;
}
</style>

