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
            CarListing::query()->where('is_active', true)->where('car_make_id', $resolved->id)->exists(),
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
            ->where('car_make_id', $this->carMake->id)
            ->selectRaw('count(*) as count, min(price) as min_price, max(price) as max_price')
            ->first();

        return [
            'count' => (int) $row->count,
            'minPrice' => (int) $row->min_price,
            'maxPrice' => (int) $row->max_price,
        ];
    }

    /**
     * @return array<int, array{name: string, slug: string, count: int}>
     */
    #[Computed]
    public function topModels(): array
    {
        return $this->carMake->models()
            ->withCount(['listings as count' => fn ($q) => $q->where('is_active', true)])
            ->whereHas('listings', fn ($q) => $q->where('is_active', true))
            ->orderByDesc('count')
            ->limit(12)
            ->get(['id', 'name', 'slug'])
            ->map(fn (CarModel $m): array => ['name' => $m->name, 'slug' => $m->slug, 'count' => $m->count])
            ->toArray();
    }
}
?>

<div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <h1 class="mb-6 text-2xl font-bold text-gray-900 sm:text-3xl dark:text-white">
        {{ $carMake->name }} обяви
    </h1>

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
