@extends('layouts.app')

@section('content')
<div class="page">
    <div class="page-head">
        <div>
            <h1 class="page-title">
                <span class="title-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                {{ __('users.title') }}
            </h1>
            <p class="page-sub">{{ __('auth.sign_in_subtitle') }}</p>
        </div>
    </div>
    @livewire('users-manager')
</div>
@endsection
