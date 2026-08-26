<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SiteBrandingService
{
    public function apply(Site $site, bool $persist = true): Site
    {
        $url = Site::normalizeUrl((string) $site->url);
        $domain = Site::domainFromUrl($url);
        $site->url = $url;
        $site->domain = $domain;
        $site->favicon_url = $domain
            ? ($this->storeFavicon($site, $domain) ?: Site::googleFavicon($domain))
            : null;

        if ($persist && $site->exists) {
            $site->saveQuietly();
        }

        return $site;
    }

    public function storeFavicon(Site $site, string $domain): ?string
    {
        $candidates = array_filter([
            Site::googleFavicon($domain),
            "https://icons.duckduckgo.com/ip3/{$domain}.ico",
            $site->origin() ? $site->origin().'/favicon.ico' : null,
        ]);

        foreach ($candidates as $source) {
            try {
                $response = Http::timeout(4)
                    ->withHeaders(['User-Agent' => 'AnayaFavicon/1.0'])
                    ->get($source);

                if (! $response->successful()) {
                    continue;
                }

                $body = $response->body();
                if (strlen($body) < 32 || strlen($body) > 500_000) {
                    continue;
                }

                $ext = $this->extensionFromContentType((string) $response->header('Content-Type')) ?? 'png';
                $path = 'favicons/'.$site->id.'.'.$ext;
                Storage::disk('public')->put($path, $body);

                return Storage::disk('public')->url($path);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function extensionFromContentType(string $type): ?string
    {
        $type = strtolower(trim(explode(';', $type)[0]));

        return match ($type) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            'image/svg+xml' => 'svg',
            default => null,
        };
    }
}
