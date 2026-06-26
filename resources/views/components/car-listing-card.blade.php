@props(['listing'])

<a
    href="{{ url('/cars/'.$listing->id) }}"
    wire:navigate
    {{ $attributes->merge(['class' => 'group flex flex-col overflow-hidden rounded-lg bg-white shadow-sm transition-shadow hover:shadow-md dark:bg-gray-800']) }}
>
    {{-- Image --}}
    <div class="aspect-4/3 w-full overflow-hidden bg-gray-100 dark:bg-gray-700">
        @if($listing->image_url)
            <img
                class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                src="{{ $listing->displayImageUrl() }}"
                alt="{{ $listing->title }}"
                loading="lazy"
            />
            <div class="hidden h-full w-full items-center justify-center text-gray-400">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
            </div>
        @else
            <div class="flex h-full w-full items-center justify-center text-gray-400">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
            </div>
        @endif
    </div>

    {{-- Content --}}
    <div class="flex flex-1 flex-col p-4">
        {{-- Badges --}}
        <div class="mb-2 flex flex-wrap gap-2">
            @if($listing->fuel_type)
                <span class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/50 dark:text-green-300">
                    {{ __("fuels.$listing->fuel_type") }}
                </span>
            @endif
            @if($listing->transmission)
                <span class="rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900/50 dark:text-purple-300">
                    {{ __("transmission.$listing->transmission") }}
                </span>
            @endif
        </div>

        {{-- Title --}}
        <h3 class="mb-1 line-clamp-2 text-lg font-semibold text-gray-900 group-hover:text-blue-600 dark:text-white dark:group-hover:text-blue-400">
            {{ $listing->title ?? 'Без заглавие' }}
        </h3>

        {{-- Location --}}
        @if($listing->location)
            <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">
                <svg xmlns="http://www.w3.org/2000/svg" class="mr-1 inline-block h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" />
                </svg>
                {{ $listing->location }}
            </p>
        @endif

        {{-- Details --}}
        <div class="mb-2 flex items-center gap-4 text-sm text-gray-600 dark:text-gray-400">
            <span>{{ $listing->year }} г.</span>
            <span class="text-gray-300 dark:text-gray-600">|</span>
            <span>{{ number_format($listing->mileage, 0, ',', ' ') }} км</span>
        </div>

        {{-- Price - pushed to bottom --}}
        <div class="mt-auto">
            <span class="text-xl font-bold text-blue-600 dark:text-blue-400">
                {{ number_format($listing->price, 0, ',', ' ') }} EUR
            </span>
            <span class="ml-1 text-sm text-gray-500 dark:text-gray-400">
                ({{ number_format($listing->price_bgn, 0, ',', ' ') }} лв)
            </span>
        </div>

        {{-- Publication dates & per-source badges --}}
        @php($firstPublished = $listing->firstPublishedAt())
        @if($listing->source_dates && count($listing->source_dates) > 0)
            <div class="mt-4 border-t border-gray-100 dark:border-gray-700">
                @if($firstPublished)
                    <p class="  text-xs py-2 text-gray-500 dark:text-gray-400">
                        Създадена: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $firstPublished->format('d.m.Y') }}</span>
                        @if($listing->published_at && $listing->published_at->gt($firstPublished))
                            · Обновена: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $listing->published_at->format('d.m.Y') }}</span>
                        @endif
                    </p>
                @endif
                <div class="flex flex-wrap gap-2 py-4">
                    @foreach($listing->source_dates as $source => $date)
                        @php($sourceDate = $listing->sourceDate($source))
                        <span
                            wire:key="src-{{ $listing->id }}-{{ $source }}"
                            class="inline-flex items-center gap-2 rounded-lg bg-gray-100 px-4 py-2 font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-300"
                        >
                            <span class="flex flex-col items-start leading-tight">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $source }}</span>
                                @if($sourceDate)
                                    <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ $sourceDate->format('d.m.Y') }}</span>
                                @endif
                            </span>
                        </span>
                    @endforeach
                </div>
            </div>
        @elseif($listing->source_urls && count($listing->source_urls) > 0)
            {{-- Records not yet re-scraped have no per-source dates: show plain badges. --}}
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach($listing->source_urls as $source => $url)
                    <span wire:key="srcurl-{{ $listing->id }}-{{ $source }}" class="inline-flex items-center gap-2 rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                        {{ $listing->sourceLabel($url) }}
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z" />
                            <path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z" />
                        </svg>
                    </span>
                @endforeach
            </div>
        @endif
    </div>
</a>
