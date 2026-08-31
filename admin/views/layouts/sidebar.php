<?php
$activeTab = $activeTab ?? 'dashboard';

// Dynamic Database Stats for Sidebar Badges
$db = \App\Config\Database::getConnection();
$sidebarStats = [
    'users'         => 0,
    'devices'       => 0,
    'heartbeats'    => 0,
    'watch_history' => 0
];

if ($db) {
    try {
        $rU = $db->query("SELECT COUNT(*) FROM `users`");
        if ($rU) $sidebarStats['users'] = (int)$rU->fetchColumn();

        $rD = $db->query("SELECT COUNT(*) FROM `user_device`");
        if ($rD) $sidebarStats['devices'] = (int)$rD->fetchColumn();

        $rW = $db->query("SELECT COUNT(*) FROM `watch_history`");
        if ($rW) $sidebarStats['watch_history'] = (int)$rW->fetchColumn();
    } catch (Throwable $e) {}
}
?>
<!-- SIDEBAR -->
<aside id="sidebar" class="w-64 bg-[#0B0D1B] border-r border-brand-border/60 flex flex-col flex-shrink-0 transition-all duration-300 z-30">
    <!-- Brand Logo -->
    <div class="h-16 px-6 flex items-center justify-between border-b border-brand-border/60">
        <a href="/admin/dashboard" class="flex items-center space-x-3 group">
            <div class="w-9 h-9 rounded-xl bg-brand-gradient flex items-center justify-center text-white font-black text-lg shadow-brand-glow group-hover:scale-105 transition-transform">
                HD
            </div>
            <div>
                <span class="text-base font-black tracking-tight text-white block leading-none">HDHub4u <span class="text-brand-red">OTT</span></span>
                <span class="text-[9px] uppercase tracking-widest text-brand-muted font-bold block mt-1">Master Control v3.1</span>
            </div>
        </a>
    </div>

    <!-- Navigation Links -->
    <nav class="flex-1 px-3 py-4 space-y-1.5 overflow-y-auto">
        <div class="px-3 pb-2 text-[10px] font-extrabold text-brand-muted uppercase tracking-wider">Core Operations</div>

        <!-- Dashboard -->
        <a href="/admin/dashboard" class="flex items-center space-x-3 px-3.5 py-2.5 rounded-2xl font-semibold text-xs transition-all <?= ($activeTab === 'dashboard') ? 'text-white bg-brand-navy-light border border-brand-border shadow-card-glow' : 'text-gray-400 hover:text-white hover:bg-brand-navy-light/40' ?>">
            <i data-lucide="layout-dashboard" class="w-4 h-4 text-brand-red"></i>
            <span>Executive Dashboard</span>
        </a>

        <!-- Media Catalog -->
        <a href="/admin/media" class="flex items-center justify-between px-3.5 py-2.5 rounded-2xl font-semibold text-xs transition-all <?= ($activeTab === 'media') ? 'text-white bg-brand-navy-light border border-brand-border shadow-card-glow' : 'text-gray-400 hover:text-white hover:bg-brand-navy-light/40' ?>">
            <div class="flex items-center space-x-3">
                <i data-lucide="film" class="w-4 h-4 text-brand-info"></i>
                <span>Media Catalog</span>
            </div>
            <span class="bg-brand-navy/60 text-brand-info text-[9px] font-black px-2 py-0.5 rounded-full border border-brand-border/40">LIVE</span>
        </a>

        <!-- Scraper & Mirrors -->
        <a href="/admin/scrapers" class="flex items-center justify-between px-3.5 py-2.5 rounded-2xl font-semibold text-xs transition-all <?= ($activeTab === 'scrapers') ? 'text-white bg-brand-navy-light border border-brand-border shadow-card-glow' : 'text-gray-400 hover:text-white hover:bg-brand-navy-light/40' ?>">
            <div class="flex items-center space-x-3">
                <i data-lucide="globe" class="w-4 h-4 text-brand-success"></i>
                <span>Scraper & Mirrors</span>
            </div>
            <span class="bg-brand-success/10 text-brand-success border border-brand-success/20 text-[9px] font-extrabold px-2 py-0.5 rounded-full">1-CLICK</span>
        </a>

        <!-- Streaming Sandbox -->
        <a href="/admin/streaming" class="flex items-center space-x-3 px-3.5 py-2.5 rounded-2xl font-semibold text-xs transition-all <?= ($activeTab === 'streaming') ? 'text-white bg-brand-navy-light border border-brand-border shadow-card-glow' : 'text-gray-400 hover:text-white hover:bg-brand-navy-light/40' ?>">
            <i data-lucide="play-circle" class="w-4 h-4 text-brand-red-light"></i>
            <span>Video Player Sandbox</span>
        </a>

        <!-- HubCloud & Downloads -->
        <a href="/admin/downloads" class="flex items-center space-x-3 px-3.5 py-2.5 rounded-2xl font-semibold text-xs transition-all <?= ($activeTab === 'downloads') ? 'text-white bg-brand-navy-light border border-brand-border shadow-card-glow' : 'text-gray-400 hover:text-white hover:bg-brand-navy-light/40' ?>">
            <i data-lucide="download-cloud" class="w-4 h-4 text-brand-warning"></i>
            <span>HubCloud Bypass & DL</span>
        </a>

        <div class="pt-4 px-3 pb-2 text-[10px] font-extrabold text-brand-muted uppercase tracking-wider">Users & App Telemetry</div>

        <!-- Users Directory -->
        <a href="/admin/users" class="flex items-center justify-between px-3.5 py-2.5 rounded-2xl font-semibold text-xs transition-all <?= ($activeTab === 'users') ? 'text-white bg-brand-navy-light border border-brand-border shadow-card-glow' : 'text-gray-400 hover:text-white hover:bg-brand-navy-light/40' ?>">
            <div class="flex items-center space-x-3">
                <i data-lucide="users" class="w-4 h-4 text-purple-400"></i>
                <span>Registered Users</span>
            </div>
            <span class="bg-purple-500/10 text-purple-400 border border-purple-500/20 text-[10px] font-extrabold px-2 py-0.5 rounded-full"><?= number_format($sidebarStats['users']) ?></span>
        </a>

        <!-- Push Notifications & Banners -->
        <a href="/admin/notifications" class="flex items-center justify-between px-3.5 py-2.5 rounded-2xl font-semibold text-xs transition-all <?= ($activeTab === 'notifications') ? 'text-white bg-brand-navy-light border border-brand-border shadow-card-glow' : 'text-gray-400 hover:text-white hover:bg-brand-navy-light/40' ?>">
            <div class="flex items-center space-x-3">
                <i data-lucide="bell-ring" class="w-4 h-4 text-brand-red"></i>
                <span>Push Broadcast</span>
            </div>
            <span class="bg-brand-red/20 text-brand-red border border-brand-red/30 text-[10px] font-black px-1.5 py-0.5 rounded-md"><?= number_format($sidebarStats['devices']) ?> Devices</span>
        </a>

        <!-- App OTA Updates (Non-Play Store) -->
        <a href="/admin/updates" class="flex items-center justify-between px-3.5 py-2.5 rounded-2xl font-semibold text-xs transition-all <?= ($activeTab === 'updates') ? 'text-white bg-brand-navy-light border border-brand-border shadow-card-glow' : 'text-gray-400 hover:text-white hover:bg-brand-navy-light/40' ?>">
            <div class="flex items-center space-x-3">
                <i data-lucide="smartphone" class="w-4 h-4 text-emerald-400"></i>
                <span>App OTA Updates</span>
            </div>
            <span class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-[9px] font-black px-2 py-0.5 rounded-full">OTA APK</span>
        </a>

        <div class="pt-4 px-3 pb-2 text-[10px] font-extrabold text-brand-muted uppercase tracking-wider">System & Health</div>

        <!-- System Telemetry & Logs -->
        <a href="/admin/system" class="flex items-center space-x-3 px-3.5 py-2.5 rounded-2xl font-semibold text-xs transition-all <?= ($activeTab === 'system') ? 'text-white bg-brand-navy-light border border-brand-border shadow-card-glow' : 'text-gray-400 hover:text-white hover:bg-brand-navy-light/40' ?>">
            <i data-lucide="cpu" class="w-4 h-4 text-cyan-400"></i>
            <span>System Telemetry & Logs</span>
        </a>
    </nav>

    <!-- Bottom Quick Status Card -->
    <div class="p-3 border-t border-brand-border/60">
        <div class="p-3 rounded-2xl bg-brand-navy-light/60 border border-brand-border/40">
            <div class="flex items-center justify-between text-xs mb-1">
                <span class="text-brand-muted font-bold text-[10px] uppercase">Engine Status</span>
                <span class="flex items-center space-x-1 text-brand-success text-[10px] font-extrabold">
                    <span class="w-1.5 h-1.5 rounded-full bg-brand-success pulse-live"></span>
                    <span>100% ONLINE</span>
                </span>
            </div>
            <div class="text-[11px] font-bold text-gray-300">LiteSpeed PHP 8.2 • 0ms Lag</div>
        </div>
    </div>
</aside>
