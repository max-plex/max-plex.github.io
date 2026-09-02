<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ScraperService;

class DownloadController {
    public function resolveDownload(Request $request): void {
        $rawUrl = trim($request->getQuery('url') ?? ($request->get('url') ?? ''));

        if (empty($rawUrl)) {
            Response::error('Missing "url" parameter', 422);
        }

        // Clean double-encoded wrappers
        while (preg_match('/download\?url=(.+)$/i', $rawUrl, $m)) {
            $rawUrl = urldecode($m[1]);
        }

        // 1. Greenmount Gateway (Decodes 4-layer token to HBLinks -> HubCloud)
        if (str_contains($rawUrl, 'greenmount')) {
            $res = ScraperService::resolveGreenmount($rawUrl);
            if ($res) {
                $res['input_url'] = $rawUrl;
                Response::success($res, 'Download link resolved successfully');
            }
        }

        // 2. Instant HubCDN Fast DL (e.g. hubcdn.sbs/file/...)
        if (str_contains($rawUrl, 'hubcdn')) {
            $res = ScraperService::resolveHubCdn($rawUrl);
            if ($res && !empty($res['servers'])) {
                Response::success([
                    'input_url'           => $rawUrl,
                    'is_direct'           => true,
                    'download_type'       => 'instant_cdn_10gbps',
                    'primary_download_url'=> $res['primary_download_url'],
                    'direct_download_url' => $res['primary_download_url'],
                    'servers'             => $res['servers']
                ], 'Direct high-speed download link resolved successfully');
            }
        }

        // 3. Direct HubCloud drive page
        if (str_contains($rawUrl, 'hubcloud') && (str_contains($rawUrl, '/drive/') || str_contains($rawUrl, 'hubcloud.php'))) {
            $res = ScraperService::resolveHubCloud($rawUrl);
            if ($res && !empty($res['servers'])) {
                Response::success([
                    'input_url'           => $rawUrl,
                    'is_direct'           => true,
                    'download_type'       => 'hubcloud_mirrors',
                    'primary_download_url'=> $res['primary_download_url'],
                    'direct_download_url' => $res['primary_download_url'],
                    'servers'             => $res['servers']
                ], 'HubCloud download servers resolved');
            }
        }

        // 4. HubDrive Mirrors (e.g. hubdrive.tips, hubdrive.space)
        if (str_contains($rawUrl, 'hubdrive')) {
            $res = ScraperService::resolveHubDrive($rawUrl);
            if ($res && !empty($res['servers'])) {
                Response::success([
                    'input_url'           => $rawUrl,
                    'is_direct'           => true,
                    'download_type'       => 'hubdrive_mirrors',
                    'primary_download_url'=> $res['primary_download_url'] ?? null,
                    'direct_download_url' => $res['primary_download_url'] ?? null,
                    'servers'             => $res['servers']
                ], 'HubDrive download servers resolved');
            }
        }

        // 5. HBLinks Archive (e.g. hblinks.co/archives/...)
        if (str_contains($rawUrl, 'hblinks')) {
            $res = ScraperService::resolveHbLinks($rawUrl);
            if ($res) {
                $res['input_url'] = $rawUrl;
                Response::success($res, 'HBLinks archive resolved successfully');
            }
        }

        // 6. KMHD Gateway (KatDrama links.kmhd.me / kmhd.eu / katdrive)
        if (str_contains($rawUrl, 'kmhd.me') || str_contains($rawUrl, 'kmhd.eu') || str_contains($rawUrl, 'katdrive')) {
            $res = \App\Services\KatDramaService::resolveKmhd($rawUrl);
            if ($res) {
                $res['input_url'] = $rawUrl;
                Response::success($res, 'KMHD download link resolved successfully');
                return;
            }
        }

        // 7. NexDrive Gateway (VegaMovies nexdrive.you / vgmlinks / fast-dl)
        if (str_contains($rawUrl, 'nexdrive') || str_contains($rawUrl, 'vgmlinks')) {
            $res = \App\Services\VegaMoviesService::resolveNexDrive($rawUrl);
            if ($res) {
                $res['input_url'] = $rawUrl;
                Response::success($res, 'NexDrive download link resolved successfully');
                return;
            }
        }

        // 8. Generic Direct Redirect Unwrapper
        $directUrl = ScraperService::getFinalRedirectUrl($rawUrl);
        Response::success([
            'input_url'           => $rawUrl,
            'primary_download_url'=> $directUrl,
            'direct_download_url' => $directUrl,
            'is_direct'           => (bool)preg_match('/googleusercontent\.com|fsl|pixeldrain|r2\.dev|bunker|cloudflarestorage/i', $directUrl),
            'servers'             => [
                [
                    'server_name'  => 'Direct Download Server',
                    'download_url' => $directUrl,
                    'is_direct'    => (bool)preg_match('/googleusercontent\.com|fsl|pixeldrain|r2\.dev|bunker|cloudflarestorage/i', $directUrl)
                ]
            ]
        ], 'Download link resolved');
    }
}
