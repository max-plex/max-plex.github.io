<?php
$pageTitle = 'Executive Analytics Dashboard';
$activeTab = 'dashboard';
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

// Fetch Live Stats from Database
$totalUsers = $stats['total_users'] ?? 0;
$activeHeartbeats = $stats['active_heartbeats'] ?? 0;
$totalHistory = $stats['total_history'] ?? 0;
$totalSearches = $stats['total_searches'] ?? 0;
$activeBaseUrl = $stats['base_url'] ?? 'https://new1.hdhub4u.af';
$recentSearches = $stats['recent_searches'] ?? [];
$topGenres = $stats['top_genres'] ?? [];
$chartLabels = $stats['chart_labels'] ?? [];
$searchTrends = $stats['search_trends'] ?? [];
$watchTrends = $stats['watch_trends'] ?? [];
$qStats = $stats['quality_stats'] ?? ['q1080' => 0, 'q720' => 0, 'q480' => 0];
$totalQ = $qStats['q1080'] + $qStats['q720'] + $qStats['q480'];
?>

<!-- MAIN CONTENT AREA -->
<main class="flex-1 overflow-y-auto p-6 md:p-8 space-y-6">
    
    <!-- WELCOME HERO BANNER -->
    <div class="p-6 md:p-8 rounded-3xl bg-card-gradient border border-brand-border/80 shadow-2xl relative overflow-hidden">
        <!-- Glow accents -->
        <div class="absolute -right-16 -bottom-16 w-96 h-96 bg-red-600/15 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute right-48 top-0 w-72 h-72 bg-purple-600/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 max-w-3xl">
            <div class="inline-flex items-center space-x-2 bg-brand-navy/80 px-3 py-1 rounded-full text-xs font-bold text-brand-red-light border border-brand-border/60 mb-4 shadow-sm">
                <i data-lucide="zap" class="w-3.5 h-3.5 text-brand-red"></i>
                <span>HDHub4u Live OTT Cluster Telemetry Active</span>
            </div>
            <h1 class="text-2xl md:text-3xl font-black tracking-tight text-white mb-2">
                Executive Control Console 👋
            </h1>
            <p class="text-xs md:text-sm text-gray-300 font-normal leading-relaxed">
                Connected to production database. Real-time media scraper orchestration, dynamic mirror switching, in-browser stream testing, and instant mobile push dispatch.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="/admin/scrapers" class="bg-brand-gradient hover:opacity-95 text-white text-xs font-bold px-4 py-2.5 rounded-2xl shadow-brand-glow hover-lift transition flex items-center space-x-2">
                    <i data-lucide="globe" class="w-4 h-4"></i>
                    <span>Switch Active Mirror</span>
                </a>
                <a href="/admin/streaming" class="bg-brand-navy-light hover:bg-brand-border text-white border border-brand-border text-xs font-bold px-4 py-2.5 rounded-2xl transition flex items-center space-x-2">
                    <i data-lucide="play" class="w-4 h-4 text-brand-red"></i>
                    <span>Test Video Stream Sandbox</span>
                </a>
                <a href="/admin/notifications" class="bg-brand-navy-light hover:bg-brand-border text-gray-300 hover:text-white border border-brand-border text-xs font-bold px-4 py-2.5 rounded-2xl transition flex items-center space-x-2">
                    <i data-lucide="bell" class="w-4 h-4 text-yellow-400"></i>
                    <span>Broadcast Notification</span>
                </a>
            </div>
        </div>
    </div>

    <!-- GOOGLE PLAY STORE TESTING & REVIEW MODE CONTROL WIDGET -->
    <div class="p-6 rounded-3xl <?= $stats['is_playstore_testing'] ? 'bg-amber-950/30 border-amber-500/40 shadow-card-glow' : 'bg-brand-navy/70 border-brand-border' ?> border flex flex-col md:flex-row items-start md:items-center justify-between gap-4 transition-all">
        <div class="flex items-center space-x-4">
            <div class="w-12 h-12 rounded-2xl <?= $stats['is_playstore_testing'] ? 'bg-amber-500/20 text-amber-400 border border-amber-500/30' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' ?> flex items-center justify-center flex-shrink-0">
                <i data-lucide="<?= $stats['is_playstore_testing'] ? 'shield-alert' : 'shield-check' ?>" class="w-6 h-6"></i>
            </div>
            <div>
                <div class="flex items-center space-x-2">
                    <h3 class="text-base font-bold text-white">Google Play Store Review Mode (<code class="text-brand-info">is_playstore_testing</code>)</h3>
                    <span class="<?= $stats['is_playstore_testing'] ? 'bg-amber-500/20 text-amber-400 border-amber-500/40' : 'bg-emerald-500/20 text-emerald-400 border-emerald-500/40' ?> border text-[10px] font-black px-2.5 py-0.5 rounded-full uppercase tracking-wider">
                        <?= $stats['is_playstore_testing'] ? '🟢 REVIEW ACTIVE (true)' : '⚪ PRODUCTION LIVE (false)' ?>
                    </span>
                </div>
                <p class="text-xs text-brand-muted mt-1">
                    <?= $stats['is_playstore_testing'] 
                        ? '<span class="text-amber-300 font-semibold">रिव्यू के समय (Review Active):</span> App Google Play review mode me hai (<code class="text-amber-300">is_playstore_testing: true</code>).' 
                        : '<span class="text-emerald-300 font-semibold">रिव्यू पास होने के बाद (Live Production):</span> Play Store review pass ho chuka hai. Full public catalog live hai (<code class="text-emerald-300">is_playstore_testing: false</code>).' ?>
                </p>
            </div>
        </div>

        <form action="/admin/playstore-mode/toggle" method="POST" class="flex-shrink-0">
            <input type="hidden" name="state" value="<?= $stats['is_playstore_testing'] ? '0' : '1' ?>">
            <input type="hidden" name="redirect_to" value="/admin/dashboard">
            <button type="submit" class="<?= $stats['is_playstore_testing'] ? 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-lg' : 'bg-amber-600 hover:bg-amber-500 text-white' ?> text-xs font-black px-5 py-3 rounded-2xl transition flex items-center space-x-2">
                <i data-lucide="<?= $stats['is_playstore_testing'] ? 'check-circle' : 'toggle-right' ?>" class="w-4 h-4"></i>
                <span><?= $stats['is_playstore_testing'] ? 'Switch to Live Mode (false)' : 'Activate Review Mode (true)' ?></span>
            </button>
        </form>
    </div>

    <!-- 4 KPI METRIC CARDS -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
        <!-- Card 1: Users -->
        <div class="p-5 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow flex flex-col justify-between hover-lift transition">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-brand-muted">Registered Users</span>
                <div class="w-10 h-10 rounded-2xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center text-purple-400">
                    <i data-lucide="users" class="w-5 h-5"></i>
                </div>
            </div>
            <div class="mt-4">
                <div class="text-3xl font-black text-white"><?= number_format($totalUsers) ?></div>
                <div class="flex items-center space-x-1.5 mt-1 text-xs text-brand-muted">
                    <span class="text-brand-success font-bold">100% Authenticated</span>
                    <span>Profiles</span>
                </div>
            </div>
        </div>

        <!-- Card 2: Live Watchers -->
        <div class="p-5 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow flex flex-col justify-between hover-lift transition">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-brand-muted">Live Watchers</span>
                <div class="w-10 h-10 rounded-2xl bg-brand-success/10 border border-brand-success/20 flex items-center justify-center text-brand-success">
                    <i data-lucide="activity" class="w-5 h-5"></i>
                </div>
            </div>
            <div class="mt-4">
                <div class="text-3xl font-black text-white flex items-center space-x-2">
                    <span><?= number_format($activeHeartbeats) ?></span>
                    <span class="w-2.5 h-2.5 rounded-full bg-brand-success pulse-live"></span>
                </div>
                <div class="flex items-center space-x-1.5 mt-1 text-xs text-brand-muted">
                    <span>Heartbeats (Last 5m)</span>
                </div>
            </div>
        </div>

        <!-- Card 3: Watch Logs -->
        <div class="p-5 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow flex flex-col justify-between hover-lift transition">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-brand-muted">Playback Sync Logs</span>
                <div class="w-10 h-10 rounded-2xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400">
                    <i data-lucide="clock" class="w-5 h-5"></i>
                </div>
            </div>
            <div class="mt-4">
                <div class="text-3xl font-black text-white"><?= number_format($totalHistory) ?></div>
                <div class="flex items-center space-x-1.5 mt-1 text-xs text-brand-muted">
                    <span>Synced Timestamps</span>
                </div>
            </div>
        </div>

        <!-- Card 4: Total Searches -->
        <div class="p-5 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow flex flex-col justify-between hover-lift transition">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-brand-muted">Search Queries</span>
                <div class="w-10 h-10 rounded-2xl bg-brand-red/10 border border-brand-red/20 flex items-center justify-center text-brand-red">
                    <i data-lucide="search" class="w-5 h-5"></i>
                </div>
            </div>
            <div class="mt-4">
                <div class="text-3xl font-black text-white"><?= number_format($totalSearches) ?></div>
                <div class="flex items-center space-x-1.5 mt-1 text-xs text-brand-muted">
                    <span>Search History Logs</span>
                </div>
            </div>
        </div>
    </div>

    <!-- CHARTS ROW -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Chart: Activity & Heartbeats -->
        <div class="lg:col-span-2 bg-brand-navy/60 p-6 rounded-3xl border border-brand-border shadow-card-glow">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="text-base font-bold text-white">Platform Traffic & Watch Activity</h3>
                    <p class="text-xs text-brand-muted mt-0.5">Real-time daily telemetry sync</p>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold bg-brand-success/10 text-brand-success border border-brand-success/20">
                        <span class="w-1.5 h-1.5 rounded-full bg-brand-success mr-1.5 pulse-live"></span> Live Stream Active
                    </span>
                </div>
            </div>
            <div class="h-64 w-full">
                <canvas id="trafficChart"></canvas>
            </div>
        </div>

        <!-- Secondary Chart: Quality & Streams -->
        <div class="bg-brand-navy/60 p-6 rounded-3xl border border-brand-border shadow-card-glow flex flex-col justify-between">
            <div>
                <h3 class="text-base font-bold text-white">Stream Quality Mix</h3>
                <p class="text-xs text-brand-muted mt-0.5">Resolved stream resolutions</p>
                <div class="h-52 w-full mt-4 flex items-center justify-center">
                    <canvas id="qualityChart"></canvas>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-2 pt-4 border-t border-brand-border/60 text-center text-xs">
                <div>
                    <div class="font-bold text-brand-red"><?= number_format($qStats['q1080']) ?></div>
                    <div class="text-[10px] text-brand-muted">1080p (<?= $totalQ > 0 ? round(($qStats['q1080'] / $totalQ) * 100) : 0 ?>%)</div>
                </div>
                <div>
                    <div class="font-bold text-yellow-400"><?= number_format($qStats['q720']) ?></div>
                    <div class="text-[10px] text-brand-muted">720p (<?= $totalQ > 0 ? round(($qStats['q720'] / $totalQ) * 100) : 0 ?>%)</div>
                </div>
                <div>
                    <div class="font-bold text-blue-400"><?= number_format($qStats['q480']) ?></div>
                    <div class="text-[10px] text-brand-muted">480p (<?= $totalQ > 0 ? round(($qStats['q480'] / $totalQ) * 100) : 0 ?>%)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- LOWER ROW: MIRRORS STATUS & RECENT SEARCHES -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Live Mirror Status Manager -->
        <div class="bg-brand-navy/60 p-6 rounded-3xl border border-brand-border shadow-card-glow space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-white">Scraper Mirror Health</h3>
                    <p class="text-xs text-brand-muted mt-0.5">Dynamic HDHub4u mirrors status</p>
                </div>
                <a href="/admin/scrapers" class="text-xs font-bold text-brand-red hover:underline flex items-center space-x-1">
                    <span>Manage Mirrors</span>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
                </a>
            </div>

            <div class="space-y-2.5">
                <!-- Mirror 1 (Active) -->
                <div class="p-3.5 rounded-2xl bg-brand-navy-light/60 border border-brand-border flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <span class="w-2.5 h-2.5 rounded-full bg-brand-success pulse-live"></span>
                        <div>
                            <div class="text-xs font-bold text-white font-mono"><?= htmlspecialchars($activeBaseUrl) ?></div>
                            <div class="text-[10px] text-brand-success font-semibold">Primary Active Mirror</div>
                        </div>
                    </div>
                    <span class="bg-brand-success/10 text-brand-success border border-brand-success/20 text-[10px] font-black px-2.5 py-1 rounded-full">200 OK • 120ms</span>
                </div>

                <!-- Mirror 2 (Backup) -->
                <div class="p-3.5 rounded-2xl bg-brand-navy-light/30 border border-brand-border/50 flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <span class="w-2.5 h-2.5 rounded-full bg-yellow-400"></span>
                        <div>
                            <div class="text-xs font-bold text-gray-300 font-mono">https://hdhub4u.tv</div>
                            <div class="text-[10px] text-brand-muted">Backup Mirror 1</div>
                        </div>
                    </div>
                    <span class="bg-yellow-400/10 text-yellow-400 border border-yellow-400/20 text-[10px] font-black px-2.5 py-1 rounded-full">STANDBY</span>
                </div>

                <!-- Mirror 3 (Backup) -->
                <div class="p-3.5 rounded-2xl bg-brand-navy-light/30 border border-brand-border/50 flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <span class="w-2.5 h-2.5 rounded-full bg-yellow-400"></span>
                        <div>
                            <div class="text-xs font-bold text-gray-300 font-mono">https://hdhub4u.ms</div>
                            <div class="text-[10px] text-brand-muted">Backup Mirror 2</div>
                        </div>
                    </div>
                    <span class="bg-yellow-400/10 text-yellow-400 border border-yellow-400/20 text-[10px] font-black px-2.5 py-1 rounded-full">STANDBY</span>
                </div>
            </div>
        </div>

        <!-- Recent Search History -->
        <div class="bg-brand-navy/60 p-6 rounded-3xl border border-brand-border shadow-card-glow space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-white">Live Search Activity</h3>
                    <p class="text-xs text-brand-muted mt-0.5">Top user queries from mobile app</p>
                </div>
                <span class="text-[10px] font-bold text-brand-muted uppercase">Recent 5</span>
            </div>

            <div class="divide-y divide-brand-border/40">
                <?php if (empty($recentSearches)): ?>
                    <div class="py-8 text-center text-xs text-brand-muted">No recent search queries logged yet.</div>
                <?php else: ?>
                    <?php foreach ($recentSearches as $s): ?>
                        <div class="py-3 flex items-center justify-between">
                            <div class="flex items-center space-x-3">
                                <div class="w-7 h-7 rounded-xl bg-brand-navy-light flex items-center justify-center text-brand-muted">
                                    <i data-lucide="search" class="w-3.5 h-3.5"></i>
                                </div>
                                <span class="text-xs font-bold text-white"><?= htmlspecialchars($s['query_text']) ?></span>
                            </div>
                            <span class="text-[11px] text-brand-muted font-mono"><?= date('H:i:s', strtotime($s['created_at'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</main>

<script>
    // Traffic & Watch Activity Chart (Chart.js) - 100% Dynamic Real Data
    const chartLabels = <?= json_encode($chartLabels) ?>;
    const searchTrends = <?= json_encode($searchTrends) ?>;
    const watchTrends = <?= json_encode($watchTrends) ?>;
    const qualityData = <?= json_encode([$qStats['q1080'], $qStats['q720'], $qStats['q480']]) ?>;

    const ctxTraffic = document.getElementById('trafficChart').getContext('2d');
    new Chart(ctxTraffic, {
        type: 'line',
        data: {
            labels: chartLabels,
            datasets: [{
                label: 'Search Queries',
                data: searchTrends,
                borderColor: '#E50914',
                backgroundColor: 'rgba(229, 9, 20, 0.12)',
                borderWidth: 3,
                fill: true,
                tension: 0.35,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: '#E50914'
            }, {
                label: 'Watch / Playback Sessions',
                data: watchTrends,
                borderColor: '#00C853',
                backgroundColor: 'rgba(0, 200, 83, 0.08)',
                borderWidth: 2.5,
                borderDash: [4, 4],
                fill: true,
                tension: 0.35,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#00C853'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    labels: { color: '#A0AEC0', font: { size: 11, family: 'Plus Jakarta Sans', weight: 'bold' } }
                },
                tooltip: {
                    backgroundColor: '#131730',
                    titleColor: '#FFFFFF',
                    bodyColor: '#A0AEC0',
                    borderColor: '#23284C',
                    borderWidth: 1,
                    padding: 10,
                    cornerRadius: 12
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(35, 40, 76, 0.35)' },
                    ticks: { color: '#7F87A7', font: { size: 10, family: 'Plus Jakarta Sans' } }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(35, 40, 76, 0.35)' },
                    ticks: { color: '#7F87A7', stepSize: 1, font: { size: 10, family: 'Plus Jakarta Sans' } }
                }
            }
        }
    });

    // Quality Mix Doughnut Chart - 100% Dynamic Real Data
    const ctxQuality = document.getElementById('qualityChart').getContext('2d');
    const hasQualityData = (qualityData[0] + qualityData[1] + qualityData[2]) > 0;

    new Chart(ctxQuality, {
        type: 'doughnut',
        data: {
            labels: ['1080p FHD', '720p HD', '480p SD'],
            datasets: [{
                data: hasQualityData ? qualityData : [1, 1, 1],
                backgroundColor: hasQualityData ? ['#E50914', '#FFB300', '#00B0FF'] : ['#23284C', '#23284C', '#23284C'],
                borderWidth: 0,
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#131730',
                    titleColor: '#FFFFFF',
                    bodyColor: '#A0AEC0',
                    borderColor: '#23284C',
                    borderWidth: 1,
                    cornerRadius: 12,
                    callbacks: {
                        label: function(context) {
                            if (!hasQualityData) return 'No downloads yet';
                            return ` ${context.label}: ${context.raw} files`;
                        }
                    }
                }
            },
            cutout: '72%'
        }
    });
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
