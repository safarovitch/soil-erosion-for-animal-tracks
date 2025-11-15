"""
RUSLE (Revised Universal Soil Loss Equation) Calculator
Implements all RUSLE factors and erosion computation for Tajikistan
"""
import ee
import logging
import math
import numpy as np
import threading
from concurrent.futures import ThreadPoolExecutor, as_completed
from typing import Any, Dict, List, Mapping, Optional, Sequence

from gee_service import gee_service
from rusle_config import RUSLEConfig, build_config

logger = logging.getLogger(__name__)

class TimeoutError(Exception):
    pass

def timeout_wrapper(func, timeout_seconds=600):
    """
    Wrapper to timeout long-running GEE API calls
    """
    result = [None]
    exception = [None]
    
    def target():
        try:
            result[0] = func()
        except Exception as e:
            exception[0] = e
    
    thread = threading.Thread(target=target)
    thread.daemon = True
    thread.start()
    thread.join(timeout_seconds)
    
    if thread.is_alive():
        logger.error(f"Operation timed out after {timeout_seconds} seconds")
        raise TimeoutError(f"Operation timed out after {timeout_seconds} seconds")
    
    if exception[0]:
        raise exception[0]
    
    return result[0]

class RUSLECalculator:
    """RUSLE Calculator for soil erosion estimation"""
    
    LONG_TERM_R_START_YEAR = 1994
    LONG_TERM_R_END_YEAR = 2024  # Exclusive upper bound for filterDate
    FLOW_ACC_GRID_SIZE = 1000  # meters, matches HydroSHEDS 30 arc-second (~927m) res resampled to 1000m (1km) resolution
    
    def __init__(self, config: Optional[Mapping[str, Any]] = None):
        if isinstance(config, RUSLEConfig):
            self.config = config
        else:
            self.config = build_config(config)
        self._initialize_parameters()

    def _initialize_parameters(self) -> None:
        # R-factor parameters
        self.r_factor_params = {
            "coefficient": float(self.config.get("r_factor.coefficient", 0.562)),
            "intercept": float(self.config.get("r_factor.intercept", -8.12)),
            "long_term_start_year": int(
                self.config.get("r_factor.long_term_start_year", self.LONG_TERM_R_START_YEAR)
            ),
            "long_term_end_year": int(
                self.config.get("r_factor.long_term_end_year", self.LONG_TERM_R_END_YEAR)
            ),
            "use_long_term_default": bool(
                self.config.get("r_factor.use_long_term_default", True)
            ),
        }

        # K-factor parameters
        self.k_factor_params = {
            "sand_fraction_multiplier": float(
                self.config.get("k_factor.sand_fraction_multiplier", 0.2)
            ),
            "soc_to_organic_multiplier": float(
                self.config.get("k_factor.soc_to_organic_multiplier", 0.01724)
            ),
            "base_constant": float(self.config.get("k_factor.base_constant", 27.66)),
            "m_exponent": float(self.config.get("k_factor.m_exponent", 1.14)),
            "area_factor": float(self.config.get("k_factor.area_factor", 1e-8)),
            "organic_matter_subtract": float(
                self.config.get("k_factor.organic_matter_subtract", 12.0)
            ),
            "structure_coefficient": float(
                self.config.get("k_factor.structure_coefficient", 0.0043)
            ),
            "structure_baseline": float(
                self.config.get("k_factor.structure_baseline", 2.0)
            ),
            "permeability_coefficient": float(
                self.config.get("k_factor.permeability_coefficient", 0.0033)
            ),
            "permeability_baseline": float(
                self.config.get("k_factor.permeability_baseline", 3.0)
            ),
        }

        # LS-factor parameters
        self.flow_acc_grid_size = int(
            self.config.get("ls_factor.grid_size", self.FLOW_ACC_GRID_SIZE)
        )
        self.ls_factor_params = {
            "flow_length_reference": float(
                self.config.get("ls_factor.flow_length_reference", 22.13)
            ),
            "flow_exponent": float(self.config.get("ls_factor.flow_exponent", 0.4)),
            "slope_normalisation": float(
                self.config.get("ls_factor.slope_normalisation", 0.0896)
            ),
            "slope_exponent": float(
                self.config.get("ls_factor.slope_exponent", 1.3)
            ),
            "minimum_slope_radians": float(
                self.config.get("ls_factor.minimum_slope_radians", 0.0001)
            ),
        }

        # C-factor lookup
        class_map = self.config.get("c_factor.class_map", {})
        if isinstance(class_map, Mapping) and class_map:
            sorted_classes = sorted(
                (
                    (int(class_id), float(value))
                    for class_id, value in class_map.items()
                ),
                key=lambda item: item[0],
            )
        else:
            sorted_classes = [
                (1, 0.05),
                (2, 0.05),
                (3, 0.05),
                (4, 0.05),
                (5, 0.05),
                (6, 0.1),
                (7, 0.1),
                (8, 0.05),
                (9, 0.1),
                (10, 0.1),
                (11, 0.0),
                (12, 0.15),
                (13, 0.01),
                (14, 0.15),
                (15, 0.0),
                (16, 0.4),
                (17, 0.0),
            ]
        self.c_factor_classes: List[int] = [item[0] for item in sorted_classes]
        self.c_factor_values: List[float] = [item[1] for item in sorted_classes]
        self.c_factor_default = float(self.config.get("c_factor.default_value", 0.0))

        # P-factor parameters
        self.p_factor_default = float(self.config.get("p_factor.default_value", 1.0))
        self.p_factor_cropland_class = int(
            self.config.get("p_factor.cropland_class", 12)
        )
        self.p_factor_segments = self._prepare_p_factor_segments(
            self.config.get("p_factor.breakpoints", [])
        )

        # Soil loss clamp
        self.soil_loss_clamp_min = float(
            self.config.get("soil_loss.clamp_min", 0.0)
        )
        self.soil_loss_clamp_max = float(
            self.config.get("soil_loss.clamp_max", 200.0)
        )

        # Erosion class definitions
        self.erosion_classes = self._prepare_erosion_classes(
            self.config.get("erosion_classes", [])
        )
        self.erosion_class_labels = {cls["key"]: cls["label"] for cls in self.erosion_classes}

        # Rainfall statistics parameters
        self.rainfall_mean_scale = float(
            self.config.get("rainfall_statistics.mean_scale", 5000)
        )
        self.rainfall_cv_scale = float(
            self.config.get("rainfall_statistics.cv_scale", 5000)
        )
        self.rainfall_trend_rules = self._prepare_trend_rules(
            self.config.get("rainfall_statistics.trend_interpretation", [])
        )
        self.rainfall_cv_rules = self._prepare_cv_rules(
            self.config.get("rainfall_statistics.cv_interpretation", [])
        )

        self.include_config_snapshot = bool(
            self.config.get("logging.include_config_snapshot", True)
        )

    def _prepare_p_factor_segments(
        self, raw_breakpoints: Optional[Sequence[Mapping[str, Any]]]
    ) -> List[Dict[str, Optional[float]]]:
        default_breakpoints = [
            {"max": 5.0, "value": 0.10},
            {"max": 10.0, "value": 0.12},
            {"max": 20.0, "value": 0.14},
            {"max": 30.0, "value": 0.19},
            {"max": 50.0, "value": 0.25},
            {"max": 100.0, "value": 0.33},
            {"max": None, "value": 0.33},
        ]

        if not raw_breakpoints:
            raw_breakpoints = []

        finite_segments: List[Dict[str, float]] = []
        infinite_segments: List[Dict[str, Optional[float]]] = []
        for entry in raw_breakpoints:
            value = entry.get("value")
            try:
                value = float(value)
            except (TypeError, ValueError):
                continue

            max_slope = entry.get("max_slope")
            if max_slope is None:
                infinite_segments.append({"max": None, "value": value})
                continue

            try:
                finite_segments.append({"max": float(max_slope), "value": value})
            except (TypeError, ValueError):
                continue

        if not finite_segments and not infinite_segments:
            finite_segments = default_breakpoints[:-1]  # type: ignore[assignment]
            infinite_segments = [default_breakpoints[-1]]  # type: ignore[list-item]

        finite_segments.sort(key=lambda item: item["max"])

        segments: List[Dict[str, Optional[float]]] = []
        previous_max: Optional[float] = None
        for entry in finite_segments + infinite_segments:
            max_value = entry["max"]
            segments.append(
                {
                    "min": previous_max,
                    "max": max_value,
                    "value": float(entry["value"]),
                }
            )
            previous_max = max_value
        return segments

    def _prepare_erosion_classes(
        self, classes: Optional[Sequence[Mapping[str, Any]]]
    ) -> List[Dict[str, Any]]:
        default_classes = [
            {"key": "very_low", "label": "Very Low", "min": 0.0, "max": 5.0},
            {"key": "low", "label": "Low", "min": 5.0, "max": 15.0},
            {"key": "moderate", "label": "Moderate", "min": 15.0, "max": 30.0},
            {"key": "severe", "label": "Severe", "min": 30.0, "max": 50.0},
            {"key": "excessive", "label": "Excessive", "min": 50.0, "max": None},
        ]

        processed: List[Dict[str, Any]] = []
        if classes:
            for entry in classes:
                key = entry.get("key")
                if not key:
                    continue
                processed.append(
                    {
                        "key": str(key),
                        "label": str(entry.get("label", key)),
                        "min": float(entry.get("min", 0.0)),
                        "max": (
                            float(entry["max"])
                            if entry.get("max") is not None
                            else None
                        ),
                    }
                )
        if not processed:
            processed = default_classes
        processed.sort(key=lambda item: item["min"])
        return processed

    def _prepare_trend_rules(
        self, rules: Optional[Sequence[Mapping[str, Any]]]
    ) -> List[Dict[str, Any]]:
        default_rules = [
            {"min": 2.0, "label": "Significant increasing trend"},
            {"min": 0.5, "label": "Moderate increasing trend"},
            {"min": -0.5, "label": "Stable/No significant trend"},
            {"min": -2.0, "label": "Moderate decreasing trend"},
            {"min": None, "label": "Significant decreasing trend"},
        ]

        processed: List[Dict[str, Any]] = []
        if rules:
            for entry in rules:
                label = entry.get("label")
                if label is None:
                    continue
                min_value = entry.get("min")
                if min_value is None:
                    processed.append({"min": None, "label": str(label)})
                else:
                    try:
                        processed.append({"min": float(min_value), "label": str(label)})
                    except (TypeError, ValueError):
                        continue
        if not processed:
            processed = default_rules
        processed.sort(
            key=lambda item: float("-inf")
            if item["min"] is None
            else item["min"],
            reverse=True,
        )
        return processed

    def _prepare_cv_rules(
        self, rules: Optional[Sequence[Mapping[str, Any]]]
    ) -> List[Dict[str, Any]]:
        default_rules = [
            {"max": 10.0, "label": "Very low variability"},
            {"max": 20.0, "label": "Low variability"},
            {"max": 30.0, "label": "Moderate variability"},
            {"max": 40.0, "label": "High variability"},
            {"max": None, "label": "Very high variability"},
        ]

        processed: List[Dict[str, Any]] = []
        if rules:
            for entry in rules:
                label = entry.get("label")
                if label is None:
                    continue
                max_value = entry.get("max")
                if max_value is None:
                    processed.append({"max": None, "label": str(label)})
                else:
                    try:
                        processed.append({"max": float(max_value), "label": str(label)})
                    except (TypeError, ValueError):
                        continue
        if not processed:
            processed = default_rules
        processed.sort(
            key=lambda item: float("inf") if item["max"] is None else item["max"]
        )
        return processed

    def _interpret_trend(self, value: float) -> str:
        for rule in self.rainfall_trend_rules:
            threshold = rule["min"]
            if threshold is None or value >= threshold:
                return rule["label"]
        return self.rainfall_trend_rules[-1]["label"]

    def _interpret_cv(self, value: float) -> str:
        for rule in self.rainfall_cv_rules:
            threshold = rule["max"]
            if threshold is None or value < threshold:
                return rule["label"]
        return self.rainfall_cv_rules[-1]["label"]

    def config_snapshot(self) -> Dict[str, Any]:
        return self.config.to_dict()
    
    @staticmethod
    def _clip_image(image, geometry):
        if geometry:
            return image.clip(geometry)
        return image
    
    @staticmethod
    def _filter_collection(collection, geometry):
        if geometry:
            return collection.filterBounds(geometry)
        return collection
    
    def _load_modis_landcover(self, year, geometry):
        """Load MODIS land cover image for supplied year, clipped to geometry"""
        start_date = f'{year}-01-01'
        end_date = f'{year}-12-31'
        collection = ee.ImageCollection('MODIS/061/MCD12Q1') \
            .filterDate(start_date, end_date)
        collection = self._filter_collection(collection, geometry)
        
        # Fallback to the provided GEE code default (2023) if collection is empty
        land_cover_image = ee.Image(ee.Algorithms.If(
            collection.size().gt(0),
            collection.first(),
            ee.Image('MODIS/061/MCD12Q1/2023_01_01')
        ))
        
        land_cover_image = land_cover_image.select('LC_Type1')
        return self._clip_image(land_cover_image, geometry)
    
    def compute_r_factor(self, year, geometry=None, use_long_term=True):
        """
        Compute R-Factor (Rainfall Erosivity)
        Using CHIRPS precipitation data
        """
        try:
            params = self.r_factor_params
            if use_long_term and params["use_long_term_default"]:
                start_year = params["long_term_start_year"]
                end_year = params["long_term_end_year"]
                start_date = f'{start_year}-01-01'
                end_date = f'{end_year}-01-01'
                years = max(1, end_year - start_year)
            else:
                start_date = f'{year}-01-01'
                end_date = f'{year + 1}-01-01'
                years = 1
            
            # Load CHIRPS precipitation data
            chirps = ee.ImageCollection('UCSB-CHG/CHIRPS/DAILY') \
                .filterDate(start_date, end_date) \
                .select('precipitation')
            chirps = self._filter_collection(chirps, geometry)
            
            # Calculate annual precipitation
            total_precip = chirps.sum().toFloat()
            mean_precip = total_precip.divide(years)
            
            # Linear R-factor approximation from GEE workflow
            r_factor = mean_precip.multiply(params["coefficient"]).add(params["intercept"])
            r_factor = r_factor.max(0)
            r_factor = self._clip_image(r_factor, geometry)
            
            return r_factor.rename('R_factor')
            
        except Exception as e:
            logger.error(f"Failed to compute R-factor: {str(e)}")
            raise
    
    def compute_r_factor_range(self, start_year, end_year, geometry=None):
        """
        Compute R-Factor using mean annual rainfall over a multi-year range.
        Args:
            start_year: Inclusive start year
            end_year: Inclusive end year (must be >= start_year)
            geometry: Optional geometry to clip/filter collections
        """
        if end_year < start_year:
            raise ValueError("end_year must be greater than or equal to start_year")
        
        try:
            start_date = f'{start_year}-01-01'
            end_date = f'{end_year + 1}-01-01'
            years = max(1, (end_year - start_year + 1))
            
            chirps = ee.ImageCollection('UCSB-CHG/CHIRPS/DAILY') \
                .filterDate(start_date, end_date) \
                .select('precipitation')
            chirps = self._filter_collection(chirps, geometry)
            
            total_precip = chirps.sum().toFloat()
            mean_precip = total_precip.divide(years)
            
            params = self.r_factor_params
            r_factor = mean_precip.multiply(params["coefficient"]).add(params["intercept"])
            r_factor = r_factor.max(0)
            r_factor = self._clip_image(r_factor, geometry)
            
            return r_factor.rename('R_factor')
        except Exception as e:
            logger.error(f"Failed to compute range-based R-factor: {str(e)}")
            raise
    
    def compute_k_factor(self, geometry=None, structure_code=2, permeability_code=3):
        """
        Compute K-Factor (Soil Erodibility)
        Using OpenLandMap soil fraction data
        """
        try:
            clay = ee.Image("OpenLandMap/SOL/SOL_CLAY-WFRACTION_USDA-3A1A1A_M/v02").select('b0')
            sand = ee.Image("OpenLandMap/SOL/SOL_SAND-WFRACTION_USDA-3A1A1A_M/v02").select('b0')
            organic_carbon = ee.Image("OpenLandMap/SOL/SOL_ORGANIC-CARBON_USDA-6A1C_M/v02").select('b0')
            
            clay = self._clip_image(clay, geometry)
            sand = self._clip_image(sand, geometry).multiply(
                self.k_factor_params["sand_fraction_multiplier"]
            )
            organic_carbon = self._clip_image(organic_carbon, geometry)
            
            # Convert SOC (%) to organic matter (%)
            organic_matter = organic_carbon.multiply(
                self.k_factor_params["soc_to_organic_multiplier"]
            )
            
            # Derive silt as residual
            silt = ee.Image.constant(100).subtract(sand).subtract(clay)
            
            # Compute M parameter
            M = silt.add(sand.multiply(ee.Image.constant(100).subtract(clay)))
            
            base_k = ee.Image(self.k_factor_params["base_constant"]) \
                .multiply(M.pow(self.k_factor_params["m_exponent"])) \
                .multiply(self.k_factor_params["area_factor"]) \
                .multiply(
                    ee.Image(self.k_factor_params["organic_matter_subtract"]).subtract(organic_matter)
                )
            
            k_factor = base_k \
                .add(
                    ee.Image(self.k_factor_params["structure_coefficient"]).multiply(
                        ee.Image(structure_code).subtract(self.k_factor_params["structure_baseline"])
                    )
                ) \
                .add(
                    ee.Image(self.k_factor_params["permeability_coefficient"]).multiply(
                        ee.Image(permeability_code).subtract(self.k_factor_params["permeability_baseline"])
                    )
                )
            
            k_factor = k_factor.max(0)
            k_factor = self._clip_image(k_factor, geometry)
            
            return k_factor.rename('K_factor')
            
        except Exception as e:
            logger.error(f"Failed to compute K-factor: {str(e)}")
            raise
    
    def compute_ls_factor(self, geometry=None, grid_size=None):
        """
        Compute LS-Factor (Slope Length and Steepness)
        Using SRTM DEM and HydroSHEDS flow accumulation
        """
        try:
            params = self.ls_factor_params
            grid_size = grid_size or self.flow_acc_grid_size
            
            dem = ee.Image('USGS/SRTMGL1_003').select('elevation')
            dem = self._clip_image(dem, geometry)
            
            slope_deg = ee.Terrain.slope(dem)
            slope_rad = slope_deg.multiply(math.pi / 180.0)
            slope_rad = slope_rad.where(
                slope_rad.eq(0),
                params["minimum_slope_radians"]
            )
            sin_slope = slope_rad.sin()
            
            flow_acc = ee.Image("WWF/HydroSHEDS/30ACC")
            flow_acc = self._clip_image(flow_acc, geometry)
            flow_acc = flow_acc.where(flow_acc.eq(0), 1)
            
            ls_factor = flow_acc.multiply(grid_size) \
                .divide(params["flow_length_reference"]) \
                .pow(params["flow_exponent"]) \
                .multiply(
                    sin_slope.divide(params["slope_normalisation"]).pow(
                        params["slope_exponent"]
                    )
                )
            
            ls_factor = ls_factor.max(0)
            ls_factor = self._clip_image(ls_factor, geometry)
            
            return ls_factor.rename('LS_factor')
            
        except Exception as e:
            logger.error(f"Failed to compute LS-factor: {str(e)}")
            raise
    
    def compute_c_factor(self, year, geometry=None):
        """
        Compute C-Factor (Cover Management)
        Using MODIS land cover mapping
        """
        try:
            land_cover = self._load_modis_landcover(year, geometry)
            
            # Remap MODIS IGBP classes to C-factor values (based on provided GEE script)
            c_factor = land_cover.remap(
                self.c_factor_classes,
                self.c_factor_values,
                self.c_factor_default
            ).rename('C_factor')
            c_factor = self._clip_image(c_factor, geometry)
            
            return c_factor.rename('C_factor')
            
        except Exception as e:
            logger.error(f"Failed to compute C-factor: {str(e)}")
            raise
    
    def compute_p_factor(self, year, geometry=None):
        """
        Compute P-Factor (Conservation Practice)
        Using MODIS land cover with slope-dependent coefficients for cropland
        """
        try:
            land_cover = self._load_modis_landcover(year, geometry)
            
            dem = ee.Image('USGS/SRTMGL1_003').select('elevation')
            dem = self._clip_image(dem, geometry)
            slope_deg = ee.Terrain.slope(dem)
            
            p_factor = ee.Image.constant(self.p_factor_default)
            p_factor = self._clip_image(p_factor, geometry)
            
            cropland = land_cover.eq(self.p_factor_cropland_class)
            for segment in self.p_factor_segments:
                condition = cropland
                min_slope = segment["min"]
                max_slope = segment["max"]
                if min_slope is not None:
                    condition = condition.And(slope_deg.gt(min_slope))
                if max_slope is not None:
                    condition = condition.And(slope_deg.lte(max_slope))
                p_factor = p_factor.where(condition, segment["value"])
            
            return p_factor.rename('P_factor')
            
        except Exception as e:
            logger.error(f"Failed to compute P-factor: {str(e)}")
            raise
    
    def compute_rusle(self, year, geometry, scale=1000, compute_stats=True, r_factor_image=None):
        """
        Compute full RUSLE erosion rate
        A = R * K * LS * C * P
        Returns erosion rate in t/ha/yr
        
        Args:
            year: Year for computation
            geometry: Area geometry
            scale: Resolution in meters (default 1000m/1km)
            compute_stats: Whether to compute statistics (can be slow for large areas)
        """
        try:
            logger.info(f"Computing RUSLE for year {year} at {scale}m resolution")
            
            # Compute all factors
            if r_factor_image is not None:
                r_factor = self._clip_image(r_factor_image, geometry)
            else:
                r_factor = self.compute_r_factor(year, geometry)
            k_factor = self.compute_k_factor(geometry)
            ls_factor = self.compute_ls_factor(geometry)
            c_factor = self.compute_c_factor(year, geometry)
            p_factor = self.compute_p_factor(year, geometry)
            
            # Calculate soil loss: A = R * K * LS * C * P
            soil_loss = r_factor.multiply(k_factor) \
                .multiply(ls_factor) \
                .multiply(c_factor) \
                .multiply(p_factor) \
                .clamp(self.soil_loss_clamp_min, self.soil_loss_clamp_max)
            
            # Ensure single band output (select first band if multiple exist)
            soil_loss = soil_loss.select([0])
            soil_loss = soil_loss.rename('soil_loss')
            
            # Compute statistics only if requested (can be slow for large areas)
            if compute_stats:
                logger.info(f"  Computing statistics at {scale}m scale...")
                stats = gee_service.compute_statistics(soil_loss, geometry, scale=scale)
                
                # Helper function to safely round values, handling None
                def safe_round(value, decimals=2):
                    if value is None:
                        return 0.0
                    try:
                        return round(float(value), decimals)
                    except (TypeError, ValueError):
                        return 0.0
                
                return {
                    'image': soil_loss,
                    'statistics': {
                        'mean': safe_round(stats.get('soil_loss_mean', 0)),
                        'min': safe_round(stats.get('soil_loss_min', 0)),
                        'max': safe_round(stats.get('soil_loss_max', 0)),
                        'std_dev': safe_round(stats.get('soil_loss_stdDev', 0))
                    }
                }
            else:
                # Return without statistics for faster processing
                return {
                    'image': soil_loss,
                    'statistics': None
                }
            
        except Exception as e:
            logger.error(f"Failed to compute RUSLE: {str(e)}")
            raise
    
    def compute_rainfall_statistics(self, start_year, end_year, geometry, scale: Optional[int] = None):
        """
        Compute rainfall statistics for a multi-year range.
        Returns:
            dict containing:
                - mean_annual_rainfall_mm
                - trend_mm_per_year (slope)
                - coefficient_of_variation_percent
                - yearly_totals_mm (list of {year, mean_precip})
        """
        if geometry is None:
            raise ValueError("geometry is required to compute rainfall statistics")
        if end_year < start_year:
            raise ValueError("end_year must be greater than or equal to start_year")
        
        try:
            base_scale = self.rainfall_mean_scale
            requested_scale = scale if scale is not None else base_scale
            analysis_scale = max(requested_scale, base_scale)

            years_sequence = ee.List.sequence(start_year, end_year)
            
            def annual_total(year):
                year = ee.Number(year)
                start = ee.Date.fromYMD(year, 1, 1)
                end = start.advance(1, 'year')
                
                collection = ee.ImageCollection('UCSB-CHG/CHIRPS/DAILY') \
                    .filterDate(start, end) \
                    .select('precipitation')
                collection = self._filter_collection(collection, geometry)
                
                total = collection.sum().toFloat()
                return total.set('year', year)
            
            annual_collection = ee.ImageCollection(years_sequence.map(annual_total))
            
            def image_to_feature(image):
                reduction = image.reduceRegion(
                    reducer=ee.Reducer.mean(),
                    geometry=geometry,
                    scale=analysis_scale,
                    bestEffort=True,
                    maxPixels=1e13
                )
                return ee.Feature(None, {
                    'year': image.get('year'),
                    'mean_precip': reduction.get('precipitation')
                })
            
            rainfall_series = ee.FeatureCollection(annual_collection.map(image_to_feature)).getInfo()
            features = rainfall_series.get('features', []) if rainfall_series else []
            
            yearly_values = []
            for feature in features:
                properties = feature.get('properties', {})
                year = properties.get('year')
                mean_precip = properties.get('mean_precip')
                if year is None or mean_precip is None:
                    continue
                try:
                    yearly_values.append({
                        'year': int(round(year)),
                        'mean_precip': float(mean_precip)
                    })
                except (TypeError, ValueError):
                    continue
            
            yearly_values.sort(key=lambda item: item['year'])
            
            if not yearly_values:
                return {
                    'mean_annual_rainfall_mm': 0.0,
                    'trend_mm_per_year': 0.0,
                    'coefficient_of_variation_percent': 0.0,
                    'trend_interpretation': self._interpret_trend(0.0),
                    'variability_interpretation': self._interpret_cv(0.0),
                    'analysis_scale_m': analysis_scale,
                    'yearly_totals_mm': []
                }
            
            years = np.array([item['year'] for item in yearly_values], dtype=float)
            rainfall_vals = np.array([item['mean_precip'] for item in yearly_values], dtype=float)
            
            mean_rainfall = float(np.mean(rainfall_vals))
            std_rainfall = float(np.std(rainfall_vals))
            cv_percent = float((std_rainfall / mean_rainfall) * 100) if mean_rainfall > 0 else 0.0
            
            trend_slope = 0.0
            if years.size >= 2:
                slope, _ = np.polyfit(years, rainfall_vals, 1)
                trend_slope = float(slope)
            
            return {
                'mean_annual_rainfall_mm': round(mean_rainfall, 2),
                'trend_mm_per_year': round(trend_slope, 4),
                'coefficient_of_variation_percent': round(cv_percent, 2),
                'trend_interpretation': self._interpret_trend(trend_slope),
                'variability_interpretation': self._interpret_cv(cv_percent),
                'analysis_scale_m': analysis_scale,
                'yearly_totals_mm': yearly_values
            }
        except Exception as e:
            logger.error(f"Failed to compute rainfall statistics: {str(e)}", exc_info=True)
            raise
    
    def compute_erosion_class_breakdown(self, soil_loss_image, geometry, scale=1000):
        """
        Compute percentage area for predefined erosion classes.
        Classes:
            - very_low: 0–5 t/ha/yr
            - low: 5–15 t/ha/yr
            - moderate: 15–30 t/ha/yr
            - severe: 30–50 t/ha/yr
            - excessive: >50 t/ha/yr
        Returns dict with percentages and area (hectares) per class.
        """
        if geometry is None:
            raise ValueError("geometry is required to compute erosion class breakdown")
        
        try:
            pixel_area = ee.Image.pixelArea().rename('area')
            valid_mask = soil_loss_image.gte(0)
            total_area = ee.Number(
                pixel_area.updateMask(valid_mask).reduceRegion(
                    reducer=ee.Reducer.sum(),
                    geometry=geometry,
                    scale=scale,
                    bestEffort=True,
                    maxPixels=1e13
                ).get('area')
            )
            
            def class_area(lower, upper):
                mask = soil_loss_image.gte(lower)
                if upper is not None:
                    mask = mask.And(soil_loss_image.lt(upper))
                return ee.Number(
                    pixel_area.updateMask(mask).reduceRegion(
                        reducer=ee.Reducer.sum(),
                        geometry=geometry,
                        scale=scale,
                        bestEffort=True,
                        maxPixels=1e13
                    ).get('area')
                )
            
            class_areas = {'total': total_area}
            for cls in self.erosion_classes:
                class_areas[cls['key']] = class_area(cls['min'], cls['max'])
            areas_dict = ee.Dictionary(class_areas).getInfo()
            
            total_area_m2 = float(areas_dict.get('total') or 0.0)
            if total_area_m2 <= 0:
                result = {}
                for cls in self.erosion_classes:
                    result[cls['key']] = {
                        'label': cls['label'],
                        'percentage': 0.0,
                        'area_hectares': 0.0
                    }
                result['total_area_hectares'] = 0.0
                return result
            
            def to_output(key: str, label: str):
                area_m2 = float(areas_dict.get(key) or 0.0)
                area_ha = area_m2 / 10000.0
                percentage = (area_m2 / total_area_m2) * 100.0 if total_area_m2 > 0 else 0.0
                return {
                    'label': label,
                    'percentage': round(percentage, 2),
                    'area_hectares': round(area_ha, 2)
                }
            
            output = {
                cls['key']: to_output(cls['key'], cls['label'])
                for cls in self.erosion_classes
            }
            output['total_area_hectares'] = round(total_area_m2 / 10000.0, 2)
            return output
        except Exception as e:
            logger.error(f"Failed to compute erosion class breakdown: {str(e)}", exc_info=True)
            raise
    
    def compute_detailed_grid(self, year, geometry, grid_size=10, bbox=None, geojson=None):
        """
        Compute detailed erosion grid for visualization
        Returns cell-by-cell erosion data
        OPTIMIZED: Uses adaptive parameters based on geometry complexity
        
        Args:
            bbox: Optional pre-calculated bbox as [minLon, minLat, maxLon, maxLat]
            geojson: Optional original GeoJSON for complexity analysis
        """
        try:
            logger.info(f"Computing detailed grid for year {year}, grid_size={grid_size}")
            
            # OPTIMIZATION 1: Analyze geometry complexity
            logger.info("  Step 1/5: Analyzing geometry complexity...")
            if geojson:
                complexity = gee_service.analyze_geometry_complexity(geojson, geometry)
                logger.info(f"    Complexity: {complexity['complexity_level']} "
                          f"({complexity['coord_count']} coords, {complexity['area_km2']} km²)")
                
                # Use recommended parameters
                params = complexity['recommended']
                simplify_tolerance = params['simplify_tolerance']
                rusle_scale = params['rusle_scale']
                sample_scale = params['sample_scale']
                recommended_grid = params['grid_size']
                max_samples = params['max_samples']
                batch_size = params['batch_size']
                max_workers = params['max_workers']
                
                # Use smaller grid if recommended and not overridden
                if grid_size == 10:  # If using default
                    grid_size = recommended_grid
                    logger.info(f"    Adjusted grid_size: {grid_size}x{grid_size} (optimized for area size)")
                
                logger.info(f"    Optimization parameters:")
                logger.info(f"      - Simplify tolerance: {simplify_tolerance}m")
                logger.info(f"      - RUSLE scale: {rusle_scale}m")
                logger.info(f"      - Sample scale: {sample_scale}m")
                logger.info(f"      - Max samples: {max_samples}")
                logger.info(f"      - Batch size: {batch_size}, Workers: {max_workers}")
            else:
                # Use default optimized parameters
                from config import Config
                simplify_tolerance = 1000
                rusle_scale = 150
                sample_scale = 100
                max_samples = Config.MAX_SAMPLES_LARGE_AREA
                batch_size = Config.BATCH_SIZE_OPTIMIZED
                max_workers = Config.MAX_WORKERS_OPTIMIZED
                logger.info("    Using default optimized parameters")
            
            # OPTIMIZATION 2: Simplify geometry BEFORE computation
            logger.info(f"  Step 2/5: Simplifying geometry (tolerance: {simplify_tolerance}m)...")
            simplified_geometry = geometry.simplify(maxError=simplify_tolerance)
            logger.info("    ✓ Geometry simplified")
            
            # OPTIMIZATION 3: Compute RUSLE with adaptive scale
            logger.info(f"  Step 3/5: Computing RUSLE soil loss image (scale: {rusle_scale}m)...")
            rusle_result = self.compute_rusle(year, simplified_geometry, scale=rusle_scale, compute_stats=False)
            soil_loss_image = rusle_result['image']
            
            # Get bounding box (use pre-calculated if provided)
            logger.info("  Step 4/5: Calculating bounding box...")
            if bbox and len(bbox) == 4:
                logger.info(f"    Using pre-calculated bbox: {bbox}")
                bbox_dict = {
                    'min_lon': bbox[0],
                    'min_lat': bbox[1],
                    'max_lon': bbox[2],
                    'max_lat': bbox[3]
                }
            else:
                logger.info("    Calling GEE to calculate bbox...")
                bbox_dict = gee_service.calculate_bbox(simplified_geometry)
            
            # Use the bbox dict
            bbox = bbox_dict
            
            # Calculate cell dimensions
            lon_range = bbox['max_lon'] - bbox['min_lon']
            lat_range = bbox['max_lat'] - bbox['min_lat']
            cell_width = lon_range / grid_size
            cell_height = lat_range / grid_size
            
            logger.info(f"  Step 5/5: Creating {grid_size}x{grid_size} grid and sampling erosion values...")
            
            # Create ALL grid cell geometries as Earth Engine objects (client-side, no API calls)
            grid_cells = []
            for i in range(grid_size):
                for j in range(grid_size):
                    min_lon = bbox['min_lon'] + (i * cell_width)
                    max_lon = min_lon + cell_width
                    min_lat = bbox['min_lat'] + (j * cell_height)
                    max_lat = min_lat + cell_height
                    
                    # Create cell geometry (EE object, not fetched yet)
                    cell_geom = ee.Geometry.Rectangle([min_lon, min_lat, max_lon, max_lat])
                    clipped_cell = cell_geom.intersection(simplified_geometry, ee.ErrorMargin(1))
                    
                    grid_cells.append({
                        'x': i,
                        'y': j,
                        'geometry': clipped_cell,
                        'bbox': [min_lon, min_lat, max_lon, max_lat]
                    })
            
            # OPTIMIZATION 4: Smart sampling - limit to max_samples for large areas
            total_cells = len(grid_cells)
            logger.info(f"    Created {total_cells} cells")
            
            try:
                # Create sample points at cell centers
                point_features = []
                for idx, cell in enumerate(grid_cells):
                    bbox_cell = cell['bbox']
                    center_lon = (bbox_cell[0] + bbox_cell[2]) / 2
                    center_lat = (bbox_cell[1] + bbox_cell[3]) / 2
                    point = ee.Geometry.Point([center_lon, center_lat])
                    
                    # Create a feature with the cell index
                    feature = ee.Feature(point, {'cell_idx': idx})
                    point_features.append(feature)
                
                # Create a FeatureCollection with all points and filter to region
                points_fc = ee.FeatureCollection(point_features)
                points_in_region = points_fc.filterBounds(simplified_geometry)
                
                # OPTIMIZATION: Limit samples for very large areas
                sample_limit = min(total_cells, max_samples)
                logger.info(f"    Sampling {sample_limit} points (optimized from {total_cells} cells)")
                logger.info(f"    Using batch_size={batch_size}, workers={max_workers}")
                
                def sample_batch(batch_start, batch_end):
                    """Sample a batch of points in parallel"""
                    try:
                        batch_fc = points_in_region.toList(batch_end - batch_start, batch_start)
                        batch_fc = ee.FeatureCollection(batch_fc)
                        
                        sample_result = soil_loss_image.sampleRegions(
                            collection=batch_fc,
                            scale=sample_scale,  # Use adaptive scale
                            geometries=False
                        ).getInfo()
                        
                        return sample_result.get('features', [])
                    except Exception as e:
                        logger.warning(f"    Batch {batch_start}-{batch_end} failed: {str(e)}")
                        return []
                
                # Execute parallel sampling with optimized parameters
                all_samples = []
                with ThreadPoolExecutor(max_workers=max_workers) as executor:
                    futures = []
                    for batch_start in range(0, sample_limit, batch_size):
                        batch_end = min(batch_start + batch_size, sample_limit)
                        future = executor.submit(sample_batch, batch_start, batch_end)
                        futures.append(future)
                    
                    # Collect results as they complete
                    for future in as_completed(futures):
                        try:
                            batch_samples = future.result(timeout=45)  # Reduced timeout
                            all_samples.extend(batch_samples)
                            logger.info(f"    ✓ Batch complete ({len(all_samples)} total samples so far)")
                        except Exception as e:
                            logger.warning(f"    Batch failed: {str(e)}")
                
                samples = {'features': all_samples}
                logger.info(f"    ✓ Received {len(all_samples)} samples from GEE (parallel, optimized)")
                
            except Exception as e:
                logger.error(f"    ✗ Failed to sample regions: {str(e)}")
                samples = None
            
            # If sampling returns no features, fall back to simpler approach
            if not samples or 'features' not in samples or len(samples['features']) == 0:
                logger.warning("  Sampling returned no results, using center point sampling...")
                # Sample each center point individually
                erosion_values_dict = {}
                for idx, cell in enumerate(grid_cells):
                    bbox_cell = cell['bbox']
                    center_lon = (bbox_cell[0] + bbox_cell[2]) / 2
                    center_lat = (bbox_cell[1] + bbox_cell[3]) / 2
                    point = ee.Geometry.Point([center_lon, center_lat])
                    
                    # Check if point is within the geometry
                    if idx < 5:  # Only sample first 5 for speed
                        try:
                            sample = soil_loss_image.sample(point, 30).first().getInfo()
                            if sample and 'properties' in sample:
                                erosion_values_dict[idx] = sample['properties'].get('soil_loss', 0)
                        except:
                            pass
                
                # Don't use default values - only return cells with actual data
                # This ensures we only show cells inside the region
                pass  # erosion_values_dict already has the sampled values
            else:
                # Extract erosion values from samples using cell_idx
                erosion_values_dict = {}
                for feature in samples['features']:
                    if 'properties' in feature:
                        cell_idx = feature['properties'].get('cell_idx')
                        soil_loss = feature['properties'].get('soil_loss', 0)
                        if cell_idx is not None:
                            erosion_values_dict[cell_idx] = soil_loss
            
            # Build result cells (only include cells with erosion data - these are inside the region)
            cells = []
            erosion_values = []
            
            for idx, cell in enumerate(grid_cells):
                erosion_rate = erosion_values_dict.get(idx, 0)
                
                # Only include cells with valid erosion data (sampling automatically filters to region)
                if erosion_rate is not None and erosion_rate > 0:
                    erosion_values.append(erosion_rate)
                    
                    # Use simple bbox geometry instead of fetching actual clipped geometry
                    bbox_cell = cell['bbox']
                    cell_geojson = {
                        'type': 'Polygon',
                        'coordinates': [[
                            [bbox_cell[0], bbox_cell[1]],
                            [bbox_cell[2], bbox_cell[1]],
                            [bbox_cell[2], bbox_cell[3]],
                            [bbox_cell[0], bbox_cell[3]],
                            [bbox_cell[0], bbox_cell[1]]
                        ]]
                    }
                    
                    cells.append({
                        'x': cell['x'],
                        'y': cell['y'],
                        'erosion_rate': round(float(erosion_rate), 2),
                        'geometry': cell_geojson
                    })
            
            # Calculate statistics from cell values
            if erosion_values:
                mean_erosion = sum(erosion_values) / len(erosion_values)
                min_erosion = min(erosion_values)
                max_erosion = max(erosion_values)
                
                # Calculate standard deviation
                variance = sum((x - mean_erosion) ** 2 for x in erosion_values) / len(erosion_values)
                std_dev = math.sqrt(variance)
            else:
                # No valid cells with erosion data
                mean_erosion = min_erosion = max_erosion = std_dev = 0
            
            logger.info(f"  ✓ Grid complete: {len(cells)} cells with data")
            
            # OPTIMIZATION 5: Skip boundary fetch - frontend already has geometry
            # This saves 1-2 minutes on complex geometries
            logger.info("  ✓ Skipping boundary fetch (optimization - frontend has geometry)")
            
            return {
                'cells': cells,
                'statistics': {
                    'mean': round(mean_erosion, 2),
                    'min': round(min_erosion, 2),
                    'max': round(max_erosion, 2),
                    'std_dev': round(std_dev, 2)
                },
                'grid_size': grid_size,
                'bbox': [bbox['min_lon'], bbox['min_lat'], bbox['max_lon'], bbox['max_lat']],
                'cell_count': len(cells)
                # region_boundary removed - frontend uses original geometry
            }
            
        except Exception as e:
            logger.error(f"Failed to compute detailed grid: {str(e)}", exc_info=True)
            raise

# Global RUSLE calculator instance
rusle_calculator = RUSLECalculator()

