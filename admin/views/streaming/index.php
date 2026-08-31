<?php
$pageTitle = 'Video Player Sandbox & Stream Config';
$activeTab = 'streaming';
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<main class="flex-1 overflow-y-auto p-6 md:p-8 space-y-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-white">Video Player Sandbox & Stream Controller</h1>
            <p class="text-xs text-brand-muted mt-1">Test any HDStream file code live inside the admin panel with HLS.js video engine</p>
        </div>
    </div>

    <!-- Video Player & Sandbox Card -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Player Canvas (2 Cols) -->
        <div class="lg:col-span-2 space-y-4">
            <div class="p-6 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow space-y-4">
                
                <!-- Input Group -->
                <div class="flex gap-3">
                    <input type="text" id="streamCode" placeholder="Enter HDStream File Code (e.g. gcuieyqjnnek)..." value="gcuieyqjnnek"
                           class="flex-1 bg-[#0B0D1B] border border-brand-border rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-red transition">
                    <button onclick="testPlayStream()" class="bg-brand-gradient hover:opacity-95 text-white font-bold px-6 py-3 rounded-xl shadow-brand-glow text-xs transition flex items-center space-x-2">
                        <i data-lucide="play" class="w-4 h-4"></i>
                        <span>Play Stream</span>
                    </button>
                </div>

                <!-- Presets -->
                <div class="flex items-center space-x-2 flex-wrap gap-y-2 text-xs">
                    <span class="text-brand-muted text-[11px] font-bold uppercase mr-1">Quick Presets:</span>
                    <button onclick="setStream('gcuieyqjnnek')" class="px-3 py-1.5 rounded-lg bg-brand-navy-light hover:bg-brand-border text-gray-300 border border-brand-border text-xs transition">Kapil Show S4</button>
                    <button onclick="setStream('3gvq14gz9gn7')" class="px-3 py-1.5 rounded-lg bg-brand-navy-light hover:bg-brand-border text-gray-300 border border-brand-border text-xs transition">Panchayat S4</button>
                    <button onclick="setStream('z23gk7i3ojcb')" class="px-3 py-1.5 rounded-lg bg-brand-navy-light hover:bg-brand-border text-gray-300 border border-brand-border text-xs transition">Deadpool & Wolverine</button>
                    <button onclick="setStream('7p6wwbukw25o')" class="px-3 py-1.5 rounded-lg bg-brand-navy-light hover:bg-brand-border text-gray-300 border border-brand-border text-xs transition">Avengers Endgame</button>
                </div>

                <!-- Video Frame -->
                <div class="relative w-full aspect-video bg-black rounded-2xl overflow-hidden shadow-2xl border border-brand-border">
                    <video id="adminVideoPlayer" controls autoplay playsinline class="w-full h-full object-contain"></video>
                </div>

                <!-- Live Stream Console -->
                <div class="p-3.5 rounded-2xl bg-[#0B0D1B] border border-brand-border font-mono text-xs text-brand-info max-h-32 overflow-y-auto" id="playerLog">
                    [System] Player sandbox initialized. Ready to test stream.
                </div>
            </div>
        </div>

        <!-- Stream Information & Headers (1 Col) -->
        <div class="space-y-6">
            <!-- Headers Card -->
            <div class="p-6 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow space-y-4">
                <h3 class="text-sm font-bold text-white uppercase tracking-wider">Upstream CDN Headers</h3>
                <div class="space-y-2.5 text-xs font-mono">
                    <div class="p-3 rounded-xl bg-[#0B0D1B] border border-brand-border/60">
                        <div class="text-[10px] text-brand-muted uppercase font-sans font-bold">Referer</div>
                        <div class="text-green-400 font-bold mt-0.5">https://hdstream4u.com/</div>
                    </div>
                    <div class="p-3 rounded-xl bg-[#0B0D1B] border border-brand-border/60">
                        <div class="text-[10px] text-brand-muted uppercase font-sans font-bold">Origin</div>
                        <div class="text-green-400 font-bold mt-0.5">https://hdstream4u.com</div>
                    </div>
                    <div class="p-3 rounded-xl bg-[#0B0D1B] border border-brand-border/60">
                        <div class="text-[10px] text-brand-muted uppercase font-sans font-bold">User-Agent</div>
                        <div class="text-gray-300 text-[11px] mt-0.5">Mozilla/5.0 Chrome/122.0</div>
                    </div>
                </div>
            </div>

            <!-- Quality Matrix -->
            <div class="p-6 rounded-3xl bg-brand-navy/70 border border-brand-border shadow-card-glow space-y-4">
                <h3 class="text-sm font-bold text-white uppercase tracking-wider">Multi-Quality Support</h3>
                <div class="space-y-2 text-xs">
                    <div class="flex items-center justify-between p-3 rounded-xl bg-brand-navy-light/60 border border-brand-border">
                        <span class="font-bold text-brand-red">1080P Full HD</span>
                        <span class="text-brand-muted">1920x1080 • Auto</span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-xl bg-brand-navy-light/60 border border-brand-border">
                        <span class="font-bold text-yellow-400">720P HD Standard</span>
                        <span class="text-brand-muted">1280x720 • Universal</span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-xl bg-brand-navy-light/60 border border-brand-border">
                        <span class="font-bold text-blue-400">480P SD Low Data</span>
                        <span class="text-brand-muted">852x480 • Mobile</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

</main>

<script>
    let adminHls = null;
    const adminVideo = document.getElementById('adminVideoPlayer');
    const logElem = document.getElementById('playerLog');

    function logP(msg) {
        const time = new Date().toLocaleTimeString();
        logElem.innerHTML = `[${time}] ${msg}<br>` + logElem.innerHTML;
    }

    function setStream(code) {
        document.getElementById('streamCode').value = code;
        testPlayStream();
    }

    async function testPlayStream() {
        const code = document.getElementById('streamCode').value.trim();
        if (!code) return alert('Enter stream code');

        logP(`Resolving stream from /api/v1/media/stream?code=${code}...`);

        try {
            const res = await fetch(`/api/v1/media/stream?code=${encodeURIComponent(code)}`);
            const json = await res.json();

            if (!json.status || !json.data || !json.data.master_url) {
                throw new Error(json.message || 'Stream not found');
            }

            const streamUrl = json.data.stream_url || json.data.master_url;
            logP(`Stream Resolved: ${streamUrl.substring(0, 60)}...`);

            if (Hls.isSupported()) {
                if (adminHls) adminHls.destroy();

                adminHls = new Hls({ debug: false, enableWorker: true });
                adminHls.loadSource(streamUrl);
                adminHls.attachMedia(adminVideo);

                adminHls.on(Hls.Events.MANIFEST_PARSED, (e, data) => {
                    logP(`✓ HLS Manifest parsed! Quality tiers: ${data.levels.length}`);
                    adminVideo.play();
                });

                adminHls.on(Hls.Events.ERROR, (e, data) => {
                    if (data.fatal) {
                        logP(`⚠️ HLS Notice: ${data.type} - ${data.details}`);
                    }
                });
            } else if (adminVideo.canPlayType('application/vnd.apple.mpegurl')) {
                adminVideo.src = streamUrl;
                adminVideo.play();
            }
        } catch (err) {
            logP(`❌ Error: ${err.message}`);
        }
    }
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
