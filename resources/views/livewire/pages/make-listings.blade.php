<?php

use App\Models\CarListing;
use App\Models\CarMake;
use App\Models\CarModel;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.app')]
class extends Component {
    use WithPagination;

    public CarMake $carMake;

    public function mount(string $make): void
    {
        $resolved = CarMake::where('slug', $make)->firstOrFail();

        abort_unless(
            CarListing::query()->where('is_active', true)->priced()->where('car_make_id', $resolved->id)->exists(),
            404
        );

        $this->carMake = $resolved;
    }

    public function render(): View
    {
        return $this->view()->title($this->carMake->name.' автомобили');
    }

    #[Computed]
    public function listings(): LengthAwarePaginator
    {
        return CarListing::query()
            ->where('is_active', true)
            ->priced()
            ->where('car_make_id', $this->carMake->id)
            ->orderByRaw('COALESCE(published_at, created_at) desc')
            ->paginate(15);
    }

    /**
     * @return array{count: int, minPrice: int, maxPrice: int}
     */
    #[Computed]
    public function stats(): array
    {
        $row = CarListing::query()
            ->where('is_active', true)
            ->priced()
            ->where('car_make_id', $this->carMake->id)
            ->selectRaw('count(*) as count, min(price) as min_price, max(price) as max_price')
            ->first();

        return [
            'count' => (int) $row->count,
            'minPrice' => (int) $row->min_price,
            'maxPrice' => (int) $row->max_price,
        ];
    }

    public function metaDescription(): string
    {
        $stats = $this->stats;

        return sprintf(
            'Разгледай %d обяви за %s от mobile.bg, cars.bg, auto.bg и car24.bg. Цени от %s до %s EUR.',
            $stats['count'],
            $this->carMake->name,
            number_format($stats['minPrice'], 0, ',', ' '),
            number_format($stats['maxPrice'], 0, ',', ' '),
        );
    }

    /** @return array<string, mixed> */
    public function breadcrumbJsonLd(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Начало', 'item' => route('car-listings')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Марки', 'item' => route('make-index')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $this->carMake->name, 'item' => route('make-listings', $this->carMake->slug)],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function collectionPageJsonLd(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $this->carMake->name.' автомобили',
            'description' => $this->metaDescription(),
            'url' => route('make-listings', $this->carMake->slug),
        ];
    }

    /**
     * @return array<int, array{name: string, slug: string, count: int}>
     */
    #[Computed]
    public function topModels(): array
    {
        return $this->carMake->models()
            ->withCount(['listings as count' => fn ($q) => $q->where('is_active', true)->priced()])
            ->whereHas('listings', fn ($q) => $q->where('is_active', true)->priced())
            ->orderByDesc('count')
            ->limit(12)
            ->get(['id', 'name', 'slug'])
            ->map(fn (CarModel $m): array => ['name' => $m->name, 'slug' => $m->slug, 'count' => $m->count])
            ->toArray();
    }
}
?>

<div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    @push('seo')
        <link rel="canonical" href="{{ route('make-listings', $carMake->slug) }}">
        <meta name="description" content="{{ $this->metaDescription() }}">
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ $carMake->name }} автомобили - AutoSearch">
        <meta property="og:description" content="{{ $this->metaDescription() }}">
        <meta property="og:url" content="{{ route('make-listings', $carMake->slug) }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $carMake->name }} автомобили - AutoSearch">
        <meta name="twitter:description" content="{{ $this->metaDescription() }}">

        <script type="application/ld+json">{!! json_encode($this->breadcrumbJsonLd(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        <script type="application/ld+json">{!! json_encode($this->collectionPageJsonLd(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endpush

    <nav class="mb-4 text-sm text-gray-500 dark:text-gray-400" aria-label="breadcrumb">
        <a href="{{ route('car-listings') }}" class="hover:text-blue-600" wire:navigate>Начало</a>
        <span class="mx-1">/</span>
        <a href="{{ route('make-index') }}" class="hover:text-blue-600" wire:navigate>Марки</a>
        <span class="mx-1">/</span>
        <span class="text-gray-700 dark:text-gray-300">{{ $carMake->name }}</span>
    </nav>

    <h1 class="mb-2 text-2xl font-bold text-gray-900 sm:text-3xl dark:text-white">
        {{ $carMake->name }} обяви
    </h1>

    <p class="mb-6 max-w-3xl text-gray-600 dark:text-gray-300">
        {{ $this->metaDescription() }}
    </p>

    @if(count($this->topModels) > 0)
        <div class="mb-6 flex flex-wrap gap-2">
            @foreach($this->topModels as $model)
                <a
                    href="{{ route('car-listings', ['make' => $carMake->slug, 'model' => $model['slug']]) }}"
                    wire:navigate
                    class="rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-700 hover:bg-blue-100 hover:text-blue-700 dark:bg-gray-700 dark:text-gray-200"
                >
                    {{ $model['name'] }} <span class="text-gray-400">({{ $model['count'] }})</span>
                </a>
            @endforeach
        </div>
    @endif

    @if($this->listings->count() > 0)
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 py-5">
            @foreach($this->listings as $car)
                <x-car-listing-card :listing="$car" wire:key="car-{{ $car->id }}" />
            @endforeach
        </div>

        <div>{{ $this->listings->links() }}</div>
    @else
        <p class="text-gray-600 dark:text-gray-300">Няма намерени обяви.</p>
    @endif
</div>
