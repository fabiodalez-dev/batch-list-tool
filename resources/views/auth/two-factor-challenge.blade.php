<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Two-factor challenge') }} — {{ config('app.name') }}</title>

    {{-- Filament's compiled CSS so this page looks like the rest of the panel.
         Falls back to plain HTML if the asset hasn't been compiled yet. --}}
    @if (file_exists(public_path('css/filament/filament/app.css')))
        <link rel="stylesheet" href="{{ asset('css/filament/filament/app.css') }}">
    @endif

    {{-- Inline minimal styling so the page is usable even without compiled assets --}}
    <style>
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
               background: #f3f4f6; margin: 0; padding: 0; }
        .container { max-width: 26rem; margin: 4rem auto; padding: 0 1rem; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 0.75rem;
                padding: 1.5rem; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
        h1 { font-size: 1.125rem; font-weight: 600; margin: 0 0 .5rem 0; color: #111827; }
        p  { color: #6b7280; font-size: .875rem; margin: 0 0 1rem 0; line-height: 1.5; }
        label { display: block; font-size: .875rem; font-weight: 500; color: #374151; margin-bottom: .25rem; }
        input[type="text"] { width: 100%; padding: .5rem .75rem; font-size: 1rem;
                             border: 1px solid #d1d5db; border-radius: .375rem; box-sizing: border-box;
                             font-family: ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .15em; }
        button { background: #2563eb; color: #fff; border: 0; border-radius: .375rem;
                 padding: .5rem 1rem; font-weight: 500; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .toggle { display: block; margin-top: 1rem; text-align: center; font-size: .8125rem; color: #2563eb;
                  text-decoration: none; cursor: pointer; background: none; border: 0; padding: 0; }
        .toggle:hover { text-decoration: underline; }
        .error  { color: #b91c1c; font-size: .8125rem; margin-top: .5rem; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>{{ __('Two-factor challenge') }}</h1>

        <div id="code-block">
            <p>{{ __('Enter the 6-digit code from your authenticator app to finish signing in.') }}</p>
            <form method="POST" action="{{ url('/two-factor-challenge') }}">
                @csrf
                <label for="code">{{ __('Authentication code') }}</label>
                <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*"
                       autocomplete="one-time-code" autofocus maxlength="8" required>
                @error('code')
                    <div class="error">{{ $message }}</div>
                @enderror
                <div style="margin-top:1rem; display:flex; gap:.5rem;">
                    <button type="submit">{{ __('Sign in') }}</button>
                </div>
            </form>
            <a class="toggle" href="#recovery-block"
               onclick="document.getElementById('code-block').style.display='none';document.getElementById('recovery-block').style.display='block';return false;">
                {{ __('Use a recovery code instead') }}
            </a>
        </div>

        <div id="recovery-block" style="display:none">
            <p>{{ __('Use one of your single-use recovery codes to sign in. Each code works only once.') }}</p>
            <form method="POST" action="{{ url('/two-factor-challenge') }}">
                @csrf
                <label for="recovery_code">{{ __('Recovery code') }}</label>
                <input id="recovery_code" name="recovery_code" type="text"
                       autocomplete="one-time-code" required>
                @error('recovery_code')
                    <div class="error">{{ $message }}</div>
                @enderror
                <div style="margin-top:1rem; display:flex; gap:.5rem;">
                    <button type="submit">{{ __('Sign in') }}</button>
                </div>
            </form>
            <a class="toggle" href="#code-block"
               onclick="document.getElementById('recovery-block').style.display='none';document.getElementById('code-block').style.display='block';return false;">
                {{ __('Use an authenticator code instead') }}
            </a>
        </div>
    </div>
</div>
</body>
</html>
