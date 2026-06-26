<?php

use App\Models\CarListing;
use App\Models\CarMake;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.app')]
class extends Component {
    public function render(): View
    {
        return $this->view()->title('Марки автомобили');
    }

    /**
     * @return array<int, array{name: string, slug: string, count: int}>
     */
    #[Computed]
    public function makes(): array
    {
        $counts = CarListing::query()
            ->where('is_active', true)
            ->whereNotNull('car_make_id')
            ->selectRaw('car_make_id, count(*) as total')
            ->groupBy('car_make_id')
            ->pluck('total', 'car_make_id');

        return CarMake::whereIn('id', $counts->keys())
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (CarMake $m): array => ['name' => $m->name, 'slug' => $m->slug, 'count' => $counts[$m->id]])
            ->toArray();
    }
}
?>

<div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    @push('seo')
        <link rel="canonical" href="{{ route('make-index') }}">
        <meta name="description" content="Разгледай всички марки автомобили с активни обяви в AutoSearch.">
    @endpush

    <h1 class="mb-6 text-2xl font-bold text-gray-900 sm:text-3xl dark:text-white">
        Марки автомобили
    </h1>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @foreach($this->makes as $make)
            <a
                href="{{ route('make-listings', $make['slug']) }}"
                wire:key="make-{{ $make['slug'] }}"
                wire:navigate
                class="flex items-center justify-between rounded-lg bg-white px-4 py-3 shadow-sm hover:shadow-md dark:bg-gray-800"
            >
                <span class="font-medium text-gray-900 dark:text-white">{{ $make['name'] }}</span>
                <span class="text-sm text-gray-400">{{ $make['count'] }}</span>
            </a>
        @endforeach
    </div>
</div>
