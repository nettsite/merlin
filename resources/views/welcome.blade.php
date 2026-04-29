<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Merlin</title>

        <!-- Favicon -->
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="alternate icon" href="/favicon.ico">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />

        <!-- Styles -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="antialiased font-sans bg-white text-ink">
        <div class="min-h-screen flex flex-col">
            <header class="border-b border-line">
                <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
                    <span class="font-semibold text-lg text-ink">Merlin</span>
                    @if (Route::has('login'))
                        <livewire:welcome.navigation />
                    @endif
                </div>
            </header>

            <main class="flex-1 flex items-center justify-center px-6 py-24">
                <div class="text-center max-w-lg">
                    <h1 class="text-4xl font-bold text-ink tracking-tight">Smart invoicing for small business.</h1>
                    <p class="mt-4 text-base text-ink-soft">Merlin reads your supplier invoices and posts them to your ledger — automatically.</p>
                    @auth
                        <a href="{{ route('dashboard') }}" class="mt-8 inline-flex items-center px-5 py-2.5 bg-ink text-white text-sm font-semibold rounded-md hover:bg-ink-soft transition">
                            Go to dashboard
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="mt-8 inline-flex items-center px-5 py-2.5 bg-ink text-white text-sm font-semibold rounded-md hover:bg-ink-soft transition">
                            Sign in
                        </a>
                    @endauth
                </div>
            </main>
        </div>
    </body>
</html>
