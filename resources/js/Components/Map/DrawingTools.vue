<template>
  <div class="space-y-4">
    <h3 class="text-lg font-semibold text-gray-900">Drawing Tools</h3>

    <!-- Drawing Mode Selection -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Drawing Mode
      </label>
      <div class="grid grid-cols-2 gap-2">
        <button
          v-for="mode in drawingModes"
          :key="mode.id"
          @click="selectDrawingMode(mode.id)"
          :class="[
            'px-3 py-2 rounded-md text-sm transition-colors flex items-center justify-center space-x-2',
            drawingMode === mode.id
              ? 'bg-blue-600 text-white'
              : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
          ]"
        >
          <span>{{ mode.icon }}</span>
          <span>{{ mode.name }}</span>
        </button>
      </div>
    </div>

    <!-- Active Drawing Instructions -->
    <div v-if="drawingMode && drawingMode !== 'none'" class="bg-blue-50 border border-blue-200 rounded-lg p-3">
      <div class="flex items-start space-x-2">
        <span class="text-blue-600 text-lg">ℹ️</span>
        <div class="text-sm text-blue-800">
          <p class="font-medium">{{ getDrawingModeInfo().name }} Mode Active</p>
          <p>{{ getDrawingModeInfo().instructions }}</p>
        </div>
      </div>
    </div>

    <!-- Shape Management Tools -->
    <div class="border-t pt-4">
      <h4 class="text-sm font-medium text-gray-900 mb-2">Shape Management</h4>
      <div class="flex space-x-2">
        <button
          @click="toggleEditMode"
          :class="[
            'flex-1 px-3 py-2 rounded-md text-sm transition-colors',
            editMode
              ? 'bg-blue-600 text-white'
              : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
          ]"
        >
          ✏️ {{ editMode ? 'Stop Editing' : 'Edit Shapes' }}
        </button>
        <button
          @click="deleteSelectedShape"
          :disabled="!editMode"
          class="flex-1 bg-red-600 text-white px-3 py-2 rounded-md hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed text-sm"
        >
          🗑️ Delete
        </button>
      </div>
      <button
        v-if="drawingHistory.length > 0"
        @click="clearAllShapes"
        class="w-full mt-2 bg-gray-600 text-white px-3 py-2 rounded-md hover:bg-gray-700 text-sm"
      >
        Clear All Shapes
      </button>
    </div>

    <!-- Drawing History -->
    <div v-if="drawingHistory.length > 0" class="border-t pt-4">
      <div class="flex items-center justify-between mb-2">
        <h4 class="text-sm font-medium text-gray-900">Drawn Shapes ({{ drawingHistory.length }})</h4>
      </div>

      <div class="space-y-2 max-h-32 overflow-y-auto">
        <div
          v-for="(drawing, index) in drawingHistory"
          :key="drawing.id"
          :class="[
            'flex items-center justify-between p-2 rounded text-sm',
            selectedDrawing && selectedDrawing.id === drawing.id
              ? 'bg-blue-100 border border-blue-300'
              : 'bg-gray-50'
          ]"
        >
          <div class="flex items-center space-x-2">
            <span>{{ drawing.icon }}</span>
            <span class="font-medium">{{ drawing.type }}</span>
            <span class="text-gray-500 text-xs">{{ drawing.timestamp }}</span>
          </div>
          <div class="flex space-x-1">
            <button
              @click="selectDrawing(index)"
              class="text-blue-600 hover:text-blue-800 text-xs px-1"
              title="View Stats"
            >
              📊
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Measurement Tools -->
    <div class="border-t pt-4">
      <h4 class="text-sm font-medium text-gray-900 mb-2">Measurements</h4>
      <div class="grid grid-cols-2 gap-2">
        <button
          @click="toggleMeasurement('area')"
          :class="[
            'px-3 py-2 rounded-md text-sm transition-colors',
            measuringArea
              ? 'bg-green-600 text-white'
              : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
          ]"
        >
          📐 Area
        </button>
        <button
          @click="toggleMeasurement('distance')"
          :class="[
            'px-3 py-2 rounded-md text-sm transition-colors',
            measuringDistance
              ? 'bg-green-600 text-white'
              : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
          ]"
        >
          📏 Distance
        </button>
      </div>

      <!-- Measurement Results -->
      <div v-if="measurementResult" class="mt-3 bg-green-50 border border-green-200 rounded-lg p-3">
        <div class="text-sm text-green-800">
          <p class="font-medium">{{ measurementResult.type }}</p>
          <p>{{ measurementResult.value }}</p>
        </div>
      </div>
    </div>

    <!-- Export Tools -->
    <div class="border-t pt-4">
      <h4 class="text-sm font-medium text-gray-900 mb-2">Export</h4>
      <div class="flex space-x-2">
        <button
          @click="exportDrawings"
          :disabled="drawingHistory.length === 0"
          class="flex-1 bg-purple-600 text-white px-3 py-2 rounded-md hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed text-sm"
        >
          Export GeoJSON
        </button>
        <button
          @click="exportStatistics"
          :disabled="!selectedDrawing"
          class="flex-1 bg-indigo-600 text-white px-3 py-2 rounded-md hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-sm"
        >
          Export Stats
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

// Props
const props = defineProps({
  drawingMode: String,
  map: Object,
})

// Emits
const emit = defineEmits(['update:drawingMode', 'geometry-drawn'])

// Reactive data
const drawingHistory = ref([])
const selectedDrawing = ref(null)
const measuringArea = ref(false)
const measuringDistance = ref(false)
const measurementResult = ref(null)
const editMode = ref(false)

// Drawing modes
const drawingModes = ref([
  { id: 'none', name: 'None', icon: '✋', instructions: 'Click to deactivate drawing mode' },
  { id: 'point', name: 'Point', icon: '📍', instructions: 'Click on the map to place a point' },
  { id: 'line', name: 'Line', icon: '📏', instructions: 'Click to start, click to add points, double-click to finish' },
  { id: 'polygon', name: 'Polygon', icon: '⬟', instructions: 'Click to start, click to add points, double-click to close' },
  { id: 'rectangle', name: 'Rectangle', icon: '⬜', instructions: 'Click and drag to create a rectangle' },
  { id: 'circle', name: 'Circle', icon: '⭕', instructions: 'Click and drag to create a circle' },
])

// Computed properties
const isDrawingActive = computed(() => {
  return props.drawingMode && props.drawingMode !== 'none'
})

// Methods
const selectDrawingMode = (mode) => {
  emit('update:drawingMode', mode)

  // Reset measurement modes when drawing
  if (mode !== 'none') {
    measuringArea.value = false
    measuringDistance.value = false
    measurementResult.value = null
  }
}

const getDrawingModeInfo = () => {
  return drawingModes.value.find(mode => mode.id === props.drawingMode) || drawingModes.value[0]
}

const addToHistory = (geometry, type) => {
  const drawing = {
    id: Date.now(),
    type,
    geometry,
    timestamp: new Date().toLocaleTimeString(),
    icon: getIconForType(type),
  }

  drawingHistory.value.unshift(drawing)

  // Keep only last 10 drawings
  if (drawingHistory.value.length > 10) {
    drawingHistory.value = drawingHistory.value.slice(0, 10)
  }
}

const getIconForType = (type) => {
  const icons = {
    point: '📍',
    line: '📏',
    polygon: '⬟',
    rectangle: '⬜',
    circle: '⭕',
  }
  return icons[type] || '📝'
}

const selectDrawing = (index) => {
  selectedDrawing.value = drawingHistory.value[index]
  // Emit the geometry for analysis
  emit('geometry-drawn', selectedDrawing.value.geometry)
}

const deleteDrawing = (index) => {
  drawingHistory.value.splice(index, 1)
  if (selectedDrawing.value === drawingHistory.value[index]) {
    selectedDrawing.value = null
  }
}

const clearHistory = () => {
  drawingHistory.value = []
  selectedDrawing.value = null
}

const toggleEditMode = () => {
  editMode.value = !editMode.value
  
  if (editMode.value) {
    // Enable edit mode on map
    if (props.map && props.map.enableShapeEditing) {
      props.map.enableShapeEditing()
    }
    // Deactivate drawing mode
    emit('update:drawingMode', 'none')
  } else {
    // Disable edit mode on map
    if (props.map && props.map.disableShapeEditing) {
      props.map.disableShapeEditing()
    }
  }
}

const deleteSelectedShape = () => {
  if (!editMode.value) return
  
  if (confirm('Are you sure you want to delete the selected shape?')) {
    if (props.map && props.map.deleteSelectedShape) {
      props.map.deleteSelectedShape()
    }
    // Remove from history
    if (selectedDrawing.value) {
      const index = drawingHistory.value.findIndex(d => d.id === selectedDrawing.value.id)
      if (index > -1) {
        drawingHistory.value.splice(index, 1)
      }
      selectedDrawing.value = null
    }
  }
}

const clearAllShapes = () => {
  if (confirm('Are you sure you want to delete all drawn shapes?')) {
    if (props.map && props.map.clearAllShapes) {
      props.map.clearAllShapes()
    }
    clearHistory()
    editMode.value = false
  }
}

const toggleMeasurement = (type) => {
  if (type === 'area') {
    measuringArea.value = !measuringArea.value
    measuringDistance.value = false
  } else {
    measuringDistance.value = !measuringDistance.value
    measuringArea.value = false
  }

  // Reset drawing mode when measuring
  if (measuringArea.value || measuringDistance.value) {
    emit('update:drawingMode', 'none')
  }
}

const exportDrawings = () => {
  if (drawingHistory.value.length === 0) return

  const geojson = {
    type: 'FeatureCollection',
    features: drawingHistory.value.map(drawing => ({
      type: 'Feature',
      geometry: drawing.geometry,
      properties: {
        type: drawing.type,
        timestamp: drawing.timestamp,
      },
    })),
  }

  const blob = new Blob([JSON.stringify(geojson, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `soil-erosion-drawings-${new Date().toISOString().split('T')[0]}.geojson`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

const exportStatistics = () => {
  if (!selectedDrawing.value) return

  // This would export statistics for the selected drawing
  const stats = {
    drawing: selectedDrawing.value,
    timestamp: new Date().toISOString(),
    // Add more statistics here
  }

  const blob = new Blob([JSON.stringify(stats, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `soil-erosion-stats-${new Date().toISOString().split('T')[0]}.json`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

// Watch for drawing mode changes
watch(() => props.drawingMode, (newMode) => {
  if (newMode === 'none') {
    measuringArea.value = false
    measuringDistance.value = false
    measurementResult.value = null
  }
})
</script>
