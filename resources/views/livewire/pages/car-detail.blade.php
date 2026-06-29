<?php

use App\Models\CarListing;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.app')]
class extends Component {
    public CarListing $carListing;

    public function mount(CarListing $carListing): void
    {
        if (!$carListing->is_active) {
            abort(404);
        }

        $this->carListing = $carListing;
    }

    public function render(): View
    {
        return $this->view()->title($this->getTitle());
    }

    public function getTitle(): string
    {
        return $this->carListing->title ?? 'Детайли за автомобил';
    }

    public function metaDescription(): string
    {
        $car = $this->carListing;

        $facts = array_filter([
            $car->year ? $car->year.' г.' : null,
            $car->mileage ? number_format($car->mileage, 0, ',', ' ').' км' : null,
            $car->fuel_type ? __("fuels.$car->fuel_type") : null,
            number_format((float) $car->price, 0, ',', ' ').' EUR',
            $car->location,
        ]);

        return ($car->title ? $car->title.' — ' : '').implode(', ', $facts).'. Вижте обявата в AutoSearch.';
    }

    /**
     * Schema.org Car + Offer structured data for rich results.
     *
     * @return array<string, mixed>
     */
    public function structuredData(): array
    {
        $car = $this->carListing;

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Car',
            'name' => $car->title,
            'vehicleModelDate' => (string) $car->year,
            'mileageFromOdometer' => [
                '@type' => 'QuantitativeValue',
                'value' => $car->mileage,
                'unitCode' => 'KMT',
            ],
            'offers' => [
                '@type' => 'Offer',
                'price' => number_format((float) $car->price, 2, '.', ''),
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
                'url' => route('car-detail', $car),
            ],
        ];

        if ($car->description) {
            $data['description'] = $car->description;
        }

        if ($car->image_url) {
            $data['image'] = $car->displayImageUrl();
        }

        if ($car->fuel_type) {
            $data['fuelType'] = $car->fuel_type;
        }

        if ($car->transmission) {
            $data['vehicleTransmission'] = $car->transmission;
        }

        return $data;
    }

}
?>

<div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
    @push('seo')
        <link rel="canonical" href="{{ route('car-detail', $carListing) }}">
        <meta name="description" content="{{ $this->metaDescription() }}">
        <meta property="og:type" content="product">
        <meta property="og:title" content="{{ $carListing->title }}">
        <meta property="og:description" content="{{ $this->metaDescription() }}">
        <meta property="og:url" content="{{ route('car-detail', $carListing) }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $carListing->title }}">
        <meta name="twitter:description" content="{{ $this->metaDescription() }}">
        @if($carListing->image_url)
            <meta property="og:image" content="{{ $carListing->displayImageUrl() }}">
            <meta name="twitter:image" content="{{ $carListing->displayImageUrl() }}">
        @endif
        <script type="application/ld+json">{!! json_encode($this->structuredData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endpush

    {{-- Back Link --}}
    <a
        href="{{ url('/') }}"
        wire:navigate
        class="mb-6 inline-flex items-center gap-2 text-sm font-medium text-blue-600 transition-colors hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd" />
        </svg>
        Обратно към обявите
    </a>

    {{-- Main Card --}}
    <div class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
        {{-- Header --}}
        <div class="border-b border-gray-200 p-6 dark:border-gray-700">
            {{-- Badges --}}
            <div class="mb-4 flex flex-wrap gap-2">
                @if($carListing->fuel_type)
                    <span class="rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-700 dark:bg-green-900/50 dark:text-green-300">
                        {{ __("fuels.$carListing->fuel_type") }}
                    </span>
                @endif
                @if($carListing->transmission)
                    <span class="rounded-full bg-purple-100 px-3 py-1 text-sm font-medium text-purple-700 dark:bg-purple-900/50 dark:text-purple-300">
                        {{ __("transmission.$carListing->transmission") }}
                    </span>
                @endif
            </div>

            {{-- Title --}}
            <h1 class="mb-2 text-2xl font-bold text-gray-900 sm:text-3xl dark:text-white">
                {{ $carListing->title ?? 'Без заглавие' }}
            </h1>

            {{-- Location --}}
            @if($carListing->location)
                <p class="flex items-center gap-1 text-gray-500 dark:text-gray-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" />
                    </svg>
                    {{ $carListing->location }}
                </p>
            @endif
        </div>

        {{-- Price Section --}}
        <div class="border-b border-gray-200 bg-gray-50 p-6 dark:border-gray-700 dark:bg-gray-800/50">
            <div class="flex flex-wrap items-baseline gap-3">
                <span class="text-3xl font-bold text-blue-600 sm:text-4xl dark:text-blue-400">
                    {{ number_format($carListing->price, 0, ',', ' ') }} EUR
                </span>
                <span class="text-xl text-gray-500 dark:text-gray-400">
                    ({{ number_format($carListing->price_bgn, 0, ',', ' ') }} лв)
                </span>
            </div>
        </div>

        @if($carListing->image_url)
            <div class="border-b border-gray-200 bg-gray-50 p-6 dark:border-gray-700 dark:bg-gray-800/50">
                <div class="flex items-center justify-center">
                    <img
                        class="max-h-96 w-auto rounded-lg object-contain"
                        src="{{ $carListing->displayImageUrl() }}"
                        alt="{{ $carListing->title }}"
                    />
                </div>
            </div>
            @else
                <div class="flex h-full w-full items-center justify-center text-gray-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </div>
        @endif

        {{-- Details Grid --}}
        <div class="grid gap-6 p-6 sm:grid-cols-2">
            {{-- Year --}}
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/50">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600 dark:text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Година</p>
                    <p class="font-semibold text-gray-900 dark:text-white">{{ $carListing->year }} г.</p>
                </div>
            </div>

            {{-- Mileage --}}
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/50">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600 dark:text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Пробег</p>
                    <p class="font-semibold text-gray-900 dark:text-white">{{ number_format($carListing->mileage, 0, ',', ' ') }} км</p>
                </div>
            </div>

            {{-- Fuel Type --}}
            @if($carListing->fuel_type)
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900/50">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-600 dark:text-green-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h3a1 1 0 011 1v3a1 1 0 01-1 1H4a1 1 0 01-1-1V4zm2 2V5h1v1H5zM3 13a1 1 0 011-1h3a1 1 0 011 1v3a1 1 0 01-1 1H4a1 1 0 01-1-1v-3zm2 2v-1h1v1H5zM13 3a1 1 0 00-1 1v3a1 1 0 001 1h3a1 1 0 001-1V4a1 1 0 00-1-1h-3zm1 2v1h1V5h-1z" clip-rule="evenodd" />
                            <path d="M11 4a1 1 0 10-2 0v1a1 1 0 002 0V4zM10 7a1 1 0 011 1v1h2a1 1 0 110 2h-3a1 1 0 01-1-1V8a1 1 0 011-1zM16 9a1 1 0 100 2 1 1 0 000-2zM9 13a1 1 0 011-1h1a1 1 0 110 2v2a1 1 0 11-2 0v-3zM7 11a1 1 0 100-2H4a1 1 0 100 2h3zM17 13a1 1 0 00-1 1v1h-2a1 1 0 100 2h3a1 1 0 001-1v-2a1 1 0 00-1-1zM16 17a1 1 0 100-2 1 1 0 000 2z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Гориво</p>
                        <p class="font-semibold text-gray-900 dark:text-white">{{ __("fuels.$carListing->fuel_type") }}</p>
                    </div>
                </div>
            @endif

            {{-- Transmission --}}
            @if($carListing->transmission)
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-100 dark:bg-purple-900/50">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-purple-600 dark:text-purple-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Скоростна кутия</p>
                        <p class="font-semibold text-gray-900 dark:text-white">{{ __("transmission.$carListing->transmission") }}</p>
                    </div>
                </div>
            @endif
        </div>

        {{-- Description --}}
        @if($carListing->description)
            <div class="border-t border-gray-200 p-6 dark:border-gray-700">
                <h2 class="mb-3 text-lg font-semibold text-gray-900 dark:text-white">Описание</h2>
                <div class="prose prose-gray max-w-none dark:prose-invert">
                    <p class="whitespace-pre-line text-gray-600 dark:text-gray-300">{{ $carListing->description }}</p>
                </div>
            </div>
        @endif

        {{-- Source Links --}}
        @if($carListing->source_urls && count($carListing->source_urls) > 0)
            <div class="border-t border-gray-200 p-6 dark:border-gray-700">
                <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Вижте пълната обява в:</h2>
                <div class="flex flex-wrap gap-3">
                    @foreach($carListing->source_urls as $url)
                        @php($source = $carListing->sourceLabel($url))
                        @php($sourceDate = $carListing->sourceDate($source))
                        <a
                            href="{{ $url }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            wire:key="source-{{ $loop->index }}"
                            class="inline-flex items-center gap-2 rounded-lg bg-gray-100 px-4 py-2 font-medium text-gray-700 transition-colors hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                        >
                            <span class="flex flex-col items-start leading-tight">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $source }}</span>
                                @if($sourceDate)
                                    <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ $sourceDate->format('d.m.Y') }}</span>
                                @endif
                            </span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z" />
                                <path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z" />
                            </svg>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Meta Info --}}
        <div class="border-t border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-800/50">
            @php($firstPublished = $carListing->firstPublishedAt())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if($firstPublished)
                    Създадена: {{ $firstPublished->format('d.m.Y') }}
                    @if($carListing->published_at && $carListing->published_at->gt($firstPublished))
                        | Обновена: {{ $carListing->published_at->format('d.m.Y') }}
                    @endif
                @elseif($carListing->published_at)
                    Публикувана: {{ $carListing->published_at->format('d.m.Y') }}
                @endif
            </p>
        </div>
    </div>
</div>

