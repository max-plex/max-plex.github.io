<?php
$pageTitle = 'HubCloud & Fast Download Resolver Monitor';
$activeTab = 'downloads';
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$downloads = $downloads ?? [];
?>

<main class="flex-1 overflow-y-auto p-6 md:p-8 space-y-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-white">HubCloud Bypass & Download Health</h1>
            <p class="text-xs text-brand-muted mt-1">Real-time status of HubCloud, GDFlix, FastDL, Pixeldrain and 10Gbps mirrors</p>
        </div>
    </div>

    <!-- Download Servers Matrix -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <div class="p-4 rounded-2xl bg-brand-navy/60 border border-brand-border text-center">
            <div class="w-2 h-2 rounded-full bg-brand-success pulse-live mx-auto mb-2"></div>
            <div class="text-xs font-bold text-white">HubCloud 10G</div>
            <div class="text-[10px] text-brand-success font-semibold mt-0.5">ONLINE</div>
        </div>
        <div class="p-4 rounded-2xl bg-brand-navy/60 border border-brand-border text-center">
            <div class="w-2 h-2 rounded-full bg-brand-success pulse-live mx-auto mb-2"></div>
            <div class="text-xs font-bold text-white">FastDL Cloud</div>
            <div class="text-[10px] text-brand-success font-semibold mt-0.5">ONLINE</div>
        </div>
        <div class="p-4 rounded-2xl bg-brand-navy/60 border border-brand-border text-center">
            <div class="w-2 h-2 rounded-full bg-brand-success pulse-live mx-auto mb-2"></div>
            <div class="text-xs font-bold text-white">GDFlix Mirror</div>
            <div class="text-[10px] text-brand-success font-semibold mt-0.5">ONLINE</div>
        </div>
        <div class="p-4 rounded-2xl bg-brand-navy/60 border border-brand-border text-center">
            <div class="w-2 h-2 rounded-full bg-brand-success pulse-live mx-auto mb-2"></div>
            <div class="text-xs font-bold text-white">PixelDrain</div>
            <div class="text-[10px] text-brand-success font-semibold mt-0.5">ONLINE</div>
        </div>
        <div class="p-4 rounded-2xl bg-brand-navy/60 border border-brand-border text-center">
            <div class="w-2 h-2 rounded-full bg-brand-success pulse-live mx-auto mb-2"></div>
            <div class="text-xs font-bold text-white">Mega Server</div>
            <div class="text-[10px] text-brand-success font-semibold mt-0.5">ONLINE</div>
        </div>
        <div class="p-4 rounded-2xl bg-brand-navy/60 border border-brand-border text-center">
            <div class="w-2 h-2 rounded-full bg-brand-success pulse-live mx-auto mb-2"></div>
            <div class="text-xs font-bold text-white">1fichier</div>
            <div class="text-[10px] text-brand-success font-semibold mt-0.5">ONLINE</div>
        </div>
    </div>

    <!-- Live HubCloud Link Tester -->
    <div class="p-6 md:p-8 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow space-y-4">
        <h3 class="text-base font-bold text-white">Live HubCloud Token Bypass Sandbox</h3>
        <p class="text-xs text-brand-muted">Paste any HubCloud or GDFlix intermediate URL to test the 2-step token bypass and extract direct download links</p>

        <div class="flex gap-3">
            <input type="url" id="hubcloudUrl" placeholder="https://hubcloud.club/drive/..." value="https://hubcloud.club/drive/sample"
                   class="flex-1 bg-[#0B0D1B] border border-brand-border rounded-xl px-4 py-3 text-sm text-white font-mono placeholder-gray-600 focus:outline-none focus:border-brand-red transition">
            <button onclick="testHubcloud()" class="bg-brand-gradient hover:opacity-95 text-white font-bold px-6 py-3 rounded-xl shadow-brand-glow text-xs transition flex items-center space-x-2">
                <i data-lucide="zap" class="w-4 h-4"></i>
                <span>Resolve Links</span>
            </button>
        </div>

        <div id="hubcloudResult" class="p-4 rounded-2xl bg-[#0B0D1B] border border-brand-border font-mono text-xs text-brand-info max-h-48 overflow-y-auto">
            [Ready] Enter HubCloud URL and click 'Resolve Links'.
        </div>
    </div>

    <!-- Download History Table -->
    <div class="p-6 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow space-y-4">
        <h3 class="text-base font-bold text-white">Recent Mobile App Download History</h3>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-brand-border/60 text-brand-muted uppercase text-[10px]">
                        <th class="py-3 px-4">Media Title</th>
                        <th class="py-3 px-4">Quality</th>
                        <th class="py-3 px-4">File Size</th>
                        <th class="py-3 px-4">Server</th>
                        <th class="py-3 px-4">Timestamp</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-border/40 text-gray-300">
                    <?php if (empty($downloads)): ?>
                        <tr><td colspan="5" class="py-8 text-center text-brand-muted">No downloads recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($downloads as $d): ?>
                            <tr>
                                <td class="py-3.5 px-4 font-bold text-white"><?= htmlspecialchars($d['media_title']) ?></td>
                                <td class="py-3.5 px-4"><span class="px-2 py-0.5 rounded-md bg-brand-navy-light text-brand-red font-bold text-[10px]"><?= htmlspecialchars($d['quality'] ?? 'HD') ?></span></td>
                                <td class="py-3.5 px-4"><?= htmlspecialchars($d['file_size'] ?? '-') ?></td>
                                <td class="py-3.5 px-4 font-mono text-[11px]"><?= htmlspecialchars($d['mirror_server'] ?? 'HubCloud') ?></td>
                                <td class="py-3.5 px-4 text-brand-muted"><?= htmlspecialchars($d['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<script>
    async function testHubcloud() {
        const u = document.getElementById('hubcloudUrl').value.trim();
        if (!u) return alert('Enter HubCloud URL');

        document.getElementById('hubcloudResult').innerHTML = `Executing 2-step token bypass for: ${u}...`;

        try {
            const res = await fetch(`/api/v1/media/download?url=${encodeURIComponent(u)}`);
            const json = await res.json();
            document.getElementById('hubcloudResult').innerHTML = JSON.stringify(json, null, 2);
        } catch (e) {
            document.getElementById('hubcloudResult').innerHTML = `Error: ${e.message}`;
        }
    }
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
