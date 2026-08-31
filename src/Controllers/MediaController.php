<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ScraperService;
use App\Services\KatDramaService;
use DOMDocument;
use DOMXPath;

class MediaController {
    private string $baseUrl;

    public function __construct() {
        $this->baseUrl = ScraperService::getBaseUrl();
    }

    public function getHome(Request $request): void {
        $page = max(1, (int)$request->getQuery('page', 1));
        $url = ($page === 1) ? $this->baseUrl . '/' : $this->baseUrl . "/page/{$page}/";

        $html = ScraperService::fetchHTML($url);
        if (!$html) {
            Response::error('Failed to fetch home page releases', 502);
        }

        $items = $this->parsePostCards($html);
        Response::success($items, 'Home page releases fetched successfully');
    }

    /**
     * Dedicated K-Drama Feed (/api/v1/media/k-drama)
     */
    public function getKDramaFeed(Request $request): void {
        $page = max(1, (int)$request->getQuery('page', 1));
        $category = $request->getQuery('category') ?? $request->getQuery('cat');

        $result = KatDramaService::getFeed($page, $category);
        Response::success($result, 'K-Drama feed fetched successfully');
    }

    public function search(Request $request): void {
        $query = trim($request->getQuery('query') ?? ($request->getQuery('q') ?? ''));
        $page = max(1, (int)$request->getQuery('page', 1));
        $source = strtolower(trim($request->getQuery('source', 'all')));

        if (empty($query)) {
            Response::error('Missing search query parameter', 422);
        }

        $items = [];

        // 1. Search HDHub4u
        if ($source === 'all' || $source === 'hdhub4u') {
            $cleanQ = urlencode($query);
            $url = ($page === 1) ? "{$this->baseUrl}/search/{$cleanQ}/" : "{$this->baseUrl}/search/{$cleanQ}/page/{$page}/";
            $html = ScraperService::fetchHTML($url);
            if ($html) {
                $hdItems = $this->parsePostCards($html);
                $items = array_merge($items, $hdItems);
            }
        }

        // 2. Search KatDrama
        if ($source === 'all' || $source === 'kdrama' || $source === 'katdrama') {
            $kdramaRes = KatDramaService::search($query, $page);
            if (!empty($kdramaRes['posts'])) {
                $items = array_merge($items, $kdramaRes['posts']);
            }
        }

        Response::success($items, 'Search results fetched successfully');
    }

    public function getCategories(Request $request): void {
        $categories = [
            ["name" => "K-Drama (Korean)", "slug" => "k-drama", "icon" => "local_movies"],
            ["name" => "Bollywood Movies", "slug" => "bollywood-movies", "icon" => "movie"],
            ["name" => "Hollywood Hindi", "slug" => "hollywood-movies-in-hindi", "icon" => "public"],
            ["name" => "South Hindi Movies", "slug" => "south-hindi-movies", "icon" => "videocam"],
            ["name" => "Web Series", "slug" => "web-series", "icon" => "tv"],
            ["name" => "Adult (18+)", "slug" => "18-movies", "icon" => "lock"],
            ["name" => "300MB Movies", "slug" => "300mb-movies", "icon" => "compress"],
            ["name" => "Dual Audio", "slug" => "dual-audio", "icon" => "headset"],
            ["name" => "Anime Series", "slug" => "anime", "icon" => "animation"],
            ["name" => "Hindi Dubbed", "slug" => "hindi-dubbed-movies", "icon" => "translate"],
            ["name" => "Punjabi Movies", "slug" => "punjabi-movies", "icon" => "music_note"]
        ];

        Response::success($categories, 'Categories list fetched');
    }

    public function getCategoryFeed(Request $request): void {
        $cat = trim($request->getQuery('cat') ?? ($request->getQuery('category') ?? 'bollywood-movies'));
        $page = max(1, (int)$request->getQuery('page', 1));
        $typeFilter = strtolower(trim($request->getQuery('type', '')));

        // 1. Dedicated K-Drama Category Resolver (scrapes /search/korean/ with pagination)
        if (in_array(strtolower($cat), ['k-drama', 'korean', 'korean-drama', 'kdrama', 'k-dramas'])) {
            $url = ($page === 1) ? "{$this->baseUrl}/search/korean/" : "{$this->baseUrl}/search/korean/page/{$page}/";
            $html = ScraperService::fetchHTML($url);

            if (!$html) {
                Response::error('Failed to fetch K-Drama feed', 502);
            }

            $items = $this->parsePostCards($html);

            if ($typeFilter === 'series') {
                $items = array_values(array_filter($items, fn($i) => !empty($i['is_series']) && $i['is_series'] === true));
            } elseif ($typeFilter === 'movie' || $typeFilter === 'movies') {
                $items = array_values(array_filter($items, fn($i) => empty($i['is_series']) || $i['is_series'] === false));
            }

            Response::success($items, "K-Drama feed fetched successfully");
            return;
        }

        // 2. Standard Category Resolver
        $url = ($page === 1) ? "{$this->baseUrl}/category/{$cat}/" : "{$this->baseUrl}/category/{$cat}/page/{$page}/";
        $html = ScraperService::fetchHTML($url);

        if (!$html) {
            Response::error('Failed to fetch category feed', 502);
        }

        $items = $this->parsePostCards($html);

        if ($typeFilter === 'series') {
            $items = array_values(array_filter($items, fn($i) => !empty($i['is_series']) && $i['is_series'] === true));
        } elseif ($typeFilter === 'movie' || $typeFilter === 'movies') {
            $items = array_values(array_filter($items, fn($i) => empty($i['is_series']) || $i['is_series'] === false));
        }

        Response::success($items, "Category feed for '{$cat}' fetched");
    }

    public function getDetails(Request $request): void {
        $slug = trim($request->getQuery('slug') ?? ($request->get('slug') ?? ''));
        $rawUrl = trim($request->getQuery('url') ?? ($request->get('url') ?? ''));
        $source = strtolower(trim($request->getQuery('source', '')));

        if (empty($slug) && empty($rawUrl)) {
            Response::error('Provide either "slug" or "url" parameter', 422);
        }

        if (!empty($slug)) {
            $slug = trim($slug, '/');
            $targetUrl = "{$this->baseUrl}/{$slug}/";
        } else {
            $targetUrl = $rawUrl;
            $path = trim(parse_url($rawUrl, PHP_URL_PATH) ?? '', '/');
            $parts = explode('/', $path);
            $slug = end($parts);
        }

        // 1. Explicit KatDrama Request
        if ($source === 'kdrama' || $source === 'katdrama' || str_contains($rawUrl, 'katdrama.my')) {
            $kdramaDetails = KatDramaService::getDetails($slug);
            if ($kdramaDetails) {
                Response::success($kdramaDetails, 'K-Drama details fetched successfully');
                return;
            }
        }

        // 2. Try HDHub4u First
        $html = ScraperService::fetchHTML($targetUrl);
        if (!$html) {
            // Dual lookup fallback: try KatDrama
            $kdramaDetails = KatDramaService::getDetails($slug);
            if ($kdramaDetails) {
                Response::success($kdramaDetails, 'K-Drama details fetched successfully');
                return;
            }
            Response::error('Failed to fetch media details from source', 404);
        }

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);

        $cleanText = function($str) {
            if (empty($str)) return '';
            $str = preg_replace('/[\x00-\x1F\x7F]/u', '', $str);
            $str = trim(preg_replace('/\s+/u', ' ', $str));
            return html_entity_decode($str, ENT_QUOTES, 'UTF-8');
        };

        // 1. Title
        $titleNode = $xpath->query('//h1[contains(@class, "page-title")]//span[contains(@class, "material-text")]')->item(0);
        if (!$titleNode) $titleNode = $xpath->query('//h1[contains(@class, "page-title")] | //h1[contains(@class, "entry-title")]')->item(0);
        if (!$titleNode) $titleNode = $xpath->query('//title')->item(0);
        $rawTitle = $titleNode ? $cleanText($titleNode->textContent) : '';
        $rawTitle = preg_replace('/\s*\|\s*HDHub4u.*$/i', '', $rawTitle);

        // 2. Genres
        $genres = [];
        $metaLinks = $xpath->query('//div[contains(@class, "page-meta")]//a//em | //div[contains(@class, "page-meta")]//a | //span[contains(@class, "category")]//a');
        foreach ($metaLinks as $mLink) {
            $gName = $cleanText($mLink->textContent);
            if (!empty($gName) && !in_array($gName, $genres)) $genres[] = $gName;
        }

        // 3. Multi-Tier Poster Extraction (Never misses poster!)
        $poster = '';
        $ogImageNode = $xpath->query('//meta[@property="og:image"]')->item(0);
        if ($ogImageNode && !empty($ogImageNode->getAttribute('content'))) {
            $poster = $ogImageNode->getAttribute('content');
        }

        if (empty($poster) || str_contains($poster, 'blank.gif') || str_contains($poster, 'default-poster')) {
            // Find all images in entry-content or page-body
            $allImgs = $xpath->query('//div[contains(@class, "entry-content")]//img | //main[contains(@class, "page-body")]//img | //figure//img');
            foreach ($allImgs as $img) {
                $src = $img->getAttribute('src') ?: ($img->getAttribute('data-src') ?: $img->getAttribute('data-lazy-src'));
                if (!empty($src) && !str_contains($src, 'blank.gif') && !str_contains($src, 'data:image') && !str_contains($src, 'joinwhatsapp') && !str_contains($src, 'telegram')) {
                    if (str_contains($src, 'catimages') || str_contains($src, 'image.tmdb') || str_contains($src, 'poster') || str_contains($src, 'wp-content')) {
                        $poster = $src;
                        break;
                    }
                }
            }
            if (empty($poster) && $allImgs->length > 0) {
                $poster = $allImgs->item(0)->getAttribute('src') ?: $allImgs->item(0)->getAttribute('data-src');
            }
        }

        // 4. IMDb Rating & Trailer
        $imdbRating = null;
        $imdbNodes = $xpath->query('//a[contains(@href, "imdb.com/title")] | //p[contains(., "IMDb")] | //span[contains(., "IMDb")]');
        if ($imdbNodes->length > 0) {
            $text = $imdbNodes->item(0)->textContent;
            if (preg_match('/(\d+(?:\.\d+)?\s*\/\s*10)/i', $text, $imMatch)) {
                $imdbRating = $imMatch[1];
            } else {
                $imdbRating = trim($text);
            }
        }

        $trailer = null;
        $iframeNodes = $xpath->query('//iframe[contains(@src, "youtube.com")]');
        if ($iframeNodes->length > 0) $trailer = $iframeNodes->item(0)->getAttribute('src');

        // 5. Storyline & Screenshots
        $storyline = '';
        $storyNodes = $xpath->query('//div[contains(@class, "kno-rdesc")] | //p[contains(., "Storyline")] | //p[contains(., "Plot")] | //div[contains(@class, "synopsis")]');
        if ($storyNodes->length > 0) {
            $storyline = $cleanText(preg_replace('/^.*?(Storyline|Synopsis|Plot)\s*:\s*/i', '', $storyNodes->item(0)->textContent));
        }

        $screenshots = [];
        $allImages = $xpath->query('//main[contains(@class, "page-body")]//img | //div[contains(@class, "entry-content")]//img');
        foreach ($allImages as $img) {
            $src = $img->getAttribute('src') ?: $img->getAttribute('data-src');
            if (!empty($src) && (str_contains($src, 'catimages') || str_contains($src, 'imgshare') || str_contains($src, 'image.tmdb') || str_contains($src, 'screens'))) {
                if ($src !== $poster && !str_contains($src, 'joinwhatsapp') && !str_contains($src, 'telegram')) {
                    $screenshots[] = $src;
                }
            }
        }
        $screenshots = array_values(array_unique($screenshots));

        // 6. Parsed Links (Downloads & Streams)
        $allAnchors = $xpath->query('//main[contains(@class, "page-body")]//a | //div[contains(@class, "entry-content")]//a');
        $parsedLinks = [];
        foreach ($allAnchors as $a) {
            $href = $a->getAttribute('href');
            $text = trim($a->textContent);
            if (empty($href) || str_contains($href, 'hdhub4u.af/category') || str_contains($href, 'how-to-download') || str_contains($href, 'whatsapp.com') || str_contains($href, 'telegram') || $href === '#' || $href === '/') continue;
            $parsedLinks[] = ['href' => $href, 'text' => $text];
        }

        $hasEpisodes = false;
        foreach ($parsedLinks as $link) {
            if (preg_match('/EPiSODE\s*(\d+)|EP\s*(\d+)|E0\d/i', $link['text'])) {
                $hasEpisodes = true;
                break;
            }
        }

        $isWebSeries = $hasEpisodes || preg_match('/(Season\s*\d+|Series|Episodes|Part\s*\d+|EP\s*–|All\s*Episodes)/i', $rawTitle . ' ' . $slug) || in_array('Web Series', $genres);
        $contentType = $isWebSeries ? 'web_series' : 'movie';

        $buildStreamObj = function($url, $label = 'Watch Online') {
            $fileCode = '';
            if (preg_match('/\/file\/([a-zA-Z0-9]+)/', $url, $m)) $fileCode = $m[1];
            return [
                'player_name'       => $label,
                'stream_type'       => 'direct_m3u8',
                'file_code'         => $fileCode,
                'code'              => $fileCode,
                'stream_code'       => $fileCode,
                'url'               => $url,
                'direct_stream_api' => !empty($fileCode) ? "/api/v1/media/stream?code={$fileCode}" : null,
                'stream_url'        => !empty($fileCode) ? "/api/v1/media/stream?code={$fileCode}" : null,
                'proxy_stream_api'  => !empty($fileCode) ? "/api/v1/media/play?code={$fileCode}&quality=720p" : null
            ];
        };

        $movieDownloads = [];
        $movieStreaming = [];
        $seriesEpisodes = [];

        $count = count($parsedLinks);
        for ($i = 0; $i < $count; $i++) {
            $item = $parsedLinks[$i];
            $href = $item['href'];
            $text = $item['text'];

            // Extract file size if present (e.g. [1.4GB] or (450MB))
            $size = null;
            if (preg_match('/\[\s*([\d\.]+\s*(?:GB|MB))\s*\]|\(\s*([\d\.]+\s*(?:GB|MB))\s*\)/i', $text, $sMatch)) {
                $size = $sMatch[1] ?: $sMatch[2];
            }

            if (preg_match('/(?:EPiSODE|EP)\s*(\d+)/i', $text, $epMatch)) {
                $epNum = (int)$epMatch[1];
                $epStream = null;
                if ($i + 1 < $count && (stripos($parsedLinks[$i + 1]['text'], 'WATCH') !== false || stripos($parsedLinks[$i + 1]['href'], 'hdstream') !== false)) {
                    $epStream = $buildStreamObj($parsedLinks[$i + 1]['href'], "Episode {$epNum} Player");
                }

                $epDownloadObj = [
                    'quality'              => 'Multi-Quality Archive',
                    'label'                => 'Download Episode',
                    'size'                 => $size,
                    'url'                  => $href,
                    'download_page_url'    => $href,
                    'direct_download_api'  => "/api/v1/media/download?url=" . urlencode($href),
                    'download_resolver_api'=> "/api/v1/media/download?url=" . urlencode($href)
                ];

                $seriesEpisodes[] = [
                    'episode_number'        => $epNum,
                    'episode'               => $epNum,
                    'title'                 => "Episode {$epNum}",
                    'stream'                => $epStream,
                    'stream_code'           => $epStream['file_code'] ?? null,
                    'stream_url'            => $epStream['stream_url'] ?? null,
                    'downloads'             => [$epDownloadObj]
                ];
            } elseif (stripos($text, 'WATCH') !== false || stripos($href, 'hdstream') !== false) {
                $movieStreaming[] = $buildStreamObj($href, $text ?: 'HDStream Player');
            } elseif (preg_match('/(480p|720p|1080p|2160p|4k|hevc|web-dl|bluray|download|zip|pack|gdrive|hubcloud|drive|hdhub)/i', $text . ' ' . $href)) {
                $movieDownloads[] = [
                    'quality'              => $text ?: 'HD Quality',
                    'label'                => $text ?: 'Download Link',
                    'size'                 => $size,
                    'url'                  => $href,
                    'download_page_url'    => $href,
                    'direct_download_api'  => "/api/v1/media/download?url=" . urlencode($href),
                    'download_resolver_api'=> "/api/v1/media/download?url=" . urlencode($href)
                ];
            }
        }

        $result = [
            'title'        => $rawTitle,
            'slug'         => $slug,
            'content_type' => $contentType,
            'poster'       => $poster,
            'imdb_rating'  => $imdbRating,
            'trailer'      => $trailer,
            'storyline'    => $storyline,
            'genres'       => $genres,
            'screenshots'  => $screenshots,
            'streams'      => ($contentType === 'web_series') ? [] : $movieStreaming,
            'downloads'    => $movieDownloads
        ];

        if ($contentType === 'web_series') {
            $result['episodes'] = $seriesEpisodes;
            $result['series']   = $seriesEpisodes; // Backward-compatible with schooleasy old schema
        }

        Response::success($result, 'Media details fetched successfully');
    }

    private function parsePostCards(string $html): array {
        $items = [];
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $posts = $xpath->query("//li[contains(@class, 'thumb')] | //li[contains(@class, 'recent-posts')] | //article | //div[contains(@class, 'post')]");

        foreach ($posts as $post) {
            $aNodes = $xpath->query(".//figcaption//a | .//figure//a | .//a", $post);
            if ($aNodes->length === 0) continue;
            $aNode = $aNodes->item(0);

            $href = $aNode->getAttribute('href');
            
            $pNodes = $xpath->query(".//figcaption//p | .//p", $post);
            $title = '';
            if ($pNodes->length > 0) {
                $title = trim($pNodes->item(0)->textContent);
            }
            if (empty($title)) {
                $title = $aNode->getAttribute('title') ?: trim($aNode->textContent);
            }

            $imgNodes = $xpath->query(".//figure//img | .//img", $post);
            $img = '';
            if ($imgNodes->length > 0) {
                $imgNode = $imgNodes->item(0);
                $img = $imgNode->getAttribute('src') ?: ($imgNode->getAttribute('data-src') ?: $imgNode->getAttribute('data-lazy-src'));
                if (empty($title)) {
                    $title = $imgNode->getAttribute('title') ?: $imgNode->getAttribute('alt');
                }
            }

            $quality = '';
            $qNodes = $xpath->query(".//*[contains(@class, 'quality') or contains(@class, 'badge')]", $post);
            if ($qNodes->length > 0) $quality = trim($qNodes->item(0)->textContent);

            $slug = trim(parse_url($href, PHP_URL_PATH) ?? '', '/');
            if (str_contains($slug, '/')) {
                $parts = explode('/', $slug);
                $slug = end($parts);
            }

            $isSeries = (bool)preg_match('/season|episode|s0\d|complete|all-episodes/i', $title . ' ' . $slug);

            if (!empty($title) && !empty($href) && !empty($slug)) {
                $items[] = [
                    'title'     => html_entity_decode(trim($title), ENT_QUOTES, 'UTF-8'),
                    'slug'      => $slug,
                    'poster'    => $img,
                    'url'       => $href,
                    'quality'   => $quality ?: 'HD',
                    'is_series' => $isSeries
                ];
            }
        }

        return $items;
    }
}
