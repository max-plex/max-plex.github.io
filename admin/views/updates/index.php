<?php
$pageTitle = 'App OTA Updates & APK Release Manager';
$activeTab = 'updates';
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';

$update = $updateData ?? [
    'latest_version'        => '3.2.0',
    'latest_version_code'   => 32,
    'min_version'           => '3.0.0',
    'min_version_code'      => 30,
    'force_update'          => false,
    'apk_url'               => 'https://mov.aimacademycbse.com/downloads/hdhub4u-v3.2.0.apk',
    'apk_size'              => '18.5 MB',
    'release_notes'         => "🚀 4K 60FPS Direct Video Streaming Engine\n⚡ Faster HubCloud & FastDL token bypass\n🐞 Subtitle sync & player buffering improvements",
    'published_at'          => date('Y-m-d H:i:s')
];
$devicesCount = $devicesCount ?? 0;
?>

<main class="flex-1 overflow-y-auto p-6 md:p-8 space-y-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="inline-flex items-center space-x-2 bg-emerald-500/10 px-3 py-1 rounded-full text-xs font-bold text-emerald-400 border border-emerald-500/20 mb-2">
                <i data-lucide="smartphone" class="w-3.5 h-3.5 text-emerald-400"></i>
                <span>In-House APK Release Center (Non-Play Store)</span>
            </div>
            <h1 class="text-2xl font-black text-white">App OTA Updates & Releases</h1>
            <p class="text-xs text-brand-muted mt-1">Push over-the-air APK updates, force critical patches, and broadcast update alerts directly to mobile devices</p>
        </div>

        <form action="/admin/updates/broadcast" method="POST">
            <button type="submit" class="bg-brand-gradient hover:opacity-95 text-white font-bold px-4 py-2.5 rounded-2xl shadow-brand-glow text-xs transition flex items-center space-x-2">
                <i data-lucide="bell" class="w-4 h-4"></i>
                <span>Broadcast Update Alert (<?= number_format($devicesCount) ?> Devices)</span>
            </button>
        </form>
    </div>

    <!-- Active Version KPI Card -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-5 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow">
            <span class="text-[10px] font-bold text-brand-muted uppercase tracking-wider">Live Published Version</span>
            <div class="text-2xl font-black text-emerald-400 mt-1">v<?= htmlspecialchars($update['latest_version']) ?></div>
            <div class="text-[10px] text-brand-muted mt-0.5">Version Code: <?= (int)$update['latest_version_code'] ?></div>
        </div>

        <div class="p-5 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow">
            <span class="text-[10px] font-bold text-brand-muted uppercase tracking-wider">Update Enforcement</span>
            <div class="text-2xl font-black <?= $update['force_update'] ? 'text-brand-red' : 'text-yellow-400' ?> mt-1">
                <?= $update['force_update'] ? 'MANDATORY' : 'OPTIONAL' ?>
            </div>
            <div class="text-[10px] text-brand-muted mt-0.5">Min Allowed: v<?= htmlspecialchars($update['min_version']) ?></div>
        </div>

        <div class="p-5 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow">
            <span class="text-[10px] font-bold text-brand-muted uppercase tracking-wider">APK Package Size</span>
            <div class="text-2xl font-black text-white mt-1"><?= htmlspecialchars($update['apk_size']) ?></div>
            <div class="text-[10px] text-brand-success font-semibold mt-0.5">Direct CDN High-Speed</div>
        </div>

        <div class="p-5 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow">
            <span class="text-[10px] font-bold text-brand-muted uppercase tracking-wider">Targeted Devices</span>
            <div class="text-2xl font-black text-white mt-1"><?= number_format($devicesCount) ?></div>
            <div class="text-[10px] text-brand-muted mt-0.5">Active Push Tokens</div>
        </div>
    </div>

    <!-- Main 2-Col Grid: Release Form & Mobile Dialog Preview -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Form: Publish New APK Version (2 Cols) -->
        <div class="lg:col-span-2 p-6 md:p-8 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow space-y-6">
            <div class="flex items-center space-x-3 pb-4 border-b border-brand-border/60">
                <div class="w-10 h-10 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                    <i data-lucide="upload-cloud" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white">Publish New App Version</h3>
                    <p class="text-xs text-brand-muted">Update OTA metadata, direct APK URL, and release notes</p>
                </div>
            </div>

            <form action="/admin/updates/save" method="POST" enctype="multipart/form-data" class="space-y-4">
                
                <!-- Version Strings -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">New Version String</label>
                        <input type="text" name="latest_version" id="formVersion" required value="<?= htmlspecialchars($update['latest_version']) ?>" placeholder="3.2.0" 
                               class="w-full bg-[#0B0D1B] border border-brand-border rounded-xl px-4 py-3 text-sm text-white font-mono focus:outline-none focus:border-brand-red transition">
                        <p class="text-[10px] text-brand-muted mt-1">Semantic version (e.g. 3.2.0)</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">New Version Code (Integer)</label>
                        <input type="number" name="latest_version_code" id="formVersionCode" required value="<?= (int)$update['latest_version_code'] ?>" placeholder="32" 
                               class="w-full bg-[#0B0D1B] border border-brand-border rounded-xl px-4 py-3 text-sm text-white font-mono focus:outline-none focus:border-brand-red transition">
                        <p class="text-[10px] text-brand-muted mt-1">Incremental integer (e.g. 32)</p>
                    </div>
                </div>

                <!-- Minimum Supported Version -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">Minimum Supported Version</label>
                        <input type="text" name="min_version" value="<?= htmlspecialchars($update['min_version']) ?>" placeholder="3.0.0" 
                               class="w-full bg-[#0B0D1B] border border-brand-border rounded-xl px-4 py-3 text-sm text-white font-mono focus:outline-none focus:border-brand-red transition">
                        <p class="text-[10px] text-brand-muted mt-1">Users below this version will be forced to update</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">Package File Size</label>
                        <input type="text" name="apk_size" id="formSize" value="<?= htmlspecialchars($update['apk_size']) ?>" placeholder="18.5 MB" 
                               class="w-full bg-[#0B0D1B] border border-brand-border rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-red transition">
                        <p class="text-[10px] text-brand-muted mt-1">Displayed in update popup (e.g. 18.5 MB)</p>
                    </div>
                </div>

                <!-- Direct Download URL or Upload APK -->
                <div>
                    <label class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">Direct APK Download Link</label>
                    <input type="url" name="apk_url" id="formApkUrl" value="<?= htmlspecialchars($update['apk_url']) ?>" placeholder="https://mov.aimacademycbse.com/downloads/hdhub4u-v3.2.apk" 
                           class="w-full bg-[#0B0D1B] border border-brand-border rounded-xl px-4 py-3 text-sm text-white font-mono placeholder-gray-600 focus:outline-none focus:border-brand-red transition">
                    <p class="text-[10px] text-brand-muted mt-1">Direct APK link (self-hosted, Google Drive, GitHub Releases, or CDN)</p>
                </div>

                <!-- Upload Local APK -->
                <div class="p-4 rounded-2xl bg-[#0B0D1B] border border-dashed border-brand-border/80">
                    <label class="block text-xs font-bold text-gray-300 mb-1">Or Upload New .APK File (Optional)</label>
                    <input type="file" name="apk_file" accept=".apk" class="text-xs text-gray-400 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-brand-navy-light file:text-white hover:file:bg-brand-border transition">
                    <p class="text-[10px] text-brand-muted mt-1">Upload will save to /downloads/ directory and auto-populate download URL</p>
                </div>

                <!-- Force Update Toggle -->
                <div class="flex items-center justify-between p-4 rounded-2xl bg-[#0B0D1B] border border-brand-border">
                    <div>
                        <div class="text-xs font-bold text-white">Enforce Critical Update (Hard Blocking)</div>
                        <div class="text-[11px] text-brand-muted">If enabled, users cannot dismiss the update popup or use old app versions</div>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="force_update" id="formForce" value="1" <?= $update['force_update'] ? 'checked' : '' ?> class="sr-only peer" onchange="updatePreview()">
                        <div class="w-11 h-6 bg-brand-navy-light peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-brand-red"></div>
                    </label>
                </div>

                <!-- Release Notes / Changelog -->
                <div>
                    <label class="block text-xs font-bold text-brand-muted uppercase tracking-wider mb-2">What's New / Release Notes (Changelog)</label>
                    <textarea name="release_notes" id="formNotes" rows="4" placeholder="🚀 4K 60FPS Streaming Enabled&#10;⚡ Instant HubCloud & FastDL token bypass&#10;🐞 Bug Fixes" 
                              class="w-full bg-[#0B0D1B] border border-brand-border rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-red transition"
                              oninput="updatePreview()"><?= htmlspecialchars($update['release_notes']) ?></textarea>
                    <p class="text-[10px] text-brand-muted mt-1">One improvement per line (supports emojis)</p>
                </div>

                <!-- Broadcast Push Checkbox -->
                <div class="flex items-center space-x-2 pt-1">
                    <input type="checkbox" name="broadcast_push" value="1" id="broadcastPush" checked class="rounded bg-brand-navy border-brand-border text-brand-red focus:ring-0">
                    <label for="broadcastPush" class="text-xs font-bold text-gray-300 cursor-pointer">
                        Automatically send Push Notification alert to all <?= number_format($devicesCount) ?> devices upon publishing
                    </label>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full bg-brand-gradient hover:opacity-95 text-white font-bold py-3.5 px-4 rounded-xl shadow-brand-glow text-xs transition flex items-center justify-center space-x-2">
                        <i data-lucide="send" class="w-4 h-4"></i>
                        <span>Publish App Update & Sync Telemetry</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- In-App Mobile Dialog Simulator (1 Col) -->
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-bold text-white uppercase tracking-wider">Mobile In-App Preview</h3>
                <span class="text-[10px] font-bold text-emerald-400 font-mono">Live Simulator</span>
            </div>

            <!-- Phone Frame -->
            <div class="p-4 rounded-[2.5rem] bg-[#05060D] border-4 border-[#23284C] shadow-2xl space-y-4 relative overflow-hidden">
                
                <!-- Notch -->
                <div class="w-24 h-4 bg-[#131730] rounded-full mx-auto mb-2"></div>

                <!-- In-App Update Modal Box -->
                <div class="p-5 rounded-3xl bg-[#131730] border border-brand-border/80 shadow-2xl space-y-4">
                    
                    <!-- Icon & Header -->
                    <div class="text-center space-y-2">
                        <div class="w-12 h-12 rounded-2xl bg-brand-gradient flex items-center justify-center text-white font-black text-xl mx-auto shadow-lg shadow-red-900/40">
                            HD
                        </div>
                        <div class="inline-flex items-center space-x-1 px-2.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-[10px] font-extrabold">
                            <span>v<span id="prevVersion"><?= htmlspecialchars($update['latest_version']) ?></span> Available</span>
                        </div>
                        <h4 class="text-sm font-black text-white" id="prevTitle">
                            <?= $update['force_update'] ? '⚠️ Update Required' : '🎉 New Version Available!' ?>
                        </h4>
                        <p class="text-[11px] text-gray-400">
                            A fresh update is ready to install (<span id="prevSize"><?= htmlspecialchars($update['apk_size']) ?></span>).
                        </p>
                    </div>

                    <!-- Release Notes List -->
                    <div class="p-3.5 rounded-2xl bg-[#0B0D1B] border border-brand-border/60 text-[11px] text-gray-300 space-y-1.5 font-medium max-h-36 overflow-y-auto" id="prevNotesList">
                        <?php
                        $lines = explode("\n", $update['release_notes']);
                        foreach ($lines as $line):
                            $trimmed = trim($line);
                            if (!empty($trimmed)):
                        ?>
                            <div class="flex items-start space-x-2">
                                <span class="text-emerald-400 font-bold">•</span>
                                <span><?= htmlspecialchars($trimmed) ?></span>
                            </div>
                        <?php endif; endforeach; ?>
                    </div>

                    <!-- Action Buttons -->
                    <div class="space-y-2 pt-1">
                        <button type="button" class="w-full py-2.5 rounded-xl bg-brand-gradient text-white text-xs font-bold shadow-md tracking-wide uppercase flex items-center justify-center space-x-1.5">
                            <i data-lucide="download" class="w-3.5 h-3.5"></i>
                            <span>Download & Install APK</span>
                        </button>
                        
                        <div id="prevDismissWrap" class="<?= $update['force_update'] ? 'hidden' : '' ?> text-center">
                            <button type="button" class="text-[11px] text-gray-400 hover:text-white font-medium">
                                Remind Me Later
                            </button>
                        </div>
                    </div>
                </div>

                <div class="text-center text-[10px] text-brand-muted">
                    OTA In-App Dialog Prototype
                </div>
            </div>

            <!-- Developer Integration Guide -->
            <div class="p-5 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow space-y-2 text-xs">
                <div class="font-bold text-white flex items-center space-x-2">
                    <i data-lucide="code" class="w-4 h-4 text-brand-info"></i>
                    <span>App API Integration URL</span>
                </div>
                <p class="text-[11px] text-brand-muted">Call this endpoint from your Android app at startup:</p>
                <div class="p-2.5 rounded-xl bg-[#0B0D1B] border border-brand-border/60 font-mono text-[10px] text-brand-info select-all overflow-x-auto">
                    GET /api/v1/system/check-update?version=3.1.0&version_code=31
                </div>
            </div>
        </div>

    </div>

</main>

<script>
    function updatePreview() {
        const ver = document.getElementById('formVersion').value || '3.2.0';
        const size = document.getElementById('formSize').value || '18.5 MB';
        const notes = document.getElementById('formNotes').value;
        const isForce = document.getElementById('formForce').checked;

        document.getElementById('prevVersion').innerText = ver;
        document.getElementById('prevSize').innerText = size;
        document.getElementById('prevTitle').innerText = isForce ? '⚠️ Update Required' : '🎉 New Version Available!';
        
        if (isForce) {
            document.getElementById('prevDismissWrap').classList.add('hidden');
        } else {
            document.getElementById('prevDismissWrap').classList.remove('hidden');
        }

        const lines = notes.split('\n');
        let html = '';
        lines.forEach(l => {
            const t = l.trim();
            if (t) {
                html += `<div class="flex items-start space-x-2"><span class="text-emerald-400 font-bold">•</span><span>${t}</span></div>`;
            }
        });
        document.getElementById('prevNotesList').innerHTML = html;
        if (window.lucide) lucide.createIcons();
    }
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
