@php
    $locale = app()->getLocale();
    $dir = $locale === 'ar' ? 'rtl' : 'ltr';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('auth.sign_in') }} · {{ __('app_name') }}</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}?v=6.0">
</head>
<body class="auth-body">

<div class="auth-deco" aria-hidden="true">
    <span class="auth-orb auth-orb-a"></span>
    <span class="auth-orb auth-orb-b"></span>
    <span class="auth-orb auth-orb-c"></span>
</div>

<div class="auth-shell">
    <div class="auth-card">
        <div class="auth-brand">
            <img src="{{ asset('assets/img/logo.jpeg') }}" alt="Al Boukhari" class="auth-logo-img">
            <h1 class="auth-title">{{ __('school_name') }}</h1>
            <p class="auth-subtitle">{{ __('auth.sign_in_subtitle') }}</p>
        </div>

        <form method="POST" action="{{ route('login') }}" class="auth-form" novalidate>
            @csrf

            @if ($errors->any())
                <div class="auth-error" role="alert">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <div>
                        @foreach ($errors->all() as $err)
                            <div>{{ $err }}</div>
                        @endforeach
                    </div>
                </div>
            @endif

            <label class="auth-field">
                <span class="auth-label">{{ __('auth.email') }}</span>
                <span class="auth-input-wrap">
                    <svg class="auth-input-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <input type="email" name="email" value="{{ old('email') }}"
                           autocomplete="username" autofocus required
                           class="form-control auth-input" placeholder="name@example.com">
                </span>
            </label>

            <label class="auth-field">
                <span class="auth-label">{{ __('auth.password') }}</span>
                <span class="auth-input-wrap" x-data="{ show: false }">
                    <svg class="auth-input-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input :type="show ? 'text' : 'password'" name="password" autocomplete="current-password" required
                           class="form-control auth-input" placeholder="••••••••">
                    <button type="button" class="auth-toggle" @click="show = !show" tabindex="-1" :aria-label="show ? 'Hide' : 'Show'">
                        <svg x-show="!show" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg x-show="show" x-cloak width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </span>
            </label>

            <label class="auth-remember">
                <input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}>
                <span>{{ __('auth.remember_me') }}</span>
            </label>

            <button type="submit" class="btn btn-primary auth-submit">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                <span>{{ __('auth.sign_in') }}</span>
            </button>
        </form>

        <div class="auth-footer">
            <div class="lang-switcher" title="{{ __('topbar.language') }}">
                <a href="{{ route('locale.switch', 'en') }}" class="{{ $locale === 'en' ? 'active' : '' }}">EN</a>
                <a href="{{ route('locale.switch', 'nl') }}" class="{{ $locale === 'nl' ? 'active' : '' }}">NL</a>
                <a href="{{ route('locale.switch', 'ar') }}" class="{{ $locale === 'ar' ? 'active' : '' }}">عر</a>
            </div>
            <small class="auth-copy">&copy; {{ date('Y') }} · {{ __('school_name') }}</small>
        </div>
    </div>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
