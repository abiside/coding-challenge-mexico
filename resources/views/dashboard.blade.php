<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/logo.svg">
    <title>{{ config('app.name') }} - Arbitrage Dashboard</title>
    @vite(['resources/css/app.css', 'resources/js/dashboard.jsx'])
</head>
<body>
    <div id="dashboard"></div>
</body>
</html>
