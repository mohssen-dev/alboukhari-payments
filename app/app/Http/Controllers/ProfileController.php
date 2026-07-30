<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function edit()
    {
        return view('profile.edit', ['user' => auth()->user()]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name'   => ['required', 'string', 'max:191'],
            'email'  => ['required', 'string', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'locale' => ['nullable', Rule::in(['en', 'nl', 'ar'])],
        ]);

        $user->fill($data)->save();

        if (! empty($data['locale'])) {
            session(['locale' => $data['locale']]);
        }

        return back()->with('flash', __('flash.profile_updated'));
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => __('auth.current_password_wrong')]);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        return back()->with('flash', __('flash.password_updated'));
    }
}
