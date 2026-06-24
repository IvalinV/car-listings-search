<?php

use App\Models\CarListing;
use App\Models\CarMake;
use App\Models\CarModel;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.app')]
#[Title('Търсене на автомобили')]
class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $fuelType = '';

    #[Url]
    public string $transmission = '';

    #[Url]
    public ?int $minPrice = null;

    #[Url]
    public ?int $maxPrice = null;

    #[Url]
    public ?int $minYear = null;

    #[Url]
    public ?int $maxYear = null;

    #[Url]
    public string $location = '';

    #[Url]
    public string $make = '';

    #[Url]
    public string $model = '';

    #[Url]
    public string $sortBy = 'published_at';

    #[Url]
    public string $sortDirection = 'desc';

    public bool $showFilters = false;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFuelType(): void
    {
        $this->resetPage();
    }

    public function updatedTransmission(): void
    {
        $this->resetPage();
    }

    public function updatedMinPrice(): void
    {
        $this->resetPage();
    }

    public function updatedMaxPrice(): void
    {
        $this->resetPage();
    }

    public function updatedMinYear(): void
    {
        $this->resetPage();
    }

    public function updatedMaxYear(): void
    {
        $this->resetPage();
    }

    public function updatedLocation(): void
    {
        $this->resetPage();
    }

    public function updatedMake(): void
    {
        $this->model = '';
        $this->resetPage();
    }

    public function updatedModel(): void
    {
        $this->resetPage();
    }

    public function updatedSortBy(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'fuelType', 'transmission', 'minPrice', 'maxPrice', 'minYear', 'maxYear', 'location', 'make', 'model']);
        $this->resetPage();
    }

    public function setSort(string $field): void
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    #[Computed]
    public function activeFilterCount(): int
    {
        $count = 0;
        if ($this->search) $count++;
        if ($this->fuelType) $count++;
        if ($this->transmission) $count++;
        if ($this->minPrice) $count++;
        if ($this->maxPrice) $count++;
        if ($this->minYear) $count++;
        if ($this->maxYear) $count++;
        if ($this->location) $count++;
        if ($this->make) $count++;
        if ($this->model) $count++;
        return $count;
    }

    #[Computed]
    public function fuelTypes(): array
    {
        return CarListing::query()
            ->whereNotNull('fuel_type')
            ->where('fuel_type', '!=', '')
            ->distinct()
            ->pluck('fuel_type')
            ->sort()
            ->values()
            ->toArray();
    }

    #[Computed]
    public function transmissions(): array
    {
        return CarListing::query()
            ->whereNotNull('transmission')
            ->where('transmission', '!=', '')
            ->distinct()
            ->pluck('transmission')
            ->sort()
            ->values()
            ->toArray();
    }

    #[Computed]
    public function locations(): array
    {
        $query = CarListing::query()
            ->whereNotNull('location')
            ->where('location', '!=', '')
            ->distinct()
            ->pluck('location')
            ->sort();

       return $query->map(function (string $item, int $key) {
           $result = explode(',', $item);

           return count($result) > 1  ? \Arr::get($result, 1) : \Arr::first($result);
       })
           ->values()
           ->unique()
           ->toArray();
    }

    protected function selectedMake(): ?CarMake
    {
        if ($this->make === '' || $this->make === 'unspecified') {
            return null;
        }

        return CarMake::where('slug', $this->make)->first();
    }

    #[Computed]
    public function makes(): array
    {
        $counts = CarListing::query()
            ->where('is_active', true)
            ->whereNotNull('car_make_id')
            ->selectRaw('car_make_id, count(*) as total')
            ->groupBy('car_make_id')
            ->pluck('total', 'car_make_id');

        $makes = CarMake::whereIn('id', $counts->keys())
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (CarMake $m): array => ['slug' => $m->slug, 'name' => $m->name, 'count' => $counts[$m->id]])
            ->toArray();

        $nullMakeCount = CarListing::where('is_active', true)->whereNull('car_make_id')->count();

        if ($nullMakeCount > 0) {
            $makes[] = ['slug' => 'unspecified', 'name' => 'Без марка', 'count' => $nullMakeCount];
        }

        return $makes;
    }

    #[Computed]
    public function models(): array
    {
        $make = $this->selectedMake();

        if ($make === null) {
            return [];
        }

        $models = $make->models()
            ->withCount(['listings as count' => fn ($q) => $q->where('is_active', true)])
            ->whereHas('listings', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (CarModel $m): array => ['slug' => $m->slug, 'name' => $m->name, 'count' => $m->count])
            ->toArray();

        $nullModelCount = CarListing::where('is_active', true)
            ->where('car_make_id', $make->id)
            ->whereNull('car_model_id')
            ->count();

        if ($nullModelCount > 0) {
            $models[] = ['slug' => 'unspecified', 'name' => 'Без модел', 'count' => $nullModelCount];
        }

        return $models;
    }

    #[Computed]
    public function listings(): \Illuminate\Pagination\LengthAwarePaginator|array
    {
        $make = $this->selectedMake();
        $modelId = null;

        if ($make !== null && $this->model !== '' && $this->model !== 'unspecified') {
            $modelId = $make->models()->where('slug', $this->model)->value('id');
        }

        return CarListing::query()
            ->where('is_active', true)
            ->when($this->search, function ($q) {
                $term = $this->search;
                $makeIds = CarMake::where('name', 'like', '%' . $term . '%')->pluck('id');
                $modelIds = CarModel::where('name', 'like', '%' . $term . '%')->pluck('id');

                $q->where(function ($q) use ($term, $makeIds, $modelIds) {
                    $q->where('title', 'like', '%' . $term . '%')
                      ->orWhere('description', 'like', '%' . $term . '%')
                      ->when($makeIds->isNotEmpty(), fn ($q) => $q->orWhereIn('car_make_id', $makeIds))
                      ->when($modelIds->isNotEmpty(), fn ($q) => $q->orWhereIn('car_model_id', $modelIds));
                });
            })
            ->when($this->make === 'unspecified', fn ($q) => $q->whereNull('car_make_id'))
            ->when($make, fn ($q) => $q->where('car_make_id', $make->id))
            ->when($make && $this->model === 'unspecified', fn ($q) => $q->whereNull('car_model_id'))
            ->when($modelId, fn ($q) => $q->where('car_model_id', $modelId))
            ->when($this->fuelType, fn ($q) => $q->where('fuel_type', $this->fuelType))
            ->when($this->transmission, fn ($q) => $q->where('transmission', $this->transmission))
            ->when($this->location, fn ($q) => $q->where('location', 'LIKE', "%$this->location%"))
            ->when($this->minPrice, fn ($q) => $q->where('price', '>=', $this->minPrice))
            ->when($this->maxPrice, fn ($q) => $q->where('price', '<=', $this->maxPrice))
            ->when($this->minYear, fn ($q) => $q->where('year', '>=', $this->minYear))
            ->when($this->maxYear, fn ($q) => $q->where('year', '<=', $this->maxYear))
            ->tap(fn ($q) => $this->applySorting($q))
            ->paginate(15);
    }

    /**
     * Apply a validated sort to the listings query.
     *
     * Most listings have no `published_at` yet, so sorting by it falls back to
     * `created_at`. This also avoids Postgres ordering NULLs first on a DESC sort,
     * which would otherwise bury every dated listing beneath the date-less ones.
     */
    protected function applySorting(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $sortableColumns = ['published_at', 'price', 'year', 'mileage'];
        $sortBy = in_array($this->sortBy, $sortableColumns, true) ? $this->sortBy : 'published_at';
        $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'published_at') {
            $query->orderByRaw("COALESCE(published_at, created_at) {$direction}");
        } else {
            $query->orderBy($sortBy, $direction);
        }
    }

    public function getSource($url)
    {
        if (\Str::contains($url, 'cars.bg')) {
            return 'cars.bg';
        } else if (\Str::contains($url, 'car24.bg')) {
            return 'car24.bg';
        } else if (\Str::contains($url, 'mobile.bg')){
            return 'mobile.bg';
        } else if(\Str::contains($url, 'auto.bg')){
            return 'auto.bg';
        }
    }

    public function formatImageUrl($url)
    {
        if (!is_null($url) && !str_starts_with($url, 'https://')) {
            return "https://$url";
        }

        return $url;
    }
}
?>

<div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    @php
        $metaDescription = 'Хиляди обяви за автомобили втора ръка и нови от cars.bg, mobile.bg, auto.bg и car24.bg — търсене и филтриране на едно място.';
    @endphp

    @push('seo')
        <link rel="canonical" href="{{ route('car-listings') }}">
        <meta name="description" content="{{ $metaDescription }}">
        <meta property="og:type" content="website">
        <meta property="og:title" content="Търсене на автомобили - AutoSearch">
        <meta property="og:description" content="{{ $metaDescription }}">
        <meta property="og:url" content="{{ route('car-listings') }}">
        <meta property="og:image" content="{{ asset('images/marketing-image.png') }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="Търсене на автомобили - AutoSearch">
        <meta name="twitter:description" content="{{ $metaDescription }}">
        <meta name="twitter:image" content="{{ asset('images/marketing-image.png') }}">
    @endpush

    {{-- Page Heading --}}
    <h1 class="mb-6 text-2xl font-bold text-gray-900 sm:text-3xl dark:text-white">
        Обяви за автомобили втора ръка и нови
    </h1>

    {{-- Mobile Filter Toggle --}}
    <div class="mb-4 lg:hidden">
        <button
            wire:click="$toggle('showFilters')"
            class="flex w-full items-center justify-between rounded-lg bg-white px-4 py-3 shadow-sm dark:bg-gray-800"
        >
            <span class="font-medium">
                Филтри
                @if($this->activeFilterCount > 0)
                    <span class="ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-600 dark:bg-blue-900 dark:text-blue-300">
                        {{ $this->activeFilterCount }}
                    </span>
                @endif
            </span>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform {{ $showFilters ? 'rotate-180' : '' }}" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
        </button>
    </div>

    <div class="flex flex-col gap-6 lg:flex-row">
        {{-- Filters Sidebar --}}
        <aside class="{{ $showFilters ? 'block' : 'hidden' }} lg:block lg:w-72 lg:shrink-0">
            <div class="sticky top-20 space-y-4 rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">Филтри</h2>
                    @if($this->activeFilterCount > 0)
                        <button
                            wire:click="clearFilters"
                            class="text-sm text-blue-600 hover:text-blue-700 cursor-pointer dark:text-blue-400 dark:hover:text-blue-300"
                        >
                            Изчисти филтрите
                        </button>
                    @endif
                </div>

                {{-- Search --}}
                <div>
                    <label for="search" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Търсене</label>
                    <input
                        wire:model.live.debounce.300ms="search"
                        type="text"
                        id="search"
                        placeholder="Марка, модел..."
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400"
                    >
                </div>

                {{-- Make --}}
                <div>
                    <label for="make" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Марка</label>
                    <select
                        wire:model.live="make"
                        id="make"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                    >
                        <option value="">Всички</option>
                        @foreach($this->makes as $makeOption)
                            <option value="{{ $makeOption['slug'] }}">{{ $makeOption['name'] }} ({{ $makeOption['count'] }})</option>
                        @endforeach
                    </select>
                </div>

                {{-- Model --}}
                <div>
                    <label for="model" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Модел</label>
                    <select
                        wire:model.live="model"
                        id="model"
                        @disabled($this->make === '')
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                    >
                        <option value="">Всички</option>
                        @foreach($this->models as $modelOption)
                            <option value="{{ $modelOption['slug'] }}">{{ $modelOption['name'] }} ({{ $modelOption['count'] }})</option>
                        @endforeach
                    </select>
                </div>

                {{-- Fuel Type --}}
                <div>
                    <label for="fuelType" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Гориво</label>
                    <select
                        wire:model.live="fuelType"
                        id="fuelType"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                    >
                        <option value="">Всички</option>
                        @foreach($this->fuelTypes as $type)
                            <option value="{{ $type }}">{{ __("fuels.$type") }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Transmission --}}
                <div>
                    <label for="transmission" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Скоростна кутия</label>
                    <select
                        wire:model.live="transmission"
                        id="transmission"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                    >
                        <option value="">Всички</option>
                        @foreach($this->transmissions as $trans)
                            <option value="{{ $trans }}">{{ __("transmission.$trans") }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- Location --}}
                <div>
                    <label for="location" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Местоположение</label>
                    <select
                        wire:model.live="location"
                        id="location"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                    >
                        <option value="">Всички</option>
                        @foreach($this->locations as $loc)
                            <option value="{{ $loc }}">{{ $loc }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Price Range --}}
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Цена (EUR)</label>
                    <div class="flex items-center gap-2">
                        <input
                            wire:model.live.debounce.500ms="minPrice"
                            type="number"
                            placeholder="От"
                            min="0"
                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400"
                        >
                        <span class="text-gray-400">-</span>
                        <input
                            wire:model.live.debounce.500ms="maxPrice"
                            type="number"
                            placeholder="До"
                            min="0"
                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400"
                        >
                    </div>
                </div>

                {{-- Year Range --}}
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Година</label>
                    <div class="flex items-center gap-2">
                        <input
                            wire:model.live.debounce.500ms="minYear"
                            type="number"
                            placeholder="От"
                            min="1900"
                            max="{{ date('Y') + 1 }}"
                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400"
                        >
                        <span class="text-gray-400">-</span>
                        <input
                            wire:model.live.debounce.500ms="maxYear"
                            type="number"
                            placeholder="До"
                            min="1900"
                            max="{{ date('Y') + 1 }}"
                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400"
                        >
                    </div>
                </div>

                {{-- Sort --}}
                <div>
                    <label for="sortBy" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Сортирай по</label>
                    <select
                        wire:model.live="sortBy"
                        id="sortBy"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                    >
                        <option value="published_at">Дата на публикуване</option>
                        <option value="price">Цена</option>
                        <option value="year">Година</option>
                        <option value="mileage">Пробег</option>
                    </select>
                    <div class="mt-2 flex gap-2">
                        <button
                            wire:click="$set('sortDirection', 'asc')"
                            class="flex-1 rounded-lg px-3 py-1.5 text-sm font-medium cursor-pointer transition-colors {{ $sortDirection === 'asc' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600' }}"
                        >
                            Възходящо
                        </button>
                        <button
                            wire:click="$set('sortDirection', 'desc')"
                            class="flex-1 rounded-lg px-3 py-1.5 text-sm font-medium cursor-pointer transition-colors {{ $sortDirection === 'desc' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600' }}"
                        >
                            Низходящо
                        </button>
                    </div>
                </div>
            </div>
        </aside>

        {{-- Main Content --}}
        <div class="flex-1">
            {{-- Results Header --}}
            <div class="mb-4 flex items-center justify-between">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    <span wire:loading.remove wire:target="search, make, model, fuelType, transmission, location, minPrice, maxPrice, minYear, maxYear, sortBy, sortDirection">
                        Намерени <strong>{{ $this->listings->total() }}</strong> обяви
                    </span>
                    <span wire:loading wire:target="search, make, model, fuelType, transmission, location, minPrice, maxPrice, minYear, maxYear, sortBy, sortDirection">
                        Зареждане...
                    </span>
                </p>
            </div>

            {{-- Loading Overlay --}}
            <div wire:loading.delay wire:target="search, make, model, fuelType, transmission, location, minPrice, maxPrice, minYear, maxYear, sortBy, sortDirection, gotoPage, previousPage, nextPage" class="fixed inset-0 z-50 flex items-center justify-center bg-black/20">
                <div class="rounded-lg bg-white px-6 py-4 shadow-lg dark:bg-gray-800">
                    <div class="flex items-center gap-3">
                        <svg class="h-5 w-5 animate-spin text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="font-medium">Зареждане...</span>
                    </div>
                </div>
            </div>

            {{-- Pagination --}}
            <div class="mt-6  cursor-pointer!">
                {{ $this->listings->links() }}
            </div>

            {{-- Car Grid --}}
            @if($this->listings->count() > 0)
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 py-5">
                    @foreach($this->listings as $car)
                        <a
                            href="{{ url('/cars/' . $car->id) }}"
                            wire:key="car-{{ $car->id }}"
                            wire:navigate
                            class="group flex flex-col overflow-hidden rounded-lg bg-white shadow-sm transition-shadow hover:shadow-md dark:bg-gray-800"
                        >
                            {{-- Image --}}
                            <div class="aspect-4/3 w-full overflow-hidden bg-gray-100 dark:bg-gray-700">
                                @if($car->image_url)
                                    <img
                                        class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                        src="{{ $this->formatImageUrl($car->image_url) }}"
                                        alt="{{ $car->title }}"
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
                                    @if($car->fuel_type)
                                        <span class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/50 dark:text-green-300">
                                            {{ __("fuels.$car->fuel_type") }}
                                        </span>
                                    @endif
                                    @if($car->transmission)
                                        <span class="rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900/50 dark:text-purple-300">
                                            {{ __("transmission.$car->transmission") }}
                                        </span>
                                    @endif
                                </div>

                                {{-- Title --}}
                                <h3 class="mb-1 line-clamp-2 text-lg font-semibold text-gray-900 group-hover:text-blue-600 dark:text-white dark:group-hover:text-blue-400">
                                    {{ $car->title ?? 'Без заглавие' }}
                                </h3>

                                {{-- Location --}}
                                @if($car->location)
                                    <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="mr-1 inline-block h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" />
                                        </svg>
                                        {{ $car->location }}
                                    </p>
                                @endif

                                {{-- Details --}}
                                <div class="mb-2 flex items-center gap-4 text-sm text-gray-600 dark:text-gray-400">
                                    <span>{{ $car->year }} г.</span>
                                    <span class="text-gray-300 dark:text-gray-600">|</span>
                                    <span>{{ number_format($car->mileage, 0, ',', ' ') }} км</span>
                                </div>

                                {{-- Price - pushed to bottom --}}
                                <div class="mt-auto">
                                    <span class="text-xl font-bold text-blue-600 dark:text-blue-400">
                                        {{ number_format($car->price, 0, ',', ' ') }} EUR
                                    </span>
                                    <span class="ml-1 text-sm text-gray-500 dark:text-gray-400">
                                        ({{ number_format($car->price_bgn, 0, ',', ' ') }} лв)
                                    </span>
                                </div>

                                {{-- Publication dates & per-source badges --}}
                                @php($firstPublished = $car->firstPublishedAt())
                                @if($car->source_dates && count($car->source_dates) > 0)
                                    <div class="mt-4 border-t border-gray-100 dark:border-gray-700">
                                        @if($firstPublished)
                                            <p class="  text-xs text-gray-500 dark:text-gray-400">
                                                Създадена: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $firstPublished->format('d.m.Y') }}</span>
                                                @if($car->published_at && $car->published_at->gt($firstPublished))
                                                    · Обновена: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $car->published_at->format('d.m.Y') }}</span>
                                                @endif
                                            </p>
                                        @endif
                                        <div class="flex flex-wrap gap-2 py-4">
                                            @foreach($car->source_dates as $source => $date)
                                                @php($sourceDate = $car->sourceDate($source))
                                                <span
                                                    wire:key="src-{{ $car->id }}-{{ $source }}"
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
                                @elseif($car->source_urls && count($car->source_urls) > 0)
                                    {{-- Records not yet re-scraped have no per-source dates: show plain badges. --}}
                                    <div class="mt-4 flex flex-wrap gap-2">
                                        @foreach($car->source_urls as $source => $url)
                                            <span wire:key="srcurl-{{ $car->id }}-{{ $source }}" class="inline-flex items-center gap-2 rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                                {{ $this->getSource($url)}}
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
                    @endforeach
                </div>

                {{-- Pagination --}}
                <div>
                    {{ $this->listings->links() }}
                </div>
            @else
                {{-- Empty State --}}
                <div class="rounded-lg bg-white p-12 text-center shadow-sm dark:bg-gray-800">
                    <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-16 w-16 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                    <h3 class="mt-4 text-lg font-semibold text-gray-900 dark:text-white">Няма резултати</h3>
                    <p class="mt-2 text-gray-500 dark:text-gray-400">Опитайте да промените филтрите за търсене</p>
                    @if($this->activeFilterCount > 0)
                        <button
                            wire:click="clearFilters"
                            class="mt-4 rounded-lg bg-blue-600 px-4 py-2 text-sm cursor-pointer font-medium text-white transition-colors hover:bg-blue-700"
                        >
                            Изчисти филтрите
                        </button>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
