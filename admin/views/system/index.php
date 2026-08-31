<?php
$pageTitle = 'System Telemetry & Server Logs';
$activeTab = 'system';
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$logs = $logs ?? [];
$dbSize = $dbSize ?? '1.2 MB';
?>

<main class="flex-1 overflow-y-auto p-6 md:p-8 space-y-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-white">System Telemetry & Logs</h1>
            <p class="text-xs text-brand-muted mt-1">Real-time PHP runtime diagnostics, database status, and error logs</p>
        </div>

        <form action="/admin/system/optimize-db" method="POST">
            <button type="submit" class="bg-brand-gradient hover:opacity-95 text-white font-bold px-4 py-2.5 rounded-2xl shadow-brand-glow text-xs transition flex items-center space-x-2">
                <i data-lucide="database" class="w-4 h-4"></i>
                <span>Optimize Database</span>
            </button>
        </form>
    </div>

    <!-- Telemetry Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="p-5 rounded-3xl bg-brand-navy/70 border border-brand-border">
            <span class="text-[10px] font-bold text-brand-muted uppercase tracking-wider">PHP Version</span>
            <div class="text-xl font-black text-white mt-1"><?= PHP_VERSION ?></div>
            <div class="text-[10px] text-brand-success font-semibold mt-0.5">LiteSpeed API</div>
        </div>
        <div class="p-5 rounded-3xl bg-brand-navy/70 border border-brand-border">
            <span class="text-[10px] font-bold text-brand-muted uppercase tracking-wider">Memory Limit</span>
            <div class="text-xl font-black text-white mt-1"><?= ini_get('memory_limit') ?></div>
            <div class="text-[10px] text-brand-muted mt-0.5">Usage: <?= round(memory_get_usage(true) / 1024 / 1024, 2) ?> MB</div>
        </div>
        <div class="p-5 rounded-3xl bg-brand-navy/70 border border-brand-border">
            <span class="text-[10px] font-bold text-brand-muted uppercase tracking-wider">Database Size</span>
            <div class="text-xl font-black text-white mt-1"><?= htmlspecialchars($dbSize) ?></div>
            <div class="text-[10px] text-brand-success font-semibold mt-0.5">MySQL InnoDB</div>
        </div>
        <div class="p-5 rounded-3xl bg-brand-navy/70 border border-brand-border">
            <span class="text-[10px] font-bold text-brand-muted uppercase tracking-wider">OPcache Status</span>
            <div class="text-xl font-black text-brand-success mt-1">ENABLED</div>
            <div class="text-[10px] text-brand-muted mt-0.5">JIT Acceleration</div>
        </div>
    </div>

    <!-- Real-time Error Log Viewer -->
    <div class="p-6 md:p-8 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-base font-bold text-white">Application Error & Event Console</h3>
            <form action="/admin/system/clear-logs" method="POST">
                <button type="submit" class="text-xs font-bold text-brand-red hover:underline flex items-center space-x-1">
                    <i data-lucide="trash" class="w-3.5 h-3.5"></i>
                    <span>Clear Logs</span>
                </button>
            </form>
        </div>

        <div class="p-4 rounded-2xl bg-[#0B0D1B] border border-brand-border font-mono text-xs text-gray-300 max-h-96 overflow-y-auto space-y-1">
            <?php if (empty($logs)): ?>
                <div class="text-brand-success py-4">[OK] System operating cleanly. No error logs recorded.</div>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <div class="py-1 border-b border-brand-border/30 text-red-400 font-mono text-[11px]"><?= htmlspecialchars($log) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
