<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

final class DraftPickCardRenderer
{
    /**
     * @param array<string,mixed> $card
     */
    public function render(array $card, ?string $path = null): ?string
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            return null;
        }

        $width = 1760;
        $height = 900;
        $image = imagecreatetruecolor($width, $height);

        if (! $image) {
            return null;
        }

        if (function_exists('imageantialias')) {
            imageantialias($image, true);
        }
        imagealphablending($image, true);

        $ink = imagecolorallocate($image, 3, 9, 20);
        $navy = imagecolorallocate($image, 6, 18, 38);
        $slate = imagecolorallocate($image, 12, 28, 54);
        $muted = imagecolorallocate($image, 155, 166, 186);
        $soft = imagecolorallocate($image, 217, 226, 240);
        $white = imagecolorallocate($image, 255, 255, 255);
        $green = imagecolorallocate($image, 34, 197, 94);
        $blue = imagecolorallocate($image, 35, 132, 255);
        $line = imagecolorallocate($image, 55, 75, 108);
        $teamColor = $this->teamBadgeColor($image, (string) ($card['team_abbrev'] ?? ''));
        $font = $this->draftCardFontPath();

        imagefilledrectangle($image, 0, 0, $width, $height, $ink);
        imagefilledpolygon($image, [0, 0, 1180, 0, 940, $height, 0, $height], 4, $navy);
        imagefilledpolygon($image, [1180, 0, $width, 0, $width, $height, 910, $height], 4, $slate);
        imagefilledpolygon($image, [0, 690, 470, 560, 680, $height, 0, $height], 4, $this->colorWithAlpha($image, 35, 132, 255, 82));
        imagefilledellipse($image, 980, 420, 720, 360, $this->colorWithAlpha($image, 35, 132, 255, 118));
        imagefilledellipse($image, 1350, 280, 520, 300, $this->colorWithAlpha($image, 34, 197, 94, 122));

        $frame = [
            64, 88,
            112, 40,
            1188, 40,
            1154, 132,
            1128, 168,
            64, 176,
        ];
        imagefilledpolygon($image, $frame, 6, $this->colorWithAlpha($image, 15, 23, 42, 18));
        imageline($image, 104, 40, 1188, 40, $line);
        imageline($image, 64, 176, 1128, 168, $blue);
        imageline($image, 1198, 40, 1164, 132, $blue);
        imagefilledrectangle($image, 64, 176, 1695, 876, $this->colorWithAlpha($image, 5, 12, 25, 18));
        imagefilledrectangle($image, 88, 204, 1670, 850, $this->colorWithAlpha($image, 8, 18, 34, 54));

        $ownerAvatar = $this->avatarImage((string) ($card['drafting_owner_avatar_url'] ?? ''));
        imagefilledellipse($image, 146, 106, 96, 96, $soft);
        imagefilledellipse($image, 146, 106, 86, 86, $ink);
        imagefilledellipse($image, 146, 106, 78, 78, $this->colorWithAlpha($image, 15, 23, 42, 18));

        if ($ownerAvatar) {
            $this->drawCircularImage($image, $ownerAvatar, 146, 106, 70);
            imagedestroy($ownerAvatar);
        } else {
            imagefilledellipse($image, 146, 106, 70, 70, $green);
            $this->drawCardText($image, 'DIQ', 22, 121, 115, $ink, $font);
        }

        $selectorText = trim((string) ($card['team_name'] ?? 'Drafting Team') . ' Selects');
        $this->drawFittedCardText($image, $selectorText, 38, 26, 880, 212, 121, $white, $font);

        imagefilledrectangle($image, 102, 234, 366, 532, $this->colorWithAlpha($image, 0, 0, 0, 76));
        imagefilledrectangle($image, 96, 226, 360, 522, $this->colorWithAlpha($image, 22, 44, 78, 18));
        imagerectangle($image, 96, 226, 360, 522, $line);
        imagerectangle($image, 102, 232, 354, 516, $this->colorWithAlpha($image, 35, 132, 255, 86));
        imagefilledrectangle($image, 118, 252, 338, 496, $this->colorWithAlpha($image, 3, 9, 20, 28));
        $this->drawCardText($image, 'OVERALL', 26, 165, 286, $green, $font);
        $this->drawCenteredFittedCardText($image, (string) ($card['overall_pick'] ?? '-'), 112, 70, 190, 118, 338, 338, 430, $white, $font);
        imageline($image, 134, 464, 322, 464, $green);
        $this->drawFittedCardText($image, strtoupper($this->footerTextFromCard($card)), 22, 17, 196, 132, 502, $muted, $font);

        $this->drawCardText($image, 'DRAFT ALERT', 24, 426, 260, $green, $font);
        imageline($image, 622, 250, 722, 250, $green);

        $playerName = (string) ($card['player_name'] ?? 'Drafted Player');
        $nameSize = $this->fittedCardTextSize($playerName, 660, 58, 38, $font);
        $this->drawCardText($image, $playerName, $nameSize, 426, 342, $white, $font);

        $position = trim((string) ($card['position'] ?? ''));
        if ($position !== '') {
            $nameWidth = $font && function_exists('imagettfbbox')
                ? $this->cardTextWidth($playerName, $nameSize, $font)
                : (int) ceil(strlen($playerName) * ($nameSize * 0.62));
            $positionX = min(1098, 426 + $nameWidth + 46);
            $this->drawCardText($image, strtoupper($position), max(34, $nameSize - 8), $positionX, 342, $white, $font);
        }

        $teamAbbrev = (string) ($card['team_abbrev'] ?? '');

        if ($teamAbbrev !== '') {
            imagefilledrectangle($image, 426, 378, 560, 442, $teamColor);
            imagefilledrectangle($image, 438, 388, 548, 432, $this->colorWithAlpha($image, 3, 9, 20, 54));
            imagerectangle($image, 426, 378, 560, 442, $blue);
            $this->drawCenteredFittedCardText($image, substr(strtoupper($teamAbbrev), 0, 4), 34, 26, 88, 438, 548, 460, 422, $white, $font);
        }

        imagefilledrectangle($image, 1212, 84, 1658, 548, $this->colorWithAlpha($image, 0, 0, 0, 82));
        imagefilledrectangle($image, 1202, 74, 1648, 538, $this->colorWithAlpha($image, 3, 12, 28, 4));
        imagerectangle($image, 1202, 74, 1648, 538, $line);
        imagerectangle($image, 1220, 92, 1630, 520, $this->colorWithAlpha($image, 35, 132, 255, 34));
        imagefilledrectangle($image, 1236, 108, 1614, 504, $this->colorWithAlpha($image, 3, 9, 20, 36));
        imageline($image, 1220, 520, 1630, 92, $this->colorWithAlpha($image, 22, 214, 255, 98));
        $avatar = $this->avatarImage((string) ($card['avatar_url'] ?? ''));

        if ($avatar) {
            imagecopyresampled($image, $avatar, 1250, 116, 0, 0, 352, 374, imagesx($avatar), imagesy($avatar));
            imagedestroy($avatar);
        } else {
            imagefilledellipse($image, 1426, 306, 230, 230, $line);
            $this->drawCardText($image, 'DIQ', 52, 1378, 326, $white, $font);
        }

        imagefilledrectangle($image, 102, 600, 1672, 810, $this->colorWithAlpha($image, 0, 0, 0, 82));
        imagefilledrectangle($image, 92, 590, 1662, 800, $this->colorWithAlpha($image, 12, 28, 50, 9));
        imagerectangle($image, 92, 590, 1662, 800, $line);
        imagefilledrectangle($image, 108, 660, 1646, 718, $this->colorWithAlpha($image, 35, 132, 255, 118));
        imagefilledrectangle($image, 108, 722, 1646, 780, $this->colorWithAlpha($image, 3, 9, 20, 78));
        imageline($image, 108, 648, 1646, 648, $blue);
        $this->drawCardText($image, 'SEASON', 22, 145, 636, $muted, $font);
        $this->drawCardText($image, 'LEAGUE', 22, 378, 636, $muted, $font);
        $this->drawCardText($image, 'GP', 22, 675, 636, $muted, $font);
        $this->drawCardText($image, 'G', 22, 868, 636, $muted, $font);
        $this->drawCardText($image, 'A', 22, 1048, 636, $muted, $font);
        $this->drawCardText($image, 'PTS', 22, 1215, 636, $muted, $font);
        $this->drawCardText($image, 'TEAM', 22, 1418, 636, $muted, $font);

        $y = 676;
        foreach (array_slice((array) ($card['stats'] ?? []), 0, 2) as $stat) {
            $season = $this->formatSeasonId((string) ($stat['season_id'] ?? ''));
            $baseline = $y + 28;
            $this->drawCardText($image, $season, 30, 145, $baseline, $white, $font);
            $this->drawFittedCardText($image, (string) ($stat['league_abbrev'] ?? '-'), 30, 22, 180, 378, $baseline, $white, $font);
            $this->drawCardText($image, $this->cardStatValue($stat['gp'] ?? null), 30, 672, $baseline, $white, $font);
            $this->drawCardText($image, $this->cardStatValue($stat['g'] ?? null), 30, 864, $baseline, $white, $font);
            $this->drawCardText($image, $this->cardStatValue($stat['a'] ?? null), 30, 1042, $baseline, $white, $font);
            $this->drawCardText($image, $this->cardStatValue($stat['pts'] ?? null), 30, 1208, $baseline, $white, $font);
            $this->drawFittedCardText($image, (string) ($stat['team_name'] ?? '-'), 30, 20, 280, 1374, $baseline, $white, $font);
            imageline($image, 120, $y + 52, 1634, $y + 52, $this->colorWithAlpha($image, 148, 163, 184, 92));
            $y += 72;
        }

        $this->drawCardText($image, 'DYNASTYIQ', 24, 102, 858, $white, $font);
        $this->drawCardText($image, 'LIVE DRAFT BOARD', 22, 1324, 858, $green, $font);
        imagefilledellipse($image, 1602, 850, 14, 14, $green);

        $targetPath = $path ?: sys_get_temp_dir() . '/diq-draft-pick-' . bin2hex(random_bytes(8)) . '.png';
        $written = imagepng($image, $targetPath);
        imagedestroy($image);

        return $written ? $targetPath : null;
    }

    private function avatarImage(string $url): mixed
    {
        if ($url === '' || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $response = Http::timeout(4)->get($url);

            if (! $response->successful()) {
                return null;
            }

            return imagecreatefromstring($response->body()) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function drawCircularImage(mixed $image, mixed $source, int $centerX, int $centerY, int $diameter): void
    {
        $square = imagecreatetruecolor($diameter, $diameter);

        if (! $square) {
            return;
        }

        imagealphablending($square, true);

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $cropSize = min($sourceWidth, $sourceHeight);
        $sourceX = (int) floor(($sourceWidth - $cropSize) / 2);
        $sourceY = (int) floor(($sourceHeight - $cropSize) / 2);

        imagecopyresampled($square, $source, 0, 0, $sourceX, $sourceY, $diameter, $diameter, $cropSize, $cropSize);

        $radius = $diameter / 2;
        $destinationX = $centerX - (int) floor($radius);
        $destinationY = $centerY - (int) floor($radius);

        for ($x = 0; $x < $diameter; $x++) {
            for ($y = 0; $y < $diameter; $y++) {
                $distanceX = $x - $radius + 0.5;
                $distanceY = $y - $radius + 0.5;

                if (($distanceX * $distanceX) + ($distanceY * $distanceY) <= $radius * $radius) {
                    imagesetpixel($image, $destinationX + $x, $destinationY + $y, imagecolorat($square, $x, $y));
                }
            }
        }

        imagedestroy($square);
    }

    /**
     * @param array<string,mixed> $card
     */
    private function footerTextFromCard(array $card): string
    {
        $parts = [];

        if (! empty($card['round'])) {
            $parts[] = 'Round ' . $card['round'];
        }

        if (! empty($card['pick_in_round'])) {
            $parts[] = 'Pick ' . $card['pick_in_round'];
        }

        return $parts !== [] ? implode(' | ', $parts) : 'Draft pick';
    }

    private function formatSeasonId(string $seasonId): string
    {
        if (preg_match('/^(\d{4})(\d{4})$/', $seasonId, $matches) === 1) {
            return $matches[1] . '-' . substr($matches[2], -2);
        }

        return $seasonId;
    }

    private function cardStatValue(mixed $value): string
    {
        return $value === null || $value === '' ? '-' : (string) $value;
    }

    private function cardTextWidth(string $value, int $size, string $font): int
    {
        $box = imagettfbbox($size, 0, $font, $value);

        if ($box === false) {
            return strlen($value) * $size;
        }

        return abs((int) $box[2] - (int) $box[0]);
    }

    private function fittedCardTextSize(string $value, int $maxWidth, int $preferredSize, int $minSize, ?string $font): int
    {
        for ($size = $preferredSize; $size >= $minSize; $size--) {
            $width = $font && function_exists('imagettfbbox')
                ? $this->cardTextWidth($value, $size, $font)
                : (int) ceil(strlen($value) * ($size * 0.62));

            if ($width <= $maxWidth) {
                return $size;
            }
        }

        return $minSize;
    }

    private function teamBadgeColor(mixed $image, string $teamAbbrev): int
    {
        $palette = [
            'ANA' => [252, 76, 2],
            'BOS' => [255, 184, 28],
            'BUF' => [0, 38, 84],
            'CGY' => [200, 16, 46],
            'CHI' => [207, 10, 44],
            'COL' => [111, 38, 61],
            'DET' => [206, 17, 38],
            'EDM' => [255, 76, 0],
            'MTL' => [175, 30, 45],
            'NYR' => [0, 56, 168],
            'SEA' => [0, 22, 40],
            'TOR' => [0, 32, 91],
            'VAN' => [0, 32, 91],
            'VGK' => [180, 151, 90],
        ];
        $rgb = $palette[strtoupper($teamAbbrev)] ?? [34, 197, 94];

        return imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    }

    private function colorWithAlpha(mixed $image, int $red, int $green, int $blue, int $alpha): int
    {
        return imagecolorallocatealpha($image, $red, $green, $blue, $alpha);
    }

    private function draftCardFontPath(): ?string
    {
        $paths = [
            '/System/Library/Fonts/SFNS.ttf',
            '/System/Library/Fonts/HelveticaNeue.ttc',
            '/System/Library/Fonts/Avenir.ttc',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function drawCardText(mixed $image, string $text, int $size, int $x, int $baselineY, int $color, ?string $font): void
    {
        if ($font && function_exists('imagettftext')) {
            imagettftext($image, $size, 0, $x, $baselineY, $color, $font, $text);

            return;
        }

        imagestring($image, min(5, max(1, (int) round($size / 5))), $x, max(0, $baselineY - 14), $text, $color);
    }

    private function drawFittedCardText(
        mixed $image,
        string $text,
        int $preferredSize,
        int $minSize,
        int $maxWidth,
        int $x,
        int $baselineY,
        int $color,
        ?string $font
    ): void {
        $size = $this->fittedCardTextSize($text, $maxWidth, $preferredSize, $minSize, $font);

        $this->drawCardText($image, $text, $size, $x, $baselineY, $color, $font);
    }

    private function drawCenteredFittedCardText(
        mixed $image,
        string $text,
        int $preferredSize,
        int $minSize,
        int $maxWidth,
        int $left,
        int $right,
        int $fallbackX,
        int $baselineY,
        int $color,
        ?string $font
    ): void {
        $size = $this->fittedCardTextSize($text, $maxWidth, $preferredSize, $minSize, $font);
        $width = $font && function_exists('imagettfbbox')
            ? $this->cardTextWidth($text, $size, $font)
            : (int) ceil(strlen($text) * ($size * 0.62));
        $x = $width > 0 ? (int) round($left + (($right - $left - $width) / 2)) : $fallbackX;

        $this->drawCardText($image, $text, $size, max($left, $x), $baselineY, $color, $font);
    }
}
