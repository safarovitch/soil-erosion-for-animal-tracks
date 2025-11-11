<template>
  <div class="bg-white rounded-lg shadow-lg p-6">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-bold text-gray-900">
        Detailed Statistics
        <span v-if="panelSubtitle" class="text-sm font-normal text-gray-600">
          - {{ panelSubtitle }}
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

    <div v-if="formattedEntries.length === 0" class="text-sm text-gray-500">
      Select an area to view statistics.
    </div>

    <div v-else class="space-y-6">
      <div v-if="activeTab === 'overview'" class="space-y-4">
        <div
          v-for="entry in formattedEntries"
          :key="`overview-${entry.key}`"
          class="bg-gray-50 rounded-lg p-4 space-y-4"
        >
          <div class="flex items-center justify-between">
            <div>
              <h4 class="font-semibold text-gray-900">{{ entry.areaName }}</h4>
              <p class="text-xs text-gray-500">
                Key erosion and rainfall metrics for this {{ entry.areaTypeLabel.toLowerCase() }}.
              </p>
            </div>
            <span
              class="px-2 py-1 rounded text-xs font-semibold uppercase tracking-wide"
              :class="entry.badgeClass"
            >
              {{ entry.areaTypeLabel }}
            </span>
          </div>

          <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="text-center">
              <div class="text-2xl font-bold text-blue-600">{{ entry.erosion.mean }}</div>
              <div class="text-xs text-gray-600">Mean (t/ha/yr)</div>
            </div>
            <div class="text-center">
              <div class="text-2xl font-bold text-green-600">{{ entry.erosion.min }}</div>
              <div class="text-xs text-gray-600">Min (t/ha/yr)</div>
            </div>
            <div class="text-center">
              <div class="text-2xl font-bold text-red-600">{{ entry.erosion.max }}</div>
              <div class="text-xs text-gray-600">Max (t/ha/yr)</div>
            </div>
            <div class="text-center">
              <div class="text-2xl font-bold text-purple-600">{{ entry.erosion.cv }}%</div>
              <div class="text-xs text-gray-600">CV</div>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="text-center">
              <div class="text-2xl font-bold text-orange-600">{{ entry.rainfall.slope }}%</div>
              <div class="text-xs text-gray-600">Rainfall Trend (% change)</div>
            </div>
            <div class="text-center">
              <div class="text-2xl font-bold text-teal-600">{{ entry.rainfall.cv }}%</div>
              <div class="text-xs text-gray-600">Rainfall Variability (CV)</div>
            </div>
          </div>
        </div>
      </div>

      <div v-else-if="activeTab === 'distribution'" class="space-y-4">
        <div
          v-for="entry in formattedEntries"
          :key="`distribution-${entry.key}`"
          class="bg-gray-50 rounded-lg p-4 space-y-4"
        >
          <div class="flex items-center justify-between">
            <div>
              <h4 class="font-semibold text-gray-900">
                Severity Breakdown — {{ entry.areaName }}
              </h4>
              <p class="text-xs text-gray-500">
                Area share by erosion severity class (hectares & percentage).
              </p>
            </div>
            <span
              class="px-2 py-1 rounded text-xs font-semibold uppercase tracking-wide"
              :class="entry.badgeClass"
            >
              {{ entry.areaTypeLabel }}
            </span>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b">
                  <th class="text-left py-2">Class</th>
                  <th class="text-right py-2">Area (ha)</th>
                  <th class="text-right py-2">Percentage</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(item, index) in entry.severityDistribution"
                  :key="index"
                  class="border-b"
                >
                  <td class="py-2">
                    <span :class="getSeverityColorClass(item.class)">{{ item.class }}</span>
                  </td>
                  <td class="text-right py-2">{{ item.area.toLocaleString(undefined, { maximumFractionDigits: 1 }) }}</td>
                  <td class="text-right py-2">{{ item.percentage.toFixed(1) }}%</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="entry.hasSeverityData">
            <canvas
              class="max-h-64"
              :ref="el => setPieChartRef(entry.key, el)"
            ></canvas>
          </div>
          <p v-else class="text-xs text-gray-500 italic">
            Not enough data to render the severity chart for this area.
          </p>
        </div>
      </div>

      <div v-else-if="activeTab === 'charts'" class="space-y-4">
        <div
          v-for="entry in formattedEntries"
          :key="`chart-${entry.key}`"
          class="bg-gray-50 rounded-lg p-4 space-y-4"
        >
          <div class="flex items-center justify-between">
            <div>
              <h4 class="font-semibold text-gray-900">
                Top Eroding Areas — {{ entry.areaName }}
              </h4>
              <p class="text-xs text-gray-500">
                Highest erosion hotspots within this {{ entry.areaTypeLabel.toLowerCase() }}.
              </p>
            </div>
            <span
              class="px-2 py-1 rounded text-xs font-semibold uppercase tracking-wide"
              :class="entry.badgeClass"
            >
              {{ entry.areaTypeLabel }}
            </span>
          </div>

          <div v-if="entry.hasTopAreas">
            <canvas
              class="max-h-64"
              :ref="el => setBarChartRef(entry.key, el)"
            ></canvas>
          </div>
          <p v-else class="text-xs text-gray-500 italic">
            No ranked erosion hotspots were returned for this area.
          </p>
        </div>

        <div
          v-if="primaryTimeSeries.length"
          class="bg-gray-50 rounded-lg p-4 space-y-4"
        >
          <div class="flex items-center justify-between">
            <div>
              <h4 class="font-semibold text-gray-900">
                Temporal Trend — {{ primaryAreaName }}
              </h4>
              <p class="text-xs text-gray-500">
                Annual erosion trend (t/ha/yr) across the selected time window.
              </p>
            </div>
            <span
              class="px-2 py-1 rounded text-xs font-semibold uppercase tracking-wide bg-slate-100 text-slate-700"
            >
              Time Series
            </span>
          </div>
          <canvas ref="lineChartCanvas" class="max-h-64"></canvas>
        </div>
      </div>

      <div v-else-if="activeTab === 'factors'" class="space-y-4">
        <div
          v-for="entry in formattedEntries"
          :key="`factors-${entry.key}`"
          class="bg-gray-50 rounded-lg p-4 space-y-4"
        >
          <div class="flex items-center justify-between">
            <div>
              <h4 class="font-semibold text-gray-900">
                RUSLE Factors — {{ entry.areaName }}
              </h4>
              <p class="text-xs text-gray-500">
                Average R, K, LS, C, and P factors representing erosion drivers.
              </p>
            </div>
            <span
              class="px-2 py-1 rounded text-xs font-semibold uppercase tracking-wide"
              :class="entry.badgeClass"
            >
              {{ entry.areaTypeLabel }}
            </span>
          </div>
          <div class="space-y-3">
            <div
              v-for="factor in entry.rusleFactors"
              :key="factor.id"
              class="flex justify-between items-center border-b pb-2"
            >
              <div>
                <div class="font-medium">{{ factor.name }}</div>
                <div class="text-xs text-gray-600">{{ factor.description }}</div>
              </div>
              <div class="text-right">
                <div class="font-semibold">{{ factor.value }}</div>
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
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { Chart, registerables } from 'chart.js'

Chart.register(...registerables)

const props = defineProps({
  selectedArea: Object,
  statistics: Object,
  timeSeriesData: {
    type: Array,
    default: () => []
  },
  areaStatistics: {
    type: Array,
    default: () => []
  }
})

const activeTab = ref('overview')

const tabs = [
  { id: 'overview', name: 'Overview' },
  { id: 'distribution', name: 'Area Distribution' },
  { id: 'charts', name: 'Charts' },
  { id: 'factors', name: 'RUSLE Factors' },
]

const pieChartRefs = ref({})
const barChartRefs = ref({})
const lineChartCanvas = ref(null)

const pieCharts = new Map()
const barCharts = new Map()
let lineChart = null

const areaTypeLabels = {
  country: 'Country',
  region: 'Region',
  district: 'District',
}

const areaBadgeClasses = {
  country: 'bg-blue-100 text-blue-700',
  region: 'bg-purple-100 text-purple-700',
  district: 'bg-emerald-100 text-emerald-700',
  default: 'bg-gray-100 text-gray-600',
}

const defaultSeverity = [
  { class: 'Very Low', area: 0, percentage: 0 },
  { class: 'Low', area: 0, percentage: 0 },
  { class: 'Moderate', area: 0, percentage: 0 },
  { class: 'Severe', area: 0, percentage: 0 },
  { class: 'Excessive', area: 0, percentage: 0 },
]

const defaultRusleFactors = [
  { id: 'r', name: 'R-Factor', description: 'Rainfall Erosivity', unit: 'MJ mm/(ha h yr)' },
  { id: 'k', name: 'K-Factor', description: 'Soil Erodibility', unit: 't ha h/(ha MJ mm)' },
  { id: 'ls', name: 'LS-Factor', description: 'Topographic', unit: 'dimensionless' },
  { id: 'c', name: 'C-Factor', description: 'Cover Management', unit: '0-1' },
  { id: 'p', name: 'P-Factor', description: 'Support Practice', unit: '0-1' },
]

const formatNumber = (value, digits = 2) => {
  const num = Number(value)
  if (!Number.isFinite(num)) {
    return (0).toFixed(digits)
  }
  return num.toFixed(digits)
}

const resolveAreaType = (area, fallbackType) => {
  if (area?.area_type) return area.area_type
  if (area?.id === 0) return 'country'
  if (area?.region_id) return 'district'
  if (area?.type === 'region' || fallbackType === 'region') return 'region'
  return fallbackType || 'region'
}

const displayEntries = computed(() => {
  if (props.areaStatistics && props.areaStatistics.length > 0) {
    return props.areaStatistics.map((entry, index) => {
      const area = entry.area || {}
      const areaType = resolveAreaType(area, entry.areaType)
      const name =
        area.name_en ||
        area.name ||
        (areaType === 'country' ? 'Country' : `Area ${index + 1}`)

      return {
        key: entry.key || `${areaType}-${area.id ?? index}`,
        area,
        areaType,
        areaName: name,
        statistics: entry.statistics || {},
        timeSeries: Array.isArray(entry.timeSeries) ? entry.timeSeries : [],
      }
    })
  }

  if (props.statistics) {
    const area = props.selectedArea || {}
    const areaType = resolveAreaType(area, area.type)
    const name =
      area.name_en ||
      area.name ||
      (areaType === 'country' ? 'Country' : 'Selected Area')

    return [
      {
        key: area.id != null ? `area-${area.id}` : 'primary-area',
        area,
        areaType,
        areaName: name,
        statistics: props.statistics,
        timeSeries: props.timeSeriesData || [],
      },
    ]
  }

  return []
})

const formattedEntries = computed(() =>
  displayEntries.value.map((entry, index) => {
    const stats = entry.statistics || {}
    const severityRaw =
      Array.isArray(stats.severityDistribution) && stats.severityDistribution.length
        ? stats.severityDistribution
        : Array.isArray(stats.severity_distribution) && stats.severity_distribution.length
        ? stats.severity_distribution
        : defaultSeverity

    const severityDistribution = severityRaw.map((item) => ({
      class: item.class || item.name || 'Class',
      area: Number(item.area ?? 0),
      percentage: Number(item.percentage ?? 0),
    }))

    const hasSeverityData = severityDistribution.some((item) => item.percentage > 0)

    const topAreasRaw = Array.isArray(stats.topErodingAreas)
      ? stats.topErodingAreas
      : []

    const topErodingAreas = topAreasRaw
      .filter((item) => item && (item.name || item.name_en || item.name_tj))
      .map((item) => ({
        name: item.name || item.name_en || item.name_tj || 'Unknown',
        erosion: Number(item.erosion || item.erosion_rate || item.mean_erosion_rate || 0),
      }))

    const rusleRaw = stats.rusleFactors || stats.rusle_factors || {}
    const rusleFactors = defaultRusleFactors.map((factor) => {
      const rawValue = Number(rusleRaw[factor.id] ?? 0)
      const decimals = ['k', 'c', 'p'].includes(factor.id) ? 3 : 2
      return {
        ...factor,
        value: formatNumber(rawValue, decimals),
      }
    })

    const rainfallSlope = Number(stats.rainfallSlope ?? 0)
    const rainfallCV = Number(stats.rainfallCV ?? 0)

    const entryTimeSeries = Array.isArray(entry.timeSeries) ? entry.timeSeries : []

    return {
      key: entry.key,
      areaName: entry.areaName,
      areaType: entry.areaType,
      areaTypeLabel: areaTypeLabels[entry.areaType] || 'Area',
      badgeClass: areaBadgeClasses[entry.areaType] || areaBadgeClasses.default,
      erosion: {
        mean: formatNumber(stats.meanErosionRate ?? 0, 2),
        min: formatNumber(stats.minErosionRate ?? 0, 2),
        max: formatNumber(stats.maxErosionRate ?? 0, 2),
        cv: formatNumber(stats.erosionCV ?? 0, 1),
      },
      rainfall: {
        slope: formatNumber(rainfallSlope, 2),
        cv: formatNumber(rainfallCV, 1),
      },
      severityDistribution,
      hasSeverityData,
      topErodingAreas,
      hasTopAreas: topErodingAreas.length > 0,
      rusleFactors,
      timeSeries:
        entryTimeSeries.length > 0
          ? entryTimeSeries
          : index === 0
          ? props.timeSeriesData || []
          : [],
    }
  })
)

const primaryTimeSeries = computed(() => {
  if (!formattedEntries.value.length) {
    return []
  }
  return formattedEntries.value[0].timeSeries || []
})

const primaryAreaName = computed(() => formattedEntries.value[0]?.areaName || 'Selected Area')

const panelSubtitle = computed(() => {
  if (!formattedEntries.value.length) return ''
  if (formattedEntries.value.length === 1) return formattedEntries.value[0].areaName
  return `${formattedEntries.value.length} areas selected`
})

const getSeverityColorClass = (className) => {
  const colorMap = {
    'Very Low': 'text-green-700 font-medium',
    Low: 'text-yellow-700 font-medium',
    Moderate: 'text-orange-700 font-medium',
    Severe: 'text-red-700 font-medium',
    Excessive: 'text-red-900 font-medium',
  }
  return colorMap[className] || 'text-gray-700'
}

const setPieChartRef = (key, el) => {
  if (el) {
    pieChartRefs.value[key] = el
  } else {
    delete pieChartRefs.value[key]
  }
}

const setBarChartRef = (key, el) => {
  if (el) {
    barChartRefs.value[key] = el
  } else {
    delete barChartRefs.value[key]
  }
}

const destroyChartMap = (chartMap) => {
  chartMap.forEach((chart) => chart.destroy())
  chartMap.clear()
}

const createPieCharts = () => {
  if (activeTab.value !== 'distribution') {
    destroyChartMap(pieCharts)
    return
  }

  const seen = new Set()

  formattedEntries.value.forEach((entry) => {
    if (!entry.hasSeverityData) {
      if (pieCharts.has(entry.key)) {
        pieCharts.get(entry.key).destroy()
        pieCharts.delete(entry.key)
      }
      return
    }

    const canvas = pieChartRefs.value[entry.key]
    if (!canvas || canvas.offsetWidth === 0) {
      return
    }

    const ctx = canvas.getContext('2d')
    if (!ctx) {
      return
    }

    if (pieCharts.has(entry.key)) {
      pieCharts.get(entry.key).destroy()
    }

    const labels = entry.severityDistribution.map((item) => item.class)
    const data = entry.severityDistribution.map((item) => item.percentage)
    const colors = [
      'rgba(34, 139, 34, 0.8)',
      'rgba(255, 215, 0, 0.8)',
      'rgba(255, 140, 0, 0.8)',
      'rgba(220, 20, 60, 0.8)',
      'rgba(139, 0, 0, 0.9)',
    ]

    const chart = new Chart(ctx, {
      type: 'pie',
      data: {
        labels,
        datasets: [
          {
            data,
            backgroundColor: colors.slice(0, labels.length),
            borderWidth: 1,
          },
        ],
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
              label(context) {
                return `${context.label}: ${context.parsed.toFixed(1)}%`
              },
            },
          },
        },
      },
    })

    pieCharts.set(entry.key, chart)
    seen.add(entry.key)
  })

  for (const [key, chart] of pieCharts.entries()) {
    if (!seen.has(key)) {
      chart.destroy()
      pieCharts.delete(key)
    }
  }
}

const createBarCharts = () => {
  if (activeTab.value !== 'charts') {
    destroyChartMap(barCharts)
    return
  }

  const seen = new Set()

  formattedEntries.value.forEach((entry) => {
    if (!entry.hasTopAreas) {
      if (barCharts.has(entry.key)) {
        barCharts.get(entry.key).destroy()
        barCharts.delete(entry.key)
      }
      return
    }

    const canvas = barChartRefs.value[entry.key]
    if (!canvas || canvas.offsetWidth === 0) {
      return
    }

    const ctx = canvas.getContext('2d')
    if (!ctx) {
      return
    }

    if (barCharts.has(entry.key)) {
      barCharts.get(entry.key).destroy()
    }

    const labels = entry.topErodingAreas.map((item) => item.name)
    const data = entry.topErodingAreas.map((item) => item.erosion)

    const chart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            label: 'Erosion Rate (t/ha/yr)',
            data,
            backgroundColor: 'rgba(220, 20, 60, 0.7)',
            borderColor: 'rgba(220, 20, 60, 1)',
            borderWidth: 1,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
          y: {
            beginAtZero: true,
            title: {
              display: true,
              text: 'Erosion Rate (t/ha/yr)',
            },
          },
        },
        plugins: {
          legend: {
            display: false,
          },
        },
      },
    })

    barCharts.set(entry.key, chart)
    seen.add(entry.key)
  })

  for (const [key, chart] of barCharts.entries()) {
    if (!seen.has(key)) {
      chart.destroy()
      barCharts.delete(key)
    }
  }
}

const createLineChart = () => {
  if (activeTab.value !== 'charts') {
    if (lineChart) {
      lineChart.destroy()
      lineChart = null
    }
    return
  }

  const series = primaryTimeSeries.value
  if (!series || !series.length) {
    if (lineChart) {
      lineChart.destroy()
      lineChart = null
    }
    return
  }

  if (!lineChartCanvas.value || lineChartCanvas.value.offsetWidth === 0) {
    return
  }

  const ctx = lineChartCanvas.value.getContext('2d')
  if (!ctx) return

  if (lineChart) {
    lineChart.destroy()
  }

  lineChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: series.map((item) => item.year),
      datasets: [
        {
          label: 'Erosion Rate (t/ha/yr)',
          data: series.map(
            (item) =>
              item.erosionRate ??
              item.erosion_rate ??
              item.value ??
              0
          ),
          borderColor: 'rgba(59, 130, 246, 1)',
          backgroundColor: 'rgba(59, 130, 246, 0.1)',
          tension: 0.3,
          fill: true,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      scales: {
        y: {
          beginAtZero: true,
          title: {
            display: true,
            text: 'Erosion Rate (t/ha/yr)',
          },
        },
        x: {
          title: {
            display: true,
            text: 'Year',
          },
        },
      },
    },
  })
}

const scheduleChartRender = () => {
  nextTick(() => {
    createPieCharts()
    createBarCharts()
    createLineChart()
  })
}

watch(() => activeTab.value, scheduleChartRender)
watch(formattedEntries, scheduleChartRender, { deep: true })
watch(() => props.areaStatistics, scheduleChartRender, { deep: true })
watch(() => props.statistics, scheduleChartRender, { deep: true })
watch(() => props.timeSeriesData, scheduleChartRender, { deep: true })

onMounted(scheduleChartRender)

onBeforeUnmount(() => {
  destroyChartMap(pieCharts)
  destroyChartMap(barCharts)
  if (lineChart) {
    lineChart.destroy()
    lineChart = null
  }
})

const exportPNG = () => {
  alert('PNG export feature will capture the statistics view in a future update.')
}

const exportCSV = () => {
  if (!formattedEntries.value.length) return

  const csvRows = []

  formattedEntries.value.forEach((entry, index) => {
    csvRows.push([`Area`, entry.areaName, entry.areaTypeLabel])
    csvRows.push(['Mean Erosion Rate', entry.erosion.mean, 't/ha/yr'])
    csvRows.push(['Min Erosion Rate', entry.erosion.min, 't/ha/yr'])
    csvRows.push(['Max Erosion Rate', entry.erosion.max, 't/ha/yr'])
    csvRows.push(['Erosion CV', entry.erosion.cv, '%'])
    csvRows.push(['Rainfall Trend', entry.rainfall.slope, '%'])
    csvRows.push(['Rainfall CV', entry.rainfall.cv, '%'])
    csvRows.push([])

    csvRows.push(['Severity Class', 'Area (ha)', 'Percentage'])
    entry.severityDistribution.forEach((item) => {
      csvRows.push([
        item.class,
        item.area.toFixed(1),
        `${item.percentage.toFixed(1)}%`,
      ])
    })
    csvRows.push([])

    csvRows.push(['RUSLE Factor', 'Value', 'Unit'])
    entry.rusleFactors.forEach((factor) => {
      csvRows.push([factor.name, factor.value, factor.unit])
    })

    if (entry.hasTopAreas) {
      csvRows.push([])
      csvRows.push(['Top Area', 'Erosion Rate (t/ha/yr)'])
      entry.topErodingAreas.forEach((area) => {
        csvRows.push([area.name, area.erosion.toFixed(2)])
      })
    }

    if (index < formattedEntries.value.length - 1) {
      csvRows.push([])
    }
  })

  const csvString = csvRows.map((row) => row.join(',')).join('\n')

  const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' })
  const link = document.createElement('a')
  const url = URL.createObjectURL(blob)
  link.setAttribute('href', url)
  link.setAttribute(
    'download',
    `rusle-statistics-${new Date().toISOString().split('T')[0]}.csv`
  )
  link.style.visibility = 'hidden'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}
</script>

<style scoped>
canvas {
  max-width: 100%;
}
</style>

