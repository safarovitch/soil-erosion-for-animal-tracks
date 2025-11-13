import math
import sys
from pathlib import Path
from types import ModuleType

PROJECT_ROOT = Path(__file__).resolve().parents[1]
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

if "ee" not in sys.modules:
    sys.modules["ee"] = ModuleType("ee")

if "dotenv" not in sys.modules:
    dotenv_stub = ModuleType("dotenv")
    dotenv_stub.load_dotenv = lambda *args, **kwargs: None
    sys.modules["dotenv"] = dotenv_stub

from rusle_calculator import RUSLECalculator
from rusle_config import build_config


def test_build_config_merges_nested_overrides_without_mutating_defaults():
    default_config = build_config()
    overrides = {
        "k_factor": {
            "sand_fraction_multiplier": 0.35,
        },
        "ls_factor": {
            "grid_size": 180,
        },
    }
    merged_config = build_config(overrides)

    assert math.isclose(default_config.get("k_factor.sand_fraction_multiplier"), 0.2)
    assert math.isclose(merged_config.get("k_factor.sand_fraction_multiplier"), 0.35)
    assert merged_config.get("ls_factor.grid_size") == 180


def test_rusle_calculator_parameters_reflect_overrides():
    overrides = {
        "k_factor": {
            "sand_fraction_multiplier": 0.15,
            "soc_to_organic_multiplier": 0.02,
        },
        "p_factor": {
            "breakpoints": [
                {"max_slope": 8, "value": 0.2},
                {"max_slope": None, "value": 0.4},
            ],
        },
        "erosion_classes": [
            {"key": "low", "label": "Low", "min": 0, "max": 10},
            {"key": "high", "label": "High", "min": 10, "max": None},
        ],
        "logging": {
            "include_config_snapshot": False,
        },
    }

    calculator = RUSLECalculator(overrides)

    assert math.isclose(calculator.k_factor_params["sand_fraction_multiplier"], 0.15)
    assert math.isclose(calculator.k_factor_params["soc_to_organic_multiplier"], 0.02)
    assert math.isclose(calculator.p_factor_segments[-1]["value"], 0.4)
    assert [cls["key"] for cls in calculator.erosion_classes] == ["low", "high"]
    assert not calculator.include_config_snapshot


def test_config_snapshot_returns_merged_data():
    overrides = {"r_factor": {"coefficient": 0.7}}
    calculator = RUSLECalculator(overrides)
    snapshot = calculator.config_snapshot()

    assert math.isclose(snapshot["r_factor"]["coefficient"], 0.7)
    assert math.isclose(snapshot["k_factor"]["sand_fraction_multiplier"], 0.2)

