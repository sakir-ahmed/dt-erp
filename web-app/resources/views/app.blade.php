<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title inertia>DT ERP</title>
        <script>
            try {
                const theme = localStorage.getItem('theme');
                document.documentElement.classList.toggle('dark', theme ? theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches);
            } catch {}
        </script>
        @vite('resources/js/app.js')
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
