<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/** Extracts bounded, cached text from X photo attachments without changing lineup authority. */
class NhlLineupImageOcr
{
    /** @return array<string,mixed> Extraction evidence, including explicit failure reasons. */
    public function extract(string $url, float $deadline): array
    {
        $evidence = ['url' => $url, 'engine' => 'tesseract.js-7.0.0', 'status' => 'error', 'text' => ''];
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'pbs.twimg.com'
            || ! str_starts_with($parts['path'] ?? '', '/media/')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return $evidence + ['reason' => 'Only HTTPS X photo CDN URLs are allowed.'];
        }
        if (! config('lineup_ocr.enabled')) {
            return $evidence + ['reason' => 'Lineup OCR is disabled.'];
        }

        $key = 'lineup-ocr:tesseract-v1:' . hash('sha256', $url . ':' . config('lineup_ocr.minimum_confidence'));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached + ['cached' => true];
        }
        if ($deadline <= microtime(true)) {
            return $evidence + ['reason' => 'The discovery OCR time budget was exhausted.'];
        }

        $path = tempnam(sys_get_temp_dir(), 'diq-lineup-ocr-');
        if ($path === false) {
            return $evidence + ['reason' => 'Could not allocate a temporary image file.'];
        }

        try {
            $maxBytes = (int) config('lineup_ocr.max_bytes');
            $response = Http::connectTimeout(5)
                ->timeout(max(1, min((int) config('lineup_ocr.download_timeout_seconds'), (int) ceil($deadline - microtime(true)))))
                ->withOptions([
                    'allow_redirects' => false,
                    'sink' => $path,
                    'progress' => static function ($total, $downloaded) use ($maxBytes): void {
                        if ($total > $maxBytes || $downloaded > $maxBytes) {
                            throw new RuntimeException('Image exceeds the download size limit.');
                        }
                    },
                ])->get($url);
            if ($response->status() !== 200) {
                throw new RuntimeException('Image download returned HTTP ' . $response->status() . '.');
            }
            clearstatcache(true, $path);
            if (filesize($path) > $maxBytes) {
                throw new RuntimeException('Image exceeds the download size limit.');
            }
            $data = $this->readImage($path, $deadline);
            $evidence = array_replace($evidence, $data);
            Cache::put($key, $evidence, (int) config('lineup_ocr.cache_seconds'));

            return $evidence;
        } catch (Throwable $exception) {
            // Do not turn an unreadable attachment into a failed team import or lose its caption.
            $evidence['reason'] = $exception->getMessage();
            Log::warning('Lineup image OCR failed.', ['image_url' => $url, 'reason' => $evidence['reason']]);
            Cache::put($key, $evidence, 60);

            return $evidence;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** Read a validated HTTP upload without publishing it or requesting any provider. @return array<string,mixed> */
    public function extractUpload(UploadedFile $image): array
    {
        $evidence = [
            'engine' => 'tesseract.js-7.0.0', 'status' => 'error', 'text' => '',
            'sha256' => hash_file('sha256', $image->getPathname()),
            'mime_type' => $image->getMimeType(), 'bytes' => $image->getSize(),
        ];
        if (! config('lineup_ocr.enabled')) {
            return $evidence + ['reason' => 'Lineup OCR is disabled.'];
        }
        if (! $image->isValid() || $image->getSize() > (int) config('lineup_ocr.max_bytes')
            || ! in_array($image->getMimeType(), ['image/jpeg', 'image/png'], true)) {
            return $evidence + ['reason' => 'Upload a JPEG or PNG image no larger than 10 MB.'];
        }
        $key = 'lineup-ocr:upload:tesseract-v1:' . $evidence['sha256'] . ':' . config('lineup_ocr.minimum_confidence');
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached + ['cached' => true];
        }
        try {
            $evidence = array_replace($evidence, $this->readImage(
                $image->getPathname(),
                microtime(true) + (int) config('lineup_ocr.timeout_seconds')
            ));
            Cache::put($key, $evidence, (int) config('lineup_ocr.cache_seconds'));

            return $evidence;
        } catch (Throwable $exception) {
            Log::warning('Manual lineup image OCR failed.', ['reason' => $exception->getMessage()]);

            return $evidence + ['reason' => 'Image processing failed. Try another image or paste the lineup text.'];
        }
    }

    /** Execute the same bounded OCR runner for remote photos and temporary uploads. @return array<string,mixed> */
    private function readImage(string $path, float $deadline): array
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('The discovery OCR time budget was exhausted.');
        }
        $result = Process::timeout(max(1, (int) min((int) config('lineup_ocr.timeout_seconds'), floor($remaining))))->run([
            (string) (config('lineup_ocr.node') ?: 'node'),
            base_path('scripts/lineup-ocr.mjs'),
            $path,
            '--max-pixels', (string) config('lineup_ocr.max_pixels'),
            '--minimum-confidence', (string) config('lineup_ocr.minimum_confidence'),
        ]);
        if (! $result->successful()) {
            throw new RuntimeException('Lineup OCR failed; verify Node and the installed npm production dependencies. '
                . mb_substr(trim($result->errorOutput()), 0, 500));
        }
        $data = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_string($data['text'] ?? null)
            || ! is_array($data['lines'] ?? null)
            || ! in_array($data['status'] ?? null, ['ok', 'empty', 'uncertain'], true)) {
            throw new RuntimeException('Lineup OCR returned an invalid extraction payload.');
        }

        return $data;
    }
}
