<?php
$pageTitle = 'Media Catalog & Stream Inspector';
$activeTab = 'media';
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$category = $_GET['cat'] ?? 'home';
$query = $_GET['q'] ?? '';
$items = $items ?? [];
?>

<main class="flex-1 overflow-y-auto p-6 md:p-8 space-y-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-white">Media Catalog & Stream Inspector</h1>
            <p class="text-xs text-brand-muted mt-1">Browse live indexed titles, inspect episode trees, and verify download links</p>
        </div>

        <!-- Search Bar -->
        <form action="/admin/media" method="GET" class="flex gap-2">
            <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Search movies, web series..."
                   class="bg-brand-navy border border-brand-border rounded-xl px-4 py-2 text-xs text-white placeholder-gray-500 focus:outline-none focus:border-brand-red transition w-64">
            <button type="submit" class="bg-brand-gradient hover:opacity-90 text-white px-4 py-2 rounded-xl text-xs font-bold transition flex items-center space-x-1">
                <i data-lucide="search" class="w-3.5 h-3.5"></i>
                <span>Search</span>
            </button>
        </form>
    </div>

    <!-- Category Filter Tabs -->
    <div class="flex items-center space-x-2 overflow-x-auto pb-2">
        <?php
        $categories = [
            'home' => 'Latest Releases',
            'bollywood-movies' => 'Bollywood',
            'hollywood-movies' => 'Hollywood',
            'web-series' => 'Web Series',
            'south-hindi-movies' => 'South Hindi',
            'anime' => 'Anime'
        ];
        foreach ($categories as $k => $label):
        ?>
            <a href="/admin/media?cat=<?= $k ?>" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition whitespace-nowrap <?= ($category === $k) ? 'bg-brand-red text-white shadow-brand-glow' : 'bg-brand-navy/60 text-brand-muted hover:text-white border border-brand-border/60' ?>">
                <?= $label ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Media Grid -->
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <?php if (empty($items)): ?>
            <div class="col-span-full py-16 text-center text-xs text-brand-muted">
                <i data-lucide="film" class="w-8 h-8 mx-auto mb-2 opacity-40"></i>
                <span>No media items found for this query or category.</span>
            </div>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                <div class="rounded-2xl bg-brand-navy/60 border border-brand-border overflow-hidden hover-lift transition flex flex-col justify-between group">
                    <div class="relative aspect-[2/3] bg-black overflow-hidden">
                        <img src="<?= htmlspecialchars($item['poster_url'] ?? '') ?>" alt="" 
                             class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                             onerror="this.src='https://via.placeholder.com/300x450/131730/ffffff?text=HDHub4u'">
                        
                        <div class="absolute top-2 left-2">
                            <span class="bg-black/70 backdrop-blur-md text-white border border-white/10 text-[9px] font-black px-2 py-0.5 rounded-md uppercase">
                                <?= htmlspecialchars($item['quality'] ?? 'HD') ?>
                            </span>
                        </div>
                    </div>

                    <div class="p-3 flex flex-col justify-between flex-1 space-y-2">
                        <h4 class="text-xs font-bold text-white line-clamp-2 leading-tight" title="<?= htmlspecialchars($item['title']) ?>">
                            <?= htmlspecialchars($item['title']) ?>
                        </h4>

                        <div class="pt-2 border-t border-brand-border/40 flex items-center justify-between">
                            <button onclick="inspectMedia('<?= htmlspecialchars($item['post_url']) ?>')" class="text-[11px] font-bold text-brand-info hover:underline flex items-center space-x-1">
                                <i data-lucide="external-link" class="w-3 h-3"></i>
                                <span>Inspect</span>
                            </button>
                            <a href="/admin/streaming?code=gcuieyqjnnek" class="p-1 rounded-lg bg-brand-navy-light text-brand-red hover:bg-brand-red hover:text-white transition" title="Test Stream">
                                <i data-lucide="play" class="w-3 h-3"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</main>

<!-- Details Modal -->
<div id="inspectModal" class="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="w-full max-w-2xl bg-[#131730] border border-brand-border rounded-3xl p-6 shadow-2xl space-y-4 max-h-[85vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-3 border-b border-brand-border/60">
            <h3 class="text-base font-bold text-white" id="modalTitle">Scraped Details & Links</h3>
            <button onclick="closeModal()" class="p-1 text-brand-muted hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <div id="modalBody" class="text-xs text-brand-muted space-y-3 font-mono">
            Loading scraped episode tree & HubCloud download links...
        </div>
    </div>
</div>

<script>
    async function inspectMedia(postUrl) {
        document.getElementById('inspectModal').classList.remove('hidden');
        document.getElementById('modalBody').innerHTML = 'Fetching scraped post details...';

        try {
            const res = await fetch(`/api/v1/media/details?url=${encodeURIComponent(postUrl)}`);
            const json = await res.json();
            if (json.status && json.data) {
                const d = json.data;
                document.getElementById('modalTitle').innerText = d.title || 'Media Details';
                let html = `
                    <div class="p-3 rounded-xl bg-[#0B0D1B] border border-brand-border space-y-1">
                        <div class="text-white font-bold font-sans">${d.title}</div>
                        <div class="text-brand-muted">Year: ${d.year || '-'} • Quality: ${d.quality || 'HD'}</div>
                    </div>
                `;

                if (d.seasons && d.seasons.length > 0) {
                    html += `<div class="font-bold text-white font-sans mt-3">Series Seasons & Episodes:</div>`;
                    d.seasons.forEach(s => {
                        html += `<div class="p-3 rounded-xl bg-[#0B0D1B] border border-brand-border mb-2"><div class="font-bold text-yellow-400 font-sans">${s.season_name} (${s.episodes.length} Episodes)</div>`;
                        s.episodes.forEach(ep => {
                            html += `<div class="mt-1 flex items-center justify-between text-[11px] text-gray-300"><span>${ep.episode_name}</span><a href="${ep.episode_url}" target="_blank" class="text-brand-red hover:underline">Open Link</a></div>`;
                        });
                        html += `</div>`;
                    });
                } else if (d.download_links && d.download_links.length > 0) {
                    html += `<div class="font-bold text-white font-sans mt-3">Direct Download Mirrors:</div>`;
                    d.download_links.forEach(l => {
                        html += `<div class="p-2.5 rounded-xl bg-[#0B0D1B] border border-brand-border mb-1.5 flex items-center justify-between"><span class="text-white font-bold">${l.link_title}</span><a href="${l.download_url}" target="_blank" class="text-brand-success hover:underline">Download Link</a></div>`;
                    });
                }

                document.getElementById('modalBody').innerHTML = html;
            }
        } catch (e) {
            document.getElementById('modalBody').innerHTML = `Error: ${e.message}`;
        }
    }

    function closeModal() {
        document.getElementById('inspectModal').classList.add('hidden');
    }
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
