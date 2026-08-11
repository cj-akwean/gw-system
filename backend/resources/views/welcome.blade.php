<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Guinobatan Waterworks') }}</title>
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 1.5rem;
                font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
                background: #f1f5f9;
                color: #0f172a;
                padding: 1.5rem;
                text-align: center;
            }
            .brand { display: flex; flex-direction: column; align-items: center; gap: 1rem; }
            .brand img { width: 64px; height: 64px; border-radius: 14px; }
            .brand h1 { font-size: 1.5rem; font-weight: 700; letter-spacing: -0.02em; }
            .brand p { color: #475569; font-size: 0.9rem; }
            .links { display: flex; gap: 0.75rem; flex-wrap: wrap; justify-content: center; }
            .links a {
                padding: 0.55rem 1.25rem;
                border-radius: 0.5rem;
                text-decoration: none;
                font-size: 0.875rem;
                font-weight: 600;
                border: 1px solid #cbd5e1;
                background: #ffffff;
                color: #0f172a;
                transition: border-color 0.15s ease, background 0.15s ease;
            }
            .links a:hover { border-color: #f59e0b; background: #f8fafc; }
        </style>
    </head>
    <body>
        <div class="brand">
            <img src="{{ asset('favicon.svg') }}" alt="Guinobatan Waterworks logo">
            <h1>Guinobatan Waterworks</h1>
            <p>Guinobatan Waterworks System — API &amp; admin backend</p>
        </div>
        <div class="links">
            <a href="/admin">Admin Panel</a>
        </div>
    </body>
</html>
