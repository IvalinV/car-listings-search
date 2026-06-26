<?php /** @var \Illuminate\Support\Collection<int, \App\Models\CarMake> $makes */ ?>
<?php echo '<?xml version="1.0" encoding="UTF-8"?>'."\n"; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    @foreach ($makes as $make)
        <url>
            <loc>{{ route('make-listings', $make->slug) }}</loc>
            <changefreq>daily</changefreq>
            <priority>0.7</priority>
        </url>
    @endforeach
</urlset>
