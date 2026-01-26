<?php

namespace App\Support;

use Illuminate\Support\Str;

class MediaEmbed
{
    public function toIframe(string $url): ?string
    {
        if (Str::contains($url, ['youtube.com', 'youtu.be'])) {
            $id = $this->youtubeId($url);
            if (! $id) return null;

            return $this->iframe("https://www.youtube.com/embed/{$id}");
        }

        if (Str::contains($url, ['vimeo.com'])) {
            $id = $this->vimeoId($url);
            if (! $id) return null;

            return $this->iframe("https://player.vimeo.com/video/{$id}");
        }

        // Permitir solo embebidos ya seguros
        if (Str::startsWith($url, [
            'https://www.youtube.com/embed/',
            'https://player.vimeo.com/video/',
        ])) {
            return $this->iframe($url);
        }

        return null;
    }

    private function iframe(string $src): string
    {
        return '<iframe src="' . e($src) . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
    }

    private function youtubeId(string $url): ?string
    {
        if (preg_match('~youtu\.be/([^?&/]+)~', $url, $m)) return $m[1];
        if (preg_match('~v=([^?&/]+)~', $url, $m)) return $m[1];
        if (preg_match('~youtube\.com/embed/([^?&/]+)~', $url, $m)) return $m[1];
        return null;
    }

    private function vimeoId(string $url): ?string
    {
        if (preg_match('~vimeo\.com/(?:video/)?([0-9]+)~', $url, $m)) return $m[1];
        return null;
    }
}
