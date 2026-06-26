<?php /** @var int $pages */ ?>
<?php echo '<?xml version="1.0" encoding="UTF-8"?>'."\n"; ?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <sitemap>
        <loc>{{ route('sitemap.makes') }}</loc>
    </sitemap>
    @for ($page = 1; $page <= $pages; $page++)
        <sitemap>
            <loc>{{ route('sitemap.page', ['page' => $page]) }}</loc>
        </sitemap>
    @endfor
</sitemapindex>