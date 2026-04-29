<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Merlin') }}</title>

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/favicon.ico">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased">

    {{-- Mobile sidebar --}}
    <flux:sidebar sticky collapsible="mobile" class="lg:hidden bg-surface-alt border-r border-line">
        <flux:sidebar.header class="px-4 py-4">
            <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2">
                <x-application-logo :height="28" />
            </a>
            <flux:sidebar.collapse />
        </flux:sidebar.header>
        <x-layout.nav />
    </flux:sidebar>

    {{-- Top navigation --}}
    <flux:header class="bg-white border-b border-line">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center shrink-0 mr-4">
            <x-application-logo :height="26" />
        </a>

        <x-layout.top-nav />

        <flux:spacer />

        <flux:dropdown position="bottom" align="end">
            <flux:profile name="{{ auth()->user()?->name ?? '' }}" />
            <flux:menu>
                <flux:menu.item icon="user" href="{{ route('profile') }}" wire:navigate>Profile</flux:menu.item>
                <flux:menu.separator />
                <form method="POST" action="{{ route('logout') }}" id="app-logout-form" class="hidden">@csrf</form>
                <flux:menu.item
                    icon="arrow-right-start-on-rectangle"
                    x-on:click="document.getElementById('app-logout-form').submit()"
                >Log out</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    <flux:main class="bg-white p-0">
        @if (isset($header))
            <div class="px-6 py-5 border-b border-line">
                {{ $header }}
            </div>
        @endif

        {{ $slot }}
    </flux:main>

</body>
</html>
