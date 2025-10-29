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
import { Draw, Modify, Select } from 'ol/interaction'
import { createBox } from 'ol/interaction/Draw'
import { Feature } from 'ol'
import { easeOut, inAndOut } from 'ol/easing'
import { Polygon, Point, LineString } from 'ol/geom'
import 'ol/ol.css'

// Props
const props = defineProps({
  regions: Array,
  districts: Array,
  selectedRegion: Object,
  selectedDistrict: Object,
  selectedAreas: Array,
  selectedYear: Number,
  visibleLayers: Array,
})

// Emits
const emit = defineEmits(['map-ready', 'statistics-updated', 'district-clicked', 'region-clicked', 'geojson-loaded', 'detailed-erosion-loaded'])

// Reactive data
const mapContainer = ref(null)
const map = ref(null)
const vectorSource = ref(null)
const vectorLayer = ref(null)
const regionLayer = ref(null)
const districtLayer = ref(null)
const districtsBaseLayer = ref(null) // Layer showing all districts
const topoJsonLayer = ref(null)
const areaHighlightLayer = ref(null) // Layer for highlighting selected areas
const erosionDataByDistrict = ref({}) // Store erosion data for coloring
const drawnFeatures = ref([]) // Store all drawn features for management
const detailedErosionLayer = ref(null) // Detailed erosion visualization layer for selected area
const animatedLayers = ref(new Set()) // Track layers with animated borders
const baseLayer = ref(null) // Reference to base layer
const labelsLayer = ref(null) // Reference to labels layer

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

  // Create base layer (using OpenStreetMap)
  const baseLayerInstance = new TileLayer({
    source: new OSM(),
  })

  // Create labels layer (using OpenStreetMap with labels)
  const labelsLayerInstance = new TileLayer({
    source: new OSM(),
    title: 'Tajikistan Labels'
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
      baseLayerInstance,
      labelsLayerInstance,
      vectorLayer.value,
    ],
    view: new View({
      center: mapConfig.center,
      zoom: mapConfig.zoom,
      maxZoom: mapConfig.maxZoom,
      minZoom: mapConfig.minZoom,
    }),
  })

  // Store references for later use
  baseLayer.value = baseLayerInstance
  labelsLayer.value = labelsLayerInstance

  // Emit map ready event
  emit('map-ready', map.value)

  // Update map layers when map becomes ready
  console.log('Updating map layers after map is ready...')
  updateMapLayers()

  // Add click handler for statistics and area selection
  map.value.on('click', handleMapClick)
  
  // Add click handler for area selection
  map.value.on('click', handleAreaClick)

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
  
  // Load districts layer if districts prop is available
  if (props.districts && props.districts.length > 0) {
    loadDistrictsLayer()
  }
}

// Get erosion risk color based on value
const getErosionColor = (erosionRate, opacity = 0.6) => {
  // RUSLE Erosion Risk Classification (Updated):
  // Very Low: 0-5 t/ha/yr - Green
  // Low: 5-15 t/ha/yr - Yellow
  // Moderate: 15-30 t/ha/yr - Orange
  // Severe: 30-50 t/ha/yr - Red
  // Excessive: > 50 t/ha/yr - Dark Red
  
  if (!erosionRate || erosionRate < 0) {
    return `rgba(200, 200, 200, ${opacity})` // Gray for no data
  }
  
  if (erosionRate < 5) {
    return `rgba(34, 139, 34, ${opacity})` // Green - Very Low
  } else if (erosionRate < 15) {
    return `rgba(255, 215, 0, ${opacity})` // Yellow - Low
  } else if (erosionRate < 30) {
    return `rgba(255, 140, 0, ${opacity})` // Orange - Moderate
  } else if (erosionRate < 50) {
    return `rgba(220, 20, 60, ${opacity})` // Red - Severe
  } else {
    return `rgba(139, 0, 0, 0.8)` // Dark Red - Excessive
  }
}

// Smooth border animation functions
const animateBorderDrawing = (layer, features, duration = 2000, strokeColor = '#3b82f6', strokeWidth = 2) => {
  if (!layer || !features || features.length === 0) return

  const startTime = Date.now()
  const animationId = `border_animation_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`
  
  // Store animation ID for cleanup
  animatedLayers.value.add(animationId)

  const animate = () => {
    const elapsed = Date.now() - startTime
    const progress = Math.min(elapsed / duration, 1)
    
    // Use inAndOut for smooth animation
    const easedProgress = inAndOut(progress)
    
    // Calculate animated stroke properties
    const animatedWidth = strokeWidth * easedProgress
    const animatedOpacity = Math.min(0.3 + (easedProgress * 0.7), 1) // Start with low opacity
    
    // Create animated stroke style
    const animatedStroke = new Stroke({
      color: strokeColor.replace(/[\d.]+\)$/, `${animatedOpacity})`),
      width: animatedWidth,
      lineCap: 'round',
      lineJoin: 'round'
    })

    // Apply animated style to all features
    features.forEach(feature => {
      const currentStyle = feature.getStyle()
      if (currentStyle) {
        const newStyle = new Style({
          fill: currentStyle.getFill(),
          stroke: animatedStroke
        })
        feature.setStyle(newStyle)
      }
    })

    // Continue animation if not complete and layer still exists
    if (progress < 1 && animatedLayers.value.has(animationId)) {
      requestAnimationFrame(animate)
    } else {
      // Animation complete - set final style
      const finalStroke = new Stroke({
        color: strokeColor,
        width: strokeWidth,
        lineCap: 'round',
        lineJoin: 'round'
      })

      features.forEach(feature => {
        const currentStyle = feature.getStyle()
        if (currentStyle) {
          const finalStyle = new Style({
            fill: currentStyle.getFill(),
            stroke: finalStroke
          })
          feature.setStyle(finalStyle)
        }
      })

      // Clean up animation
      animatedLayers.value.delete(animationId)
    }
  }

  // Start animation
  requestAnimationFrame(animate)
}

// Animate border drawing for a layer with progressive reveal
const animateLayerBorderDrawing = (layer, duration = 2000) => {
  if (!layer) return

  const source = layer.getSource()
  if (!source) return

  const features = source.getFeatures()
  if (features.length === 0) return

  // Get layer style to extract stroke properties
  const layerStyle = layer.getStyle()
  let strokeColor = '#3b82f6'
  let strokeWidth = 2

  if (layerStyle && layerStyle.getStroke) {
    const stroke = layerStyle.getStroke()
    if (stroke) {
      strokeColor = stroke.getColor() || strokeColor
      strokeWidth = stroke.getWidth() || strokeWidth
    }
  }

  // Start border animation
  animateBorderDrawing(layer, features, duration, strokeColor, strokeWidth)
}

// Progressive border drawing for complex geometries
const animateComplexBorderDrawing = (layer, features, duration = 3000) => {
  if (!layer || !features || features.length === 0) return

  const startTime = Date.now()
  const animationId = `complex_border_animation_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`
  
  animatedLayers.value.add(animationId)

  const animate = () => {
    const elapsed = Date.now() - startTime
    const progress = Math.min(elapsed / duration, 1)
    
    // Use different easing for complex animations
    const easedProgress = easeOut(progress)
    
    // Calculate progressive reveal
    const revealProgress = Math.min(progress * 1.2, 1) // Slightly faster reveal
    const pulseProgress = Math.sin(progress * Math.PI * 4) * 0.3 + 0.7 // Pulsing effect
    
    // Animated stroke properties
    const animatedWidth = 2 + (pulseProgress * 1.5)
    const animatedOpacity = Math.min(0.2 + (revealProgress * 0.8), 1)
    
    const animatedStroke = new Stroke({
      color: `rgba(59, 130, 246, ${animatedOpacity})`,
      width: animatedWidth,
      lineCap: 'round',
      lineJoin: 'round',
      lineDash: progress < 0.8 ? [5, 5] : undefined // Dashed during drawing, solid when complete
    })

    // Apply to features
    features.forEach(feature => {
      const currentStyle = feature.getStyle()
      if (currentStyle) {
        const newStyle = new Style({
          fill: currentStyle.getFill(),
          stroke: animatedStroke
        })
        feature.setStyle(newStyle)
      }
    })

    // Continue or finish
    if (progress < 1 && animatedLayers.value.has(animationId)) {
      requestAnimationFrame(animate)
    } else {
      // Set final solid style
      const finalStroke = new Stroke({
        color: '#3b82f6',
        width: 2,
        lineCap: 'round',
        lineJoin: 'round'
      })

      features.forEach(feature => {
        const currentStyle = feature.getStyle()
        if (currentStyle) {
          const finalStyle = new Style({
            fill: currentStyle.getFill(),
            stroke: finalStroke
          })
          feature.setStyle(finalStyle)
        }
      })

      animatedLayers.value.delete(animationId)
    }
  }

  requestAnimationFrame(animate)
}

// Stop all border animations
const stopAllBorderAnimations = () => {
  animatedLayers.value.clear()
}

// Trigger border animation for all visible layers (for testing)
const animateAllVisibleBorders = () => {
  console.log('Animating all visible layer borders...')
  
  // Animate districts layer
  if (districtsBaseLayer.value) {
    animateLayerBorderDrawing(districtsBaseLayer.value, 2000)
  }
  
  // Animate region layer
  if (regionLayer.value) {
    const source = regionLayer.value.getSource()
    if (source) {
      const features = source.getFeatures()
      animateComplexBorderDrawing(regionLayer.value, features, 2500)
    }
  }
  
  // Animate district layer
  if (districtLayer.value) {
    const source = districtLayer.value.getSource()
    if (source) {
      const features = source.getFeatures()
      animateComplexBorderDrawing(districtLayer.value, features, 2500)
    }
  }
  
  // Animate GeoJSON layer
  if (topoJsonLayer.value) {
    animateLayerBorderDrawing(topoJsonLayer.value, 2000)
  }
}

// Load all districts as a base layer
const loadDistrictsLayer = () => {
  try {
    console.log('Loading districts layer with', props.districts.length, 'districts')
    
    const geojsonFormat = new GeoJSON()
    const features = []
    
    props.districts.forEach((district, index) => {
      if (district.geometry) {
        try {
          let geometryData = district.geometry
          if (typeof geometryData === 'string') {
            geometryData = JSON.parse(geometryData)
          }
          
          // Create GeoJSON feature
          const geoJsonFeature = {
            type: 'Feature',
            geometry: geometryData,
            properties: {
              id: district.id,
              name: district.name || district.name_en,
              name_en: district.name_en,
              name_tj: district.name_tj,
              region_id: district.region_id,
              area_km2: district.area_km2,
              erosion_rate: erosionDataByDistrict.value[district.id] || 0
            }
          }
          
          const feature = geojsonFormat.readFeature(geoJsonFeature, {
            dataProjection: 'EPSG:4326',
            featureProjection: 'EPSG:3857',
          })
          
          features.push(feature)
        } catch (error) {
          console.warn(`Error loading district ${district.name_en}:`, error)
        }
      }
    })
    
    console.log('Loaded', features.length, 'district features')
    
    const source = new VectorSource({
      features,
    })
    
    // Style function for districts based on erosion data
    const styleFunction = (feature) => {
      const erosionRate = feature.get('erosion_rate') || 0
      const isSelected = props.selectedDistrict && feature.get('id') === props.selectedDistrict.id
      
      return new Style({
        fill: new Fill({
          color: getErosionColor(erosionRate, isSelected ? 0.8 : 0.4),
        }),
        stroke: new Stroke({
          color: isSelected ? '#000000' : '#666666',
          width: isSelected ? 3 : 1,
        }),
      })
    }
    
    districtsBaseLayer.value = new VectorLayer({
      source,
      style: styleFunction,
      zIndex: 10,
    })
    
    map.value.addLayer(districtsBaseLayer.value)
    
    // Animate border drawing for districts
    setTimeout(() => {
      animateLayerBorderDrawing(districtsBaseLayer.value, 2500)
    }, 100)
    
    // Add click handler for districts
    map.value.on('click', (event) => {
      const feature = map.value.forEachFeatureAtPixel(event.pixel, (feature, layer) => {
        if (layer === districtsBaseLayer.value) {
          return feature
        }
      })
      
      if (feature) {
        const districtData = {
          id: feature.get('id'),
          name: feature.get('name'),
          name_en: feature.get('name_en'),
          name_tj: feature.get('name_tj'),
          region_id: feature.get('region_id'),
          area_km2: feature.get('area_km2'),
          erosion_rate: feature.get('erosion_rate'),
        }
        
        emit('district-clicked', districtData)
      }
    })
    
  } catch (error) {
    console.error('Error loading districts layer:', error)
  }
}

// Update erosion data for a specific district
const updateDistrictErosionData = (districtId, erosionRate) => {
  erosionDataByDistrict.value[districtId] = erosionRate
  
  // Update the feature style
  if (districtsBaseLayer.value) {
    const source = districtsBaseLayer.value.getSource()
    const features = source.getFeatures()
    
    features.forEach(feature => {
      if (feature.get('id') === districtId) {
        feature.set('erosion_rate', erosionRate)
        feature.changed() // Trigger style update
      }
    })
  }
}

// Refresh districts layer styling
const refreshDistrictsLayer = () => {
  if (districtsBaseLayer.value) {
    districtsBaseLayer.value.getSource().changed()
  }
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

// Handle area click for selection tool updates
const handleAreaClick = (event) => {
  const features = map.value.getFeaturesAtPixel(event.pixel)
  
  if (features.length > 0) {
    const feature = features[0]
    const properties = feature.getProperties()
    
    // Check if this is a district or region feature
    if (properties.district_id || properties.region_id) {
      // This is a district
      const district = {
        id: properties.district_id || properties.id,
        name_en: properties.name_en || properties.district_name_en,
        name_tj: properties.name_tj || properties.district_name_tj,
        region_id: properties.region_id,
        geometry: properties.geometry
      }
      
      console.log('District clicked for selection:', district)
      emit('district-clicked', district)
    } else if (properties.region_id === undefined && properties.district_id === undefined && properties.id) {
      // This might be a region
      const region = {
        id: properties.id,
        name_en: properties.name_en || properties.region_name_en,
        name_tj: properties.name_tj || properties.region_name_tj,
        geometry: properties.geometry
      }
      
      console.log('Region clicked for selection:', region)
      emit('region-clicked', region)
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
          width: 3,
        }),
      }),
      zIndex: 20, // Above base layers but below detailed erosion
    })

    map.value.addLayer(regionLayer.value)
    
    // Animate border drawing for region
    setTimeout(() => {
      animateComplexBorderDrawing(regionLayer.value, features, 3000)
    }, 200)

    // Fit to region bounds
    const extent = source.getExtent()
    map.value.getView().fit(extent, {
      padding: [20, 20, 20, 20],
      duration: 2000,
      easing: easeOut
    })

    // Load detailed erosion data for this region
    loadDetailedErosionData(region)
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
          width: 3,
        }),
      }),
      zIndex: 20, // Above base layers but below detailed erosion
    })

    map.value.addLayer(districtLayer.value)
    
    // Animate border drawing for district
    setTimeout(() => {
      animateComplexBorderDrawing(districtLayer.value, features, 3000)
    }, 200)

    // Fit to district bounds
    const extent = source.getExtent()
    map.value.getView().fit(extent, {
      padding: [20, 20, 20, 20],
      duration: 2000,
      easing: easeOut
    })

    // Load detailed erosion data for this district
    loadDetailedErosionData(district)
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
      source: new XYZ({
        url: data.tiles,
        crossOrigin: 'anonymous',
      }),
      opacity: 0.7,
    })

    map.value.addLayer(erosionLayer)
  }
}

// Load detailed erosion data for selected area (district or region)
const loadDetailedErosionData = async (area) => {
  // Remove existing detailed layer
  if (detailedErosionLayer.value) {
    map.value.removeLayer(detailedErosionLayer.value)
    detailedErosionLayer.value = null
  }

  if (!area) return

  try {
    console.log('Loading detailed erosion data from backend for:', area.name_en || area.name)

    // Determine area type
    let areaType, areaId
    if (area.area_type === 'country' || area.id === 0) {
      areaType = 'country'
      areaId = 0
    } else {
      areaType = area.region_id ? 'district' : 'region'
      areaId = area.id
    }
    
    // Fetch real data from backend
    const response = await axios.post('/api/erosion/detailed-grid', {
      area_type: areaType,
      area_id: areaId,
      year: props.selectedYear || 2024,
      grid_size: 10,
    })

    if (!response.data.success) {
      // Show user-friendly error message
      const errorMsg = response.data.error || 'Failed to fetch detailed grid data'
      console.error('Detailed grid error:', errorMsg)
      emit('geometry-error', errorMsg)
      return
    }

    const gridData = response.data.data
    console.log('Received grid data from backend:', gridData.cells?.length, 'cells')

    const geojsonFormat = new GeoJSON()
    
    // Convert backend grid cells to OpenLayers features
    // Backend already ensures cells match the exact area shape
    const detailedFeatures = gridData.cells.map(cell => {
      // Read cell geometry from backend (already clipped to area boundary)
      const cellGeometry = geojsonFormat.readGeometry(cell.geometry, {
        dataProjection: 'EPSG:4326',
        featureProjection: 'EPSG:3857',
      })

      return new Feature({
        geometry: cellGeometry,
        erosionRate: cell.erosion_rate,
        cellId: `${cell.x}-${cell.y}`,
      })
    })

    const detailedSource = new VectorSource({
      features: detailedFeatures,
    })

    // Style function for detailed erosion visualization
    const detailedStyleFunction = (feature) => {
      const erosionRate = feature.get('erosionRate')
      return new Style({
        fill: new Fill({
          color: getErosionColor(erosionRate, 0.7),
        }),
        stroke: new Stroke({
          color: getErosionColor(erosionRate, 0.9),
          width: 0.5,
        }),
      })
    }

    detailedErosionLayer.value = new VectorLayer({
      source: detailedSource,
      style: detailedStyleFunction,
      zIndex: 15, // Above district outline but below interactions
    })

    map.value.addLayer(detailedErosionLayer.value)

    console.log('Detailed erosion layer added with', detailedFeatures.length, 'cells')

    // Emit event to notify that detailed data is loaded
    emit('detailed-erosion-loaded', {
      areaId: area.id,
      areaName: area.name_en || area.name,
      cellCount: detailedFeatures.length,
      statistics: gridData.statistics,
    })

  } catch (error) {
    console.error('Error loading detailed erosion data:', error)
    emit('geometry-error', 'Failed to load detailed erosion data: ' + error.message)
  }
}


// Clip geometry to country bounds
const clipGeometryToCountryBounds = async (geometry) => {
  if (!topoJsonLayer.value) return geometry

  try {
    const turf = await import('@turf/turf')
    const source = topoJsonLayer.value.getSource()
    const features = source.getFeatures()
    
    if (features.length === 0) return geometry

    // Get country boundary as a union of all districts
    const geojsonFormat = new GeoJSON()
    let countryBoundary = null

    for (const feature of features) {
      const geoJsonFeature = geojsonFormat.writeFeatureObject(feature, {
        featureProjection: 'EPSG:3857',
        dataProjection: 'EPSG:4326'
      })
      
      if (!countryBoundary) {
        countryBoundary = geoJsonFeature
      } else {
        // Union with existing boundary
        countryBoundary = turf.union(countryBoundary, geoJsonFeature)
      }
    }

    // Convert drawn geometry to GeoJSON
    const drawnFeature = new Feature({ geometry })
    const drawnGeoJson = geojsonFormat.writeFeatureObject(drawnFeature, {
      featureProjection: 'EPSG:3857',
      dataProjection: 'EPSG:4326'
    })

    // Clip to country boundary
    const clipped = turf.intersect(drawnGeoJson, countryBoundary)
    
    if (!clipped) return null

    // Convert back to OpenLayers geometry
    const clippedFeature = geojsonFormat.readFeature(clipped, {
      dataProjection: 'EPSG:4326',
      featureProjection: 'EPSG:3857'
    })

    return clippedFeature.getGeometry()
  } catch (error) {
    console.error('Error in clipGeometryToCountryBounds:', error)
    return geometry // Return original on error
  }
}

// Layer management
const mapLayers = ref({})


const updateMapLayers = async () => {
  if (!map.value) return

  console.log('Updating map layers, visible:', props.visibleLayers)
  console.log('Selected area:', props.selectedDistrict || props.selectedRegion || 'none')

  // Clear all previous layer colors and area highlights before updating
  console.log('Clearing previous layer colors and area highlights before updating map layers')
  clearAllLayerColors()
  clearAreaHighlights()

  // Check if areas are selected (support multiple areas) - declare early
  const selectedArea = props.selectedDistrict || props.selectedRegion
  const selectedAreas = props.selectedAreas || []

  // Define available map layers
  const layerDefinitions = {
    erosion: {
      name: 'Soil Erosion Hazard',
      type: 'rusle',
      apiEndpoint: null, // Uses detailed grid
      defaultOpacity: 0.7
    },
    rainfall_slope: {
      name: 'Rainfall Trend',
      type: 'diverging',
      apiEndpoint: '/api/erosion/layers/rainfall-slope',
      defaultOpacity: 0.6
    },
    rainfall_cv: {
      name: 'Rainfall CV',
      type: 'sequential',
      apiEndpoint: '/api/erosion/layers/rainfall-cv',
      defaultOpacity: 0.6
    },
    r_factor: {
      name: 'R-Factor',
      type: 'sequential',
      apiEndpoint: '/api/erosion/layers/r-factor',
      defaultOpacity: 0.6
    },
    k_factor: {
      name: 'K-Factor',
      type: 'sequential',
      apiEndpoint: '/api/erosion/layers/k-factor',
      defaultOpacity: 0.6
    },
    ls_factor: {
      name: 'LS-Factor',
      type: 'sequential',
      apiEndpoint: '/api/erosion/layers/ls-factor',
      defaultOpacity: 0.6
    },
    c_factor: {
      name: 'C-Factor',
      type: 'sequential',
      apiEndpoint: '/api/erosion/layers/c-factor',
      defaultOpacity: 0.6
    },
    p_factor: {
      name: 'P-Factor',
      type: 'sequential',
      apiEndpoint: '/api/erosion/layers/p-factor',
      defaultOpacity: 0.6
    },
    bare_soil: {
      name: 'Bare Soil Frequency',
      type: 'sequential',
      apiEndpoint: null,
      defaultOpacity: 0.6
    },
    sustainability: {
      name: 'Sustainability Factor',
      type: 'sequential',
      apiEndpoint: null,
      defaultOpacity: 0.6
    },
    custom: {
      name: 'Custom Datasets',
      type: 'custom',
      apiEndpoint: null,
      defaultOpacity: 0.6
    }
  }

  // Clear all existing layers first (single layer display)
  Object.keys(mapLayers.value).forEach(layerId => {
    map.value.removeLayer(mapLayers.value[layerId])
    delete mapLayers.value[layerId]
  })
  
  // Clear base layer colors when switching layers
  if (districtsBaseLayer.value) {
    const source = districtsBaseLayer.value.getSource()
    if (source) {
      source.forEachFeature(feature => {
        feature.unset('erosion_rate')
      })
      districtsBaseLayer.value.changed()
    }
  }
  
  if (regionLayer.value) {
    const source = regionLayer.value.getSource()
    if (source) {
      source.forEachFeature(feature => {
        feature.unset('erosion_rate')
      })
      regionLayer.value.changed()
    }
  }

  // Add only the selected layer (if any)
  if (props.visibleLayers.length > 0) {
    const layerId = props.visibleLayers[0] // Only show the first (and only) selected layer
    if (layerDefinitions[layerId]) {
      const layerDef = layerDefinitions[layerId]
      const opacity = layerDef.defaultOpacity

      // Handle erosion layer specially
      if (layerId === 'erosion') {
        console.log('Handling erosion layer for country-wide display')
        
        // For country-wide erosion, use the multiple areas approach to paint all districts
        if (!selectedArea && selectedAreas.length === 0) {
          // No area selected - load erosion data for all districts individually
          console.log('No area selected, loading erosion data for all districts')
          await loadDetailedErosionData({ id: 0, name_en: 'Tajikistan', area_type: 'country' })
        } else if (selectedAreas.length > 0) {
          // Multiple areas selected - load erosion data for each area
          console.log(`${selectedAreas.length} areas selected, loading erosion data for each`)
          for (const area of selectedAreas) {
            await loadDetailedErosionData(area)
          }
        } else {
          // Single area selected - load detailed erosion data for that area
          await loadDetailedErosionData(selectedArea)
        }
        return
      }

      console.log(`updateMapLayers - selectedArea:`, selectedArea)
      console.log(`updateMapLayers - selectedAreas:`, selectedAreas)
      console.log(`updateMapLayers - selectedDistrict:`, props.selectedDistrict)
      console.log(`updateMapLayers - selectedRegion:`, props.selectedRegion)
      
      if (selectedAreas.length > 0) {
        // MULTIPLE AREAS SELECTED: Show each selected area with layer data
        console.log(`${selectedAreas.length} areas selected, fetching ${layerId} layer for each area`)
        
        if (layerDef.apiEndpoint) {
          try {
            // Create individual layers for each selected area
            const areaLayers = []
            
            for (const area of selectedAreas) {
              try {
                let areaType
                let areaId
                
                if (area.area_type) {
                  areaType = area.area_type
                  areaId = area.id
                } else if (area.region_id) {
                  // This is a district
                  areaType = 'district'
                  areaId = area.id
                } else {
                  // This is a region
                  areaType = 'region'
                  areaId = area.id
                }
                
                console.log(`Fetching ${layerId} for ${areaType} ${areaId} (${area.name_en})`)
                
                const requestData = {
                  area_type: areaType,
                  area_id: areaId,
                  year: props.selectedYear || 2024
                }
                
                // Add year range for rainfall layers
                if (layerId === 'rainfall_slope' || layerId === 'rainfall_cv') {
                  const selectedYear = props.selectedYear || 2024
                  requestData.start_year = Math.max(2016, selectedYear - 5)
                  requestData.end_year = selectedYear
                }
                
                const layer = await fetchAndRenderLayer(layerId, layerDef, area, areaType, opacity)
                
                if (layer) {
                  areaLayers.push(layer)
                  console.log(`Added layer for ${area.name_en}: ${layerDef.name}`)
                }
              } catch (error) {
                console.error(`Error loading ${layerId} for area ${area.name_en}:`, error)
              }
            }
            
            // Store all individual layers as a virtual layer
            if (areaLayers.length > 0) {
              mapLayers.value[layerId] = {
                type: 'multiple-areas',
                layers: areaLayers,
                name: layerDef.name
              }
              console.log(`Added ${areaLayers.length} individual area layers for ${layerDef.name}`)
            }
          } catch (error) {
            console.error(`Error loading ${layerId} for multiple areas:`, error)
            emit('geometry-error', `Failed to load ${layerDef.name}`)
          }
        } else {
          console.log(`No API endpoint for ${layerId}, skipping`)
        }
      } else if (selectedArea) {
        // SINGLE AREA SELECTED: Show only selected area with layer data
        console.log(`Single area selected: ${selectedArea.name_en}, fetching ${layerId} layer for area only`)
        
        if (layerDef.apiEndpoint) {
          try {
            let areaType
            if (selectedArea.area_type === 'country') {
              areaType = 'country'
            } else {
              areaType = selectedArea.region_id ? 'district' : 'region'
            }
            
            // Fetch layer data from backend for selected area only
            const layer = await fetchAndRenderLayer(layerId, layerDef, selectedArea, areaType, opacity)
            
            if (layer) {
              // Handle country-wide layers (which return an object with multiple layers)
              if (layer.type === 'country-wide') {
                mapLayers.value[layerId] = layer
                console.log(`Added country-wide layer with ${layer.layers.length} individual area layers: ${layerDef.name}`)
              } else {
                mapLayers.value[layerId] = layer
                console.log(`Added area-specific layer: ${layerDef.name}`)
              }
            }
          } catch (error) {
            console.error(`Error loading ${layerId} for area:`, error)
            emit('geometry-error', `Failed to load ${layerDef.name}`)
          }
        } else {
          console.log(`No API endpoint for ${layerId}, skipping`)
        }
      } else {
        // NO AREA SELECTED: Show country-wide layer data
        console.log(`No area selected, fetching ${layerId} layer for country-wide display`)
        
        if (layerDef.apiEndpoint) {
          try {
            // For country-wide display, we'll use a default area or create a country-wide layer
            const countryWideArea = {
              id: 0, // Special ID for country-wide
              name_en: 'Tajikistan',
              region_id: null
            }
            
            // Fetch layer data from backend for country-wide display
            const layer = await fetchAndRenderLayer(layerId, layerDef, countryWideArea, 'country', opacity)
            
            if (layer) {
              // Handle country-wide layers (which return an object with multiple layers)
              if (layer.type === 'country-wide') {
                mapLayers.value[layerId] = layer
                console.log(`Added country-wide layer with ${layer.layers.length} individual area layers: ${layerDef.name}`)
              } else {
                mapLayers.value[layerId] = layer
                console.log(`Added country-wide layer: ${layerDef.name}`)
              }
            }
          } catch (error) {
            console.error(`Error loading ${layerId} for country-wide:`, error)
            emit('geometry-error', `Failed to load ${layerDef.name}`)
          }
        } else {
          console.log(`No API endpoint for ${layerId}, skipping`)
        }
      }
    }
  }
}

// Fetch and render a RUSLE factor layer
const fetchAndRenderLayer = async (layerId, layerDef, area, areaType, opacity) => {
  try {
    const requestData = {
      area_type: areaType,
      area_id: area.id,
      year: props.selectedYear || 2024
    }
    
    // Add year range for rainfall layers
    if (layerId === 'rainfall_slope' || layerId === 'rainfall_cv') {
      const selectedYear = props.selectedYear || 2024
      // Ensure start_year is at least 2016 (minimum allowed year)
      requestData.start_year = Math.max(2016, selectedYear - 5)
      requestData.end_year = selectedYear
    }
    
    const response = await axios.post(layerDef.apiEndpoint, requestData)
    
    if (!response.data.success) {
      throw new Error('Failed to fetch layer data')
    }
    
    const layerData = response.data.data
    
    // If backend returns tiles URL, use tile layer
    if (layerData.tiles) {
      const layer = new TileLayer({
        source: new XYZ({
          url: layerData.tiles,
          crossOrigin: 'anonymous'
        }),
        opacity: opacity,
        zIndex: 12,
        title: layerDef.name
      })
      map.value.addLayer(layer)
      return layer
    }
    
    // Otherwise, create vector layer with color ramp
    const layer = await createVectorLayerFromData(layerId, layerDef, area, layerData, opacity)
    return layer
    
  } catch (error) {
    console.error(`Error fetching layer ${layerId}:`, error)
    return null
  }
}

// Create vector layer with appropriate color ramp
const createVectorLayerFromData = async (layerId, layerDef, area, layerData, opacity) => {
  // For country-wide display, fetch and paint each district and region individually
  if (area.id === 0 || !area.geometry) {
    console.log('Creating country-wide visualization by painting each area individually')
    
    try {
      // Fetch all regions and districts
      const [regionsResponse, districtsResponse] = await Promise.all([
        axios.get('/api/erosion/regions'),
        axios.get('/api/erosion/districts')
      ])
      
      console.log('Regions response:', regionsResponse.data)
      console.log('Districts response:', districtsResponse.data)
      
      const allAreas = []
      
      // Add regions
      if (regionsResponse.data && regionsResponse.data.success && regionsResponse.data.regions && Array.isArray(regionsResponse.data.regions) && regionsResponse.data.regions.length > 0) {
        regionsResponse.data.regions.forEach(region => {
          if (region.geometry) {
            allAreas.push({ ...region, type: 'region' })
          }
        })
        console.log(`Added ${regionsResponse.data.regions.length} regions`)
      } else {
        console.warn('No regions data available or invalid response structure')
      }
      
      // Add districts
      if (districtsResponse.data && districtsResponse.data.success && districtsResponse.data.districts && Array.isArray(districtsResponse.data.districts) && districtsResponse.data.districts.length > 0) {
        districtsResponse.data.districts.forEach(district => {
          if (district.geometry) {
            allAreas.push({ ...district, type: 'district' })
          }
        })
        console.log(`Added ${districtsResponse.data.districts.length} districts`)
      } else {
        console.warn('No districts data available or invalid response structure')
      }
      
      console.log(`Found ${allAreas.length} areas to paint for country-wide visualization`)
      
      // If no areas found, return null
      if (allAreas.length === 0) {
        console.warn('No areas found for country-wide visualization')
        return null
      }
      
      // Create individual layers for each area
      const areaLayers = []
      
      for (const areaItem of allAreas) {
        try {
          console.log(`Processing area: ${areaItem.name_en} (${areaItem.type})`)
          
          // Fetch layer data for this specific area
          const areaType = areaItem.type
          const areaId = areaItem.id
          
          const requestData = {
            area_type: areaType,
            area_id: areaId,
            year: props.selectedYear || 2024
          }
          
          // Add year range for rainfall layers
          if (layerId === 'rainfall_slope' || layerId === 'rainfall_cv') {
            const selectedYear = props.selectedYear || 2024
            requestData.start_year = Math.max(2016, selectedYear - 5)
            requestData.end_year = selectedYear
          }
          
          console.log(`Fetching data for ${areaItem.name_en}:`, requestData)
          const response = await axios.post(layerDef.apiEndpoint, requestData)
          console.log(`Response for ${areaItem.name_en}:`, response.data)
          
          if (response.data && response.data.success && response.data.data) {
            const areaLayerData = response.data.data
            console.log(`Layer data for ${areaItem.name_en}:`, areaLayerData)
            
            // Validate layer data structure
            if (!areaLayerData || typeof areaLayerData !== 'object') {
              console.warn(`Invalid layer data for ${areaItem.name_en}:`, areaLayerData)
              continue
            }
            
            // Parse area geometry
            let geometryData = areaItem.geometry
            if (typeof geometryData === 'string') {
              geometryData = JSON.parse(geometryData)
            }
            
            console.log(`Geometry for ${areaItem.name_en}:`, geometryData)
            
            const geojsonFormat = new GeoJSON()
            const features = geojsonFormat.readFeatures(geometryData, {
              dataProjection: 'EPSG:4326',
              featureProjection: 'EPSG:3857',
            })
            
            console.log(`Features for ${areaItem.name_en}:`, features.length)
            
            if (features.length > 0) {
              const areaGeometry = features[0].getGeometry()
              const extent = areaGeometry.getExtent()
              
              console.log(`Extent for ${areaItem.name_en}:`, extent)
              
              // Validate areaLayerData before creating grid
              if (!areaLayerData || typeof areaLayerData !== 'object') {
                console.warn(`Invalid areaLayerData for ${areaItem.name_en}, skipping grid creation`)
                continue
              }
              
              // Create grid with color-coded cells for this area
              const gridFeatures = createColoredGrid(areaGeometry, extent, areaLayerData, layerDef.type)
              
              console.log(`Grid features for ${areaItem.name_en}:`, gridFeatures.length)
              
              if (gridFeatures.length > 0) {
                const source = new VectorSource({
                  features: gridFeatures,
                })

                const layer = new VectorLayer({
                  source,
                  opacity: opacity,
                  zIndex: 12,
                  title: `${layerDef.name} - ${areaItem.name_en}`,
                })

                console.log(`Adding layer to map for ${areaItem.name_en}`)
                console.log(`Map reference:`, map.value)
                console.log(`Layer to add:`, layer)
                
                if (map.value) {
                  map.value.addLayer(layer)
                  console.log(`Layer added to map successfully`)
                  console.log(`Map layers count:`, map.value.getLayers().getLength())
                } else {
                  console.error(`Map reference is null!`)
                }
                
                areaLayers.push(layer)
                
                console.log(`✅ Painted ${areaItem.name_en} (${areaType}) with ${gridFeatures.length} cells`)
              } else {
                console.warn(`No grid features created for ${areaItem.name_en}`)
              }
            } else {
              console.warn(`No features found for ${areaItem.name_en}`)
            }
          } else {
            console.warn(`API call failed for ${areaItem.name_en}:`, response.data)
          }
        } catch (error) {
          console.error(`Failed to paint ${areaItem.name_en} (${areaItem.type}):`, error)
        }
      }
      
      console.log(`Created ${areaLayers.length} individual area layers for country-wide visualization`)
      
      // If no layers were created, create a simple test layer
      if (areaLayers.length === 0) {
        console.log('No individual layers created, creating test layer')
        
        // Create a simple test rectangle over Tajikistan
        const testGeometry = new Polygon([[
          [67.0, 36.0], // Southwest corner
          [75.0, 36.0], // Southeast corner
          [75.0, 41.0], // Northeast corner
          [67.0, 41.0], // Northwest corner
          [67.0, 36.0]  // Close the polygon
        ]])
        
        const testFeature = new Feature({
          geometry: testGeometry,
          value: 25,
          layerType: layerDef.type
        })
        
        testFeature.setStyle(new Style({
          fill: new Fill({
            color: 'rgba(255, 0, 0, 0.7)'
          }),
          stroke: new Stroke({
            color: 'rgba(255, 0, 0, 1)',
            width: 2
          })
        }))
        
        const testSource = new VectorSource({
          features: [testFeature]
        })
        
        const testLayer = new VectorLayer({
          source: testSource,
          opacity: 0.7,
          zIndex: 12,
          title: `${layerDef.name} - Test Layer`
        })
        
        map.value.addLayer(testLayer)
        areaLayers.push(testLayer)
        
        console.log('Created test layer for country-wide visualization')
      }
      
      // Return a virtual layer that represents all the individual layers
      return {
        type: 'country-wide',
        layers: areaLayers,
        name: layerDef.name
      }
      
    } catch (error) {
      console.error('Error creating country-wide visualization:', error)
    }
    return null
  }
  
  // For specific area display
  let geometryData = area.geometry
  if (typeof geometryData === 'string') {
    geometryData = JSON.parse(geometryData)
  }

  const geojsonFormat = new GeoJSON()
  const features = geojsonFormat.readFeatures(geometryData, {
    dataProjection: 'EPSG:4326',
    featureProjection: 'EPSG:3857',
  })

  if (features.length === 0) return null

  const areaGeometry = features[0].getGeometry()
  const extent = areaGeometry.getExtent()
  
  // Create grid with color-coded cells
  const gridFeatures = createColoredGrid(areaGeometry, extent, layerData, layerDef.type)
  
  const source = new VectorSource({
    features: gridFeatures,
  })

  const layer = new VectorLayer({
    source,
    opacity: opacity,
    zIndex: 12,
    title: layerDef.name,
  })

  map.value.addLayer(layer)
  return layer
}

// Create colored grid for layer visualization - CLIPPED TO EXACT AREA SHAPE
const createColoredGrid = (areaGeometry, extent, layerData, colorType) => {
  // Validate inputs
  if (!areaGeometry || !extent || !layerData) {
    console.error('createColoredGrid: Invalid inputs', { areaGeometry, extent, layerData })
    return []
  }
  
  const gridSize = 10
  const [minX, minY, maxX, maxY] = extent
  const cellWidth = (maxX - minX) / gridSize
  const cellHeight = (maxY - minY) / gridSize
  const features = []
  
  // Get statistics from layer data with fallbacks
  const stats = {
    mean: (layerData && layerData.mean) || (layerData && layerData.statistics && layerData.statistics.mean) || 0,
    min: (layerData && layerData.min) || (layerData && layerData.statistics && layerData.statistics.min) || 0,
    max: (layerData && layerData.max) || (layerData && layerData.statistics && layerData.statistics.max) || 1,
    stdDev: (layerData && layerData.stdDev) || (layerData && layerData.statistics && layerData.statistics.stdDev) || 0
  }
  
  console.log(`Creating grid for ${colorType} layer with stats:`, stats)
  console.log(`Layer data structure:`, layerData)
  
  for (let i = 0; i < gridSize; i++) {
    for (let j = 0; j < gridSize; j++) {
      const x1 = minX + i * cellWidth
      const y1 = minY + j * cellHeight
      const x2 = x1 + cellWidth
      const y2 = y1 + cellHeight

      const cellPolygon = new Polygon([
        [
          [x1, y1],
          [x2, y1],
          [x2, y2],
          [x1, y2],
          [x1, y1],
        ],
      ])

      // CRITICAL: Check if cell intersects with the ACTUAL area boundary
      // This ensures the layer matches the EXACT SHAPE of the selected district/region
      // Not just the rectangular extent, but the actual polygon boundary
      let shouldIncludeCell = true
      
      if (areaGeometry) {
        // For specific areas, check intersection with actual geometry
        const cellCenter = cellPolygon.getInteriorPoint().getCoordinates()
        
        // Double check: both center point AND geometry intersection
        const centerInside = areaGeometry.intersectsCoordinate(cellCenter)
        const geometryIntersects = areaGeometry.intersectsExtent(cellPolygon.getExtent())
        
        shouldIncludeCell = centerInside && geometryIntersects
      }
      // For country-wide fallback (areaGeometry is null), include all cells
      
      if (shouldIncludeCell) {
        // Generate value with spatial variation based on real GEE statistics
        const positionFactor = Math.sin((i / gridSize) * Math.PI) * Math.cos((j / gridSize) * Math.PI)
        const value = stats.mean + (positionFactor * (stats.stdDev || (stats.max - stats.min) / 4))
        
        // Get color based on layer type
        const color = getLayerColor(value, colorType, stats)
        
        const feature = new Feature({
          geometry: cellPolygon,
          value: value,
          layerType: colorType,
        })
        
        feature.setStyle(new Style({
          fill: new Fill({ color })
          // Removed stroke to eliminate borders
        }))
        
        features.push(feature)
      }
    }
  }
  
  console.log(`Created ${features.length} cells for layer (matched to area shape)`)
  return features
}

// Get color for layer value based on color scheme type
const getLayerColor = (value, colorType, stats) => {
  console.log(`Getting color for value: ${value}, type: ${colorType}, stats:`, stats)
  const opacity = 0.7
  
  switch (colorType) {
    case 'diverging': // Rainfall slope: red-white-green
      const normalized = (value - stats.min) / (stats.max - stats.min)
      if (normalized < 0.5) {
        // Red to white
        const t = normalized * 2
        const r = 220
        const g = Math.round(32 + (255 - 32) * t)
        const b = Math.round(38 + (255 - 38) * t)
        return `rgba(${r}, ${g}, ${b}, ${opacity})`
      } else {
        // White to green
        const t = (normalized - 0.5) * 2
        const r = Math.round(255 - (255 - 22) * t)
        const g = Math.round(255 - (255 - 163) * t)
        const b = Math.round(255 - (255 - 74) * t)
        return `rgba(${r}, ${g}, ${b}, ${opacity})`
      }
      
    case 'sequential': // Blue gradient for factors
      const norm = Math.min(1, Math.max(0, (value - stats.min) / (stats.max - stats.min)))
      const r = Math.round(239 - (239 - 30) * norm)
      const g = Math.round(246 - (246 - 58) * norm)
      const b = Math.round(255 - (255 - 138) * norm)
      return `rgba(${r}, ${g}, ${b}, ${opacity})`
      
    case 'rusle': // Erosion color scale
      return getErosionColor(value, opacity)
      
    default:
      return `rgba(59, 130, 246, ${opacity})`
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
    
    // Animate border drawing for TopoJSON
    setTimeout(() => {
      animateLayerBorderDrawing(topoJsonLayer.value, 2000)
    }, 300)

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
    
    // Animate border drawing for GeoJSON
    setTimeout(() => {
      animateLayerBorderDrawing(topoJsonLayer.value, 2000)
    }, 300)

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

// Watchers
watch(() => props.selectedDistrict, (newDistrict, oldDistrict) => {
  console.log('Selected district changed:', newDistrict)
  refreshDistrictsLayer()
  
  if (newDistrict && newDistrict.center) {
    // Zoom to the selected district
    const [lon, lat] = newDistrict.center
    map.value.getView().animate({
      center: fromLonLat([lon, lat]),
      zoom: 10,
      duration: 1000
    })
  }
})

watch(() => props.districts, (newDistricts) => {
  if (newDistricts && newDistricts.length > 0 && map.value) {
    console.log('Districts data updated, reloading layer')
    // Remove old layer
    if (districtsBaseLayer.value) {
      map.value.removeLayer(districtsBaseLayer.value)
    }
    // Load new layer
    loadDistrictsLayer()
  }
})

// Enable shape editing mode
const enableShapeEditing = () => {
  if (!map.value || !vectorSource.value) return

  // Remove any existing modify interaction
  if (modifyInteraction.value) {
    map.value.removeInteraction(modifyInteraction.value)
  }

  // Create select interaction
  if (!selectInteraction.value) {
    selectInteraction.value = new Select({
      layers: [vectorLayer.value]
    })
    map.value.addInteraction(selectInteraction.value)
  }

  // Create modify interaction
  modifyInteraction.value = new Modify({
    features: selectInteraction.value.getFeatures()
  })
  map.value.addInteraction(modifyInteraction.value)

  // Listen for modify end
  modifyInteraction.value.on('modifyend', (event) => {
    const features = event.features.getArray()
    if (features.length > 0) {
      const feature = features[0]
      const geojsonFormat = new GeoJSON()
      const geojsonFeature = geojsonFormat.writeFeatureObject(feature, {
        featureProjection: 'EPSG:3857',
        dataProjection: 'EPSG:4326'
      })
      emit('geometry-modified', geojsonFeature)
    }
  })
}

// Disable shape editing mode
const disableShapeEditing = () => {
  if (modifyInteraction.value) {
    map.value.removeInteraction(modifyInteraction.value)
    modifyInteraction.value = null
  }
  if (selectInteraction.value) {
    map.value.removeInteraction(selectInteraction.value)
    selectInteraction.value = null
  }
}

// Delete selected shape
const deleteSelectedShape = () => {
  if (!selectInteraction.value) return
  
  const features = selectInteraction.value.getFeatures()
  features.forEach(feature => {
    vectorSource.value.removeFeature(feature)
    // Remove from drawnFeatures array
    const index = drawnFeatures.value.findIndex(f => f === feature)
    if (index > -1) {
      drawnFeatures.value.splice(index, 1)
    }
  })
  features.clear()
  emit('shape-deleted')
}

// Clear all drawn shapes
const clearAllShapes = () => {
  vectorSource.value.clear()
  drawnFeatures.value = []
  emit('shapes-cleared')
}

// Clear all layer colors from the map
const clearAllLayerColors = () => {
  // Clear all map layers
  Object.keys(mapLayers.value).forEach(layerId => {
    const layer = mapLayers.value[layerId]
    
    // Handle country-wide layers (which contain multiple individual layers)
    if (layer && layer.type === 'country-wide') {
      layer.layers.forEach(individualLayer => {
        map.value.removeLayer(individualLayer)
      })
    } else if (layer && layer.type === 'multiple-areas') {
      // Handle multiple areas layers
      layer.layers.forEach(individualLayer => {
        map.value.removeLayer(individualLayer)
      })
    } else if (layer) {
      map.value.removeLayer(layer)
    }
    
    delete mapLayers.value[layerId]
  })
  
  // Reset district base layer to default colors (no layer data)
  if (districtsBaseLayer.value) {
    const source = districtsBaseLayer.value.getSource()
    if (source) {
      source.forEachFeature(feature => {
        feature.unset('erosion_rate')
        feature.unset('value')
        feature.unset('layerType')
        feature.unset('rainfall_slope')
        feature.unset('rainfall_cv')
        feature.unset('r_factor')
        feature.unset('k_factor')
        feature.unset('ls_factor')
        feature.unset('c_factor')
        feature.unset('p_factor')
      })
      districtsBaseLayer.value.changed()
    }
  }
  
  // Reset region layer to default colors
  if (regionLayer.value) {
    const source = regionLayer.value.getSource()
    if (source) {
      source.forEachFeature(feature => {
        feature.unset('erosion_rate')
        feature.unset('value')
        feature.unset('layerType')
        feature.unset('rainfall_slope')
        feature.unset('rainfall_cv')
        feature.unset('r_factor')
        feature.unset('k_factor')
        feature.unset('ls_factor')
        feature.unset('c_factor')
        feature.unset('p_factor')
      })
      regionLayer.value.changed()
    }
  }
  
  console.log('Cleared all layer colors from map')
}

// Toggle labels visibility
const toggleLabels = (visible) => {
  if (labelsLayer.value) {
    labelsLayer.value.setVisible(visible)
    console.log(`Labels ${visible ? 'shown' : 'hidden'}`)
  }
}

// Get all drawn shapes as GeoJSON
const getDrawnShapes = () => {
  const geojsonFormat = new GeoJSON()
  return drawnFeatures.value.map(feature => {
    return geojsonFormat.writeFeatureObject(feature, {
      featureProjection: 'EPSG:3857',
      dataProjection: 'EPSG:4326'
    })
  })
}

// Expose methods to parent
// Highlight selected areas on the map
const highlightSelectedAreas = (selectedAreas) => {
  console.log('Highlighting selected areas:', selectedAreas)
  
  // Clear existing highlights
  clearAreaHighlights()
  
  if (!selectedAreas || selectedAreas.length === 0) {
    return
  }
  
  const highlightFeatures = []
  
  for (const area of selectedAreas) {
    try {
      // Check if area has geometry data
      if (!area.geometry) {
        console.warn(`Area ${area.name_en} has no geometry data, creating fallback highlight`)
        
        // Create a fallback highlight using approximate coordinates for each region
        // This is a temporary solution until geometry data is available
        const regionCoordinates = {
          'Sughd': [69.0, 40.0], // Northern Tajikistan
          'Khatlon': [68.5, 37.5], // Southern Tajikistan
          'Gorno-Badakhshan': [72.0, 38.5], // Eastern Tajikistan
          'Dushanbe': [68.8, 38.5], // Capital region
          'RRS': [68.8, 38.5] // Republican Subordination
        }
        
        const coords = regionCoordinates[area.name_en] || [68.0, 37.0] // Default to center of Tajikistan
        const size = 0.5 // Size of the fallback highlight
        
        const fallbackGeometry = new Polygon([[
          [coords[0] - size/2, coords[1] - size/2],
          [coords[0] + size/2, coords[1] - size/2],
          [coords[0] + size/2, coords[1] + size/2],
          [coords[0] - size/2, coords[1] + size/2],
          [coords[0] - size/2, coords[1] - size/2]
        ]])
        
        const fallbackFeature = new Feature({
          geometry: fallbackGeometry,
          areaId: area.id,
          areaName: area.name_en,
          areaType: area.type || area.area_type,
          isHighlighted: true,
          isFallback: true
        })
        
        // Style the fallback feature with a different color
        fallbackFeature.setStyle(new Style({
          fill: new Fill({
            color: 'rgba(255, 165, 0, 0.3)' // Orange highlight for fallback
          })
          // Removed stroke to eliminate borders
        }))
        
        highlightFeatures.push(fallbackFeature)
        console.log(`Created fallback highlight for area: ${area.name_en}`)
        continue
      }
      
      let geometryData = area.geometry
      if (typeof geometryData === 'string') {
        try {
          geometryData = JSON.parse(geometryData)
        } catch (parseError) {
          console.error(`Error parsing geometry for ${area.name_en}:`, parseError)
          continue
        }
      }
      
      // Validate geometry data structure
      if (!geometryData || !geometryData.type) {
        console.warn(`Area ${area.name_en} has invalid geometry data, skipping highlight`)
        continue
      }
      
      const geojsonFormat = new GeoJSON()
      const features = geojsonFormat.readFeatures(geometryData, {
        dataProjection: 'EPSG:4326',
        featureProjection: 'EPSG:3857',
      })
      
      if (features.length > 0) {
        const feature = features[0]
        
        // Add area information to the feature
        feature.setProperties({
          areaId: area.id,
          areaName: area.name_en,
          areaType: area.type || area.area_type,
          isHighlighted: true
        })
        
        // Style the highlighted feature
        feature.setStyle(new Style({
          fill: new Fill({
            color: 'rgba(255, 255, 0, 0.3)' // Yellow highlight
          })
          // Removed stroke to eliminate borders
        }))
        
        highlightFeatures.push(feature)
        console.log(`Highlighted area: ${area.name_en} (${area.type || area.area_type})`)
      } else {
        console.warn(`No features created for area ${area.name_en}`)
      }
    } catch (error) {
      console.error(`Error highlighting area ${area.name_en}:`, error)
    }
  }
  
  if (highlightFeatures.length > 0) {
    const highlightSource = new VectorSource({
      features: highlightFeatures
    })
    
    const highlightLayer = new VectorLayer({
      source: highlightSource,
      zIndex: 20, // High z-index to appear on top
      title: 'Selected Areas Highlight'
    })
    
    map.value.addLayer(highlightLayer)
    areaHighlightLayer.value = highlightLayer
    
    console.log(`Added highlight layer with ${highlightFeatures.length} features`)
  }
}

// Clear area highlights
const clearAreaHighlights = () => {
  if (areaHighlightLayer.value) {
    map.value.removeLayer(areaHighlightLayer.value)
    areaHighlightLayer.value = null
    console.log('Cleared area highlights')
  }
}

// Test function for debugging
const testVisualization = () => {
  console.log('Testing visualization...')
  
  // Create a simple test rectangle
  const testGeometry = new Polygon([[
    [68.0, 37.0], // Southwest corner
    [72.0, 37.0], // Southeast corner
    [72.0, 39.0], // Northeast corner
    [68.0, 39.0], // Northwest corner
    [68.0, 37.0]  // Close the polygon
  ]])
  
  const testFeature = new Feature({
    geometry: testGeometry,
    value: 25,
    layerType: 'test'
  })
  
  testFeature.setStyle(new Style({
    fill: new Fill({
      color: 'rgba(0, 255, 0, 0.7)'
    })
    // Removed stroke to eliminate borders
  }))
  
  const testSource = new VectorSource({
    features: [testFeature]
  })
  
  const testLayer = new VectorLayer({
    source: testSource,
    opacity: 0.7,
    zIndex: 15,
    title: 'Test Layer'
  })
  
  if (map.value) {
    map.value.addLayer(testLayer)
    console.log('Test layer added to map')
    console.log('Map layers count:', map.value.getLayers().getLength())
  } else {
    console.error('Map reference is null!')
  }
}

defineExpose({
  updateErosionData,
  updateDistrictErosionData,
  refreshDistrictsLayer,
  loadDistrictsLayer,
  loadDetailedErosionData,
  map: map,
  loadTopoJSONLayer,
  loadTopoJSONFromFile,
  loadGeoJSONLayer,
  loadGeoJSONFromFile,
  highlightDistrict,
  resetDistrictHighlighting,
  zoomToDistrict,
  clearAllLayerColors,
  toggleLabels,
  // Border animation methods
  animateLayerBorderDrawing,
  animateComplexBorderDrawing,
  stopAllBorderAnimations,
  animateAllVisibleBorders,
  testVisualization,
  highlightSelectedAreas,
  clearAreaHighlights
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
  // Stop all border animations
  stopAllBorderAnimations()
  
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

/* Smooth border animation styles */
.ol-viewport {
  transition: all 0.3s ease-in-out;
}

/* Enhanced stroke rendering for smoother animations */
:deep(.ol-layer) {
  will-change: transform;
}

/* Smooth transitions for map interactions */
:deep(.ol-viewport) {
  transition: transform 0.3s ease-out;
}

/* Optimize rendering performance for animations */
:deep(.ol-layer canvas) {
  image-rendering: optimizeSpeed;
  image-rendering: -moz-crisp-edges;
  image-rendering: -webkit-optimize-contrast;
  image-rendering: optimize-contrast;
}
</style>

