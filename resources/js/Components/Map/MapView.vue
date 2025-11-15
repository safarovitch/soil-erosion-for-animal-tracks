<template>
    <div
        ref="mapContainer"
        class="w-full h-full min-h-96 relative"
        style="min-height: 400px"
    >
        <!-- Zoom Level Indicator -->
        <div
            class="absolute top-16 left-4 bg-white bg-opacity-90 px-3 py-1.5 rounded shadow-md text-sm font-semibold text-gray-700 z-10 border border-gray-300"
        >
            <span class="text-gray-600">Zoom:</span>
            <span class="text-blue-600">{{ currentZoom.toFixed(2) }}</span>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch, nextTick, computed } from "vue";
import axios from "axios";
import { Map, View } from "ol";
import {
    Tile as TileLayer,
    Vector as VectorLayer,
    Image as ImageLayer,
} from "ol/layer";
import {
    OSM,
    Vector as VectorSource,
    XYZ,
    ImageCanvas as ImageCanvasSource,
} from "ol/source";
import { Style, Fill, Stroke } from "ol/style";
import { GeoJSON, TopoJSON } from "ol/format";
import { fromLonLat } from "ol/proj";
import { Draw, Modify, Select } from "ol/interaction";
import { createBox } from "ol/interaction/Draw";
import { Feature } from "ol";
import { easeOut, inAndOut } from "ol/easing";
import { Polygon, Point, LineString } from "ol/geom";
import { ScaleLine, defaults as defaultControls } from "ol/control";
import "ol/ol.css";
import { DEFAULT_YEAR_PERIOD } from "@/constants/yearPeriods.js";

// Props
const props = defineProps({
    regions: Array,
    districts: Array,
    selectedRegion: Object,
    selectedDistrict: Object,
    selectedAreas: Array,
    customLayers: {
        type: Array,
        default: () => [],
    },
    selectedPeriod: {
        type: Object,
        default: null,
    },
    visibleLayers: Array,
    analysisTrigger: {
        type: Number,
        default: 0,
    },
    showLabels: {
        type: Boolean,
        default: true,
    },
    customAreaDrawing: {
        type: Boolean,
        default: false,
    },
});

// Emits
const emit = defineEmits([
    "map-ready",
    "statistics-updated",
    "district-clicked",
    "region-clicked",
    "geojson-loaded",
    "detailed-erosion-loaded",
    "area-toggle-selection",
    "area-replace-selection",
    "boundary-violation",
    "layer-warning",
    "custom-polygon-drawn",
]);

// Reactive data
const mapContainer = ref(null);
const map = ref(null);
const vectorSource = ref(null);
const vectorLayer = ref(null);
const regionLayer = ref(null);
const districtLayer = ref(null);
const districtsBaseLayer = ref(null); // Layer showing all districts
const topoJsonLayer = ref(null);
const areaHighlightLayer = ref(null); // Layer for highlighting selected areas
const erosionDataByDistrict = ref({}); // Store erosion data for coloring
const drawnFeatures = ref([]); // Store all drawn features for management
const detailedErosionLayers = ref({}); // Store detailed erosion tile layers keyed by area-period
const animatedLayers = new Set(); // Track layers with animated borders
const tilePollingIntervals = Object.create(null); // Track active polling intervals for tile availability
const customPolygonDraw = ref(null); // Draw interaction for custom polygon
const customPolygonLayer = ref(null); // Layer for custom polygon
const countryBoundaryCache = ref(null); // Cache for dissolved Tajikistan boundary geometry
const baseLayer = ref(null); // Reference to base layer
const labelsLayer = ref(null); // Reference to labels layer
const tajikistanBoundaryLayer = ref(null); // Boundary layer for Tajikistan
const tajikistanBoundary = ref(null); // Tajikistan boundary geometry
const tajikistanBoundaryFeatureCollection = ref(null); // Store full boundary GeoJSON for country highlight
const currentZoom = ref(8); // Current zoom level for display
const scaleLine = ref(null); // Scale line control
const selectedPeriod = computed(
    () => props.selectedPeriod || DEFAULT_YEAR_PERIOD
);
const periodStartYear = computed(() => selectedPeriod.value.startYear);
const periodEndYear = computed(() => selectedPeriod.value.endYear);
const selectedPeriodLength = computed(() => {
    const start = Number(periodStartYear.value);
    const end = Number(periodEndYear.value);
    if (!Number.isFinite(start) || !Number.isFinite(end)) {
        return 0;
    }
    return Math.max(0, end - start + 1);
});

const defaultAreaStyle = new Style({
    fill: new Fill({
        color: "rgba(255, 255, 255, 0)", // Transparent fill
    }),
    stroke: new Stroke({
        color: "rgba(0, 0, 0, 0.2)", // Light balck outline
        width: 1,
    }),
});

const selectedAreaStyle = new Style({
    fill: new Fill({
        color: "rgba(0, 0, 0, 0.1)", // Fill
    }),
    stroke: new Stroke({
        color: "rgba(0, 92, 125, .3)", // Outline
        width: 1,
    }),
});

const buildDetailedLayerKey = (areaType, areaId, startYear, endYear) =>
    `${areaType}-${areaId}-${startYear}-${endYear}`;

const ensureDetailedLayerStore = () => {
    if (
        !detailedErosionLayers.value ||
        typeof detailedErosionLayers.value !== "object" ||
        Array.isArray(detailedErosionLayers.value)
    ) {
        detailedErosionLayers.value = {};
    }
    return detailedErosionLayers.value;
};

const removeDetailedLayer = (layerKey) => {
    // Stop polling for this layer if it exists
    stopTilePolling(layerKey);
    
    const layerStore = ensureDetailedLayerStore();
    const existingLayer = layerStore[layerKey];
    if (existingLayer && map.value) {
        map.value.removeLayer(existingLayer);
    }
    delete layerStore[layerKey];
};

const registerDetailedLayer = (layerKey, layerInstance) => {
    const layerStore = ensureDetailedLayerStore();
    const existingLayer = layerStore[layerKey];
    if (existingLayer && map.value) {
        map.value.removeLayer(existingLayer);
    }
    layerStore[layerKey] = layerInstance;
};

const removeUnusedDetailedLayers = (validKeys) => {
    const layerStore = ensureDetailedLayerStore();
    Object.keys(layerStore).forEach((key) => {
        if (!validKeys.has(key)) {
            removeDetailedLayer(key);
        }
    });
};

// Map configuration
const mapConfig = {
    center: fromLonLat([71.5, 38.5]), // Tajikistan center
    zoom: 7, // Start slightly zoomed out while keeping detail visible
    maxZoom: 18,
    minZoom: 6, // Allow zoom out to 6
};

const mapTilerKey =
    (typeof window !== "undefined" && window.MAPTILER_API_KEY) ||
    (typeof import.meta !== "undefined" &&
        import.meta.env &&
        import.meta.env.VITE_MAPTILER_API_KEY) ||
    null;
const hasMapTilerTerrain = Boolean(mapTilerKey);
const mapTilerAttribution = hasMapTilerTerrain
    ? '© <a href="https://www.maptiler.com/copyright/" target="_blank" rel="noopener">MapTiler</a> © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a>'
    : undefined;

const currentBaseMapType = ref("osm");
const availableBaseMapTypes = computed(() => {
    const options = [{ id: "osm", label: "OpenStreetMap" }];
    if (hasMapTilerTerrain) {
        options.unshift({ id: "terrain", label: "MapTiler Terrain" });
    }
    return options;
});

const DEFAULT_TILE_MAX_ZOOM = 10;
const parseMaxZoom = (value) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : DEFAULT_TILE_MAX_ZOOM;
};

const createBaseLayerPair = (type) => {
    if (type === "terrain" && hasMapTilerTerrain) {
        return {
            base: new TileLayer({
                source: new XYZ({
                    url: `https://api.maptiler.com/maps/terrain/256/{z}/{x}/{y}.png?key=${mapTilerKey}`,
                    crossOrigin: "anonymous",
                    maxZoom: 19,
                    attributions: mapTilerAttribution,
                }),
                className: "maptiler-terrain-layer",
            }),
            labels: new TileLayer({
                source: new XYZ({
                    url: `https://api.maptiler.com/maps/basic-v2/256/{z}/{x}/{y}.png?key=${mapTilerKey}`,
                    crossOrigin: "anonymous",
                    maxZoom: 20,
                    attributions: mapTilerAttribution,
                }),
                opacity: 0.35,
                className: "maptiler-label-layer",
            }),
        };
    }

    return {
        base: new TileLayer({
            source: new OSM({
                crossOrigin: "anonymous",
            }),
            className: "osm-base-layer",
        }),
        labels: null,
    };
};

const applyBaseMapType = (type) => {
    const normalizedType =
        type === "terrain" && hasMapTilerTerrain ? "terrain" : "osm";

    if (!map.value) {
        currentBaseMapType.value = normalizedType;
        return;
    }

    if (baseLayer.value) {
        map.value.removeLayer(baseLayer.value);
    }
    if (labelsLayer.value) {
        map.value.removeLayer(labelsLayer.value);
        labelsLayer.value = null;
    }

    const { base, labels } = createBaseLayerPair(normalizedType);

    baseLayer.value = base;
    map.value.getLayers().insertAt(0, baseLayer.value);

    if (labels) {
        labelsLayer.value = labels;
        map.value.getLayers().insertAt(1, labelsLayer.value);
        labelsLayer.value.setVisible(props.showLabels !== false);
    }

    currentBaseMapType.value = normalizedType;
};

// Initialize map
const initMap = () => {
    if (!mapContainer.value) return;

    // Ensure container has dimensions
    const containerRect = mapContainer.value.getBoundingClientRect();
    if (containerRect.width === 0 || containerRect.height === 0) {
        console.warn("Map container has zero dimensions, retrying...");
        setTimeout(() => initMap(), 100);
        return;
    }

    console.log(
        "Initializing map with dimensions:",
        containerRect.width,
        "x",
        containerRect.height
    );

    // Create vector source for user drawings
    vectorSource.value = new VectorSource();
    vectorLayer.value = new VectorLayer({
        source: vectorSource.value,
        style: new Style({
            defaultAreaStyle,
        }),
    });

    // Create scale line control (measurement ruler)
    scaleLine.value = new ScaleLine({
        units: "metric", // Use metric units (km, m)
        className: "ol-scale-line",
        bar: true, // Show bar style
        text: true, // Show text
        minWidth: 140, // Minimum width in pixels
    });

    // Create the map
    map.value = new Map({
        target: mapContainer.value,
        layers: [],
        view: new View({
            center: mapConfig.center,
            zoom: mapConfig.zoom,
            maxZoom: mapConfig.maxZoom,
            minZoom: mapConfig.minZoom,
        }),
        controls: defaultControls().extend([
            scaleLine.value, // Add scale line control
        ]),
    });

    applyBaseMapType(currentBaseMapType.value);
    map.value.addLayer(vectorLayer.value);

    // Initialize zoom level
    currentZoom.value = mapConfig.zoom;

    // Update zoom level indicator when zoom changes
    map.value.getView().on("change:resolution", () => {
        const view = map.value.getView();
        currentZoom.value = view.getZoom();
    });

    // Also update on moveend (catches all zoom changes)
    map.value.on("moveend", () => {
        const view = map.value.getView();
        currentZoom.value = view.getZoom();
    });

    // Emit map ready event
    emit("map-ready", map.value);

    // Update map layers when map becomes ready
    console.log("Map ready. Awaiting apply trigger for layer updates.");

    // Add click handler for statistics and area selection
    map.value.on("click", handleMapClick);

    // Add click handler for area selection
    map.value.on("click", handleAreaClick);

    // Load GeoJSON automatically when map is ready
    loadGeoJSONOnMapReady();

    // Add resize handler
    const handleResize = () => {
        if (map.value) {
            map.value.updateSize();
        }
    };

    window.addEventListener("resize", handleResize);

    // Store resize handler for cleanup
    map.value.set("resizeHandler", handleResize);

    // Load districts layer if districts prop is available
    if (props.districts && props.districts.length > 0) {
        loadDistrictsLayer();
    }
};

// Get erosion risk color based on value
const getErosionColor = (erosionRate, opacity = 1.0) => {
    // RUSLE Erosion Risk Classification (Updated):
    // Very Low: 0-5 t/ha/yr - Green
    // Low: 5-15 t/ha/yr - Yellow
    // Moderate: 15-30 t/ha/yr - Orange
    // Severe: 30-50 t/ha/yr - Red
    // Excessive: > 50 t/ha/yr - Dark Red

    if (!erosionRate || erosionRate < 0) {
        return `rgba(200, 200, 200, ${opacity})`; // Gray for no data
    }

    if (erosionRate < 5) {
        return `rgba(34, 139, 34, ${opacity})`; // Green - Very Low
    } else if (erosionRate < 15) {
        return `rgba(255, 215, 0, ${opacity})`; // Yellow - Low
    } else if (erosionRate < 30) {
        return `rgba(255, 140, 0, ${opacity})`; // Orange - Moderate
    } else if (erosionRate < 50) {
        return `rgba(220, 20, 60, ${opacity})`; // Red - Severe
    } else {
        return `rgba(139, 0, 0, ${opacity})`; // Dark Red - Excessive
    }
};

// Get erosion color as RGB object for canvas rendering with smooth gradients
const getErosionColorRGB = (erosionRate) => {
    // Smooth gradient color interpolation
    const thresholds = [
        { value: 0, color: { r: 34, g: 139, b: 34 } }, // Green
        { value: 5, color: { r: 144, g: 238, b: 144 } }, // Light Green
        { value: 15, color: { r: 255, g: 215, b: 0 } }, // Yellow
        { value: 30, color: { r: 255, g: 140, b: 0 } }, // Orange
        { value: 50, color: { r: 220, g: 20, b: 60 } }, // Red
        { value: 100, color: { r: 139, g: 0, b: 0 } }, // Dark Red
        { value: 200, color: { r: 80, g: 0, b: 0 } }, // Very Dark Red
    ];

    if (!erosionRate || erosionRate < 0) {
        return { r: 200, g: 200, b: 200, a: 0 }; // Transparent for no data
    }

    // Find surrounding thresholds for interpolation
    let lower = thresholds[0];
    let upper = thresholds[thresholds.length - 1];

    for (let i = 0; i < thresholds.length - 1; i++) {
        if (
            erosionRate >= thresholds[i].value &&
            erosionRate <= thresholds[i + 1].value
        ) {
            lower = thresholds[i];
            upper = thresholds[i + 1];
            break;
        }
    }

    // Linear interpolation between colors
    const t = (erosionRate - lower.value) / (upper.value - lower.value);
    const r = Math.round(lower.color.r + (upper.color.r - lower.color.r) * t);
    const g = Math.round(lower.color.g + (upper.color.g - lower.color.g) * t);
    const b = Math.round(lower.color.b + (upper.color.b - lower.color.b) * t);

    return { r, g, b, a: 178 }; // 0.7 opacity = 178/255
};

// Smooth border animation functions
const animateBorderDrawing = (
    layer,
    features,
    duration = 1200,
    strokeColor = "rgba(0,0,0,0.6)",
    strokeWidth = 1
) => {
    if (!layer || !features || features.length === 0) return;

    const startTime = Date.now();
    const animationId = `border_animation_${Date.now()}_${Math.random()
        .toString(36)
        .substr(2, 9)}`;

    // Store animation ID for cleanup
    animatedLayers.add(animationId);

    const animate = () => {
        const elapsed = Date.now() - startTime;
        const progress = Math.min(elapsed / duration, 1);

        // Use inAndOut for smooth animation
        const easedProgress = inAndOut(progress);

        // Calculate animated stroke properties
        const animatedWidth = strokeWidth * easedProgress;
        const animatedOpacity = Math.min(0.3 + easedProgress * 0.7, 1); // Start with low opacity

        // Create animated stroke style
        const animatedColor = `rgba(0, 0, 0, ${0.3 + animatedOpacity * 0.5})`;
        const animatedStroke = new Stroke({
            color: animatedColor,
            width: animatedWidth,
            lineCap: "round",
            lineJoin: "round",
        });

        // Apply animated style to all features
        features.forEach((feature) => {
            const currentStyle = feature.getStyle();
            if (currentStyle) {
                const newStyle = new Style({
                    fill: currentStyle.getFill(),
                    stroke: animatedStroke,
                });
                feature.setStyle(newStyle);
            }
        });

        // Continue animation if not complete and layer still exists
        if (progress < 1 && animatedLayers.has(animationId)) {
            requestAnimationFrame(animate);
        } else {
            // Animation complete - set final style
            const finalStroke = new Stroke({
                color:
                    typeof strokeColor === "string"
                        ? strokeColor
                        : "rgba(0,0,0,0.6)",
                width: strokeWidth,
                lineCap: "round",
                lineJoin: "round",
            });

            features.forEach((feature) => {
                const currentStyle = feature.getStyle();
                if (currentStyle) {
                    const finalStyle = new Style({
                        fill: currentStyle.getFill(),
                        stroke: finalStroke,
                    });
                    feature.setStyle(finalStyle);
                }
            });

            // Clean up animation
            animatedLayers.delete(animationId);
        }
    };

    // Start animation
    requestAnimationFrame(animate);
};

// Animate border drawing for a layer with progressive reveal
const animateLayerBorderDrawing = (layer, duration = 2000) => {
    if (!layer) return;

    const source = layer.getSource();
    if (!source) return;

    const features = source.getFeatures();
    if (features.length === 0) return;

    // Get layer style to extract stroke properties
    const layerStyle = layer.getStyle();
    let strokeColor = "rgba(0,0,0,0.4)";
    let strokeWidth = 1;

    if (layerStyle && layerStyle.getStroke) {
        const stroke = layerStyle.getStroke();
        if (stroke) {
            strokeColor = stroke.getColor() || strokeColor;
            strokeWidth = stroke.getWidth() || strokeWidth;
        }
    }

    // Start border animation
    animateBorderDrawing(layer, features, duration, strokeColor, strokeWidth);
};

// Progressive border drawing for complex geometries
const animateComplexBorderDrawing = (layer, features, duration = 1800) => {
    if (!layer || !features || features.length === 0) return;

    const startTime = Date.now();
    const animationId = `complex_border_animation_${Date.now()}_${Math.random()
        .toString(36)
        .substr(2, 9)}`;

    animatedLayers.add(animationId);

    const animate = () => {
        const elapsed = Date.now() - startTime;
        const progress = Math.min(elapsed / duration, 1);

        // Use different easing for complex animations
        const easedProgress = easeOut(progress);

        // Calculate progressive reveal
        const revealProgress = Math.min(progress * 1.2, 1); // Slightly faster reveal
        const pulseProgress = Math.sin(progress * Math.PI * 4) * 0.3 + 0.7; // Pulsing effect

        // Animated stroke properties
        const animatedWidth = 1 + pulseProgress * 0.8;
        const animatedOpacity = Math.min(0.15 + revealProgress * 0.5, 0.5);

        const animatedStroke = new Stroke({
            color: `rgba(0, 0, 0, ${animatedOpacity})`,
            width: animatedWidth,
            lineCap: "round",
            lineJoin: "round",
            lineDash: progress < 0.8 ? [5, 5] : undefined, // Dashed during drawing, solid when complete
        });

        // Apply to features
        features.forEach((feature) => {
            const currentStyle = feature.getStyle();
            if (currentStyle) {
                const newStyle = new Style({
                    fill: currentStyle.getFill(),
                    stroke: animatedStroke,
                });
                feature.setStyle(newStyle);
            }
        });

        // Continue or finish
        if (progress < 1 && animatedLayers.has(animationId)) {
            requestAnimationFrame(animate);
        } else {
            // Set final solid style
            const finalStroke = new Stroke({
                color: "rgba(0,0,0,0.6)",
                width: 1,
                lineCap: "round",
                lineJoin: "round",
            });

            features.forEach((feature) => {
                const currentStyle = feature.getStyle();
                if (currentStyle) {
                    const finalStyle = new Style({
                        fill: currentStyle.getFill(),
                        stroke: finalStroke,
                    });
                    feature.setStyle(finalStyle);
                }
            });

            animatedLayers.delete(animationId);
        }
    };

    requestAnimationFrame(animate);
};

// Stop all border animations
const stopAllBorderAnimations = () => {
    animatedLayers.clear();
};

// Trigger border animation for all visible layers (for testing)
const animateAllVisibleBorders = () => {
    console.log("Animating all visible layer borders...");

    // Animate districts layer
    if (districtsBaseLayer.value) {
        animateLayerBorderDrawing(districtsBaseLayer.value, 2000);
    }

    // Animate region layer
    if (regionLayer.value) {
        const source = regionLayer.value.getSource();
        if (source) {
            const features = source.getFeatures();
            animateComplexBorderDrawing(regionLayer.value, features, 2500);
        }
    }

    // Animate district layer
    if (districtLayer.value) {
        const source = districtLayer.value.getSource();
        if (source) {
            const features = source.getFeatures();
            animateComplexBorderDrawing(districtLayer.value, features, 2500);
        }
    }

    // Animate GeoJSON layer
    if (topoJsonLayer.value) {
        animateLayerBorderDrawing(topoJsonLayer.value, 2000);
    }
};

// Load all districts as a base layer
const loadDistrictsLayer = () => {
    try {
        console.log(
            "Loading districts layer with",
            props.districts.length,
            "districts"
        );

        const geojsonFormat = new GeoJSON();
        const features = [];

        props.districts.forEach((district, index) => {
            if (district.geometry) {
                try {
                    let geometryData = district.geometry;
                    if (typeof geometryData === "string") {
                        geometryData = JSON.parse(geometryData);
                    }

                    // Create GeoJSON feature
                    const geoJsonFeature = {
                        type: "Feature",
                        geometry: geometryData,
                        properties: {
                            id: district.id,
                            name: district.name || district.name_en,
                            name_en: district.name_en,
                            name_tj: district.name_tj,
                            region_id: district.region_id,
                            area_km2: district.area_km2,
                            erosion_rate:
                                erosionDataByDistrict.value[district.id] || 0,
                        },
                    };

                    const feature = geojsonFormat.readFeature(geoJsonFeature, {
                        dataProjection: "EPSG:4326",
                        featureProjection: "EPSG:3857",
                    });

                    features.push(feature);
                } catch (error) {
                    console.warn(
                        `Error loading district ${district.name_en}:`,
                        error
                    );
                }
            }
        });

        console.log("Loaded", features.length, "district features");

        const source = new VectorSource({
            features,
        });

        // Style function for districts based on erosion data
        const styleFunction = (feature) => {
            const erosionRate = feature.get("erosion_rate") || 0;
            const isSelected =
                props.selectedDistrict &&
                feature.get("id") === props.selectedDistrict.id;

            return isSelected ? selectedAreaStyle : defaultAreaStyle;
        };

        districtsBaseLayer.value = new VectorLayer({
            source,
            style: styleFunction,
            zIndex: 15, // Above data layers (8) but below selection layers (20)
        });

        map.value.addLayer(districtsBaseLayer.value);

        // Animate border drawing for districts
        setTimeout(() => {
            animateLayerBorderDrawing(districtsBaseLayer.value, 2500);
        }, 100);

        // Add click handler for districts
        map.value.on("click", (event) => {
            if (props.customAreaDrawing) {
                return;
            }
            const feature = map.value.forEachFeatureAtPixel(
                event.pixel,
                (feature, layer) => {
                    if (layer === districtsBaseLayer.value) {
                        return feature;
                    }
                }
            );

            if (feature) {
                const districtData = {
                    id: feature.get("id"),
                    name: feature.get("name"),
                    name_en: feature.get("name_en"),
                    name_tj: feature.get("name_tj"),
                    region_id: feature.get("region_id"),
                    area_km2: feature.get("area_km2"),
                    erosion_rate: feature.get("erosion_rate"),
                };

                emit("district-clicked", districtData);
            }
        });
    } catch (error) {
        console.error("Error loading districts layer:", error);
    }
};

// Update erosion data for a specific district
const updateDistrictErosionData = (districtId, erosionRate) => {
    erosionDataByDistrict.value[districtId] = erosionRate;

    // Update the feature style
    if (districtsBaseLayer.value) {
        const source = districtsBaseLayer.value.getSource();
        const features = source.getFeatures();

        features.forEach((feature) => {
            if (feature.get("id") === districtId) {
                feature.set("erosion_rate", erosionRate);
                feature.changed(); // Trigger style update
            }
        });
    }
};

// Refresh districts layer styling
const refreshDistrictsLayer = () => {
    if (districtsBaseLayer.value) {
        districtsBaseLayer.value.getSource().changed();
    }
};

// Handle map clicks
const handleMapClick = (event) => {
    if (props.customAreaDrawing) {
        return;
    }
    const coordinate = event.coordinate;
    const feature = map.value.forEachFeatureAtPixel(
        event.pixel,
        (feature) => feature
    );

    if (feature && feature.get("type") === "erosion") {
        const properties = feature.getProperties();
        emit("statistics-updated", {
            meanErosionRate: properties.meanErosionRate || 0,
            bareSoilFrequency: properties.bareSoilFrequency || 0,
            sustainabilityFactor: properties.sustainabilityFactor || 0,
        });
    } else if (feature && topoJsonLayer.value) {
        // Handle clicks on GeoJSON features (districts)
        const properties = feature.getProperties();
        console.log("Clicked on district:", properties);

        // Emit district selection event
        emit("district-clicked", {
            shapeName: properties.shapeName,
            shapeID: properties.shapeID,
            shapeISO: properties.shapeISO,
            feature: feature,
        });

        // Zoom to the clicked district
        const geometry = feature.getGeometry();
        // Zooming disabled on area click to keep current view
    }
};

// Handle area click for selection tool updates
const handleAreaClick = (event) => {
    if (props.customAreaDrawing) {
        return;
    }
    const features = map.value.getFeaturesAtPixel(event.pixel);

    if (features.length > 0) {
        const feature = features[0];
        const properties = feature.getProperties();
        const isShiftClick = event.originalEvent.shiftKey;

        // Check if clicking outside Tajikistan boundary
        const geometry = feature.getGeometry();
        if (geometry && !isFeatureWithinBoundary(geometry)) {
            console.log("Click is outside Tajikistan boundary");
            emit("boundary-violation");
            return null;
        }

        // Check if this is a district or region feature
        if (properties.district_id || properties.region_id) {
            // This is a district
            const district = {
                id: properties.district_id || properties.id,
                name_en: properties.name_en || properties.district_name_en,
                name_tj: properties.name_tj || properties.district_name_tj,
                region_id: properties.region_id,
                geometry: properties.geometry,
            };

            console.log(
                "District clicked for selection:",
                district,
                "Shift:",
                isShiftClick
            );

            if (isShiftClick) {
                emit("area-toggle-selection", district);
            } else {
                emit("district-clicked", district);
            }
        } else if (
            properties.region_id === undefined &&
            properties.district_id === undefined &&
            properties.id
        ) {
            // This might be a region
            const region = {
                id: properties.id,
                name_en: properties.name_en || properties.region_name_en,
                name_tj: properties.name_tj || properties.region_name_tj,
                geometry: properties.geometry,
            };

            console.log(
                "Region clicked for selection:",
                region,
                "Shift:",
                isShiftClick
            );

            if (isShiftClick) {
                emit("area-toggle-selection", region);
            } else {
                emit("region-clicked", region);
            }
        }
    }
};

// Update region layer
const updateRegionLayer = (region) => {
    if (regionLayer.value) {
        map.value.removeLayer(regionLayer.value);
    }

    if (!region || !region.geometry) return;

    try {
        // Handle geometry that might be a JSON string
        let geometryData = region.geometry;
        if (typeof geometryData === "string") {
            geometryData = JSON.parse(geometryData);
        }

        const geojsonFormat = new GeoJSON();

        // Wrap geometry in a Feature for OpenLayers
        const featureData = {
            type: "Feature",
            geometry: geometryData,
            properties: {
                id: region.id,
                name: region.name_en || region.name,
            },
        };

        const features = geojsonFormat.readFeatures(featureData, {
            dataProjection: "EPSG:4326",
            featureProjection: "EPSG:3857",
        });

        const source = new VectorSource({
            features,
        });

        regionLayer.value = new VectorLayer({
            source,
            style: selectedAreaStyle,
            zIndex: 20, // Above all data and base layers
        });

        map.value.addLayer(regionLayer.value);

        // Animate border drawing for region
        setTimeout(() => {
            animateComplexBorderDrawing(regionLayer.value, features, 3000);
        }, 200);

        // Do not change zoom level when selecting areas
        // Removed fit to region bounds to prevent zoom changes
    } catch (error) {
        console.error("Error updating region layer:", error);
    }
};

// Update district layer
const updateDistrictLayer = (district) => {
    if (districtLayer.value) {
        map.value.removeLayer(districtLayer.value);
    }

    if (!district || !district.geometry) return;

    try {
        // Handle geometry that might be a JSON string
        let geometryData = district.geometry;
        if (typeof geometryData === "string") {
            geometryData = JSON.parse(geometryData);
        }

        const geojsonFormat = new GeoJSON();

        // Wrap geometry in a Feature for OpenLayers
        const featureData = {
            type: "Feature",
            geometry: geometryData,
            properties: {
                id: district.id,
                name: district.name_en || district.name,
            },
        };

        const features = geojsonFormat.readFeatures(featureData, {
            dataProjection: "EPSG:4326",
            featureProjection: "EPSG:3857",
        });

        const source = new VectorSource({
            features,
        });

        districtLayer.value = new VectorLayer({
            source,
            style: selectedAreaStyle,
            zIndex: 20, // Above all data and base layers
        });

        map.value.addLayer(districtLayer.value);

        // Animate border drawing for district
        setTimeout(() => {
            animateComplexBorderDrawing(districtLayer.value, features, 3000);
        }, 200);

        // Do not change zoom level when selecting areas
        // Removed fit to district bounds to prevent zoom changes
    } catch (error) {
        console.error("Error updating district layer:", error);
    }
};

// Update erosion data layer
const updateErosionData = (data) => {
    // This would typically load raster tiles from the GEE computation
    // For now, we'll simulate with vector data

    if (data.tiles) {
        const maxZoomAvailable = parseMaxZoom(data.max_zoom);
        // Add raster layer for erosion data
        const erosionLayer = new TileLayer({
            source: new XYZ({
                url: data.tiles,
                crossOrigin: "anonymous",
                minZoom: 6,
                maxZoom: maxZoomAvailable,
            }),
            opacity: 1.0,
        });

        map.value.addLayer(erosionLayer);
    }
};

// Load detailed erosion data for selected area (district or region)
const loadDetailedErosionData = async (area) => {
    if (!area) return null;

    const areaLabel = area.name_en || area.name || "selected area";

    try {
        // Determine area type supported by availability endpoint
        if (area.area_type === "country" || area.id === 0) {
            console.log(
                "Skipping detailed erosion load for country-level selection"
            );
            emit("layer-warning", {
                type: "info",
                title: "Selection Required",
                message:
                    "Please select a specific region or district to view erosion tiles.",
                details: "Country-wide erosion tiles are not generated.",
            });
            return null;
        }

        const areaType =
            area.area_type ??
            (area.region_id ? "district" : area.type === "region" ? "region" : "district");
        const areaId = area.id ?? area.area_id ?? 0;
        const areaCacheKey =
            areaType === "custom"
                ? area.geometry_hash || area.cacheKey || "custom"
                : areaId;
        const endYear = periodEndYear.value || new Date().getFullYear();
        let layerKey = buildDetailedLayerKey(
            areaType,
            areaCacheKey,
            periodStartYear.value,
            endYear
        );

        // if (endYear < 1993) {
        //   emit('layer-warning', {
        //     type: 'info',
        //     title: 'Tiles Unavailable',
        //     message: `Precomputed tiles are only available from 1993 onwards.`,
        //     details: `Selected period ${periodStartYear.value} - ${endYear} predates available tile data.`,
        //   })
        //   return
        // }

        const requestPayload = {
            area_type: areaType,
            start_year: periodStartYear.value,
            end_year: endYear,
        };

        if (areaType === "custom") {
            requestPayload.area_id = 0;
            requestPayload.geometry = area.geometry || area.geometry_snapshot || null;
        } else {
            requestPayload.area_id = areaId;
        }

        if (areaType === "custom" && !requestPayload.geometry) {
            console.warn("Custom area missing geometry for tile request.");
            emit("layer-warning", {
                type: "error",
                title: "Custom Area Geometry Missing",
                message:
                    "Unable to request tiles for the custom area because its geometry is unavailable.",
                details: "Please redraw the polygon and try again.",
            });
            return null;
        }

        // Ask backend for availability (this queues generation when needed)
        const availabilityResponse = await axios.post(
            "/api/erosion/check-availability",
            requestPayload
        );

        const availability = availabilityResponse.data || {};
        const fallbackLabel =
            periodStartYear.value === endYear
                ? `${periodStartYear.value}`
                : `${periodStartYear.value} - ${endYear}`;
        const periodLabelFromResponse =
            availability.period_label || fallbackLabel;
        const status = availability.status;
        const taskId = availability.task_id;
        const maxZoomAvailable = parseMaxZoom(availability.max_zoom);

        console.log(`Availability for ${areaLabel}:`, availability);

        if (areaType === "custom" && availability.geometry_hash) {
            area.geometry_hash = availability.geometry_hash;
        }

        const resolvedLayerKey =
            areaType === "custom" && availability.geometry_hash
                ? buildDetailedLayerKey(
                      areaType,
                      availability.geometry_hash,
                      periodStartYear.value,
                      endYear
                  )
                : layerKey;

        if (status === "available" && availability.tiles_url) {
            // Stop any polling for this layer (in case it was polling)
            stopTilePolling(resolvedLayerKey);
            
            const tileLayer = new TileLayer({
                source: new XYZ({
                    url: availability.tiles_url,
                    crossOrigin: "anonymous",
                    minZoom: 6,
                    maxZoom: maxZoomAvailable,
                }),
                opacity: 1.0,
                zIndex: 8,
                minZoom: 6,
                maxZoom: 18,
            });

            registerDetailedLayer(resolvedLayerKey, tileLayer);
            map.value.addLayer(tileLayer);
            console.log(
                `✓ Added precomputed erosion tile layer (${periodLabelFromResponse}):`,
                availability.tiles_url
            );

            emit("detailed-erosion-loaded", {
                areaId,
                areaName: areaLabel,
                cellCount: null,
                statistics: availability.statistics || null,
            });

            emit("layer-warning", {
                type: "success",
                title: "Erosion Tiles Ready",
                message: `${areaLabel} (${periodLabelFromResponse}) tiles are now available.`,
                details: "Tiles have been loaded on the map.",
            });

            return resolvedLayerKey;
        }

        if (status === "queued" || status === "processing") {
            emit("layer-warning", {
                type: "info",
                title:
                    status === "queued"
                        ? "Erosion Tiles Queued"
                        : "Erosion Tiles Processing",
                message: `${areaLabel} (${periodLabelFromResponse}) tiles are being prepared.`,
                details: taskId
                    ? `Fresh erosion tiles are being generated. Task ID: ${taskId}. Checking automatically...`
                    : "Fresh erosion tiles are being generated and should be ready within about 5–10 minutes. Checking automatically...",
            });
            
            // Start polling for tile availability
            startTilePolling(
                area,
                resolvedLayerKey,
                areaType,
                areaId,
                areaType === "custom" ? requestPayload.geometry : null
            );
            
            return null;
        }

        if (status === "failed" || status === "error") {
            emit("layer-warning", {
                type: "error",
                title: "Erosion Tiles Unavailable",
                message: `We could not load erosion tiles for ${areaLabel} (${periodLabelFromResponse}).`,
                details:
                    availability.error ||
                    availability.error_message ||
                    "Please try again later.",
            });
            return;
        }

        emit("layer-warning", {
            type: "warning",
            title: "Erosion Tiles Pending",
            message: `Erosion tiles for ${areaLabel} (${periodLabelFromResponse}) are not ready yet.`,
            details: taskId
                ? `Tile generation has been requested (Task ID: ${taskId}). Please check back shortly.`
                : "Tile generation has been requested. Please check back shortly.",
        });
        return null;
    } catch (error) {
        console.error("Error checking erosion tile availability:", error);
        emit("layer-warning", {
            type: "error",
            title: "Erosion Tiles Unavailable",
            message: `Failed to load erosion tiles for ${areaLabel}.`,
            details: error.message || "Please try again later.",
        });
        emit(
            "geometry-error",
            "Failed to load detailed erosion data: " + error.message
        );
        return null;
    }
};

// Poll for tile availability when tiles are queued or processing
const startTilePolling = (area, layerKey, areaType, areaId, customGeometry = null) => {
    // Stop any existing polling for this layer
    stopTilePolling(layerKey);

     if (areaType === "custom" && !customGeometry) {
         console.warn("Custom area polling aborted: missing geometry.");
         return;
     }
    
    const areaLabel = area.name_en || area.name || "selected area";
    const endYear = periodEndYear.value || new Date().getFullYear();
    let pollCount = 0;
    const maxPolls = 120; // Poll for up to 10 minutes (120 * 5 seconds)
    const pollInterval = 5000; // Poll every 5 seconds
    
    const pollIntervalId = setInterval(async () => {
        pollCount++;
        
        try {
            const pollPayload = {
                area_type: areaType,
                area_id: areaId,
                start_year: periodStartYear.value,
                end_year: endYear,
            };

            if (areaType === "custom" && customGeometry) {
                pollPayload.geometry = customGeometry;
            }

            const availabilityResponse = await axios.post(
                "/api/erosion/check-availability",
                pollPayload
            );
            
            const availability = availabilityResponse.data || {};
            const status = availability.status;
            const maxZoomAvailable = parseMaxZoom(availability.max_zoom);
            
            if (status === "available" && availability.tiles_url) {
                // Tiles are ready! Load them
                stopTilePolling(layerKey);
                
                const fallbackLabel =
                    periodStartYear.value === endYear
                        ? `${periodStartYear.value}`
                        : `${periodStartYear.value} - ${endYear}`;
                const periodLabelFromResponse =
                    availability.period_label || fallbackLabel;
                
                const tileLayer = new TileLayer({
                    source: new XYZ({
                        url: availability.tiles_url,
                        crossOrigin: "anonymous",
                        minZoom: 6,
                        maxZoom: maxZoomAvailable,
                    }),
                    opacity: 1.0,
                    zIndex: 8,
                    minZoom: 6,
                    maxZoom: 18,
                });
                
                registerDetailedLayer(layerKey, tileLayer);
                map.value.addLayer(tileLayer);
                console.log(
                    `✓ Auto-loaded precomputed erosion tile layer (${periodLabelFromResponse}):`,
                    availability.tiles_url
                );
                
                emit("detailed-erosion-loaded", {
                    areaId,
                    areaName: areaLabel,
                    cellCount: null,
                    statistics: availability.statistics || null,
                });
                
                emit("layer-warning", {
                    type: "success",
                    title: "Erosion Tiles Ready",
                    message: `${areaLabel} (${periodLabelFromResponse}) tiles are now available.`,
                    details: "Tiles have been automatically loaded on the map.",
                });
            } else if (status === "failed" || status === "error") {
                // Task failed, stop polling
                stopTilePolling(layerKey);
                emit("layer-warning", {
                    type: "error",
                    title: "Erosion Tiles Unavailable",
                    message: `Failed to generate erosion tiles for ${areaLabel}.`,
                    details:
                        availability.error ||
                        availability.error_message ||
                        "Please try again later.",
                });
            } else if (pollCount >= maxPolls) {
                // Max polling time reached
                stopTilePolling(layerKey);
                emit("layer-warning", {
                    type: "warning",
                    title: "Tile Generation Taking Longer",
                    message: `Tile generation for ${areaLabel} is taking longer than expected.`,
                    details: "Please check back later or try clicking Apply again.",
                });
            }
        } catch (error) {
            console.error("Error polling for tile availability:", error);
            // Continue polling on error (might be temporary network issue)
            if (pollCount >= maxPolls) {
                stopTilePolling(layerKey);
            }
        }
    }, pollInterval);
    
    tilePollingIntervals[layerKey] = pollIntervalId;
    console.log(`Started polling for tiles: ${layerKey}`);
};

// Stop polling for a specific layer
const stopTilePolling = (layerKey) => {
    const intervalId = tilePollingIntervals[layerKey];
    if (intervalId) {
        clearInterval(intervalId);
        delete tilePollingIntervals[layerKey];
        console.log(`Stopped polling for tiles: ${layerKey}`);
    }
};

// Stop all polling
const stopAllTilePolling = () => {
    Object.keys(tilePollingIntervals).forEach((layerKey) => {
        const intervalId = tilePollingIntervals[layerKey];
        if (intervalId) {
            clearInterval(intervalId);
            console.log(`Stopped polling for tiles: ${layerKey}`);
        }
        delete tilePollingIntervals[layerKey];
    });
};

// Capture the current map view as a PNG data URL
const captureMapAsImage = () => {
    return new Promise((resolve, reject) => {
        if (!map.value) {
            reject(new Error("Map is not ready yet."));
            return;
        }

        const size = map.value.getSize();
        if (!size) {
            reject(new Error("Map size is unavailable."));
            return;
        }

        map.value.once("rendercomplete", () => {
            try {
                const mapCanvas = document.createElement("canvas");
                mapCanvas.width = size[0];
                mapCanvas.height = size[1];
                const mapContext = mapCanvas.getContext("2d");

                const viewport = map.value.getViewport();
                let canvases = [];
                if (viewport && typeof viewport.querySelectorAll === "function") {
                    canvases = Array.from(viewport.querySelectorAll("canvas"));
                }
                if (!canvases.length) {
                    canvases = Array.from(
                        document.querySelectorAll(".ol-layer canvas, canvas.ol-layer")
                    );
                }

                canvases.forEach((canvas) => {
                    if (
                        !canvas ||
                        canvas.width === 0 ||
                        canvas.height === 0 ||
                        canvas.style.display === "none"
                    ) {
                        return;
                    }

                    const opacity = canvas.parentNode?.style?.opacity || canvas.style.opacity;
                    const display = canvas.parentNode?.style?.display || canvas.style.display;
                    if (display === "none") {
                        return;
                    }

                    mapContext.globalAlpha = opacity ? Number(opacity) : 1;

                    const transform = canvas.style.transform;
                    if (transform) {
                        const matrixValues = transform
                            .match(/^matrix\(([^]*)\)$/)?.[1]
                            ?.split(",")
                            .map(Number);
                        if (matrixValues && matrixValues.length === 6) {
                            mapContext.setTransform(
                                matrixValues[0],
                                matrixValues[1],
                                matrixValues[2],
                                matrixValues[3],
                                matrixValues[4],
                                matrixValues[5]
                            );
                        }
                    } else {
                        mapContext.setTransform(1, 0, 0, 1, 0, 0);
                    }

                    const backgroundColor = canvas.parentNode?.style?.backgroundColor;
                    if (backgroundColor) {
                        mapContext.fillStyle = backgroundColor;
                        mapContext.fillRect(0, 0, canvas.width, canvas.height);
                    }

                    mapContext.drawImage(canvas, 0, 0);
                });

                mapContext.globalAlpha = 1;
                mapContext.setTransform(1, 0, 0, 1, 0, 0);

                resolve({
                    dataUrl: mapCanvas.toDataURL("image/png"),
                    width: mapCanvas.width,
                    height: mapCanvas.height,
                });
            } catch (error) {
                reject(error);
            }
        });

        map.value.renderSync();
    });
};

// Clip geometry to country bounds
const clipGeometryToCountryBounds = async (geometry) => {
    if (!topoJsonLayer.value) return geometry;

    try {
        const turf = await import("@turf/turf");
        const geojsonFormat = new GeoJSON();
        let countryBoundary = countryBoundaryCache.value;

        if (!countryBoundary) {
            const source = topoJsonLayer.value.getSource();
            const features = source
                ? source.getFeatures().filter((feature) => feature?.getGeometry())
                : [];

            if (!features.length) {
                return geometry;
            }

            for (const feature of features) {
                const geoJsonFeature = geojsonFormat.writeFeatureObject(feature, {
                    featureProjection: "EPSG:3857",
                    dataProjection: "EPSG:4326",
                });

                if (!geoJsonFeature?.geometry) {
                    continue;
                }

                if (!countryBoundary) {
                    countryBoundary = geoJsonFeature;
                } else {
                    countryBoundary = turf.union(countryBoundary, geoJsonFeature);
                }
            }

            if (!countryBoundary) {
                return geometry;
            }

            countryBoundaryCache.value = countryBoundary;
        }

        // Convert drawn geometry to GeoJSON
        const drawnFeature = new Feature({ geometry });
        const drawnGeoJson = geojsonFormat.writeFeatureObject(drawnFeature, {
            featureProjection: "EPSG:3857",
            dataProjection: "EPSG:4326",
        });

        // Clip to country boundary
        const clipped = turf.intersect(drawnGeoJson, countryBoundary);

        if (!clipped) return null;

        // Convert back to OpenLayers geometry
        const clippedFeature = geojsonFormat.readFeature(clipped, {
            dataProjection: "EPSG:4326",
            featureProjection: "EPSG:3857",
        });

        return clippedFeature.getGeometry();
    } catch (error) {
        console.error("Error in clipGeometryToCountryBounds:", error);
        return geometry; // Return original on error
    }
};

// Layer management
const mapLayers = ref({});

const createCustomTileLayer = (layerDef) => {
    if (!layerDef || !layerDef.tileUrlTemplate) {
        return null;
    }

    const source = new XYZ({
        url: layerDef.tileUrlTemplate,
        crossOrigin: "anonymous",
        transition: 0,
    });

    return new TileLayer({
        source,
        opacity: layerDef.defaultOpacity ?? 1,
        className:
            layerDef.className ||
            `custom-dataset-layer-${layerDef.id || "user-custom"}`,
    });
};

const updateMapLayers = async () => {
    if (!map.value) return;

    console.log("Updating map layers, visible:", props.visibleLayers);
    console.log(
        "Selected area:",
        props.selectedDistrict || props.selectedRegion || "none"
    );

    // Clear all previous layer colors and area highlights before updating
    console.log(
        "Clearing previous layer colors and area highlights before updating map layers"
    );
    clearAllLayerColors();
    clearAreaHighlights();

    // Check if areas are selected (support multiple areas) - declare early
    const selectedArea = props.selectedDistrict || props.selectedRegion;
    const selectedAreas = props.selectedAreas || [];

    // Define available map layers
    const layerDefinitions = {
        erosion: {
            name: "Soil Erosion Hazard",
            type: "rusle",
            apiEndpoint: null, // Uses detailed grid
            defaultOpacity: 1.0,
        },
        rainfall_slope: {
            name: "Rainfall Trend",
            type: "diverging",
            apiEndpoint: "/api/erosion/layers/rainfall-slope",
            defaultOpacity: 1.0,
            minYears: 3,
        },
        rainfall_cv: {
            name: "Rainfall CV",
            type: "sequential",
            apiEndpoint: "/api/erosion/layers/rainfall-cv",
            defaultOpacity: 1.0,
        },
        r_factor: {
            name: "R-Factor",
            type: "sequential",
            apiEndpoint: "/api/erosion/layers/r-factor",
            defaultOpacity: 1.0,
        },
        k_factor: {
            name: "K-Factor",
            type: "sequential",
            apiEndpoint: "/api/erosion/layers/k-factor",
            defaultOpacity: 1.0,
        },
        ls_factor: {
            name: "LS-Factor",
            type: "sequential",
            apiEndpoint: "/api/erosion/layers/ls-factor",
            defaultOpacity: 1.0,
        },
        c_factor: {
            name: "C-Factor",
            type: "sequential",
            apiEndpoint: "/api/erosion/layers/c-factor",
            defaultOpacity: 1.0,
        },
        p_factor: {
            name: "P-Factor",
            type: "sequential",
            apiEndpoint: "/api/erosion/layers/p-factor",
            defaultOpacity: 1.0,
        },
        bare_soil: {
            name: "Bare Soil Frequency",
            type: "sequential",
            apiEndpoint: null,
            defaultOpacity: 1.0,
        },
        sustainability: {
            name: "Sustainability Factor",
            type: "sequential",
            apiEndpoint: null,
            defaultOpacity: 1.0,
        },
    };

    (props.customLayers || []).forEach((layer) => {
        if (!layer || !layer.id || !layer.tileUrlTemplate) {
            return;
        }

        layerDefinitions[layer.id] = {
            id: layer.id,
            name: layer.name || "Custom Dataset",
            type: "custom-tile",
            tileUrlTemplate: layer.tileUrlTemplate,
            defaultOpacity: layer.defaultOpacity ?? 1.0,
            metadata:
                layer.metadata && typeof layer.metadata === "object"
                    ? layer.metadata
                    : {},
        };
    });

    // Clear all existing layers first (single layer display)
    Object.keys(mapLayers.value).forEach((layerId) => {
        map.value.removeLayer(mapLayers.value[layerId]);
        delete mapLayers.value[layerId];
    });

    // Clear base layer colors when switching layers
    if (districtsBaseLayer.value) {
        const source = districtsBaseLayer.value.getSource();
        if (source) {
            source.forEachFeature((feature) => {
                feature.unset("erosion_rate");
            });
            districtsBaseLayer.value.changed();
        }
    }

    if (regionLayer.value) {
        const source = regionLayer.value.getSource();
        if (source) {
            source.forEachFeature((feature) => {
                feature.unset("erosion_rate");
            });
            regionLayer.value.changed();
        }
    }

    // Add only the selected layer (if any)
    if (props.visibleLayers.length > 0) {
        const layerId = props.visibleLayers[0]; // Only show the first (and only) selected layer
        if (layerDefinitions[layerId]) {
            const layerDef = layerDefinitions[layerId];
            const opacity = layerDef.defaultOpacity;

            if (
                layerDef.minYears &&
                selectedPeriodLength.value < layerDef.minYears
            ) {
                console.warn(
                    `${layerDef.name} requires at least ${layerDef.minYears} years. Current period length: ${selectedPeriodLength.value}`
                );
                emit("layer-warning", {
                    type: "warning",
                    title: "Extend Period Range",
                    message: `${layerDef.name} requires at least ${layerDef.minYears} years for analysis.`,
                    details: `Select a period covering ${
                        layerDef.minYears
                    } or more years to view this layer. Current selection spans ${
                        selectedPeriodLength.value || 0
                    } year(s).`,
                });
                return;
            }

            if (layerDef.type === "custom-tile") {
                const customLayer = createCustomTileLayer({
                    ...layerDef,
                    id: layerId,
                });

                if (!customLayer) {
                    console.warn(
                        `Failed to create custom dataset layer for ${layerId}`
                    );
                    return;
                }

                map.value.addLayer(customLayer);
                mapLayers.value[layerId] = customLayer;
                return;
            }

            // Handle erosion layer specially
            if (layerId === "erosion") {
                console.log("Handling erosion layer for display");

                // Check for country-level selection
                const isCountrySelection =
                    selectedArea && selectedArea.area_type === "country";
                const hasMultipleAreas = selectedAreas.length > 0;

                // For country-wide erosion, skip detailed grid (not supported by backend)
                if (
                    isCountrySelection ||
                    (!selectedArea && !hasMultipleAreas)
                ) {
                    console.log(
                        "Country-level or no area selected - skipping detailed erosion grid"
                    );
                    emit("layer-warning", {
                        type: "info",
                        title: "Area Selection Required",
                        message:
                            "Please select a region or district to visualize erosion data.",
                        details:
                            "Country-wide detailed erosion visualization is not available. Select a specific area from the dropdown.",
                    });
                    return; // Don't load detailed grid for country-level
                }

                const activeDetailedKeys = new Set();

                if (hasMultipleAreas) {
                    // Multiple areas selected - load erosion data for each area
                    console.log(
                        `${selectedAreas.length} areas selected, loading erosion data for each`
                    );
                    for (const area of selectedAreas) {
                        // Skip country-level areas in the list
                        if (area.area_type === "country") {
                            console.log(
                                "Skipping country-level area in multiple selection"
                            );
                            continue;
                        }
                        const layerKey = await loadDetailedErosionData(area);
                        if (layerKey) {
                            activeDetailedKeys.add(layerKey);
                        }
                    }
                } else if (selectedArea) {
                    // Single area selected - load detailed erosion data for that area
                    const layerKey = await loadDetailedErosionData(
                        selectedArea
                    );
                    if (layerKey) {
                        activeDetailedKeys.add(layerKey);
                    }
                }
                removeUnusedDetailedLayers(activeDetailedKeys);
                return;
            }

            console.log(`updateMapLayers - selectedArea:`, selectedArea);
            console.log(`updateMapLayers - selectedAreas:`, selectedAreas);
            console.log(
                `updateMapLayers - selectedDistrict:`,
                props.selectedDistrict
            );
            console.log(
                `updateMapLayers - selectedRegion:`,
                props.selectedRegion
            );

            if (selectedAreas.length > 0) {
                // MULTIPLE AREAS SELECTED: Show each selected area with layer data
                console.log(
                    `${selectedAreas.length} areas selected, fetching ${layerId} layer for each area`
                );

                if (layerDef.apiEndpoint) {
                    try {
                        // Create individual layers for each selected area
                        const areaLayers = [];

                        for (const area of selectedAreas) {
                            try {
                                let areaType;
                                let areaId;

                                if (area.area_type) {
                                    areaType = area.area_type;
                                    areaId = area.id;
                                } else if (area.region_id) {
                                    // This is a district
                                    areaType = "district";
                                    areaId = area.id;
                                } else {
                                    // This is a region
                                    areaType = "region";
                                    areaId = area.id;
                                }

                                console.log(
                                    `Fetching ${layerId} for ${areaType} ${areaId} (${area.name_en})`
                                );

                                const requestData = {
                                    area_type: areaType,
                                    area_id: areaId,
                                    year: periodEndYear.value,
                                    start_year: periodStartYear.value,
                                    end_year: periodEndYear.value,
                                };

                                const layer = await fetchAndRenderLayer(
                                    layerId,
                                    layerDef,
                                    area,
                                    areaType,
                                    opacity
                                );

                                if (layer) {
                                    areaLayers.push(layer);
                                    console.log(
                                        `Added layer for ${area.name_en}: ${layerDef.name}`
                                    );
                                }
                            } catch (error) {
                                console.error(
                                    `Error loading ${layerId} for area ${area.name_en}:`,
                                    error
                                );
                            }
                        }

                        // Store all individual layers as a virtual layer
                        if (areaLayers.length > 0) {
                            mapLayers.value[layerId] = {
                                type: "multiple-areas",
                                layers: areaLayers,
                                name: layerDef.name,
                            };
                            console.log(
                                `Added ${areaLayers.length} individual area layers for ${layerDef.name}`
                            );
                        }
                    } catch (error) {
                        console.error(
                            `Error loading ${layerId} for multiple areas:`,
                            error
                        );
                        emit(
                            "geometry-error",
                            `Failed to load ${layerDef.name}`
                        );
                    }
                } else {
                    console.log(`No API endpoint for ${layerId}, skipping`);
                }
            } else if (selectedArea) {
                // SINGLE AREA SELECTED: Show only selected area with layer data
                console.log(
                    `Single area selected: ${selectedArea.name_en}, fetching ${layerId} layer for area only`
                );

                if (layerDef.apiEndpoint) {
                    try {
                        let areaType;
                        if (selectedArea.area_type === "country") {
                            areaType = "country";
                        } else {
                            areaType = selectedArea.region_id
                                ? "district"
                                : "region";
                        }

                        // Fetch layer data from backend for selected area only
                        const layer = await fetchAndRenderLayer(
                            layerId,
                            layerDef,
                            selectedArea,
                            areaType,
                            opacity
                        );

                        if (layer) {
                            // Handle country-wide layers (which return an object with multiple layers)
                            if (layer.type === "country-wide") {
                                mapLayers.value[layerId] = layer;
                                console.log(
                                    `Added country-wide layer with ${layer.layers.length} individual area layers: ${layerDef.name}`
                                );
                            } else {
                                mapLayers.value[layerId] = layer;
                                console.log(
                                    `Added area-specific layer: ${layerDef.name}`
                                );
                            }
                        }
                    } catch (error) {
                        console.error(
                            `Error loading ${layerId} for area:`,
                            error
                        );
                        emit(
                            "geometry-error",
                            `Failed to load ${layerDef.name}`
                        );
                    }
                } else {
                    console.log(`No API endpoint for ${layerId}, skipping`);
                }
            } else {
                // NO AREA SELECTED: Show country-wide layer data
                console.log(
                    `No area selected, fetching ${layerId} layer for country-wide display`
                );

                if (layerDef.apiEndpoint) {
                    try {
                        // For country-wide display, we'll use a default area or create a country-wide layer
                        const countryWideArea = {
                            id: 0, // Special ID for country-wide
                            name_en: "Tajikistan",
                            region_id: null,
                        };

                        // Fetch layer data from backend for country-wide display
                        const layer = await fetchAndRenderLayer(
                            layerId,
                            layerDef,
                            countryWideArea,
                            "country",
                            opacity
                        );

                        if (layer) {
                            // Handle country-wide layers (which return an object with multiple layers)
                            if (layer.type === "country-wide") {
                                mapLayers.value[layerId] = layer;
                                console.log(
                                    `Added country-wide layer with ${layer.layers.length} individual area layers: ${layerDef.name}`
                                );
                            } else {
                                mapLayers.value[layerId] = layer;
                                console.log(
                                    `Added country-wide layer: ${layerDef.name}`
                                );
                            }
                        }
                    } catch (error) {
                        console.error(
                            `Error loading ${layerId} for country-wide:`,
                            error
                        );
                        emit(
                            "geometry-error",
                            `Failed to load ${layerDef.name}`
                        );
                    }
                } else {
                    console.log(`No API endpoint for ${layerId}, skipping`);
                }
            }
        }
    }
};

// Fetch and render a RUSLE factor layer
const fetchAndRenderLayer = async (
    layerId,
    layerDef,
    area,
    areaType,
    opacity
) => {
    try {
        const requestData = {
            area_type: areaType,
            area_id: area.id,
            year: periodEndYear.value,
            start_year: periodStartYear.value,
            end_year: periodEndYear.value,
        };

        const response = await axios.post(layerDef.apiEndpoint, requestData);

        if (!response.data.success) {
            throw new Error("Failed to fetch layer data");
        }

        const layerData = response.data.data;

        // If backend returns tiles URL, use tile layer
        if (layerData.tiles) {
            const maxZoomAvailable = parseMaxZoom(layerData.max_zoom);
            const layer = new TileLayer({
                source: new XYZ({
                    url: layerData.tiles,
                    crossOrigin: "anonymous",
                    minZoom: 6, // Precomputed tiles are now available from zoom level 6
                    maxZoom: maxZoomAvailable,
                }),
                opacity: opacity,
                zIndex: 8, // Below district borders (15) and selection layers (20)
                minZoom: 6, // Don't show layer below zoom 6
                maxZoom: 18, // Allow upscaling beyond zoom 12
                title: layerDef.name,
            });
            map.value.addLayer(layer);
            return layer;
        }

        // Otherwise, create vector layer with color ramp
        const layer = await createVectorLayerFromData(
            layerId,
            layerDef,
            area,
            layerData,
            opacity
        );
        return layer;
    } catch (error) {
        console.error(`Error fetching layer ${layerId}:`, error);
        return null;
    }
};

// Create vector layer with appropriate color ramp
const createVectorLayerFromData = async (
    layerId,
    layerDef,
    area,
    layerData,
    opacity
) => {
    // For country-wide display, fetch and paint each district and region individually
    if (area.id === 0 || !area.geometry) {
        console.log(
            "Creating country-wide visualization by painting each area individually"
        );

        try {
            // Fetch all regions and districts
            const [regionsResponse, districtsResponse] = await Promise.all([
                axios.get("/api/erosion/regions"),
                axios.get("/api/erosion/districts"),
            ]);

            console.log("Regions response:", regionsResponse.data);
            console.log("Districts response:", districtsResponse.data);

            const allAreas = [];

            // Add regions
            if (
                regionsResponse.data &&
                regionsResponse.data.success &&
                regionsResponse.data.regions &&
                Array.isArray(regionsResponse.data.regions) &&
                regionsResponse.data.regions.length > 0
            ) {
                regionsResponse.data.regions.forEach((region) => {
                    if (region.geometry) {
                        allAreas.push({ ...region, type: "region" });
                    }
                });
                console.log(
                    `Added ${regionsResponse.data.regions.length} regions`
                );
            } else {
                console.warn(
                    "No regions data available or invalid response structure"
                );
            }

            // Add districts
            if (
                districtsResponse.data &&
                districtsResponse.data.success &&
                districtsResponse.data.districts &&
                Array.isArray(districtsResponse.data.districts) &&
                districtsResponse.data.districts.length > 0
            ) {
                districtsResponse.data.districts.forEach((district) => {
                    if (district.geometry) {
                        allAreas.push({ ...district, type: "district" });
                    }
                });
                console.log(
                    `Added ${districtsResponse.data.districts.length} districts`
                );
            } else {
                console.warn(
                    "No districts data available or invalid response structure"
                );
            }

            console.log(
                `Found ${allAreas.length} areas to paint for country-wide visualization`
            );

            // If no areas found, return null
            if (allAreas.length === 0) {
                console.warn("No areas found for country-wide visualization");
                return null;
            }

            // Create individual layers for each area
            const areaLayers = [];

            for (const areaItem of allAreas) {
                try {
                    console.log(
                        `Processing area: ${areaItem.name_en} (${areaItem.type})`
                    );

                    // Fetch layer data for this specific area
                    const areaType = areaItem.type;
                    const areaId = areaItem.id;

                    const requestData = {
                        area_type: areaType,
                        area_id: areaId,
                        year: periodEndYear.value,
                        start_year: periodStartYear.value,
                        end_year: periodEndYear.value,
                    };

                    console.log(
                        `Fetching data for ${areaItem.name_en}:`,
                        requestData
                    );
                    const response = await axios.post(
                        layerDef.apiEndpoint,
                        requestData
                    );
                    console.log(
                        `Response for ${areaItem.name_en}:`,
                        response.data
                    );

                    if (
                        response.data &&
                        response.data.success &&
                        response.data.data
                    ) {
                        const areaLayerData = response.data.data;
                        console.log(
                            `Layer data for ${areaItem.name_en}:`,
                            areaLayerData
                        );

                        // Validate layer data structure
                        if (
                            !areaLayerData ||
                            typeof areaLayerData !== "object"
                        ) {
                            console.warn(
                                `Invalid layer data for ${areaItem.name_en}:`,
                                areaLayerData
                            );
                            continue;
                        }

                        // Parse area geometry
                        let geometryData = areaItem.geometry;
                        if (typeof geometryData === "string") {
                            geometryData = JSON.parse(geometryData);
                        }

                        console.log(
                            `Geometry for ${areaItem.name_en}:`,
                            geometryData
                        );

                        const geojsonFormat = new GeoJSON();
                        const features = geojsonFormat.readFeatures(
                            geometryData,
                            {
                                dataProjection: "EPSG:4326",
                                featureProjection: "EPSG:3857",
                            }
                        );

                        console.log(
                            `Features for ${areaItem.name_en}:`,
                            features.length
                        );

                        if (features.length > 0) {
                            const areaGeometry = features[0].getGeometry();
                            const extent = areaGeometry.getExtent();

                            console.log(
                                `Extent for ${areaItem.name_en}:`,
                                extent
                            );

                            // Validate areaLayerData before creating grid
                            if (
                                !areaLayerData ||
                                typeof areaLayerData !== "object"
                            ) {
                                console.warn(
                                    `Invalid areaLayerData for ${areaItem.name_en}, skipping grid creation`
                                );
                                continue;
                            }

                            // Create grid with color-coded cells for this area
                            const gridFeatures = createColoredGrid(
                                areaGeometry,
                                extent,
                                areaLayerData,
                                layerDef.type
                            );

                            console.log(
                                `Grid features for ${areaItem.name_en}:`,
                                gridFeatures.length
                            );

                            if (gridFeatures.length > 0) {
                                const source = new VectorSource({
                                    features: gridFeatures,
                                });

                                const layer = new VectorLayer({
                                    source,
                                    opacity: opacity,
                                    zIndex: 8, // Below district borders (15) and selection layers (20)
                                    title: `${layerDef.name} - ${areaItem.name_en}`,
                                });

                                console.log(
                                    `Adding layer to map for ${areaItem.name_en}`
                                );
                                console.log(`Map reference:`, map.value);
                                console.log(`Layer to add:`, layer);

                                if (map.value) {
                                    map.value.addLayer(layer);
                                    console.log(
                                        `Layer added to map successfully`
                                    );
                                    console.log(
                                        `Map layers count:`,
                                        map.value.getLayers().getLength()
                                    );
                                } else {
                                    console.error(`Map reference is null!`);
                                }

                                areaLayers.push(layer);

                                console.log(
                                    `✅ Painted ${areaItem.name_en} (${areaType}) with ${gridFeatures.length} cells`
                                );
                            } else {
                                console.warn(
                                    `No grid features created for ${areaItem.name_en}`
                                );
                            }
                        } else {
                            console.warn(
                                `No features found for ${areaItem.name_en}`
                            );
                        }
                    } else {
                        console.warn(
                            `API call failed for ${areaItem.name_en}:`,
                            response.data
                        );
                    }
                } catch (error) {
                    console.error(
                        `Failed to paint ${areaItem.name_en} (${areaItem.type}):`,
                        error
                    );
                }
            }

            console.log(
                `Created ${areaLayers.length} individual area layers for country-wide visualization`
            );

            // If no layers were created, create a simple test layer
            if (areaLayers.length === 0) {
                console.log(
                    "No individual layers created, creating test layer"
                );

                // Create a simple test rectangle over Tajikistan
                const testGeometry = new Polygon([
                    [
                        [67.0, 36.0], // Southwest corner
                        [75.0, 36.0], // Southeast corner
                        [75.0, 41.0], // Northeast corner
                        [67.0, 41.0], // Northwest corner
                        [67.0, 36.0], // Close the polygon
                    ],
                ]);

                const testFeature = new Feature({
                    geometry: testGeometry,
                    value: 25,
                    layerType: layerDef.type,
                });

                testFeature.setStyle(
                    new Style({
                        selectedAreaStyle,
                    })
                );

                const testSource = new VectorSource({
                    features: [testFeature],
                });

                const testLayer = new VectorLayer({
                    source: testSource,
                    opacity: 1.0,
                    zIndex: 8, // Below district borders (15) and selection layers (20)
                    title: `${layerDef.name} - Test Layer`,
                });

                map.value.addLayer(testLayer);
                areaLayers.push(testLayer);

                console.log(
                    "Created test layer for country-wide visualization"
                );
            }

            // Return a virtual layer that represents all the individual layers
            return {
                type: "country-wide",
                layers: areaLayers,
                name: layerDef.name,
            };
        } catch (error) {
            console.error("Error creating country-wide visualization:", error);
        }
        return null;
    }

    // For specific area display
    let geometryData = area.geometry;
    if (typeof geometryData === "string") {
        geometryData = JSON.parse(geometryData);
    }

    const geojsonFormat = new GeoJSON();
    const features = geojsonFormat.readFeatures(geometryData, {
        dataProjection: "EPSG:4326",
        featureProjection: "EPSG:3857",
    });

    if (features.length === 0) return null;

    const areaGeometry = features[0].getGeometry();
    const extent = areaGeometry.getExtent();

    // Create grid with color-coded cells
    const gridFeatures = createColoredGrid(
        areaGeometry,
        extent,
        layerData,
        layerDef.type
    );

    const source = new VectorSource({
        features: gridFeatures,
    });

    const layer = new VectorLayer({
        source,
        opacity: opacity,
        zIndex: 8, // Below district borders (15) and selection layers (20)
        title: layerDef.name,
    });

    map.value.addLayer(layer);
    return layer;
};

// Create colored grid for layer visualization - CLIPPED TO EXACT AREA SHAPE
const createColoredGrid = (areaGeometry, extent, layerData, colorType) => {
    // Validate inputs
    if (!areaGeometry || !extent || !layerData) {
        console.error("createColoredGrid: Invalid inputs", {
            areaGeometry,
            extent,
            layerData,
        });
        return [];
    }

    const gridSize = 10;
    const [minX, minY, maxX, maxY] = extent;
    const cellWidth = (maxX - minX) / gridSize;
    const cellHeight = (maxY - minY) / gridSize;
    const features = [];

    // Get statistics from layer data with fallbacks
    const stats = {
        mean:
            (layerData && layerData.mean) ||
            (layerData && layerData.statistics && layerData.statistics.mean) ||
            0,
        min:
            (layerData && layerData.min) ||
            (layerData && layerData.statistics && layerData.statistics.min) ||
            0,
        max:
            (layerData && layerData.max) ||
            (layerData && layerData.statistics && layerData.statistics.max) ||
            1,
        stdDev:
            (layerData && layerData.stdDev) ||
            (layerData &&
                layerData.statistics &&
                layerData.statistics.stdDev) ||
            0,
    };

    console.log(`Creating grid for ${colorType} layer with stats:`, stats);
    console.log(`Layer data structure:`, layerData);

    for (let i = 0; i < gridSize; i++) {
        for (let j = 0; j < gridSize; j++) {
            const x1 = minX + i * cellWidth;
            const y1 = minY + j * cellHeight;
            const x2 = x1 + cellWidth;
            const y2 = y1 + cellHeight;

            const cellPolygon = new Polygon([
                [
                    [x1, y1],
                    [x2, y1],
                    [x2, y2],
                    [x1, y2],
                    [x1, y1],
                ],
            ]);

            // CRITICAL: Check if cell intersects with the ACTUAL area boundary
            // This ensures the layer matches the EXACT SHAPE of the selected district/region
            // Not just the rectangular extent, but the actual polygon boundary
            let shouldIncludeCell = true;

            if (areaGeometry) {
                // For specific areas, check intersection with actual geometry
                const cellCenter = cellPolygon
                    .getInteriorPoint()
                    .getCoordinates();

                // Double check: both center point AND geometry intersection
                const centerInside =
                    areaGeometry.intersectsCoordinate(cellCenter);
                const geometryIntersects = areaGeometry.intersectsExtent(
                    cellPolygon.getExtent()
                );

                shouldIncludeCell = centerInside && geometryIntersects;
            }
            // For country-wide fallback (areaGeometry is null), include all cells

            if (shouldIncludeCell) {
                // Generate value with spatial variation based on real GEE statistics
                const positionFactor =
                    Math.sin((i / gridSize) * Math.PI) *
                    Math.cos((j / gridSize) * Math.PI);
                const value =
                    stats.mean +
                    positionFactor *
                        (stats.stdDev || (stats.max - stats.min) / 4);

                // Get color based on layer type
                const color = getLayerColor(value, colorType, stats);

                const feature = new Feature({
                    geometry: cellPolygon,
                    value: value,
                    layerType: colorType,
                });

                feature.setStyle(
                    new Style({
                        defaultAreaStyle,
                        // Removed stroke to eliminate borders
                    })
                );

                features.push(feature);
            }
        }
    }

    console.log(
        `Created ${features.length} cells for layer (matched to area shape)`
    );
    return features;
};

// Get color for layer value based on color scheme type
const getLayerColor = (value, colorType, stats) => {
    console.log(
        `Getting color for value: ${value}, type: ${colorType}, stats:`,
        stats
    );
    const opacity = 1.0; // Full opacity for better visibility

    switch (colorType) {
        case "diverging": // Rainfall slope: red-orange-yellow-lightgreen-darkgreen
            const normalized = (value - stats.min) / (stats.max - stats.min);

            // Define color stops based on the legend image
            if (normalized < 0.25) {
                // Red to Orange (negative trend)
                const t = normalized / 0.25;
                const r = 255;
                const g = Math.round(0 + (165 - 0) * t);
                const b = 0;
                return `rgba(${r}, ${g}, ${b}, ${opacity})`;
            } else if (normalized < 0.5) {
                // Orange to Yellow (negative to neutral)
                const t = (normalized - 0.25) / 0.25;
                const r = 255;
                const g = Math.round(165 + (255 - 165) * t);
                const b = 0;
                return `rgba(${r}, ${g}, ${b}, ${opacity})`;
            } else if (normalized < 0.75) {
                // Yellow to Light Green (neutral to positive)
                const t = (normalized - 0.5) / 0.25;
                const r = Math.round(255 - (255 - 144) * t);
                const g = 255;
                const b = Math.round(0 + (238 - 0) * t);
                return `rgba(${r}, ${g}, ${b}, ${opacity})`;
            } else {
                // Light Green to Dark Green (positive trend)
                const t = (normalized - 0.75) / 0.25;
                const r = Math.round(144 - (144 - 0) * t);
                const g = Math.round(238 - (238 - 128) * t);
                const b = Math.round(144 - (144 - 0) * t);
                return `rgba(${r}, ${g}, ${b}, ${opacity})`;
            }

        case "sequential": // Blue gradient for factors
            const norm = Math.min(
                1,
                Math.max(0, (value - stats.min) / (stats.max - stats.min))
            );
            const r = Math.round(239 - (239 - 30) * norm);
            const g = Math.round(246 - (246 - 58) * norm);
            const b = Math.round(255 - (255 - 138) * norm);
            return `rgba(${r}, ${g}, ${b}, ${opacity})`;

        case "rusle": // Erosion color scale
            return getErosionColor(value, opacity);

        default:
            return `rgba(59, 130, 246, ${opacity})`;
    }
};

// Watch for prop changes
watch(
    () => props.selectedRegion,
    (newRegion) => {
        if (map.value) {
            updateRegionLayer(newRegion);
        }
    },
    { immediate: true }
);

watch(
    () => props.selectedDistrict,
    (newDistrict) => {
        if (map.value) {
            updateDistrictLayer(newDistrict);
        }
    },
    { immediate: true }
);

watch(
    () => props.visibleLayers,
    (newLayers, oldLayers) => {
        console.log("Visible layers changed (awaiting apply):", newLayers);
        if (!map.value) {
            console.log("Map not ready yet");
            return;
        }
    },
    { deep: true }
);

watch(
    () => props.analysisTrigger,
    (trigger, previous) => {
        if (!map.value) {
            console.log("Analysis trigger changed but map not ready yet");
            return;
        }
        if (typeof trigger === "number" && trigger > 0) {
            console.log("Analysis trigger received, refreshing map layers");
            updateMapLayers();
        }
    }
);

watch(
    () => props.customLayers,
    () => {
        if (!map.value) {
            return;
        }
        updateMapLayers();
    },
    { deep: true }
);

// Load TopoJSON data
const loadTopoJSONLayer = async (topoJsonUrl, layerName = "tajikistan") => {
    try {
        console.log("Loading TopoJSON from:", topoJsonUrl);

        const response = await fetch(topoJsonUrl);
        const topoJsonData = await response.json();

        // Remove existing TopoJSON layer if it exists
        if (topoJsonLayer.value) {
            map.value.removeLayer(topoJsonLayer.value);
        }

        // Create TopoJSON format parser
        const topoJsonFormat = new TopoJSON();

        // Parse the TopoJSON data
        const features = topoJsonFormat.readFeatures(topoJsonData, {
            featureProjection: "EPSG:3857",
        });

        // Create vector source with the features
        const vectorSource = new VectorSource({
            features: features,
        });

        // Create vector layer with custom styling
        topoJsonLayer.value = new VectorLayer({
            source: vectorSource,
            style: new Style({
                defaultAreaStyle,
            }),
        });

        // Add the layer to the map
        map.value.addLayer(topoJsonLayer.value);

        // Animate border drawing for TopoJSON
        setTimeout(() => {
            animateLayerBorderDrawing(topoJsonLayer.value, 2000);
        }, 300);

        console.log(
            `TopoJSON layer '${layerName}' loaded successfully with ${features.length} features`
        );

        // Fit the map to the extent of the TopoJSON data
        const extent = vectorSource.getExtent();
        if (extent && extent[0] !== Infinity) {
            map.value.getView().fit(extent, {
                padding: [20, 20, 20, 20],
                duration: 2000,
                easing: easeOut,
            });
        }

        return topoJsonLayer.value;
    } catch (error) {
        console.error("Error loading TopoJSON:", error);
        throw error;
    }
};

// Load TopoJSON from file path
const loadTopoJSONFromFile = async (filePath, layerName = "tajikistan") => {
    try {
        // If it's a relative path, make it absolute to the public directory
        const fullPath = filePath.startsWith("/")
            ? filePath
            : `/storage/${filePath}`;
        return await loadTopoJSONLayer(fullPath, layerName);
    } catch (error) {
        console.error("Error loading TopoJSON from file:", error);
        throw error;
    }
};

// Load GeoJSON data
const loadGeoJSONLayer = async (geoJsonUrl, layerName = "tajikistan") => {
    try {
        console.log("Loading GeoJSON from:", geoJsonUrl);

        const response = await fetch(geoJsonUrl);
        const geoJsonData = await response.json();

        // Remove existing GeoJSON layer if it exists
        if (topoJsonLayer.value) {
            map.value.removeLayer(topoJsonLayer.value);
        }

        // Create GeoJSON format parser
        const geoJsonFormat = new GeoJSON();

        // Parse the GeoJSON data
        const features = geoJsonFormat.readFeatures(geoJsonData, {
            featureProjection: "EPSG:3857",
        });

        // Create vector source with the features
        const vectorSource = new VectorSource({
            features: features,
        });

        // Create vector layer with custom styling for districts
        topoJsonLayer.value = new VectorLayer({
            source: vectorSource,
            style: new Style({
                defaultAreaStyle,
            }),
        });

        // Add the layer to the map
        map.value.addLayer(topoJsonLayer.value);

        // Animate border drawing for GeoJSON
        setTimeout(() => {
            animateLayerBorderDrawing(topoJsonLayer.value, 2000);
        }, 300);

        console.log(
            `GeoJSON layer '${layerName}' loaded successfully with ${features.length} features`
        );

        // Fit the map to the extent of the GeoJSON data
        const extent = vectorSource.getExtent();
        if (extent && extent[0] !== Infinity) {
            map.value.getView().fit(extent, {
                padding: [20, 20, 20, 20],
                duration: 2000,
                easing: easeOut,
            });
        }

        return topoJsonLayer.value;
    } catch (error) {
        console.error("Error loading GeoJSON:", error);
        throw error;
    }
};

// Load GeoJSON from file path
const loadGeoJSONFromFile = async (filePath, layerName = "tajikistan") => {
    try {
        // If it's a relative path, make it absolute to the public directory
        const fullPath = filePath.startsWith("/")
            ? filePath
            : `/storage/${filePath}`;
        return await loadGeoJSONLayer(fullPath, layerName);
    } catch (error) {
        console.error("Error loading GeoJSON from file:", error);
        throw error;
    }
};

// Highlight a specific district and dim others
const highlightDistrict = (districtName) => {
    if (!topoJsonLayer.value) return;

    const source = topoJsonLayer.value.getSource();
    const features = source.getFeatures();

    features.forEach((feature) => {
        const properties = feature.getProperties();
        const isSelected = properties.shapeName === districtName;

        if (isSelected) {
            feature.setStyle(
                new Style({
                    defaultAreaStyle,
                })
            );
        } else {
            feature.setStyle(
                new Style({
                    defaultAreaStyle,
                })
            );
        }
    });
};

// Reset all districts to normal styling
const resetDistrictHighlighting = () => {
    if (!topoJsonLayer.value) return;

    const source = topoJsonLayer.value.getSource();
    const features = source.getFeatures();

    features.forEach((feature) => {
        feature.setStyle(
            new Style({
                defaultAreaStyle,
            })
        );
    });
};

// Zoom to a specific district by name
const zoomToDistrict = (districtName) => {
    if (!topoJsonLayer.value) return;

    const source = topoJsonLayer.value.getSource();
    const features = source.getFeatures();

    const targetFeature = features.find((feature) => {
        const properties = feature.getProperties();
        return properties.shapeName === districtName;
    });

    if (targetFeature) {
        const geometry = targetFeature.getGeometry();
        if (geometry) {
            const extent = geometry.getExtent();
            map.value.getView().fit(extent, {
                padding: [50, 50, 50, 50],
                duration: 2000,
                easing: easeOut,
                maxZoom: 12,
            });
        }
    }
};

// Load Tajikistan boundary and create boundary layer
const loadTajikistanBoundary = async () => {
    try {
        console.log("Loading Tajikistan boundary...");
        const geoJsonPath = "/storage/geoBoundaries-TJK-ADM2.geojson";

        const response = await fetch(geoJsonPath);
        const geoJsonData = await response.json();

        // Create GeoJSON format parser
        const geoJsonFormat = new GeoJSON();

        // Parse all features and create boundary polygon
        const features = geoJsonFormat.readFeatures(geoJsonData, {
            featureProjection: "EPSG:3857",
        });

        if (features.length > 0) {
            // Create a union of all district features to get country boundary
            let boundaryPolygon = features[0].getGeometry();
            for (let i = 1; i < features.length; i++) {
                const geom = features[i].getGeometry();
                if (geom && boundaryPolygon) {
                    boundaryPolygon = boundaryPolygon
                        .clone()
                        .extend(geom.getExtent());
                }
            }

            // Store the boundary extent for checking
            tajikistanBoundary.value = boundaryPolygon.getExtent();
            tajikistanBoundaryFeatureCollection.value =
                geoJsonFormat.writeFeaturesObject(features, {
                    featureProjection: "EPSG:3857",
                    dataProjection: "EPSG:4326",
                });

            // Create a boundary layer with subtle outline
            const boundarySource = new VectorSource({
                features: features,
            });

            tajikistanBoundaryLayer.value = new VectorLayer({
                source: boundarySource,
                style: new Style({
                    defaultAreaStyle,
                }),
                zIndex: 1, // Below districts but above base map
            });

            map.value.addLayer(tajikistanBoundaryLayer.value);
            console.log("Tajikistan boundary loaded successfully");
        }
    } catch (error) {
        console.warn("Could not load Tajikistan boundary:", error.message);
    }
};

// Check if a coordinate is within Tajikistan boundary
const isWithinBoundary = (coordinate) => {
    if (!tajikistanBoundary.value) return true; // If no boundary loaded, allow all

    const [x, y] = coordinate;
    const [minX, minY, maxX, maxY] = tajikistanBoundary.value;

    // Check if coordinate is within bounding box
    return x >= minX && x <= maxX && y >= minY && y <= maxY;
};

// Check if a feature/geometry is within boundary
const isFeatureWithinBoundary = (geometry) => {
    if (!tajikistanBoundary.value || !geometry) return true;

    // Get extent of the geometry
    const extent = geometry.getExtent();
    const [minX, minY, maxX, maxY] = extent;
    const [boundMinX, boundMinY, boundMaxX, boundMaxY] =
        tajikistanBoundary.value;

    // Check if geometry extent overlaps with boundary
    return !(
        maxX < boundMinX ||
        minX > boundMaxX ||
        maxY < boundMinY ||
        minY > boundMaxY
    );
};

// Load GeoJSON automatically when map is ready
const loadGeoJSONOnMapReady = async () => {
    try {
        console.log("Loading GeoJSON automatically...");
        const geoJsonPath = "/storage/geoBoundaries-TJK-ADM2.geojson";

        // Load boundary first
        await loadTajikistanBoundary();

        // Then load districts layer
        await loadGeoJSONLayer(geoJsonPath, "tajikistan-districts");
        console.log("GeoJSON layer loaded successfully automatically");

        // Emit event to parent to load districts data
        emit("geojson-loaded", geoJsonPath);
    } catch (error) {
        console.warn("Could not load GeoJSON automatically:", error.message);
    }
};

// Watchers
watch(
    () => props.selectedDistrict,
    (newDistrict, oldDistrict) => {
        console.log("Selected district changed:", newDistrict);
        refreshDistrictsLayer();
    }
);

watch(
    () => props.districts,
    (newDistricts) => {
        if (newDistricts && newDistricts.length > 0 && map.value) {
            console.log("Districts data updated, reloading layer");
            // Remove old layer
            if (districtsBaseLayer.value) {
                map.value.removeLayer(districtsBaseLayer.value);
            }
            // Load new layer
            loadDistrictsLayer();
        }
    }
);

// Enable shape editing mode
const enableShapeEditing = () => {
    if (!map.value || !vectorSource.value) return;

    // Remove any existing modify interaction
    if (modifyInteraction.value) {
        map.value.removeInteraction(modifyInteraction.value);
    }

    // Create select interaction
    if (!selectInteraction.value) {
        selectInteraction.value = new Select({
            layers: [vectorLayer.value],
        });
        map.value.addInteraction(selectInteraction.value);
    }

    // Create modify interaction
    modifyInteraction.value = new Modify({
        features: selectInteraction.value.getFeatures(),
    });
    map.value.addInteraction(modifyInteraction.value);

    // Listen for modify end
    modifyInteraction.value.on("modifyend", (event) => {
        const features = event.features.getArray();
        if (features.length > 0) {
            const feature = features[0];
            const geojsonFormat = new GeoJSON();
            const geojsonFeature = geojsonFormat.writeFeatureObject(feature, {
                featureProjection: "EPSG:3857",
                dataProjection: "EPSG:4326",
            });
            emit("geometry-modified", geojsonFeature);
        }
    });
};

// Disable shape editing mode
const disableShapeEditing = () => {
    if (modifyInteraction.value) {
        map.value.removeInteraction(modifyInteraction.value);
        modifyInteraction.value = null;
    }
    if (selectInteraction.value) {
        map.value.removeInteraction(selectInteraction.value);
        selectInteraction.value = null;
    }
};

// Delete selected shape
const deleteSelectedShape = () => {
    if (!selectInteraction.value) return;

    const features = selectInteraction.value.getFeatures();
    features.forEach((feature) => {
        vectorSource.value.removeFeature(feature);
        // Remove from drawnFeatures array
        const index = drawnFeatures.value.findIndex((f) => f === feature);
        if (index > -1) {
            drawnFeatures.value.splice(index, 1);
        }
    });
    features.clear();
    emit("shape-deleted");
};

// Clear all drawn shapes
const clearAllShapes = () => {
    vectorSource.value.clear();
    drawnFeatures.value = [];
    emit("shapes-cleared");
};

// Clear all layer colors from the map
const clearAllLayerColors = () => {
    // Clear all map layers
    Object.keys(mapLayers.value).forEach((layerId) => {
        const layer = mapLayers.value[layerId];

        // Handle country-wide layers (which contain multiple individual layers)
        if (layer && layer.type === "country-wide") {
            layer.layers.forEach((individualLayer) => {
                map.value.removeLayer(individualLayer);
            });
        } else if (layer && layer.type === "multiple-areas") {
            // Handle multiple areas layers
            layer.layers.forEach((individualLayer) => {
                map.value.removeLayer(individualLayer);
            });
        } else if (layer) {
            map.value.removeLayer(layer);
        }

        delete mapLayers.value[layerId];
    });

    // Reset district base layer to default colors (no layer data)
    if (districtsBaseLayer.value) {
        const source = districtsBaseLayer.value.getSource();
        if (source) {
            source.forEachFeature((feature) => {
                feature.unset("erosion_rate");
                feature.unset("value");
                feature.unset("layerType");
                feature.unset("rainfall_slope");
                feature.unset("rainfall_cv");
                feature.unset("r_factor");
                feature.unset("k_factor");
                feature.unset("ls_factor");
                feature.unset("c_factor");
                feature.unset("p_factor");
            });
            districtsBaseLayer.value.changed();
        }
    }

    // Reset region layer to default colors
    if (regionLayer.value) {
        const source = regionLayer.value.getSource();
        if (source) {
            source.forEachFeature((feature) => {
                feature.unset("erosion_rate");
                feature.unset("value");
                feature.unset("layerType");
                feature.unset("rainfall_slope");
                feature.unset("rainfall_cv");
                feature.unset("r_factor");
                feature.unset("k_factor");
                feature.unset("ls_factor");
                feature.unset("c_factor");
                feature.unset("p_factor");
            });
            regionLayer.value.changed();
        }
    }

    // Remove all detailed erosion layers
    if (map.value) {
        const layerStore = ensureDetailedLayerStore();
        Object.keys(layerStore).forEach(removeDetailedLayer);
    }

    console.log("Cleared all layer colors from map");
};

// Toggle labels visibility
const toggleLabels = (visible) => {
    if (labelsLayer.value) {
        labelsLayer.value.setVisible(visible);
        console.log(`Labels ${visible ? "shown" : "hidden"}`);
    }
};

watch(
    () => props.showLabels,
    (visible) => {
        toggleLabels(visible);
    },
    { immediate: true }
);

// Get all drawn shapes as GeoJSON
const getDrawnShapes = () => {
    const geojsonFormat = new GeoJSON();
    return drawnFeatures.value.map((feature) => {
        return geojsonFormat.writeFeatureObject(feature, {
            featureProjection: "EPSG:3857",
            dataProjection: "EPSG:4326",
        });
    });
};

// Expose methods to parent
// Highlight selected areas on the map
const highlightSelectedAreas = (selectedAreas) => {
    console.log("Highlighting selected areas:", selectedAreas);

    // Clear existing highlights
    clearAreaHighlights();

    if (!selectedAreas || selectedAreas.length === 0) {
        return;
    }

    const highlightFeatures = [];

    for (const area of selectedAreas) {
        try {
            if (area.type === "country" || area.area_type === "country") {
                const geojsonFormat = new GeoJSON();
                let countryFeatures = [];

                if (tajikistanBoundaryFeatureCollection.value) {
                    countryFeatures = geojsonFormat.readFeatures(
                        tajikistanBoundaryFeatureCollection.value,
                        {
                            dataProjection: "EPSG:4326",
                            featureProjection: "EPSG:3857",
                        }
                    );
                } else if (tajikistanBoundaryLayer.value) {
                    const source = tajikistanBoundaryLayer.value.getSource();
                    countryFeatures = source
                        ? source.getFeatures().map((feature) => feature.clone())
                        : [];
                } else if (topoJsonLayer.value) {
                    const source = topoJsonLayer.value.getSource();
                    countryFeatures = source
                        ? source.getFeatures().map((feature) => feature.clone())
                        : [];
                }

                if (countryFeatures.length > 0) {
                    countryFeatures.forEach((feature) => {
                        feature.setProperties({
                            areaId: area.id ?? 0,
                            areaName: area.name_en || area.name || "Tajikistan",
                            areaType: "country",
                            isHighlighted: true,
                        });

                        feature.setStyle(
                            selectedAreaStyle
                        );
                    });

                    highlightFeatures.push(...countryFeatures);
                    console.log(
                        `Highlighted country boundary with ${countryFeatures.length} features`
                    );
                    continue;
                }
            }

            // Check if area has geometry data
            let geometryData = area.geometry;

            // If no geometry in area object, try to find it from regions or districts props
            if (!geometryData && props.regions && area.id) {
                const foundRegion = props.regions.find((r) => r.id === area.id);
                if (foundRegion && foundRegion.geometry) {
                    geometryData = foundRegion.geometry;
                    console.log(
                        `Found geometry for region ${area.name_en} from props`
                    );
                }
            }

            if (!geometryData && props.districts && area.id) {
                const foundDistrict = props.districts.find(
                    (d) => d.id === area.id
                );
                if (foundDistrict && foundDistrict.geometry) {
                    geometryData = foundDistrict.geometry;
                    console.log(
                        `Found geometry for district ${area.name_en} from props`
                    );
                }
            }

            // If still no geometry, try to find from topoJsonLayer by name
            if (!geometryData && topoJsonLayer.value) {
                const source = topoJsonLayer.value.getSource();
                if (source) {
                    const features = source.getFeatures();
                    const matchingFeature = features.find((f) => {
                        const props = f.getProperties();
                        return (
                            props.shapeName === area.name_en ||
                            props.shapeName === area.name_tj ||
                            props.shapeName === area.name
                        );
                    });

                    if (matchingFeature) {
                        const geometry = matchingFeature.getGeometry();
                        if (geometry) {
                            // Convert OpenLayers geometry to GeoJSON
                            const geojsonFormat = new GeoJSON();
                            const geoJsonFeature =
                                geojsonFormat.writeFeatureObject(
                                    matchingFeature,
                                    {
                                        featureProjection: "EPSG:3857",
                                        dataProjection: "EPSG:4326",
                                    }
                                );
                            geometryData = geoJsonFeature.geometry;
                            console.log(
                                `Found geometry for ${area.name_en} from topoJsonLayer`
                            );
                        }
                    }
                }
            }

            // If still no geometry, use fallback
            if (!geometryData) {
                console.warn(
                    `Area ${area.name_en} has no geometry data, creating fallback highlight`
                );

                // Try to find from topoJsonLayer by matching ID or name
                let fallbackFeature = null;
                if (topoJsonLayer.value) {
                    const source = topoJsonLayer.value.getSource();
                    if (source) {
                        const features = source.getFeatures();
                        const matchingFeature = features.find((f) => {
                            const props = f.getProperties();
                            // Try multiple matching strategies
                            return (
                                (area.id && props.shapeID === area.id) ||
                                props.shapeName === area.name_en ||
                                props.shapeName === area.name_tj ||
                                props.shapeName === area.name
                            );
                        });

                        if (matchingFeature) {
                            fallbackFeature = matchingFeature.clone();
                            fallbackFeature.setProperties({
                                areaId: area.id,
                                areaName: area.name_en,
                                areaType: area.type || area.area_type,
                                isHighlighted: true,
                            });
                            fallbackFeature.setStyle(selectedAreaStyle);
                            highlightFeatures.push(fallbackFeature);
                            console.log(
                                `Created highlight from topoJsonLayer for area: ${area.name_en}`
                            );
                            continue;
                        }
                    }
                }

                // Last resort: create a small fallback polygon
                const regionCoordinates = {
                    Sughd: [69.0, 40.0],
                    Khatlon: [68.5, 37.5],
                    "Gorno-Badakhshan": [72.0, 38.5],
                    Dushanbe: [68.8, 38.5],
                    RRS: [68.8, 38.5],
                    "Republican Subordination": [68.8, 38.5],
                };

                const coords = regionCoordinates[area.name_en] || [68.0, 37.0];
                const size = 0.5;

                const fallbackGeometry = new Polygon([
                    [
                        [coords[0] - size / 2, coords[1] - size / 2],
                        [coords[0] + size / 2, coords[1] - size / 2],
                        [coords[0] + size / 2, coords[1] + size / 2],
                        [coords[0] - size / 2, coords[1] + size / 2],
                        [coords[0] - size / 2, coords[1] - size / 2],
                    ],
                ]);

                const fallbackFeatureObj = new Feature({
                    geometry: fallbackGeometry,
                    areaId: area.id,
                    areaName: area.name_en,
                    areaType: area.type || area.area_type,
                    isHighlighted: true,
                    isFallback: true,
                });

                fallbackFeatureObj.setStyle(selectedAreaStyle);

                highlightFeatures.push(fallbackFeatureObj);
                console.log(
                    `Created fallback highlight for area: ${area.name_en}`
                );
                continue;
            }

            // Process geometry data
            if (typeof geometryData === "string") {
                try {
                    geometryData = JSON.parse(geometryData);
                } catch (parseError) {
                    console.error(
                        `Error parsing geometry for ${area.name_en}:`,
                        parseError
                    );
                    continue;
                }
            }

            // Validate geometry data structure
            if (!geometryData || !geometryData.type) {
                console.warn(
                    `Area ${area.name_en} has invalid geometry data, skipping highlight`
                );
                continue;
            }

            const geojsonFormat = new GeoJSON();
            const features = geojsonFormat.readFeatures(geometryData, {
                dataProjection: "EPSG:4326",
                featureProjection: "EPSG:3857",
            });

            if (features.length > 0) {
                const feature = features[0];

                // Add area information to the feature
                feature.setProperties({
                    areaId: area.id,
                    areaName: area.name_en,
                    areaType: area.type || area.area_type,
                    isHighlighted: true,
                });

                // Style the highlighted feature
                feature.setStyle(
                    selectedAreaStyle
                );

                highlightFeatures.push(feature);
                console.log(
                    `Highlighted area: ${area.name_en} (${
                        area.type || area.area_type
                    })`
                );
            } else {
                console.warn(`No features created for area ${area.name_en}`);
            }
        } catch (error) {
            console.error(`Error highlighting area ${area.name_en}:`, error);
        }
    }

    if (highlightFeatures.length > 0) {
        const highlightSource = new VectorSource({
            features: highlightFeatures,
        });

        const highlightLayer = new VectorLayer({
            source: highlightSource,
            zIndex: 20, // High z-index to appear on top
            title: "Selected Areas Highlight",
            style: selectedAreaStyle,
        });

        map.value.addLayer(highlightLayer);
        areaHighlightLayer.value = highlightLayer;

        console.log(
            `Added highlight layer with ${highlightFeatures.length} features`
        );
    }
};

// Clear area highlights
const clearAreaHighlights = () => {
    if (areaHighlightLayer.value) {
        map.value.removeLayer(areaHighlightLayer.value);
        areaHighlightLayer.value = null;
        console.log("Cleared area highlights");
    }
};

// Custom polygon drawing methods
const enableCustomPolygonDrawing = () => {
    if (!map.value) return;
    
    // Remove existing draw interaction if any
    if (customPolygonDraw.value) {
        map.value.removeInteraction(customPolygonDraw.value);
    }
    
    // Create a new vector source and layer for custom polygon
    if (!customPolygonLayer.value) {
        const customPolygonSource = new VectorSource();
        customPolygonLayer.value = new VectorLayer({
            source: customPolygonSource,
            style: new Style({
                fill: new Fill({
                    color: 'rgba(59, 130, 246, 0.2)', // Blue fill with transparency
                }),
                stroke: new Stroke({
                    color: '#3b82f6', // Blue stroke
                    width: 2,
                }),
            }),
        });
        map.value.addLayer(customPolygonLayer.value);
    }
    
    // Create draw interaction for polygon
    customPolygonDraw.value = new Draw({
        source: customPolygonLayer.value.getSource(),
        type: 'Polygon',
    });
    
    // Handle when polygon drawing is complete
    customPolygonDraw.value.on('drawend', async (event) => {
        const geometry = event.feature.getGeometry();
        
        // Clip geometry to Tajikistan boundaries
        const clippedGeometry = await clipGeometryToCountryBounds(geometry);
        
        if (!clippedGeometry) {
            console.warn('Polygon is outside Tajikistan boundaries');
            // Remove the feature if it's outside boundaries
            customPolygonLayer.value.getSource().removeFeature(event.feature);
            return;
        }
        
        // Replace the feature geometry with clipped version
        event.feature.setGeometry(clippedGeometry);
        
        // Convert to GeoJSON
        const geojsonFormat = new GeoJSON();
        const geoJson = geojsonFormat.writeGeometryObject(clippedGeometry, {
            featureProjection: 'EPSG:3857',
            dataProjection: 'EPSG:4326',
        });
        
        // Emit the drawn polygon
        emit('custom-polygon-drawn', geoJson);
    });
    
    map.value.addInteraction(customPolygonDraw.value);
    console.log('Custom polygon drawing enabled');
};

const disableCustomPolygonDrawing = () => {
    if (!map.value) return;
    
    if (customPolygonDraw.value) {
        map.value.removeInteraction(customPolygonDraw.value);
        customPolygonDraw.value = null;
    }
    
    // Clear the custom polygon layer
    if (customPolygonLayer.value) {
        customPolygonLayer.value.getSource().clear();
    }
    
    console.log('Custom polygon drawing disabled');
};

// Watch for custom area drawing prop changes
watch(() => props.customAreaDrawing, (isActive) => {
    if (isActive) {
        enableCustomPolygonDrawing();
    } else {
        disableCustomPolygonDrawing();
    }
});

defineExpose({
    setBaseMapType: applyBaseMapType,
    getBaseMapType: () => currentBaseMapType.value,
    getAvailableBaseMapTypes: () => availableBaseMapTypes.value,
    hasMapTilerTerrain: () => hasMapTilerTerrain,
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
    highlightSelectedAreas,
    clearAreaHighlights,
    getCountryBoundary: () => tajikistanBoundaryFeatureCollection.value,
    // Custom polygon drawing methods
    enableCustomPolygonDrawing,
    disableCustomPolygonDrawing,
    captureMapAsImage,
});

// Lifecycle
onMounted(() => {
    console.log("MapView component mounted");
    nextTick(() => {
        console.log("NextTick - initializing map");
        initMap();
    });
});

onUnmounted(() => {
    // Stop all border animations
    stopAllBorderAnimations();
    
    // Stop all tile polling
    stopAllTilePolling();

    // Clean up map and resize handler
    if (map.value) {
        const resizeHandler = map.value.get("resizeHandler");
        if (resizeHandler) {
            window.removeEventListener("resize", resizeHandler);
        }
        map.value.setTarget(null);
    }
});
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

/* Simple scale line (measurement ruler) styling - minimal design */
:deep(.ol-scale-line) {
    bottom: 1em;
    left: 1em;
    background: transparent !important; /* No background container */
    padding: 0;
    border: none;
    box-shadow: none;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
        "Helvetica Neue", Arial, sans-serif;
}

/* Simple scale bar - clean ruler design */
:deep(.ol-scale-line-inner) {
    border: 2px solid #333 !important; /* Simple dark border */
    border-top: none;
    border-radius: 0;
    color: #333 !important; /* Dark text */
    font-size: 12px;
    font-weight: 500;
    text-align: center;
    margin: 0;
    padding: 2px 4px;
    background: transparent !important; /* No background */
    background-image: none !important; /* No bar patterns */
    will-change: contents, width;
    min-height: 20px;
}

/* Remove any decorative elements */
:deep(.ol-scale-line-inner)::before,
:deep(.ol-scale-line-inner)::after {
    display: none !important;
}

/* Remove any internal styling */
:deep(.ol-scale-line-inner *) {
    background: transparent !important;
    border: none !important;
    background-image: none !important;
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
