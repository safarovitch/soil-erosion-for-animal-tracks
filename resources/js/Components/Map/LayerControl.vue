<template>
  <div class="space-y-4">
    <h3 class="text-lg font-semibold text-gray-900">Layer Control</h3>

    <!-- Map Labels Toggle -->
    <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
      <div class="flex items-center space-x-3">
        <input
          type="checkbox"
          id="map-labels"
          :checked="showLabels"
          @change="toggleLabels($event.target.checked)"
          class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
        />
        <div>
          <label for="map-labels" class="text-sm font-medium text-gray-900 cursor-pointer">
            Map Labels
          </label>
          <p class="text-xs text-gray-500">Show/hide map labels</p>
        </div>
      </div>
    </div>

    <!-- Layer List -->
    <div class="space-y-3">
      <div
        v-for="layer in availableLayers"
        :key="layer.id"
        class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
      >
        <div class="flex items-center space-x-3">
          <input
            type="checkbox"
            :id="`layer-${layer.id}`"
            :checked="visibleLayers && visibleLayers.includes(layer.id)"
            @change="toggleLayer(layer.id, $event.target.checked)"
            class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
          />
          <div>
            <label :for="`layer-${layer.id}`" class="text-sm font-medium text-gray-900 cursor-pointer">
              {{ layer.name }}
            </label>
            <p class="text-xs text-gray-500">{{ layer.description }}</p>
          </div>
        </div>

        <!-- Layer Controls -->
        <div class="flex items-center space-x-2">
          <!-- Layer Info -->
          <button
            @click="showLayerInfo(layer)"
            class="text-gray-400 hover:text-gray-600 text-sm"
            title="Layer Information"
          >
            ℹ️
          </button>
        </div>
      </div>
    </div>

    <!-- Layer Order -->
    <div v-if="visibleLayers && visibleLayers.length > 1" class="border-t pt-4">
      <h4 class="text-sm font-medium text-gray-900 mb-2">Layer Order</h4>
      <div class="space-y-2">
        <div
          v-for="(layerId, index) in visibleLayers"
          :key="layerId"
          class="flex items-center justify-between p-2 bg-white border rounded"
        >
          <span class="text-sm">{{ getLayerName(layerId) }}</span>
          <div class="flex space-x-1">
            <button
              @click="moveLayerUp(index)"
              :disabled="index === 0"
              class="text-gray-400 hover:text-gray-600 disabled:opacity-50"
              title="Move Up"
            >
              ↑
            </button>
            <button
              @click="moveLayerDown(index)"
              :disabled="index === visibleLayers.length - 1"
              class="text-gray-400 hover:text-gray-600 disabled:opacity-50"
              title="Move Down"
            >
              ↓
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="border-t pt-4">
      <div class="flex space-x-2">
        <button
          @click="showAllLayers"
          class="flex-1 bg-blue-600 text-white px-3 py-2 rounded-md hover:bg-blue-700 text-sm"
        >
          Show All
        </button>
        <button
          @click="hideAllLayers"
          class="flex-1 bg-gray-600 text-white px-3 py-2 rounded-md hover:bg-gray-700 text-sm"
        >
          Hide All
        </button>
      </div>
    </div>

    <!-- Layer Info Modal -->
    <div
      v-if="selectedLayerInfo"
      class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
      @click="selectedLayerInfo = null"
    >
      <div class="bg-white rounded-lg p-6 w-96 max-h-96 overflow-y-auto" @click.stop>
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-semibold">{{ selectedLayerInfo.name }}</h3>
          <button
            @click="selectedLayerInfo = null"
            class="text-gray-400 hover:text-gray-600"
          >
            ✕
          </button>
        </div>

        <div class="space-y-3">
          <div>
            <h4 class="text-sm font-medium text-gray-900">Description</h4>
            <p class="text-sm text-gray-600">{{ selectedLayerInfo.description }}</p>
          </div>

          <div v-if="selectedLayerInfo.metadata">
            <h4 class="text-sm font-medium text-gray-900">Metadata</h4>
            <div class="text-sm text-gray-600 space-y-1">
              <div v-if="selectedLayerInfo.metadata.source">
                <span class="font-medium">Source:</span> {{ selectedLayerInfo.metadata.source }}
              </div>
              <div v-if="selectedLayerInfo.metadata.resolution">
                <span class="font-medium">Resolution:</span> {{ selectedLayerInfo.metadata.resolution }}
              </div>
              <div v-if="selectedLayerInfo.metadata.year">
                <span class="font-medium">Year:</span> {{ selectedLayerInfo.metadata.year }}
              </div>
            </div>
          </div>

          <div v-if="selectedLayerInfo.legend">
            <h4 class="text-sm font-medium text-gray-900">Legend</h4>
            <div class="text-sm text-gray-600">
              <!-- Legend content would go here -->
              <p>Legend information for {{ selectedLayerInfo.name }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

// Props
const props = defineProps({
  visibleLayers: {
    type: Array,
    required: true,
    default: () => [],
  },
  availableLayers: {
    type: Array,
    required: true,
    default: () => [],
  },
  showLabels: {
    type: Boolean,
    default: true,
  },
})

// Emits
const emit = defineEmits(['layer-toggle', 'layer-order-change', 'labels-toggle'])

// Reactive data
const selectedLayerInfo = ref(null)

// Methods
const toggleLayer = (layerId, visible) => {
  emit('layer-toggle', layerId, visible)
}

const toggleLabels = (visible) => {
  emit('labels-toggle', visible)
}

const getLayerName = (layerId) => {
  const layer = props.availableLayers.find(l => l.id === layerId)
  return layer ? layer.name : layerId
}

const moveLayerUp = (index) => {
  if (index > 0) {
    const newOrder = [...props.visibleLayers]
    const temp = newOrder[index]
    newOrder[index] = newOrder[index - 1]
    newOrder[index - 1] = temp
    emit('layer-order-change', newOrder)
  }
}

const moveLayerDown = (index) => {
  if (index < props.visibleLayers.length - 1) {
    const newOrder = [...props.visibleLayers]
    const temp = newOrder[index]
    newOrder[index] = newOrder[index + 1]
    newOrder[index + 1] = temp
    emit('layer-order-change', newOrder)
  }
}

const showAllLayers = () => {
  const allLayerIds = props.availableLayers.map(layer => layer.id)
  allLayerIds.forEach(layerId => {
    if (!props.visibleLayers || !props.visibleLayers.includes(layerId)) {
      emit('layer-toggle', layerId, true)
    }
  })
}

const hideAllLayers = () => {
  if (props.visibleLayers) {
    props.visibleLayers.forEach(layerId => {
      emit('layer-toggle', layerId, false)
    })
  }
}

const showLayerInfo = (layer) => {
  selectedLayerInfo.value = layer
}
</script>
