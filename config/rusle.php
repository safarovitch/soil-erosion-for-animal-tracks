<?php

return [
    'version' => env('RUSLE_DEFAULTS_VERSION', '2025-11-13'),

    'defaults' => [
        'r_factor' => [
            'coefficient' => 0.562,
            'intercept' => -8.12,
            'long_term_start_year' => 1994,
            'long_term_end_year' => 2024,
            'use_long_term_default' => true,
        ],
        'k_factor' => [
            'sand_fraction_multiplier' => 0.2,
            'soc_to_organic_multiplier' => 0.01724,
            'base_constant' => 27.66,
            'm_exponent' => 1.14,
            'area_factor' => 1e-8,
            'organic_matter_subtract' => 12.0,
            'structure_coefficient' => 0.0043,
            'structure_baseline' => 2.0,
            'permeability_coefficient' => 0.0033,
            'permeability_baseline' => 3.0,
        ],
        'ls_factor' => [
            'grid_size' => 1000,
            'flow_length_reference' => 22.13,
            'flow_exponent' => 0.4,
            'slope_normalisation' => 0.0896,
            'slope_exponent' => 1.3,
            'minimum_slope_radians' => 0.0001,
        ],
        'c_factor' => [
            'class_map' => [
                '1' => 0.05,
                '2' => 0.05,
                '3' => 0.05,
                '4' => 0.05,
                '5' => 0.05,
                '6' => 0.1,
                '7' => 0.1,
                '8' => 0.05,
                '9' => 0.1,
                '10' => 0.1,
                '11' => 0.0,
                '12' => 0.15,
                '13' => 0.01,
                '14' => 0.15,
                '15' => 0.0,
                '16' => 0.4,
                '17' => 0.0,
            ],
            'default_value' => 0.0,
        ],
        'p_factor' => [
            'default_value' => 1.0,
            'cropland_class' => 12,
            'breakpoints' => [
                ['max_slope' => 5.0, 'value' => 0.10],
                ['max_slope' => 10.0, 'value' => 0.12],
                ['max_slope' => 20.0, 'value' => 0.14],
                ['max_slope' => 30.0, 'value' => 0.19],
                ['max_slope' => 50.0, 'value' => 0.25],
                ['max_slope' => 100.0, 'value' => 0.33],
                ['max_slope' => null, 'value' => 0.33],
            ],
        ],
        'soil_loss' => [
            'clamp_min' => 0.0,
            'clamp_max' => 200.0,
        ],
        'erosion_classes' => [
            ['key' => 'very_low', 'label' => 'Very Low', 'min' => 0.0, 'max' => 5.0],
            ['key' => 'low', 'label' => 'Low', 'min' => 5.0, 'max' => 15.0],
            ['key' => 'moderate', 'label' => 'Moderate', 'min' => 15.0, 'max' => 30.0],
            ['key' => 'severe', 'label' => 'Severe', 'min' => 30.0, 'max' => 50.0],
            ['key' => 'excessive', 'label' => 'Excessive', 'min' => 50.0, 'max' => null],
        ],
        'rainfall_statistics' => [
            'mean_scale' => 5000,
            'cv_scale' => 5000,
            'trend_interpretation' => [
                ['min' => 2.0, 'label' => 'Significant increasing trend'],
                ['min' => 0.5, 'label' => 'Moderate increasing trend'],
                ['min' => -0.5, 'label' => 'Stable/No significant trend'],
                ['min' => -2.0, 'label' => 'Moderate decreasing trend'],
                ['min' => null, 'label' => 'Significant decreasing trend'],
            ],
            'cv_interpretation' => [
                ['max' => 10.0, 'label' => 'Very low variability'],
                ['max' => 20.0, 'label' => 'Low variability'],
                ['max' => 30.0, 'label' => 'Moderate variability'],
                ['max' => 40.0, 'label' => 'High variability'],
                ['max' => null, 'label' => 'Very high variability'],
            ],
        ],
        'logging' => [
            'include_config_snapshot' => true,
        ],
    ],
];

