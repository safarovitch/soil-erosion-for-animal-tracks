"""
Rainfall Analysis Calculator for Temporal Trend and Variability
Uses CHIRPS precipitation data from Google Earth Engine
"""
import ee
import logging
import numpy as np
from typing import Any, Dict, List, Mapping, Optional, Sequence

from gee_service import gee_service
from rusle_config import RUSLEConfig, build_config

logger = logging.getLogger(__name__)

class RainfallCalculator:
    """Calculate rainfall trends and variability metrics"""
    
    def __init__(self, config: Optional[Mapping[str, Any]] = None):
        if isinstance(config, RUSLEConfig):
            self.config = config
        else:
            self.config = build_config(config)
        self.rainfall_mean_scale = float(
            self.config.get("rainfall_statistics.mean_scale", 5000)
        )
        self.trend_rules = self._prepare_trend_rules(
            self.config.get("rainfall_statistics.trend_interpretation", [])
        )
        self.cv_rules = self._prepare_cv_rules(
            self.config.get("rainfall_statistics.cv_interpretation", [])
        )

    def config_snapshot(self) -> Dict[str, Any]:
        return self.config.to_dict()

    def _prepare_trend_rules(
        self, rules: Optional[Sequence[Mapping[str, Any]]]
    ) -> Sequence[Dict[str, Any]]:
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
            processed = list(default_rules)
        processed.sort(
            key=lambda item: float("-inf")
            if item["min"] is None
            else item["min"],
            reverse=True,
        )
        return processed

    def _prepare_cv_rules(
        self, rules: Optional[Sequence[Mapping[str, Any]]]
    ) -> Sequence[Dict[str, Any]]:
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
            processed = list(default_rules)
        processed.sort(
            key=lambda item: float("inf") if item["max"] is None else item["max"]
        )
        return processed

    def _interpret_trend(self, value: float) -> str:
        for rule in self.trend_rules:
            threshold = rule["min"]
            if threshold is None or value >= threshold:
                return rule["label"]
        return self.trend_rules[-1]["label"]

    def _interpret_cv_value(self, value: float) -> str:
        for rule in self.cv_rules:
            threshold = rule["max"]
            if threshold is None or value < threshold:
                return rule["label"]
        return self.cv_rules[-1]["label"]
    
    def compute_rainfall_slope(self, geometry, start_year, end_year):
        """
        Compute rainfall slope/trend (temporal change in rainfall)
        Uses linear regression on annual precipitation
        
        Args:
            geometry: Earth Engine geometry
            start_year: Start year
            end_year: End year
            
        Returns:
            dict: Statistics including mean slope, min, max, std_dev
        """
        try:
            logger.info(f"Computing rainfall slope from {start_year} to {end_year}")
            
            # Validate year range
            if end_year <= start_year:
                raise ValueError("End year must be greater than start year")
            
            if end_year - start_year < 3:
                raise ValueError("Need at least 3 years for meaningful trend analysis")
            
            # Load CHIRPS precipitation data for the time period
            chirps = ee.ImageCollection('UCSB-CHG/CHIRPS/DAILY')
            
            # Collect annual precipitation for each year
            annual_precip_list = []
            years = list(range(start_year, end_year + 1))
            
            for i, year in enumerate(years):
                start_date = f'{year}-01-01'
                end_date = f'{year}-12-31'
                
                # Sum precipitation for the year
                annual = chirps.filterDate(start_date, end_date) \
                    .select('precipitation') \
                    .sum()
                
                # Add year as property and as band for regression
                # Use index (i) to create consistent band types
                annual = annual.set('year', year) \
                    .set('system:time_start', ee.Date(start_date).millis())
                
                # Add year as independent variable (cast to float for consistency)
                annual = annual.addBands(
                    ee.Image.constant(year).toFloat().rename('year')
                )
                
                annual_precip_list.append(annual)
            
            # Create image collection from annual data
            annual_collection = ee.ImageCollection(annual_precip_list)
            
            # Perform linear regression: precipitation = slope * year + intercept
            # Independent variable is 'year', dependent is 'precipitation'
            regression = annual_collection.select(['year', 'precipitation']) \
                .reduce(ee.Reducer.linearFit())
            
            # Extract the slope band (rate of change in mm/year per year)
            slope_image = regression.select('scale')
            
            # Compute statistics over the geometry
            logger.info("Computing statistics over region...")
            stats = gee_service.compute_statistics(
                slope_image, geometry, scale=self.rainfall_mean_scale
            )
            
            # Extract values
            mean_slope = stats.get('scale_mean', 0)
            min_slope = stats.get('scale_min', mean_slope)
            max_slope = stats.get('scale_max', mean_slope)
            std_dev = stats.get('scale_stdDev', 0)
            
            result = {
                'mean': round(float(mean_slope), 4),
                'min': round(float(min_slope), 4),
                'max': round(float(max_slope), 4),
                'std_dev': round(float(std_dev), 4),
                'year_range': end_year - start_year + 1,
                'start_year': start_year,
                'end_year': end_year,
                'unit': 'mm/year per year',
                'interpretation': self._interpret_trend(float(mean_slope)),
                'analysis_scale_m': self.rainfall_mean_scale,
            }
            
            logger.info(f"Rainfall slope: {mean_slope:.4f} mm/year per year")
            return result
            
        except Exception as e:
            logger.error(f"Failed to compute rainfall slope: {str(e)}", exc_info=True)
            raise
    
    def compute_rainfall_cv(self, geometry, start_year, end_year):
        """
        Compute rainfall coefficient of variation (CV)
        Measures inter-annual rainfall variability
        
        Args:
            geometry: Earth Engine geometry
            start_year: Start year
            end_year: End year
            
        Returns:
            dict: Statistics including mean CV, min, max, std_dev
        """
        try:
            logger.info(f"Computing rainfall CV from {start_year} to {end_year}")
            
            # Validate year range
            if end_year <= start_year:
                raise ValueError("End year must be greater than start year")
            
            if end_year - start_year < 2:
                raise ValueError("Need at least 2 years for CV calculation")
            
            # Load CHIRPS precipitation data
            chirps = ee.ImageCollection('UCSB-CHG/CHIRPS/DAILY')
            
            # Collect annual precipitation for each year
            annual_precip_list = []
            years = list(range(start_year, end_year + 1))
            
            for year in years:
                start_date = f'{year}-01-01'
                end_date = f'{year}-12-31'
                
                # Sum precipitation for the year
                annual = chirps.filterDate(start_date, end_date) \
                    .select('precipitation') \
                    .sum()
                
                annual_precip_list.append(annual)
            
            # Create image collection from annual data
            annual_collection = ee.ImageCollection(annual_precip_list)
            
            # Calculate mean and standard deviation across years
            mean_precip = annual_collection.mean()
            std_precip = annual_collection.reduce(ee.Reducer.stdDev())
            
            # Calculate CV = (std_dev / mean) * 100
            cv_image = std_precip.divide(mean_precip).multiply(100).rename('cv')
            
            # Compute statistics over the geometry
            logger.info("Computing statistics over region...")
            stats = gee_service.compute_statistics(
                cv_image, geometry, scale=self.rainfall_mean_scale
            )
            
            # Extract values
            mean_cv = stats.get('cv_mean', 0)
            min_cv = stats.get('cv_min', mean_cv)
            max_cv = stats.get('cv_max', mean_cv)
            std_dev = stats.get('cv_stdDev', 0)
            
            result = {
                'mean': round(float(mean_cv), 2),
                'min': round(float(min_cv), 2),
                'max': round(float(max_cv), 2),
                'std_dev': round(float(std_dev), 2),
                'year_range': end_year - start_year + 1,
                'start_year': start_year,
                'end_year': end_year,
                'unit': 'percent (%)',
                'interpretation': self._interpret_cv_value(float(mean_cv)),
                'analysis_scale_m': self.rainfall_mean_scale,
            }
            
            logger.info(f"Rainfall CV: {mean_cv:.2f}%")
            return result
            
        except Exception as e:
            logger.error(f"Failed to compute rainfall CV: {str(e)}", exc_info=True)
            raise
    
    def _interpret_slope(self, slope):
        """Interpret the rainfall slope value"""
        if slope > 2:
            return "Significant increasing trend"
        elif slope > 0.5:
            return "Moderate increasing trend"
        elif slope > -0.5:
            return "Stable/No significant trend"
        elif slope > -2:
            return "Moderate decreasing trend"
        else:
            return "Significant decreasing trend"
    
    def _interpret_cv(self, cv):
        """Interpret the coefficient of variation value"""
        if cv < 10:
            return "Very low variability"
        elif cv < 20:
            return "Low variability"
        elif cv < 30:
            return "Moderate variability"
        elif cv < 40:
            return "High variability"
        else:
            return "Very high variability"
    
    def compute_rainfall_slope_grid(self, geometry, start_year, end_year, grid_size=50):
        """
        Compute rainfall slope with spatial grid data for visualization
        
        Args:
            geometry: Earth Engine geometry
            start_year: Start year
            end_year: End year
            grid_size: Grid resolution (default 50x50)
            
        Returns:
            dict: Grid data with pixel values for map visualization
        """
        try:
            logger.info(f"Computing rainfall slope grid from {start_year} to {end_year}")
            
            # Validate year range
            if end_year <= start_year:
                raise ValueError("End year must be greater than start year")
            
            if end_year - start_year < 3:
                raise ValueError("Need at least 3 years for meaningful trend analysis")
            
            # Load CHIRPS precipitation data for the time period
            chirps = ee.ImageCollection('UCSB-CHG/CHIRPS/DAILY')
            
            # Collect annual precipitation for each year
            annual_precip_list = []
            years = list(range(start_year, end_year + 1))
            
            for i, year in enumerate(years):
                start_date = f'{year}-01-01'
                end_date = f'{year}-12-31'
                
                # Sum precipitation for the year
                annual = chirps.filterDate(start_date, end_date) \
                    .select('precipitation') \
                    .sum()
                
                # Add year as independent variable (cast to float for consistency)
                annual = annual.addBands(
                    ee.Image.constant(year).toFloat().rename('year')
                )
                
                annual_precip_list.append(annual)
            
            # Create image collection from annual data
            annual_collection = ee.ImageCollection(annual_precip_list)
            
            # Perform linear regression
            regression = annual_collection.select(['year', 'precipitation']) \
                .reduce(ee.Reducer.linearFit())
            
            # Extract the slope band (rate of change in mm/year per year)
            slope_image = regression.select('scale')
            
            # Sample the image to get grid data
            logger.info("Sampling slope image for grid data...")
            grid_data = gee_service.sample_image_to_grid(
                slope_image, 
                geometry, 
                grid_size=grid_size,
                scale=5000
            )
            
            return grid_data
            
        except Exception as e:
            logger.error(f"Failed to compute rainfall slope grid: {str(e)}", exc_info=True)
            raise
    
    def compute_rainfall_cv_grid(self, geometry, start_year, end_year, grid_size=50):
        """
        Compute rainfall CV with spatial grid data for visualization
        
        Args:
            geometry: Earth Engine geometry
            start_year: Start year
            end_year: End year
            grid_size: Grid resolution (default 50x50)
            
        Returns:
            dict: Grid data with pixel values for map visualization
        """
        try:
            logger.info(f"Computing rainfall CV grid from {start_year} to {end_year}")
            
            # Validate year range
            if end_year <= start_year:
                raise ValueError("End year must be greater than start year")
            
            if end_year - start_year < 2:
                raise ValueError("Need at least 2 years for CV calculation")
            
            # Load CHIRPS precipitation data
            chirps = ee.ImageCollection('UCSB-CHG/CHIRPS/DAILY')
            
            # Collect annual precipitation for each year
            annual_precip_list = []
            years = list(range(start_year, end_year + 1))
            
            for year in years:
                start_date = f'{year}-01-01'
                end_date = f'{year}-12-31'
                
                # Sum precipitation for the year
                annual = chirps.filterDate(start_date, end_date) \
                    .select('precipitation') \
                    .sum()
                
                annual_precip_list.append(annual)
            
            # Create image collection from annual data
            annual_collection = ee.ImageCollection(annual_precip_list)
            
            # Calculate mean and standard deviation across years
            mean_precip = annual_collection.mean()
            std_precip = annual_collection.reduce(ee.Reducer.stdDev())
            
            # Calculate CV = (std_dev / mean) * 100
            cv_image = std_precip.divide(mean_precip).multiply(100).rename('cv')
            
            # Sample the image to get grid data
            logger.info("Sampling CV image for grid data...")
            grid_data = gee_service.sample_image_to_grid(
                cv_image, 
                geometry, 
                grid_size=grid_size,
                scale=5000
            )
            
            return grid_data
            
        except Exception as e:
            logger.error(f"Failed to compute rainfall CV grid: {str(e)}", exc_info=True)
            raise

# Global rainfall calculator instance
rainfall_calculator = RainfallCalculator()

