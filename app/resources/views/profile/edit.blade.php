@extends('layouts.app')

@section('content')
<div class="page profile-page">
    <div class="page-head">
        <div>
            <h1 class="page-title">
                <span class="title-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </span>
                {{ __('profile.title') }}
            </h1>
            <p class="page-sub">{{ $user->email }} · <span style="color:var(--color-primary)">{{ __('users.role_'.$user->role) }}</span></p>
        </div>
    </div>

    <div class="grid-2">
        <form method="POST" action="{{ route('profile.update') }}" class="card">
            @csrf
            <h3>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                {{ __('profile.account_details') }}
            </h3>
            <div class="card-body">
                <label>
                    <span class="lbl">{{ __('users.name') }}</span>
                    <input type="text" name="name" value="{{ old('name', $user->name) }}" class="form-control" required>
                    @error('name')<small class="text-danger">{{ $message }}</small>@enderror
                </label>

                <label>
                    <span class="lbl">{{ __('users.email') }}</span>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" class="form-control" required>
                    @error('email')<small class="text-danger">{{ $message }}</small>@enderror
                </label>

                <label>
                    <span class="lbl">{{ __('profile.preferred_locale') }}</span>
                    <select name="locale" class="form-control">
                        <option value="">—</option>
                        <option value="en" @selected(old('locale', $user->locale) === 'en')>🇬🇧 English</option>
                        <option value="nl" @selected(old('locale', $user->locale) === 'nl')>🇳🇱 Nederlands</option>
                        <option value="ar" @selected(old('locale', $user->locale) === 'ar')>🇸🇦 العربية</option>
                    </select>
                </label>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('common.save') }}</button>
                </div>
            </div>
        </form>

        <form method="POST" action="{{ route('profile.password') }}" class="card">
            @csrf
            <h3>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                {{ __('profile.change_password') }}
            </h3>
            <div class="card-body">
                <label>
                    <span class="lbl">{{ __('profile.current_password') }}</span>
                    <input type="password" name="current_password" class="form-control" autocomplete="current-password" required>
                    @error('current_password')<small class="text-danger">{{ $message }}</small>@enderror
                </label>

                <label>
                    <span class="lbl">{{ __('profile.new_password') }}</span>
                    <input type="password" name="password" class="form-control" autocomplete="new-password" required>
                    @error('password')<small class="text-danger">{{ $message }}</small>@enderror
                </label>

                <label>
                    <span class="lbl">{{ __('users.password_confirm') }}</span>
                    <input type="password" name="password_confirmation" class="form-control" autocomplete="new-password" required>
                </label>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('profile.update_password') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
