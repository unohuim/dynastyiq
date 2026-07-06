<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Resolves and prepares the persistent Chromium profile used for Fantrax logo inspection.
 */
final class FantraxLogoBrowserProfile
{
    /**
     * Return the configured browser profile path.
     */
    public function path(): ?string
    {
        $path = trim((string) config('apiurls.fantrax.browser_profile_path', ''));

        return $path === '' ? null : $path;
    }

    /**
     * Determine whether the profile path is configured and exists as a directory.
     */
    public function isReady(): bool
    {
        $path = $this->path();

        return $path !== null && is_dir($path);
    }

    /**
     * Ensure the configured profile directory exists and is writable.
     *
     * @return array{path:string,ready:bool}
     */
    public function initialize(): array
    {
        $path = $this->path();

        if ($path === null) {
            throw new RuntimeException('Fantrax browser profile path is not configured.');
        }

        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Unable to create Fantrax browser profile directory.');
        }

        if (! is_writable($path)) {
            throw new RuntimeException('Fantrax browser profile directory is not writable.');
        }

        @chmod($path, 0700);

        return [
            'path' => $path,
            'ready' => true,
        ];
    }

    /**
     * Return non-sensitive state for UI and refresh summaries.
     *
     * @return array{configured:bool,ready:bool}
     */
    public function status(): array
    {
        $path = $this->path();

        return [
            'configured' => $path !== null,
            'ready' => $path !== null && is_dir($path),
        ];
    }
}
