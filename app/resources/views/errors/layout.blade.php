@php
    $locale = app()->getLocale();
    $dir = $locale === 'ar' ? 'rtl' : 'ltr';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('code') — {{ __('app_name') }}</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Cairo', system-ui, sans-serif;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #f6f7f9; color: #1e293b; padding: 24px;
        }
        .card { text-align: center; max-width: 420px; }
        .code { font-size: 72px; font-weight: 800; color: #94a3b8; line-height: 1; }
        h1 { font-size: 20px; margin: 12px 0 8px; }
        p { color: #64748b; font-size: 14px; margin-bottom: 20px; }
        a.btn {
            display: inline-block; padding: 10px 22px; background: #2563eb; color: #fff;
            text-decoration: none; border-radius: 8px; font-size: 14px; font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="code">@yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a class="btn" href="{{ url('/') }}">{{ __('errors.back_home') }}</a>
    </div>
</body>
</html>
