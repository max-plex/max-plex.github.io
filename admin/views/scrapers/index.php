<?php
$pageTitle = 'Scraper & Dynamic Mirror Manager';
$activeTab = 'scrapers';
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$activeBaseUrl = $config['hdhub4u_base_url'] ?? 'https://new1.hdhub4u.af';
$cacheTtl = $config['cache_ttl'] ?? 300;
?>

<main class="flex-1 overflow-y-auto p-6 md:p-8 space-y-6">

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-white">Scraper & Mirror Control</h1>
            <p class="text-xs text-brand-muted mt-1">Manage upstream HDHub4u base domain, mirrors, and scraper cache without deploying code</p>
        </div>

        <form action="/admin/scrapers/purge-cache" method="POST">
            <button type="submit" class="bg-brand-navy-light hover:bg-brand-border text-white border border-brand-border text-xs font-bold px-4 py-2.5 rounded-2xl transition flex items-center space-x-2">
                <i data-lucide="trash-2" class="w-4 h-4 text-brand-red"></i>
                <span>Purge Scraper Cache</span>
            </button>
        </form>
    </div>

    <!-- Active Base URL Switcher Card -->
    <div class="p-6 md:p-8 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 rounded-2xl bg-brand-success/10 border border-brand-success/20 flex items-center justify-center text-brand-success">
                <i data-lucide="globe" class="w-5 h-5"></i>
            </div>
            <div>
                <h3 class="text-base font-bold text-white">Dynamic HDHub4u Base Domain</h3>
                <p class="text-xs text-brand-muted">All catalog requests, search queries, and details routes dynamically use this domain</p>
            </div>
        </div>

        <form action="/admin/scrapers/update-url" method="POST" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="md:col-span-3">
                    <label class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">Active Upstream Base URL</label>
                    <input type="url" name="base_url" required value="<?= htmlspecialchars($activeBaseUrl) ?>" 
                           placeholder="https://new1.hdhub4u.af" 
                           class="w-full bg-[#0B0D1B] border border-brand-border rounded-xl px-4 py-3 text-sm text-white font-mono placeholder-gray-600 focus:outline-none focus:border-brand-red transition">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full bg-brand-gradient hover:opacity-95 text-white font-bold py-3 px-4 rounded-xl shadow-brand-glow text-xs transition flex items-center justify-center space-x-2">
                        <i data-lucide="save" class="w-4 h-4"></i>
                        <span>Apply Mirror URL</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Predefined Popular Mirrors Grid -->
    <div>
        <h3 class="text-base font-bold text-white mb-4">Quick Switch Popular Mirrors</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            
            <!-- Mirror 1 -->
            <div class="p-5 rounded-3xl bg-brand-navy/60 border border-brand-border shadow-card-glow flex flex-col justify-between space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-white font-mono">https://new1.hdhub4u.af</span>
                    <?php if ($activeBaseUrl === 'https://new1.hdhub4u.af' || $activeBaseUrl === 'https://new1.hdhub4u.af/'): ?>
                        <span class="bg-brand-success/10 text-brand-success border border-brand-success/20 text-[9px] font-extrabold px-2 py-0.5 rounded-full">ACTIVE</span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center justify-between pt-2 border-t border-brand-border/40">
                    <span class="text-[11px] text-brand-muted">Primary Global Mirror</span>
                    <form action="/admin/scrapers/update-url" method="POST">
                        <input type="hidden" name="base_url" value="https://new1.hdhub4u.af">
                        <button type="submit" class="text-xs font-bold text-brand-red hover:underline flex items-center space-x-1">
                            <span>Activate</span>
                            <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Mirror 2 -->
            <div class="p-5 rounded-3xl bg-brand-navy/60 border border-brand-border shadow-card-glow flex flex-col justify-between space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-white font-mono">https://hdhub4u.tv</span>
                    <?php if ($activeBaseUrl === 'https://hdhub4u.tv' || $activeBaseUrl === 'https://hdhub4u.tv/'): ?>
                        <span class="bg-brand-success/10 text-brand-success border border-brand-success/20 text-[9px] font-extrabold px-2 py-0.5 rounded-full">ACTIVE</span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center justify-between pt-2 border-t border-brand-border/40">
                    <span class="text-[11px] text-brand-muted">Secondary TV Mirror</span>
                    <form action="/admin/scrapers/update-url" method="POST">
                        <input type="hidden" name="base_url" value="https://hdhub4u.tv">
                        <button type="submit" class="text-xs font-bold text-brand-red hover:underline flex items-center space-x-1">
                            <span>Activate</span>
                            <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Mirror 3 -->
            <div class="p-5 rounded-3xl bg-brand-navy/60 border border-brand-border shadow-card-glow flex flex-col justify-between space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-white font-mono">https://hdhub4u.ms</span>
                    <?php if ($activeBaseUrl === 'https://hdhub4u.ms' || $activeBaseUrl === 'https://hdhub4u.ms/'): ?>
                        <span class="bg-brand-success/10 text-brand-success border border-brand-success/20 text-[9px] font-extrabold px-2 py-0.5 rounded-full">ACTIVE</span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center justify-between pt-2 border-t border-brand-border/40">
                    <span class="text-[11px] text-brand-muted">Offshore Backup</span>
                    <form action="/admin/scrapers/update-url" method="POST">
                        <input type="hidden" name="base_url" value="https://hdhub4u.ms">
                        <button type="submit" class="text-xs font-bold text-brand-red hover:underline flex items-center space-x-1">
                            <span>Activate</span>
                            <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <!-- Scraper Cache & Performance Settings -->
    <div class="p-6 md:p-8 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow">
        <h3 class="text-base font-bold text-white mb-4">Cache Expiry & Anti-Block Tuning</h3>
        <form action="/admin/scrapers/update-settings" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
                <label class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">Home Feed Cache TTL (Sec)</label>
                <input type="number" name="cache_ttl" value="300" 
                       class="w-full bg-[#0B0D1B] border border-brand-border rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-red transition">
                <p class="text-[10px] text-brand-muted mt-1">Default 300s (5 mins)</p>
            </div>
            <div>
                <label class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">cURL Connect Timeout (Sec)</label>
                <input type="number" name="timeout" value="12" 
                       class="w-full bg-[#0B0D1B] border border-brand-border rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-red transition">
                <p class="text-[10px] text-brand-muted mt-1">Recommended: 10 - 15s</p>
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full bg-brand-navy-light hover:bg-brand-border text-white border border-brand-border font-bold py-3 px-4 rounded-xl text-xs transition flex items-center justify-center space-x-2">
                    <i data-lucide="settings" class="w-4 h-4"></i>
                    <span>Save Tuning Config</span>
                </button>
            </div>
        </form>
    </div>

</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
