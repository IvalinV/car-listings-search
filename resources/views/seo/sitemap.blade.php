<?php /** @var \Illuminate\Support\Collection<int, \App\Models\CarListing> $listings */ ?>
<?php /** @var bool $includeHome */ ?>
<?php echo '<?xml version="1.0" encoding="UTF-8"?>'."\n"; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    @if ($includeHome)
        <url>
            <loc>{{ route('car-listings') }}</loc>
            <changefreq>hourly</changefreq>
            <priority>1.0</priority>
        </url>
    @endif
    @foreach ($listings as $listing)
        <url>
            <loc>{{ route('car-detail', $listing) }}</loc>
            <lastmod>{{ $listing->updated_at->toAtomString() }}</lastmod>
            <changefreq>daily</changefreq>
            <priority>0.8</priority>
        </url>
    @endforeach
</urlset>