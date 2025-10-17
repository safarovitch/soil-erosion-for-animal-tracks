<template>
  <div ref="mapContainer" class="w-full h-full min-h-96" style="min-height: 400px;"></div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch, nextTick } from 'vue'
import axios from 'axios'
import { Map, View } from 'ol'
import { Tile as TileLayer, Vector as VectorLayer } from 'ol/layer'
import { OSM, Vector as VectorSource, XYZ } from 'ol/source'
import { Style, Fill, Stroke } from 'ol/style'
import { GeoJSON, TopoJSON } from 'ol/format'
import { fromLonLat } from 'ol/proj'
import { Draw } from 'ol/interaction'
import { Feature } from 'ol'
import { easeOut } from 'ol/easing'
import { Polygon, Point } from 'ol/geom'
import 'ol/ol.css'

// Props
const props = defineProps({
  selectedRegion: Object,
  selectedDistrict: Object,
  selectedYear: Number,
  visibleLayers: Array,
  drawingMode: String,
})

// Emits
const emit = defineEmits(['map-ready', 'statistics-updated', 'district-clicked', 'geojson-loaded'])

// Reactive data
const mapContainer = ref(null)
const map = ref(null)
const drawingInteraction = ref(null)
const vectorSource = ref(null)
const vectorLayer = ref(null)
const regionLayer = ref(null)
const districtLayer = ref(null)
const topoJsonLayer = ref(null)

// Map configuration
const mapConfig = {
  center: fromLonLat([71.5, 38.5]), // Tajikistan center
  zoom: 7,
  maxZoom: 18,
  minZoom: 5,
}

// Initialize map
const initMap = () => {
  if (!mapContainer.value) return

  // Ensure container has dimensions
  const containerRect = mapContainer.value.getBoundingClientRect()
  if (containerRect.width === 0 || containerRect.height === 0) {
    console.warn('Map container has zero dimensions, retrying...')
    setTimeout(() => initMap(), 100)
    return
  }

  console.log('Initializing map with dimensions:', containerRect.width, 'x', containerRect.height)

  // Create base layer
  const baseLayer = new TileLayer({
    source: new OSM(),
  })

  // Create vector source for user drawings
  vectorSource.value = new VectorSource()
  vectorLayer.value = new VectorLayer({
    source: vectorSource.value,
    style: new Style({
      fill: new Fill({
        color: 'rgba(255, 255, 255, 0.2)',
      }),
      stroke: new Stroke({
        color: '#ff0000',
        width: 2,
      }),
    }),
  })

  // Create the map
  map.value = new Map({
    target: mapContainer.value,
    layers: [
      baseLayer,
      vectorLayer.value,
    ],
    view: new View({
      center: mapConfig.center,
      zoom: mapConfig.zoom,
      maxZoom: mapConfig.maxZoom,
      minZoom: mapConfig.minZoom,
    }),
  })

  // Emit map ready event
  emit('map-ready', map.value)

  // Update map layers when map becomes ready
  console.log('Updating map layers after map is ready...')
  updateMapLayers()

  // Add click handler for statistics
  map.value.on('click', handleMapClick)

  // Load GeoJSON automatically when map is ready
  loadGeoJSONOnMapReady()

  // Add resize handler
  const handleResize = () => {
    if (map.value) {
      map.value.updateSize()
    }
  }

  window.addEventListener('resize', handleResize)

  // Store resize handler for cleanup
  map.value.set('resizeHandler', handleResize)
}

// Handle map clicks
const handleMapClick = (event) => {
  const coordinate = event.coordinate
  const feature = map.value.forEachFeatureAtPixel(event.pixel, (feature) => feature)

  if (feature && feature.get('type') === 'erosion') {
    const properties = feature.getProperties()
    emit('statistics-updated', {
      meanErosionRate: properties.meanErosionRate || 0,
      bareSoilFrequency: properties.bareSoilFrequency || 0,
      sustainabilityFactor: properties.sustainabilityFactor || 0,
    })
  } else if (feature && topoJsonLayer.value) {
    // Handle clicks on GeoJSON features (districts)
    const properties = feature.getProperties()
    console.log('Clicked on district:', properties)

    // Emit district selection event
    emit('district-clicked', {
      shapeName: properties.shapeName,
      shapeID: properties.shapeID,
      shapeISO: properties.shapeISO,
      feature: feature
    })

    // Zoom to the clicked district
    const geometry = feature.getGeometry()
    if (geometry) {
      const extent = geometry.getExtent()
      map.value.getView().fit(extent, {
        padding: [50, 50, 50, 50],
        duration: 2000,
        easing: easeOut,
        maxZoom: 12
      })
    }
  }
}

// Update region layer
const updateRegionLayer = (region) => {
  if (regionLayer.value) {
    map.value.removeLayer(regionLayer.value)
  }

  if (!region || !region.geometry) return

  try {
    // Handle geometry that might be a JSON string
    let geometryData = region.geometry
    if (typeof geometryData === 'string') {
      geometryData = JSON.parse(geometryData)
    }

    const geojsonFormat = new GeoJSON()
    const features = geojsonFormat.readFeatures(geometryData, {
      dataProjection: 'EPSG:4326',
      featureProjection: 'EPSG:3857',
    })

    const source = new VectorSource({
      features,
    })

    regionLayer.value = new VectorLayer({
      source,
      style: new Style({
        fill: new Fill({
          color: 'rgba(0, 0, 255, 0.1)',
        }),
        stroke: new Stroke({
          color: '#0000ff',
          width: 2,
        }),
      }),
    })

    map.value.addLayer(regionLayer.value)

    // Fit to region bounds
    const extent = source.getExtent()
    map.value.getView().fit(extent, {
      padding: [20, 20, 20, 20],
      duration: 2000,
      easing: easeOut
    })
  } catch (error) {
    console.error('Error updating region layer:', error)
  }
}

// Update district layer
const updateDistrictLayer = (district) => {
  if (districtLayer.value) {
    map.value.removeLayer(districtLayer.value)
  }

  if (!district || !district.geometry) return

  try {
    // Handle geometry that might be a JSON string
    let geometryData = district.geometry
    if (typeof geometryData === 'string') {
      geometryData = JSON.parse(geometryData)
    }

    const geojsonFormat = new GeoJSON()
    const features = geojsonFormat.readFeatures(geometryData, {
      dataProjection: 'EPSG:4326',
      featureProjection: 'EPSG:3857',
    })

    const source = new VectorSource({
      features,
    })

    districtLayer.value = new VectorLayer({
      source,
      style: new Style({
        fill: new Fill({
          color: 'rgba(0, 255, 0, 0.1)',
        }),
        stroke: new Stroke({
          color: '#00ff00',
          width: 2,
        }),
      }),
    })

    map.value.addLayer(districtLayer.value)

    // Fit to district bounds
    const extent = source.getExtent()
    map.value.getView().fit(extent, {
      padding: [20, 20, 20, 20],
      duration: 2000,
      easing: easeOut
    })
  } catch (error) {
    console.error('Error updating district layer:', error)
  }
}

// Update erosion data layer
const updateErosionData = (data) => {
  // This would typically load raster tiles from the GEE computation
  // For now, we'll simulate with vector data

  if (data.tiles) {
    // Add raster layer for erosion data
    const erosionLayer = new TileLayer({
      source: new TileLayer({
        url: data.tiles,
        crossOrigin: 'anonymous',
      }),
      opacity: 0.7,
    })

    map.value.addLayer(erosionLayer)
  }
}

// Handle drawing mode changes
const handleDrawingMode = (mode) => {
  // Remove existing drawing interaction
  if (drawingInteraction.value) {
    map.value.removeInteraction(drawingInteraction.value)
  }

  if (!mode || mode === 'none') return

  // Create new drawing interaction
  const geometryType = mode === 'point' ? 'Point' : mode === 'line' ? 'LineString' : 'Polygon'

  drawingInteraction.value = new Draw({
    source: vectorSource.value,
    type: geometryType,
  })

  // Add drawing interaction to map
  map.value.addInteraction(drawingInteraction.value)

  // Handle draw end
  drawingInteraction.value.on('drawend', (event) => {
    const feature = event.feature
    const geometry = feature.getGeometry()

    // Convert to GeoJSON
    const geojsonFormat = new GeoJSON()
    const geojson = geojsonFormat.writeFeature(feature)

    // Emit geometry drawn event
    emit('geometry-drawn', JSON.parse(geojson))

    // Remove the interaction after drawing
    map.value.removeInteraction(drawingInteraction.value)
    drawingInteraction.value = null
  })
}

// Layer management
const mapLayers = ref({})

const layerOpacities = ref({
  erosion: 0.7,
  bare_soil: 0.6,
  sustainability: 0.8,
  custom: 0.9
})

const updateMapLayers = () => {
  if (!map.value) return

  // Define available map layers
  const layerDefinitions = {
    erosion: {
      name: 'Soil Erosion Hazard',
      type: 'overlay',
      color: 'rgba(255, 0, 0, 0.3)', // Red overlay
      defaultOpacity: 0.3
    },
    bare_soil: {
      name: 'Bare Soil Frequency',
      type: 'overlay',
      color: 'rgba(255, 165, 0, 0.3)', // Orange overlay
      defaultOpacity: 0.3
    },
    sustainability: {
      name: 'Sustainability Factor',
      type: 'overlay',
      color: 'rgba(0, 255, 0, 0.3)', // Green overlay
      defaultOpacity: 0.3
    },
    custom: {
      name: 'Custom Datasets',
      type: 'overlay',
      color: 'rgba(0, 0, 255, 0.3)', // Blue overlay
      defaultOpacity: 0.3
    }
  }

  // Remove layers that are no longer visible
  Object.keys(mapLayers.value).forEach(layerId => {
    if (!props.visibleLayers.includes(layerId)) {
      map.value.removeLayer(mapLayers.value[layerId])
      delete mapLayers.value[layerId]
    }
  })

  // Add layers that should be visible
  props.visibleLayers.forEach(layerId => {
    if (!mapLayers.value[layerId] && layerDefinitions[layerId]) {
      const layerDef = layerDefinitions[layerId]
      let layer
      const opacity = layerOpacities.value[layerId] || layerDef.defaultOpacity

      if (layerDef.type === 'tile') {
        layer = new TileLayer({
          source: new XYZ({
            url: layerDef.url,
            crossOrigin: 'anonymous'
          }),
          opacity: opacity,
          title: layerDef.name
        })
      } else if (layerDef.type === 'vector') {
        // For custom datasets, we might load from user uploads
        layer = new VectorLayer({
          source: new VectorSource(),
          opacity: opacity,
          title: layerDef.name
        })
      } else if (layerDef.type === 'overlay') {
        // Create a simple overlay layer with a colored rectangle
        const overlaySource = new VectorSource()

        // Use selected area coordinates if user has made a selection, otherwise use Tajikistan
        let polygon
        let extent

        if (props.selectedDistrict || props.selectedRegion) {
          // User has selected a specific area - use its geometry
          const selectedArea = props.selectedDistrict || props.selectedRegion
          if (selectedArea.geometry) {
            try {
              // Parse the geometry JSON string
              const geometry = typeof selectedArea.geometry === 'string'
                ? JSON.parse(selectedArea.geometry)
                : selectedArea.geometry

              // Create polygon from the selected area's geometry
              if (geometry.type === 'Polygon' && geometry.coordinates) {
                polygon = new Polygon(geometry.coordinates)
              } else if (geometry.type === 'MultiPolygon' && geometry.coordinates) {
                // Handle MultiPolygon by taking the first polygon
                polygon = new Polygon(geometry.coordinates[0][0])
              } else {
                // Fallback to bounding box of the geometry
                const geoJsonFormat = new GeoJSON()
                const features = geoJsonFormat.readFeatures({
                  type: 'Feature',
                  geometry: geometry
                }, { featureProjection: 'EPSG:3857' })

                if (features.length > 0) {
                  const featureGeometry = features[0].getGeometry()
                  extent = featureGeometry.getExtent()
                  polygon = new Polygon([[
                    [extent[0], extent[1]],
                    [extent[2], extent[1]],
                    [extent[2], extent[3]],
                    [extent[0], extent[3]],
                    [extent[0], extent[1]]
                  ]])
                }
              }
            } catch (error) {
              console.warn('Error parsing selected area geometry:', error)
            }
          }
        }

        // If no polygon was created from user selection, use Tajikistan coordinates
        if (!polygon) {
          if (topoJsonLayer.value) {
            // Get the extent of the existing GeoJSON layer (Tajikistan districts)
            const geoJsonSource = topoJsonLayer.value.getSource()
            extent = geoJsonSource.getExtent()

            // Create polygon from the GeoJSON extent
            polygon = new Polygon([[
              [extent[0], extent[1]],
              [extent[2], extent[1]],
              [extent[2], extent[3]],
              [extent[0], extent[3]],
              [extent[0], extent[1]]
            ]])
          } else {
            // Fallback: use correct Tajikistan coordinates (Web Mercator)
            const tajikistanExtent = [7150000, 3950000, 7450000, 4150000]
            polygon = new Polygon([[
              [tajikistanExtent[0], tajikistanExtent[1]],
              [tajikistanExtent[2], tajikistanExtent[1]],
              [tajikistanExtent[2], tajikistanExtent[3]],
              [tajikistanExtent[0], tajikistanExtent[3]],
              [tajikistanExtent[0], tajikistanExtent[1]]
            ]])
          }
        }

        const feature = new Feature({
          geometry: polygon,
          name: layerDef.name
        })

        overlaySource.addFeature(feature)

        layer = new VectorLayer({
          source: overlaySource,
          style: new Style({
            fill: new Fill({
              color: layerDef.color
            }),
            stroke: new Stroke({
              color: layerDef.color.replace('0.3', '0.8'),
              width: 2
            })
          }),
          opacity: opacity,
          title: layerDef.name
        })
      }

      if (layer) {
        map.value.addLayer(layer)
        mapLayers.value[layerId] = layer
        console.log(`Added layer: ${layerDef.name} with opacity ${opacity}`)
      }
    }
  })
}

const setLayerOpacity = (layerId, opacity) => {
  layerOpacities.value[layerId] = opacity
  if (mapLayers.value[layerId]) {
    mapLayers.value[layerId].setOpacity(opacity)
    console.log(`Set ${layerId} opacity to ${opacity}`)
  }
}

// Watch for prop changes
watch(() => props.selectedRegion, (newRegion) => {
  if (map.value) {
    updateRegionLayer(newRegion)
  }
}, { immediate: true })

watch(() => props.selectedDistrict, (newDistrict) => {
  if (map.value) {
    updateDistrictLayer(newDistrict)
  }
}, { immediate: true })

watch(() => props.drawingMode, (newMode) => {
  if (map.value) {
    handleDrawingMode(newMode)
  }
}, { immediate: true })

watch(() => props.visibleLayers, (newLayers) => {
  console.log('Visible layers changed:', newLayers)
  if (map.value) {
    console.log('Updating map layers...')
    updateMapLayers()
  } else {
    console.log('Map not ready yet')
  }
}, { immediate: true, deep: true })

// Watch for selection changes to update overlay layers
watch(() => [props.selectedRegion, props.selectedDistrict], () => {
  console.log('Selection changed, updating overlay layers...')
  if (map.value) {
    updateMapLayers()
  }
}, { deep: true })

// Load TopoJSON data
const loadTopoJSONLayer = async (topoJsonUrl, layerName = 'tajikistan') => {
  try {
    console.log('Loading TopoJSON from:', topoJsonUrl)

    const response = await fetch(topoJsonUrl)
    const topoJsonData = await response.json()

    // Remove existing TopoJSON layer if it exists
    if (topoJsonLayer.value) {
      map.value.removeLayer(topoJsonLayer.value)
    }

    // Create TopoJSON format parser
    const topoJsonFormat = new TopoJSON()

    // Parse the TopoJSON data
    const features = topoJsonFormat.readFeatures(topoJsonData, {
      featureProjection: 'EPSG:3857',
    })

    // Create vector source with the features
    const vectorSource = new VectorSource({
      features: features,
    })

    // Create vector layer with custom styling
    topoJsonLayer.value = new VectorLayer({
      source: vectorSource,
      style: new Style({
        fill: new Fill({
          color: 'rgba(59, 130, 246, 0.1)', // Light blue fill
        }),
        stroke: new Stroke({
          color: '#3b82f6', // Blue border
          width: 1,
        }),
      }),
    })

    // Add the layer to the map
    map.value.addLayer(topoJsonLayer.value)

    console.log(`TopoJSON layer '${layerName}' loaded successfully with ${features.length} features`)

    // Fit the map to the extent of the TopoJSON data
    const extent = vectorSource.getExtent()
    if (extent && extent[0] !== Infinity) {
      map.value.getView().fit(extent, {
        padding: [20, 20, 20, 20],
        duration: 2000,
        easing: easeOut,
      })
    }

    return topoJsonLayer.value
  } catch (error) {
    console.error('Error loading TopoJSON:', error)
    throw error
  }
}

// Load TopoJSON from file path
const loadTopoJSONFromFile = async (filePath, layerName = 'tajikistan') => {
  try {
    // If it's a relative path, make it absolute to the public directory
    const fullPath = filePath.startsWith('/') ? filePath : `/storage/${filePath}`
    return await loadTopoJSONLayer(fullPath, layerName)
  } catch (error) {
    console.error('Error loading TopoJSON from file:', error)
    throw error
  }
}

// Load GeoJSON data
const loadGeoJSONLayer = async (geoJsonUrl, layerName = 'tajikistan') => {
  try {
    console.log('Loading GeoJSON from:', geoJsonUrl)

    const response = await fetch(geoJsonUrl)
    const geoJsonData = await response.json()

    // Remove existing GeoJSON layer if it exists
    if (topoJsonLayer.value) {
      map.value.removeLayer(topoJsonLayer.value)
    }

    // Create GeoJSON format parser
    const geoJsonFormat = new GeoJSON()

    // Parse the GeoJSON data
    const features = geoJsonFormat.readFeatures(geoJsonData, {
      featureProjection: 'EPSG:3857',
    })

    // Create vector source with the features
    const vectorSource = new VectorSource({
      features: features,
    })

    // Create vector layer with custom styling for districts
    topoJsonLayer.value = new VectorLayer({
      source: vectorSource,
      style: new Style({
        fill: new Fill({
          color: 'rgba(59, 130, 246, 0.05)', // Very light blue fill
        }),
        stroke: new Stroke({
          color: '#3b82f6', // Blue border
          width: 1,
        }),
      }),
    })

    // Add the layer to the map
    map.value.addLayer(topoJsonLayer.value)

    console.log(`GeoJSON layer '${layerName}' loaded successfully with ${features.length} features`)

    // Fit the map to the extent of the GeoJSON data
    const extent = vectorSource.getExtent()
    if (extent && extent[0] !== Infinity) {
      map.value.getView().fit(extent, {
        padding: [20, 20, 20, 20],
        duration: 2000,
        easing: easeOut,
      })
    }

    return topoJsonLayer.value
  } catch (error) {
    console.error('Error loading GeoJSON:', error)
    throw error
  }
}

// Load GeoJSON from file path
const loadGeoJSONFromFile = async (filePath, layerName = 'tajikistan') => {
  try {
    // If it's a relative path, make it absolute to the public directory
    const fullPath = filePath.startsWith('/') ? filePath : `/storage/${filePath}`
    return await loadGeoJSONLayer(fullPath, layerName)
  } catch (error) {
    console.error('Error loading GeoJSON from file:', error)
    throw error
  }
}

// Highlight a specific district and dim others
const highlightDistrict = (districtName) => {
  if (!topoJsonLayer.value) return

  const source = topoJsonLayer.value.getSource()
  const features = source.getFeatures()

  features.forEach(feature => {
    const properties = feature.getProperties()
    const isSelected = properties.shapeName === districtName

    if (isSelected) {
      feature.setStyle(new Style({
        fill: new Fill({
          color: 'rgba(59, 130, 246, 0.3)', // Highlighted district
        }),
        stroke: new Stroke({
          color: '#2563eb',
          width: 3,
        }),
      }))
    } else {
      feature.setStyle(new Style({
        fill: new Fill({
          color: 'rgba(59, 130, 246, 0.05)', // Dimmed districts
        }),
        stroke: new Stroke({
          color: '#3b82f6',
          width: 1,
        }),
      }))
    }
  })
}

// Reset all districts to normal styling
const resetDistrictHighlighting = () => {
  if (!topoJsonLayer.value) return

  const source = topoJsonLayer.value.getSource()
  const features = source.getFeatures()

  features.forEach(feature => {
    feature.setStyle(new Style({
      fill: new Fill({
        color: 'rgba(59, 130, 246, 0.1)',
      }),
      stroke: new Stroke({
        color: '#3b82f6',
        width: 1,
      }),
    }))
  })
}

// Zoom to a specific district by name
const zoomToDistrict = (districtName) => {
  if (!topoJsonLayer.value) return

  const source = topoJsonLayer.value.getSource()
  const features = source.getFeatures()

  const targetFeature = features.find(feature => {
    const properties = feature.getProperties()
    return properties.shapeName === districtName
  })

  if (targetFeature) {
    const geometry = targetFeature.getGeometry()
    if (geometry) {
      const extent = geometry.getExtent()
      map.value.getView().fit(extent, {
        padding: [50, 50, 50, 50],
        duration: 2000,
        easing: easeOut,
        maxZoom: 12
      })
    }
  }
}

// Load GeoJSON automatically when map is ready
const loadGeoJSONOnMapReady = async () => {
  try {
    console.log('Loading GeoJSON automatically...')
    const geoJsonPath = '/storage/geoBoundaries-TJK-ADM2.geojson'

    await loadGeoJSONLayer(geoJsonPath, 'tajikistan-districts')
    console.log('GeoJSON layer loaded successfully automatically')

    // Emit event to parent to load districts data
    emit('geojson-loaded', geoJsonPath)

  } catch (error) {
    console.warn('Could not load GeoJSON automatically:', error.message)
  }
}

// Expose methods to parent
defineExpose({
  updateErosionData,
  map: map,
  loadTopoJSONLayer,
  loadTopoJSONFromFile,
  loadGeoJSONLayer,
  loadGeoJSONFromFile,
  highlightDistrict,
  resetDistrictHighlighting,
  zoomToDistrict,
  setLayerOpacity,
})

// Lifecycle
onMounted(() => {
  console.log('MapView component mounted')
  nextTick(() => {
    console.log('NextTick - initializing map')
    initMap()
  })
})

onUnmounted(() => {
  // Clean up map and resize handler
  if (map.value) {
    const resizeHandler = map.value.get('resizeHandler')
    if (resizeHandler) {
      window.removeEventListener('resize', resizeHandler)
    }
    map.value.setTarget(null)
  }
})
</script>

<style scoped>
.ol-zoom {
  top: 0.5em;
  left: 0.5em;
}

.ol-rotate {
  top: 0.5em;
  right: 0.5em;
}

.ol-attribution {
  bottom: 0.5em;
  right: 0.5em;
}
</style>
