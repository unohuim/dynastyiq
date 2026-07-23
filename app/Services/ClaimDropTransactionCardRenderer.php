<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformTeam;
use App\Models\PlatformTransaction;
use App\Models\PlatformTransactionEntry;
use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Render claim and drop transactions as Discord-friendly PNG attachments.
 */
final class ClaimDropTransactionCardRenderer
{
    /**
     * Render the card and return the created PNG path.
     */
    public function render(PlatformTransaction $transaction, ?string $path = null): ?string
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            return null;
        }

        $entries = $this->claimDropEntries($transaction);
        $cardWidth = 1120;
        $width = $cardWidth + 64;
        $entryHeight = 560;
        $gap = 24;
        $height = 32 + ($entries->count() * $entryHeight) + (max(0, $entries->count() - 1) * $gap) + 32;
        $image = imagecreatetruecolor($width, $height);

        if (! $image) {
            return null;
        }

        if (function_exists('imageantialias')) {
            imageantialias($image, true);
        }

        imagealphablending($image, true);

        $font = $this->fontPath();
        $colors = $this->palette($image);
        imagefilledrectangle($image, 0, 0, $width, $height, $colors['page']);

        $y = 32;
        foreach ($entries as $entry) {
            $this->drawEntryCard($image, $transaction, $entry, 32, $y, $cardWidth, $entryHeight, $colors, $font);
            $y += $entryHeight + $gap;
        }

        $targetPath = $path ?: sys_get_temp_dir() . '/diq-claim-drop-transaction-' . bin2hex(random_bytes(8)) . '.png';
        $written = imagepng($image, $targetPath);
        imagedestroy($image);

        return $written ? $targetPath : null;
    }

    /**
     * @return Collection<int,PlatformTransactionEntry>
     */
    private function claimDropEntries(PlatformTransaction $transaction): Collection
    {
        $entries = $transaction->entries
            ->filter(static fn (PlatformTransactionEntry $entry): bool => in_array($entry->action, ['claim', 'add', 'drop'], true))
            ->values();

        return $entries->isNotEmpty() ? $entries : $transaction->entries->values();
    }

    /**
     * @param array<string,int> $colors
     */
    private function drawEntryCard(
        mixed $image,
        PlatformTransaction $transaction,
        PlatformTransactionEntry $entry,
        int $x,
        int $y,
        int $width,
        int $height,
        array $colors,
        ?string $font
    ): void {
        $isDrop = $entry->action === 'drop';
        $accent = $isDrop ? $colors['red'] : $colors['green'];
        $rail = $isDrop ? $colors['redRail'] : $colors['greenRail'];
        $action = $isDrop ? 'DROP' : 'ADD';
        $team = $this->entryTeam($entry);
        $railWidth = 190;
        $contentX = $x + $railWidth;
        $headerHeight = 224;
        $teamHeight = 202;
        $footerY = $y + $headerHeight + $teamHeight;

        $this->filledRoundedRectangle($image, $x + 4, $y + 8, $x + $width + 4, $y + $height + 8, 28, $colors['cardShadow']);
        $this->filledRoundedRectangle($image, $x, $y, $x + $width, $y + $height, 28, $colors['card']);
        $this->roundedRectangle($image, $x, $y, $x + $width, $y + $height, 28, $colors['border']);
        $this->filledRoundedRectangle($image, $x, $y, $x + $railWidth, $y + $height, 28, $rail);
        imagefilledrectangle($image, $x + $railWidth - 28, $y, $x + $railWidth, $y + $height, $rail);

        imagefilledellipse($image, $x + 95, $y + 190, 72, 72, $accent);
        $this->drawActionIcon($image, $x + 95, $y + 190, $isDrop, $colors['white']);
        $this->drawCenteredText($image, $action, 21, $x + 30, $x + $railWidth - 30, $y + 282, $accent, $font);

        $this->filledRoundedRectangle($image, $contentX, $y, $x + $width, $y + $headerHeight, 28, $colors['navy']);
        imagefilledrectangle($image, $contentX, $y, $contentX + 28, $y + $headerHeight, $colors['navy']);
        imagefilledrectangle($image, $contentX, $y + $headerHeight - 24, $x + $width, $y + $headerHeight, $colors['navy']);
        $this->drawPlayerImage($image, $entry, $contentX + 156, $y + 204, 128, $colors, $font);

        $this->drawFittedText($image, $this->assetName($entry), 31, 21, 510, $contentX + 294, $y + 114, $colors['white'], $font);
        $this->drawPlayerMeta($image, $entry, $contentX + 296, $y + 154, $colors, $font);

        $teamY = $y + $headerHeight;
        $this->drawTeamLogo($image, $team, $contentX + 132, $teamY + 98, 86, $colors, $font, $accent);
        $this->drawText($image, 'FANTASY TEAM', 13, $contentX + 214, $teamY + 82, $colors['muted'], $font);
        $this->drawFittedText($image, $team?->name ?: 'Unknown Team', 27, 18, 560, $contentX + 214, $teamY + 118, $colors['ink'], $font);

        imageline($image, $contentX + 40, $footerY, $x + $width - 40, $footerY, $colors['divider']);

        $time = $transaction->occurred_at
            ? $transaction->occurred_at->copy()->timezone('America/Toronto')->format('D M j, Y, g:i A')
            : 'Date unavailable';
        $dateSize = 18;
        $dateWidth = min(440, $this->textWidth($time, $dateSize, $font));
        $clusterWidth = 54 + 18 + $dateWidth;
        $clusterX = $x + $width - 72 - $clusterWidth;
        $clusterCenterY = $footerY + 68;
        $this->drawCalendarDaysIcon($image, $clusterX, $clusterCenterY - 28, $colors['mutedSoft']);
        $this->drawFittedText($image, $time, $dateSize, 14, 440, $clusterX + 72, $clusterCenterY + 7, $colors['mutedSoft'], $font);
    }

    private function drawActionIcon(mixed $image, int $centerX, int $centerY, bool $isDrop, int $color): void
    {
        imagesetthickness($image, 6);
        imageline($image, $centerX - 18, $centerY, $centerX + 18, $centerY, $color);

        if (! $isDrop) {
            imageline($image, $centerX, $centerY - 18, $centerX, $centerY + 18, $color);
        }

        imagesetthickness($image, 1);
    }

    /**
     * @param array<string,int> $colors
     */
    private function drawPlayerImage(mixed $image, PlatformTransactionEntry $entry, int $centerX, int $centerY, int $diameter, array $colors, ?string $font): void
    {
        $player = $this->playerFor($entry);
        $avatar = $this->remoteImage((string) ($player?->head_shot_url ?? ''));
        imagefilledellipse($image, $centerX + 4, $centerY + 7, $diameter + 8, $diameter + 8, $colors['shadow']);
        imagefilledellipse($image, $centerX, $centerY, $diameter + 12, $diameter + 12, $colors['white']);
        imagefilledellipse($image, $centerX, $centerY, $diameter, $diameter, $colors['slateSoft']);

        if ($avatar) {
            $this->drawCircularImage($image, $avatar, $centerX, $centerY, $diameter);
            imagedestroy($avatar);

            return;
        }

        $this->drawCenteredText($image, $this->initials($this->assetName($entry)), 34, $centerX - 60, $centerX + 60, $centerY + 12, $colors['muted'], $font);
    }

    /**
     * @param array<string,int> $colors
     */
    private function drawPlayerMeta(mixed $image, PlatformTransactionEntry $entry, int $x, int $baselineY, array $colors, ?string $font): void
    {
        $player = $this->playerFor($entry);
        $position = trim((string) ($player?->position ?? ''));
        $team = trim((string) ($player?->team_abbrev ?? ''));
        $meta = implode('  •  ', array_filter([$position, $team], static fn (string $value): bool => $value !== ''));

        if ($meta !== '') {
            $this->drawText($image, $meta, 17, $x, $baselineY, $colors['navyMuted'], $font);
        }
    }

    private function drawCalendarDaysIcon(mixed $image, int $x, int $y, int $color): void
    {
        imagesetthickness($image, 3);
        $this->roundedRectangle($image, $x + 3, $y + 7, $x + 51, $y + 55, 8, $color);
        imageline($image, $x + 15, $y, $x + 15, $y + 14, $color);
        imageline($image, $x + 39, $y, $x + 39, $y + 14, $color);
        imageline($image, $x + 3, $y + 23, $x + 51, $y + 23, $color);
        imagesetthickness($image, 1);

        imagefilledellipse($image, $x + 16, $y + 34, 4, 4, $color);
        imagefilledellipse($image, $x + 27, $y + 34, 4, 4, $color);
        imagefilledellipse($image, $x + 38, $y + 34, 4, 4, $color);
        imagefilledellipse($image, $x + 16, $y + 45, 4, 4, $color);
        imagefilledellipse($image, $x + 27, $y + 45, 4, 4, $color);
        imagefilledellipse($image, $x + 38, $y + 45, 4, 4, $color);
    }

    /**
     * @param array<string,int> $colors
     */
    private function drawTeamLogo(mixed $image, ?PlatformTeam $team, int $centerX, int $centerY, int $diameter, array $colors, ?string $font, int $accent): void
    {
        $logo = $this->teamImage($team);
        imagefilledellipse($image, $centerX, $centerY, $diameter, $diameter, $accent);

        if ($logo) {
            $this->drawCircularImage($image, $logo, $centerX, $centerY, $diameter);
            imagedestroy($logo);

            return;
        }

        $this->drawCenteredText($image, $this->initials((string) ($team?->name ?? 'Team')), 27, $centerX - 46, $centerX + 46, $centerY + 10, $colors['white'], $font);
    }

    private function entryTeam(PlatformTransactionEntry $entry): ?PlatformTeam
    {
        if ($entry->platformTeam instanceof PlatformTeam) {
            return $entry->platformTeam;
        }

        if (in_array($entry->action, ['claim', 'add'], true) && $entry->toTeam instanceof PlatformTeam) {
            return $entry->toTeam;
        }

        if ($entry->action === 'drop' && $entry->fromTeam instanceof PlatformTeam) {
            return $entry->fromTeam;
        }

        return $entry->toTeam ?: $entry->fromTeam;
    }

    private function teamImage(?PlatformTeam $team): mixed
    {
        $logoUrl = trim((string) ($team?->logo_url ?? ''));

        if ($logoUrl !== '') {
            return $this->requiredRemoteImage($logoUrl);
        }

        $discordAvatarUrl = $this->discordAvatarUrl($team);

        if ($discordAvatarUrl !== '') {
            return $this->requiredRemoteImage($discordAvatarUrl);
        }

        return null;
    }

    private function discordAvatarUrl(?PlatformTeam $team): string
    {
        if (! $team instanceof PlatformTeam || ! $team->relationLoaded('users')) {
            return '';
        }

        foreach ($team->users as $user) {
            if (! $user->relationLoaded('socialAccounts')) {
                continue;
            }

            $avatar = optional($user->socialAccounts->firstWhere('provider', 'discord'))->avatar;

            if (filled($avatar)) {
                return (string) $avatar;
            }
        }

        return '';
    }

    private function assetName(PlatformTransactionEntry $entry): string
    {
        $player = $this->playerFor($entry);

        return (string) ($player?->full_name ?: $entry->raw_name ?: 'Unknown player');
    }

    private function assetMeta(PlatformTransactionEntry $entry): string
    {
        $player = $this->playerFor($entry);
        $parts = array_filter([
            $player?->position,
            $player?->team_abbrev,
        ], static fn (mixed $value): bool => filled($value));

        return $parts !== [] ? implode(' / ', $parts) : 'Player';
    }

    private function assetBadge(PlatformTransactionEntry $entry): string
    {
        $player = $this->playerFor($entry);
        $position = trim((string) ($player?->position ?? ''));

        if (str_contains($position, ',')) {
            return trim(strtok($position, ',') ?: 'P');
        }

        return $position !== '' ? substr($position, 0, 2) : 'P';
    }

    private function playerFor(PlatformTransactionEntry $entry): ?Player
    {
        $player = $entry->relationLoaded('player') ? $entry->player : null;

        if ($player instanceof Player && $this->playerHasCardFields($player)) {
            return $player;
        }

        if (! $entry->player_id) {
            return $player instanceof Player ? $player : null;
        }

        $player = Player::query()
            ->select(['id', 'full_name', 'position', 'team_abbrev', 'head_shot_url'])
            ->find($entry->player_id);

        if ($player instanceof Player) {
            $entry->setRelation('player', $player);
        }

        return $player;
    }

    private function playerHasCardFields(Player $player): bool
    {
        $attributes = $player->getAttributes();

        return array_key_exists('full_name', $attributes)
            && array_key_exists('position', $attributes)
            && array_key_exists('team_abbrev', $attributes)
            && array_key_exists('head_shot_url', $attributes);
    }

    private function remoteImage(string $url): mixed
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

    private function requiredRemoteImage(string $url): mixed
    {
        $image = $this->remoteImage($url);

        if (! $image) {
            throw new \RuntimeException('Required claim/drop card image could not be downloaded and decoded.');
        }

        return $image;
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = collect($parts)
            ->filter()
            ->take(2)
            ->map(static fn (string $part): string => strtoupper(substr($part, 0, 1)))
            ->implode('');

        return $letters !== '' ? $letters : 'T';
    }

    /**
     * @return array<string,int>
     */
    private function palette(mixed $image): array
    {
        return [
            'page' => imagecolorallocate($image, 245, 247, 251),
            'card' => imagecolorallocate($image, 255, 255, 255),
            'cardShadow' => imagecolorallocate($image, 225, 231, 240),
            'white' => imagecolorallocate($image, 255, 255, 255),
            'ink' => imagecolorallocate($image, 12, 26, 48),
            'navy' => imagecolorallocate($image, 15, 27, 53),
            'navyMuted' => imagecolorallocate($image, 182, 194, 213),
            'muted' => imagecolorallocate($image, 92, 107, 132),
            'mutedSoft' => imagecolorallocate($image, 125, 138, 158),
            'border' => imagecolorallocate($image, 221, 228, 238),
            'divider' => imagecolorallocate($image, 232, 237, 245),
            'green' => imagecolorallocate($image, 30, 176, 96),
            'greenRail' => imagecolorallocate($image, 244, 253, 248),
            'red' => imagecolorallocate($image, 220, 65, 86),
            'redRail' => imagecolorallocate($image, 255, 247, 248),
            'slateSoft' => imagecolorallocate($image, 239, 242, 247),
            'shadow' => imagecolorallocate($image, 206, 214, 226),
        ];
    }

    private function fontPath(): ?string
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

    private function filledRoundedRectangle(mixed $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        if ($radius <= 0) {
            imagefilledrectangle($image, $x1, $y1, $x2, $y2, $color);

            return;
        }

        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    }

    private function roundedRectangle(mixed $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        imageline($image, $x1 + $radius, $y1, $x2 - $radius, $y1, $color);
        imageline($image, $x1 + $radius, $y2, $x2 - $radius, $y2, $color);
        imageline($image, $x1, $y1 + $radius, $x1, $y2 - $radius, $color);
        imageline($image, $x2, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagearc($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, 180, 270, $color);
        imagearc($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, 270, 360, $color);
        imagearc($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, 0, 90, $color);
        imagearc($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, 90, 180, $color);
    }

    private function drawText(mixed $image, string $text, int $size, int $x, int $baselineY, int $color, ?string $font): void
    {
        if ($font && function_exists('imagettftext')) {
            imagettftext($image, $size, 0, $x, $baselineY, $color, $font, $text);

            return;
        }

        imagestring($image, min(5, max(1, (int) round($size / 5))), $x, max(0, $baselineY - 14), $text, $color);
    }

    private function drawFittedText(mixed $image, string $text, int $preferredSize, int $minSize, int $maxWidth, int $x, int $baselineY, int $color, ?string $font): void
    {
        $size = $preferredSize;

        while ($size > $minSize && $this->textWidth($text, $size, $font) > $maxWidth) {
            $size--;
        }

        $this->drawText($image, $text, $size, $x, $baselineY, $color, $font);
    }

    private function drawCenteredText(mixed $image, string $text, int $size, int $left, int $right, int $baselineY, int $color, ?string $font): void
    {
        $width = $this->textWidth($text, $size, $font);
        $x = (int) round($left + (($right - $left - $width) / 2));

        $this->drawText($image, $text, $size, max($left, $x), $baselineY, $color, $font);
    }

    private function textWidth(string $text, int $size, ?string $font): int
    {
        if ($font && function_exists('imagettfbbox')) {
            $box = imagettfbbox($size, 0, $font, $text);

            if ($box !== false) {
                return abs((int) $box[2] - (int) $box[0]);
            }
        }

        return (int) ceil(strlen($text) * ($size * 0.62));
    }
}
