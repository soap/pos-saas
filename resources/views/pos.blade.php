<!DOCTYPE html>
<html lang="th">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#0f766e">

        <title>{{ config('app.name', 'POS') }} — POS</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=ibm-plex-sans-thai:400,500,600,700" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/pos/main.ts'])
    </head>
    <body class="h-full bg-slate-100 font-sans text-slate-900 antialiased">
        <div id="pos-app" class="h-full"></div>
    </body>
</html>
