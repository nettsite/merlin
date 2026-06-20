@if ($hostLayout = config('nettmail.layout'))
<x-dynamic-component :component="$hostLayout">
    @include('nettmail::layouts.partials.styles')
    {{ $slot }}
</x-dynamic-component>
@else
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? config('nettmail.nav_group') }}</title>

        @livewireStyles

        <style>
            * { box-sizing: border-box; }
            body { margin: 0; font-family: ui-sans-serif, system-ui, sans-serif; background: #f8fafc; color: #1e293b; }
            .nettmail-layout { display: flex; min-height: 100vh; }
            .nettmail-sidebar { width: 220px; flex-shrink: 0; background: #0f172a; color: #e2e8f0; padding: 1.5rem 1rem; }
            .nettmail-sidebar h1 { font-size: 1.1rem; margin: 0 0 1.5rem; color: #fff; }
            .nettmail-sidebar nav a { display: block; padding: 0.5rem 0.75rem; border-radius: 0.375rem; color: #cbd5e1; text-decoration: none; margin-bottom: 0.25rem; font-size: 0.9rem; }
            .nettmail-sidebar nav a:hover { background: #1e293b; color: #fff; }
            .nettmail-sidebar nav a.active { background: #2563eb; color: #fff; }
            .nettmail-main { flex: 1; padding: 2rem; max-width: 1100px; }
            .nettmail-main h2 { margin-top: 0; }
        </style>
        @include('nettmail::layouts.partials.styles')
    </head>
    <body>
        <div class="nettmail-layout">
            <aside class="nettmail-sidebar">
                <h1>{{ config('nettmail.nav_group') }}</h1>
                <nav>
                    @if (Route::has('nettmail.dashboard'))
                        <a href="{{ route('nettmail.dashboard') }}" class="{{ request()->routeIs('nettmail.dashboard') ? 'active' : '' }}">Dashboard</a>
                    @endif
                    @if (Route::has('nettmail.templates.index'))
                        <a href="{{ route('nettmail.templates.index') }}" class="{{ request()->routeIs('nettmail.templates.*') ? 'active' : '' }}">Templates</a>
                    @endif
                    @if (Route::has('nettmail.contacts.index'))
                        <a href="{{ route('nettmail.contacts.index') }}" class="{{ request()->routeIs('nettmail.contacts.*') ? 'active' : '' }}">Contacts</a>
                    @endif
                    @if (Route::has('nettmail.lists.index'))
                        <a href="{{ route('nettmail.lists.index') }}" class="{{ request()->routeIs('nettmail.lists.*') ? 'active' : '' }}">Lists</a>
                    @endif
                    @if (Route::has('nettmail.segments.index'))
                        <a href="{{ route('nettmail.segments.index') }}" class="{{ request()->routeIs('nettmail.segments.*') ? 'active' : '' }}">Segments</a>
                    @endif
                    @if (Route::has('nettmail.campaigns.index'))
                        <a href="{{ route('nettmail.campaigns.index') }}" class="{{ request()->routeIs('nettmail.campaigns.*') ? 'active' : '' }}">Campaigns</a>
                    @endif
                    @if (Route::has('nettmail.settings'))
                        <a href="{{ route('nettmail.settings') }}" class="{{ request()->routeIs('nettmail.settings') ? 'active' : '' }}">Settings</a>
                    @endif
                </nav>
            </aside>
            <main class="nettmail-main">
                {{ $slot }}
            </main>
        </div>

        @livewireScripts
    </body>
</html>
@endif
