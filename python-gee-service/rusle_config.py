"""
RUSLE configuration schema and helpers.

Provides a central location for default constants used by the Python GEE
service and utilities for merging per-request overrides supplied by Laravel.
"""
from __future__ import annotations

from copy import deepcopy
from typing import Any, Dict, Iterable, Mapping, MutableMapping, Optional


DEFAULT_RUSLE_CONFIG: Dict[str, Any] = {
    "r_factor": {
        "coefficient": 0.562,  # Linear coefficient applied to annual rainfall
        "intercept": -8.12,  # Linear intercept applied to annual rainfall
        "long_term_start_year": 1994,
        "long_term_end_year": 2024,
        "use_long_term_default": True,
    },
    "k_factor": {
        "sand_fraction_multiplier": 0.2,  # Remove 20% fine sand
        "soc_to_organic_multiplier": 0.01724,
        "base_constant": 27.66,
        "m_exponent": 1.14,
        "area_factor": 1e-8,
        "organic_matter_subtract": 12.0,
        "structure_coefficient": 0.0043,
        "structure_baseline": 2.0,
        "permeability_coefficient": 0.0033,
        "permeability_baseline": 3.0,
    },
    "ls_factor": {
        "grid_size": 1000,
        "flow_length_reference": 22.13,
        "flow_exponent": 0.4,
        "slope_normalisation": 0.0896,
        "slope_exponent": 1.3,
        "minimum_slope_radians": 0.0001,
    },
    "c_factor": {
        # MODIS IGBP land cover classes mapped to C-factor coefficients
        "class_map": {
            "1": 0.05,
            "2": 0.05,
            "3": 0.05,
            "4": 0.05,
            "5": 0.05,
            "6": 0.1,
            "7": 0.1,
            "8": 0.05,
            "9": 0.1,
            "10": 0.1,
            "11": 0.0,
            "12": 0.15,
            "13": 0.01,
            "14": 0.15,
            "15": 0.0,
            "16": 0.4,
            "17": 0.0,
        },
        "default_value": 0.0,
    },
    "p_factor": {
        "default_value": 1.0,
        "cropland_class": 12,
        # Breakpoints evaluated in ascending order; last entry with null max is fallback.
        "breakpoints": [
            {"max_slope": 5.0, "value": 0.10},
            {"max_slope": 10.0, "value": 0.12},
            {"max_slope": 20.0, "value": 0.14},
            {"max_slope": 30.0, "value": 0.19},
            {"max_slope": 50.0, "value": 0.25},
            {"max_slope": 100.0, "value": 0.33},
            {"max_slope": None, "value": 0.33},
        ],
    },
    "soil_loss": {
        "clamp_min": 0.0,
        "clamp_max": 200.0,
    },
    "erosion_classes": [
        {"key": "very_low", "label": "Very Low", "min": 0.0, "max": 5.0},
        {"key": "low", "label": "Low", "min": 5.0, "max": 15.0},
        {"key": "moderate", "label": "Moderate", "min": 15.0, "max": 30.0},
        {"key": "severe", "label": "Severe", "min": 30.0, "max": 50.0},
        {"key": "excessive", "label": "Excessive", "min": 50.0, "max": None},
    ],
    "rainfall_statistics": {
        "mean_scale": 5000,
        "cv_scale": 5000,
        "trend_interpretation": [
            {"min": 2.0, "label": "Significant increasing trend"},
            {"min": 0.5, "label": "Moderate increasing trend"},
            {"min": -0.5, "label": "Stable/No significant trend"},
            {"min": -2.0, "label": "Moderate decreasing trend"},
            {"min": None, "label": "Significant decreasing trend"},
        ],
        "cv_interpretation": [
            {"max": 10.0, "label": "Very low variability"},
            {"max": 20.0, "label": "Low variability"},
            {"max": 30.0, "label": "Moderate variability"},
            {"max": 40.0, "label": "High variability"},
            {"max": None, "label": "Very high variability"},
        ],
    },
    "logging": {
        "include_config_snapshot": True,
    },
}


def _merge_dict(base: MutableMapping[str, Any], overrides: Mapping[str, Any]) -> MutableMapping[str, Any]:
    """
    Recursively merge ``overrides`` into ``base`` and return ``base``.

    Lists and non-mapping values are replaced entirely; dictionaries are merged
    depth-wise.
    """
    for key, value in overrides.items():
        if (
            isinstance(value, Mapping)
            and key in base
            and isinstance(base[key], MutableMapping)
        ):
            base[key] = _merge_dict(base[key], value)  # type: ignore[arg-type]
        else:
            base[key] = deepcopy(value)
    return base


class RUSLEConfig:
    """
    Container for RUSLE configuration data with helper methods for retrieving
    nested values.
    """

    def __init__(
        self,
        overrides: Optional[Mapping[str, Any]] = None,
        base: Optional[Mapping[str, Any]] = None,
    ) -> None:
        self._data: Dict[str, Any] = deepcopy(base) if base is not None else deepcopy(DEFAULT_RUSLE_CONFIG)
        if overrides:
            _merge_dict(self._data, overrides)

    @classmethod
    def from_dict(cls, data: Optional[Mapping[str, Any]]) -> "RUSLEConfig":
        return cls(overrides=data)

    def merge_overrides(self, overrides: Optional[Mapping[str, Any]]) -> None:
        if overrides:
            _merge_dict(self._data, overrides)

    def get(self, path: str, default: Any = None) -> Any:
        """
        Retrieve a nested value via dotted path syntax. Returns ``default`` if
        any intermediate key is missing.
        """
        parts = path.split(".")
        current: Any = self._data
        for part in parts:
            if isinstance(current, Mapping) and part in current:
                current = current[part]
            else:
                return default
        return current

    def to_dict(self) -> Dict[str, Any]:
        """Return a deep copy of the underlying data."""
        return deepcopy(self._data)

    def __getitem__(self, item: str) -> Any:
        return self._data[item]

    def __contains__(self, item: str) -> bool:
        return item in self._data

    def items(self) -> Iterable:
        return self._data.items()


def build_config(overrides: Optional[Mapping[str, Any]] = None) -> RUSLEConfig:
    """Convenience helper to construct a config with optional overrides."""
    return RUSLEConfig(overrides=overrides)

