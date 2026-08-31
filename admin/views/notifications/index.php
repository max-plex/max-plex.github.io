<?php
$pageTitle = 'Broadcast Push Notifications & Announcements';
$activeTab = 'notifications';
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$devicesCount = $devicesCount ?? 0;
$announcement = $announcement ?? '';
?>

<main class="flex-1 overflow-y-auto p-6 md:p-8 space-y-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-white">Broadcast Push Dispatcher</h1>
            <p class="text-xs text-brand-muted mt-1">Send instant push notifications to mobile devices or update in-app emergency announcement banners</p>
        </div>
    </div>

    <!-- 2 Cols Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <!-- Push Notification Form -->
        <div class="p-6 md:p-8 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow space-y-4">
            <div class="flex items-center space-x-3 mb-2">
                <div class="w-10 h-10 rounded-2xl bg-brand-red/10 border border-brand-red/20 flex items-center justify-center text-brand-red">
                    <i data-lucide="send" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white">Broadcast Push Alert</h3>
                    <p class="text-xs text-brand-muted">Targeting <?= number_format($devicesCount) ?> registered devices</p>
                </div>
            </div>

            <form action="/admin/notifications/broadcast" method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">Notification Title</label>
                    <input type="text" name="title" required placeholder="🔥 New Release: Panchayat Season 4 is Live!" 
                           class="w-full bg-[#0B0D1B] border border-brand-border rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-red transition">
                </div>

                <div>
                    <label class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">Message Body</label>
                    <textarea name="body" required rows="3" placeholder="Watch all new episodes in 4K 60FPS with direct streaming..." 
                              class="w-full bg-[#0B0D1B] border border-brand-border rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-red transition"></textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">Target Media Slug (Optional)</label>
                    <input type="text" name="media_slug" placeholder="panchayat-s04-hindi" 
                           class="w-full bg-[#0B0D1B] border border-brand-border rounded-xl px-4 py-3 text-sm text-white font-mono focus:outline-none focus:border-brand-red transition">
                </div>

                <button type="submit" class="w-full bg-brand-gradient hover:opacity-95 text-white font-bold py-3.5 px-4 rounded-xl shadow-brand-glow text-xs transition flex items-center justify-center space-x-2">
                    <i data-lucide="bell" class="w-4 h-4"></i>
                    <span>Broadcast Push Notification</span>
                </button>
            </form>
        </div>

        <!-- In-App Announcement Banner Settings -->
        <div class="p-6 md:p-8 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow space-y-4">
            <div class="flex items-center space-x-3 mb-2">
                <div class="w-10 h-10 rounded-2xl bg-yellow-400/10 border border-yellow-400/20 flex items-center justify-center text-yellow-400">
                    <i data-lucide="alert-circle" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white">In-App Notice Banner</h3>
                    <p class="text-xs text-brand-muted">Display emergency notice or update alert at the top of the mobile app</p>
                </div>
            </div>

            <form action="/admin/notifications/banner" method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">Announcement Message</label>
                    <textarea name="announcement" rows="4" placeholder="Important update: Server maintenance scheduled tonight at 2 AM IST. Enjoy 4K streaming!" 
                              class="w-full bg-[#0B0D1B] border border-brand-border rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-red transition"><?= htmlspecialchars($announcement) ?></textarea>
                </div>

                <div class="flex items-center justify-between p-3.5 rounded-2xl bg-[#0B0D1B] border border-brand-border">
                    <span class="text-xs font-bold text-gray-300">App Maintenance Mode Toggle</span>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="maintenance_mode" value="1" class="sr-only peer">
                        <div class="w-11 h-6 bg-brand-navy-light peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-brand-red"></div>
                    </label>
                </div>

                <button type="submit" class="w-full bg-brand-navy-light hover:bg-brand-border text-white border border-brand-border font-bold py-3.5 px-4 rounded-xl text-xs transition flex items-center justify-center space-x-2">
                    <i data-lucide="save" class="w-4 h-4"></i>
                    <span>Save Announcement & Banner</span>
                </button>
            </form>
        </div>

    </div>

</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
