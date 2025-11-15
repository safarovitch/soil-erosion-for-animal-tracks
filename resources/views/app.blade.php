<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <meta name="title" content="Soil Erosion Monitoring and Reporting System for Tajikistan">
        <meta name="description" content="Calculate and estimate soil eroision rates in Tajikistan using the Revised Universal Soil Loss Equation (RUSLE).">
        <meta name="keywords" content="soil erosion, monitoring, analysis, Tajikistan">
        <meta name="author" content="ICARDA && Wyzo">
        <meta name="robots" content="index, follow">
        <meta name="googlebot" content="index, follow">
        <meta name="google" content="notranslate">

        <title inertia>{{ config('app.name', 'Soil Erosion Monitoring and Reporting System for Tajikistan') }}</title>

        <!-- Modern Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
        @routes
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
