<?php

namespace App\Models;

use App\Enums\SiteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $fillable = [
        'name',
        'url',
        'domain',
        'favicon_url',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => SiteStatus::class,
        ];
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    public function workAssignments(): HasMany
    {
        return $this->hasMany(WorkAssignment::class);
    }

    public static function normalizeUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (! preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }

    public static function domainFromUrl(?string $url): ?string
    {
        $url = static::normalizeUrl($url);
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower(preg_replace('/^www\./i', '', $host) ?? $host);
    }

    public static function googleFavicon(string $domain): string
    {
        return 'https://www.google.com/s2/favicons?domain='.urlencode($domain).'&sz=128';
    }

    public function origin(): ?string
    {
        $url = static::normalizeUrl($this->url);
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'];
    }

    public function resolvedDomain(): ?string
    {
        return $this->domain ?: static::domainFromUrl($this->url);
    }

    public function resolvedFavicon(): ?string
    {
        if ($this->favicon_url) {
            return $this->favicon_url;
        }
        $domain = $this->resolvedDomain();

        return $domain ? static::googleFavicon($domain) : null;
    }
}
