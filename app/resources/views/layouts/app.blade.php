@php
    $locale = app()->getLocale();
    $dir = $locale === 'ar' ? 'rtl' : 'ltr';
    $currentUser = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('app_name') }}</title>

    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">

    {{-- Preload CSS so wire:navigate transitions feel instant. --}}
    <link rel="preload" as="style" href="{{ asset('assets/css/app.css') }}?v=7.2">
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}?v=7.2">
    <link rel="preload" as="image" href="{{ asset('assets/img/logo.jpeg') }}">

    {{-- Prevent FOUC/x-cloak flicker across page transitions. --}}
    <style>[x-cloak]{display:none!important}</style>

    @livewireStyles
</head>
<body>

{{-- ====== Topbar ====== --}}
<nav class="topbar" x-data="{ mobileOpen: false }" @close-panels.window="mobileOpen = false">
    <a href="{{ route('home') }}" wire:navigate class="brand" style="text-decoration:none;">
        <img src="{{ asset('assets/img/logo.jpeg') }}" alt="Al Boukhari" class="brand-logo">
        <span class="brand-name d-none-mobile">{{ __('school_name') }}</span>
    </a>

    @auth
        {{-- Hamburger toggle — visible only on mobile via CSS --}}
        <button
            type="button"
            class="nav-toggle"
            @click="mobileOpen = !mobileOpen"
            :aria-expanded="mobileOpen"
            aria-label="{{ __('nav.home') }}"
        >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" x-show="!mobileOpen"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" x-show="mobileOpen" x-cloak><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>

        <div class="nav-links" :class="{ 'is-open': mobileOpen }" @click="mobileOpen = false">
        <a href="{{ route('home') }}" wire:navigate class="nav-link {{ request()->routeIs('home') ? 'active' : '' }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12l9-9 9 9"/><path d="M5 10v10h14V10"/></svg>
            {{ __('nav.home') }}
        </a>

        <a href="{{ route('grid.focus') }}" wire:navigate class="nav-link {{ request()->routeIs('grid.focus') ? 'active' : '' }}" title="{{ __('grid.title_focus') }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><line x1="12" y1="2" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22"/><line x1="2" y1="12" x2="4" y2="12"/><line x1="20" y1="12" x2="22" y2="12"/></svg>
            {{ __('grid.open_focus') }}
        </a>

        @if($currentUser?->canWrite())
        <a href="{{ route('quick-entry') }}" wire:navigate class="nav-link {{ request()->routeIs('quick-entry') ? 'active' : '' }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            {{ __('nav.quick_entry') }}
        </a>
        <a href="{{ route('send.form') }}" wire:navigate class="nav-link {{ request()->routeIs('send.*') ? 'active' : '' }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/></svg>
            {{ __('nav.send') }}
        </a>
        @endif

        <a href="{{ route('campaigns.index') }}" wire:navigate class="nav-link {{ request()->routeIs('campaigns.*') ? 'active' : '' }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
            {{ __('nav.campaigns') }}
        </a>

        @if($currentUser?->canWrite())
        <a href="{{ route('templates.index') }}" wire:navigate class="nav-link {{ request()->routeIs('templates.*') ? 'active' : '' }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
            {{ __('nav.templates') }}
        </a>
        @endif

        <a href="{{ route('reports') }}" wire:navigate class="nav-link {{ request()->routeIs('reports') ? 'active' : '' }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
            {{ __('nav.reports') }}
        </a>

        @if($currentUser?->isAdmin())
        <a href="{{ route('settings') }}" wire:navigate class="nav-link {{ request()->routeIs('settings') ? 'active' : '' }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            {{ __('nav.settings') }}
        </a>
        @endif
        </div>{{-- /.nav-links --}}

        <span class="topbar-spacer"></span>

        {{-- Language switcher --}}
        <div class="lang-switcher" title="{{ __('topbar.language') }}">
            <a href="{{ route('locale.switch', 'en') }}" class="{{ $locale === 'en' ? 'active' : '' }}">EN</a>
            <a href="{{ route('locale.switch', 'nl') }}" class="{{ $locale === 'nl' ? 'active' : '' }}">NL</a>
            <a href="{{ route('locale.switch', 'ar') }}" class="{{ $locale === 'ar' ? 'active' : '' }}">عر</a>
        </div>

        @if($currentUser?->canWrite())
            @php $halted = \App\Services\HaltService::isHalted(); @endphp
            <form method="POST" action="{{ route('halt') }}" style="display:inline;margin-inline-start:8px">
                @csrf
                <input type="hidden" name="action" value="{{ $halted ? 'resume' : 'halt' }}">
                <button type="submit" class="btn btn-sm {{ $halted ? 'btn-success' : 'btn-danger' }}">
                    {{ $halted ? '▶ ' . __('topbar.resume') : '⛔ ' . __('topbar.halt') }}
                </button>
            </form>
        @endif

        {{-- User menu --}}
        <div class="user-menu" x-data="{ open: false }" @click.away="open = false">
            <button type="button" class="user-trigger" @click="open = !open">
                <span class="user-avatar">{{ mb_strtoupper(mb_substr($currentUser->name, 0, 1)) }}</span>
                <span class="d-none-mobile">{{ $currentUser->name }}</span>
                <span class="caret">▾</span>
            </button>
            <div class="user-dropdown" x-show="open" x-cloak x-transition>
                <div class="user-info">
                    <div class="user-name">{{ $currentUser->name }}</div>
                    <div class="user-email">{{ $currentUser->email }}</div>
                    <div class="user-role">{{ __('users.role_'.$currentUser->role) }}</div>
                </div>
                <a href="{{ route('profile.edit') }}" wire:navigate class="dropdown-item">{{ __('nav.profile') }}</a>
                @if($currentUser->isAdmin())
                    <a href="{{ route('users.index') }}" wire:navigate class="dropdown-item">{{ __('nav.users') }}</a>
                    <a href="{{ route('activity.index') }}" wire:navigate class="dropdown-item">{{ __('nav.activity') }}</a>
                    <a href="{{ route('import.form') }}" wire:navigate class="dropdown-item">{{ __('nav.import') }}</a>
                @endif
                <form method="POST" action="{{ route('logout') }}" class="logout-form">
                    @csrf
                    <button type="submit" class="dropdown-item danger">{{ __('nav.logout') }}</button>
                </form>
            </div>
        </div>
    @endauth

    @guest
        <span class="topbar-spacer"></span>
        <div class="lang-switcher" title="{{ __('topbar.language') }}">
            <a href="{{ route('locale.switch', 'en') }}" class="{{ $locale === 'en' ? 'active' : '' }}">EN</a>
            <a href="{{ route('locale.switch', 'nl') }}" class="{{ $locale === 'nl' ? 'active' : '' }}">NL</a>
            <a href="{{ route('locale.switch', 'ar') }}" class="{{ $locale === 'ar' ? 'active' : '' }}">عر</a>
        </div>
    @endguest
</nav>

@if (session('flash'))
    <div
        class="toast-stack"
        x-data="{ open: true }"
        x-show="open"
        x-init="setTimeout(()=> open=false, 4000)"
    >
        <div class="toast {{ session('flash_type', 'success') }}">{{ session('flash') }}</div>
    </div>
@endif

<main class="main">
    {{ $slot ?? '' }}
    @yield('content')
</main>

{{-- Toast container --}}
<div
    class="toast-stack"
    x-data="{ toasts: [] }"
    @toast.window="
        const id = Date.now() + Math.random();
        toasts.push({ id, msg: $event.detail.message || $event.detail, type: $event.detail.type || 'success' });
        setTimeout(() => { toasts = toasts.filter(t => t.id !== id); }, 3500);
    "
    @flash.window="
        const id = Date.now() + Math.random();
        toasts.push({ id, msg: $event.detail.message, type: 'success' });
        setTimeout(() => { toasts = toasts.filter(t => t.id !== id); }, 3500);
    "
>
    <template x-for="t in toasts" :key="t.id">
        <div class="toast" :class="t.type" x-text="t.msg"></div>
    </template>
</div>

@auth
    {{-- Persistent Livewire modals + side panel.
         Mounted once per page; opened via events (open-payment-modal, open-family-modal, etc.).
         This lets any component open a modal without triggering a re-render of the caller. --}}
    <livewire:payment-modal />
    <livewire:family-modal />
    <livewire:send-single-message />
    <livewire:student-panel />
@endauth

@livewireScripts

<script>
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            window.dispatchEvent(new CustomEvent('close-panels'));
        }
        if (e.key === '/' && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) {
            e.preventDefault();
            const search = document.querySelector('.search-global, input.search');
            if (search) search.focus();
        }
    });
</script>

</body>
</html>
