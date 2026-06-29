<!DOCTYPE html>
<html lang="bg" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Търсене на автомобили' }} - AutoSearch</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

    @stack('seo')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased dark:bg-gray-900 dark:text-gray-100">
    <header class="sticky top-0 z-50 border-b border-gray-200 bg-white/80 backdrop-blur-sm dark:border-gray-700 dark:bg-gray-800/80">
        <nav class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
            <a href="{{ url('/') }}" class="flex items-center gap-2 text-xl font-bold text-blue-600 dark:text-blue-400">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/>
                    <circle cx="7" cy="17" r="2"/>
                    <path d="M9 17h6"/>
                    <circle cx="17" cy="17" r="2"/>
                </svg>
                <span>AutoSearch</span>
            </a>

            <div class="flex items-center gap-4">
                <a href="{{ url('/') }}" class="text-sm font-medium text-gray-600 transition-colors hover:text-blue-600 dark:text-gray-300 dark:hover:text-blue-400">
                    Обяви
                </a>
            </div>
        </nav>
    </header>

    <main>
        {{ $slot }}
    </main>

    <footer class="mt-auto border-t border-gray-200 bg-white py-8 dark:border-gray-700 dark:bg-gray-800 sticky">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col items-center justify-between gap-4 sm:flex-row">
                <p class="text-sm text-gray-500 dark:text-white">
                    AutoSearch - Агрегатор на автомобилни обяви от България
                </p>
                <a href="{{ route('make-index') }}" class="text-sm text-gray-500 hover:text-blue-600 dark:text-white dark:hover:text-gray-300" wire:navigate>Марки</a>
                <div class="flex items-center gap-4 text-sm text-gray-500 dark:text-white">
                    <a class="dark:hover:text-gray-300" href="https://www.cars.bg/" target="_blank">cars.bg</a>
                    <a class="dark:hover:text-gray-300" href="https://www.mobile.bg/" target="_blank">mobile.bg</a>
                    <a class="dark:hover:text-gray-300" href="https://www.auto.bg/" target="_blank">auto.bg</a>
                    <a class="dark:hover:text-gray-300" href="https://www.car24.bg/" target="_blank">car24.bg</a>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>
