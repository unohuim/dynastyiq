<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contract;
use App\Models\PlatformTeam;
use App\Models\PlatformTransaction;
use App\Models\PlatformTransactionEntry;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Render a trade transaction as a Discord-friendly PNG attachment.
 */
final class TradeTransactionCardRenderer
{
    /**
     * Render the card and return the created PNG path.
     */
    public function render(PlatformTransaction $transaction, ?string $path = null): ?string
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            return null;
        }

        $width = 1460;
        $layout = $this->layout($transaction);
        $height = $layout['height'];
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
        $teams = $this->tradeTeams($transaction);

        imagefilledrectangle($image, 0, 0, $width, $height, $colors['page']);
        $this->filledRoundedRectangle($image, 28, 28, 1432, $height - 28, 22, $colors['card']);
        $this->roundedRectangle($image, 28, 28, 1432, $height - 28, 22, $colors['border']);

        $this->drawHeader($image, $transaction, $colors, $font);
        imageline($image, 54, $layout['header_divider_y'], 1406, $layout['header_divider_y'], $colors['divider']);
        $this->drawTeamPanel($image, $teams[0] ?? null, 54, $layout['panels_y'], 636, $layout['panel_height'], $colors, $font, 'blue');
        $this->drawTeamPanel($image, $teams[1] ?? null, 770, $layout['panels_y'], 636, $layout['panel_height'], $colors, $font, 'green');
        $this->drawTradeGlyph($image, 730, $layout['glyph_y'], $colors, $font);

        $targetPath = $path ?: sys_get_temp_dir() . '/diq-trade-transaction-' . bin2hex(random_bytes(8)) . '.png';
        $written = imagepng($image, $targetPath);
        imagedestroy($image);

        return $written ? $targetPath : null;
    }

    /**
     * Compute content-driven card geometry.
     *
     * @return array{height:int,header_divider_y:int,panels_y:int,panel_height:int,glyph_y:int}
     */
    private function layout(PlatformTransaction $transaction): array
    {
        $teams = $this->tradeTeams($transaction);
        $leftEntryCount = isset($teams[0]) ? $teams[0]['entries']->count() : 0;
        $rightEntryCount = isset($teams[1]) ? $teams[1]['entries']->count() : 0;
        $maxEntries = max(
            1,
            $leftEntryCount,
            $rightEntryCount,
        );
        $visibleEntries = min(4, $maxEntries);
        $headerDividerY = 124;
        $panelsY = 142;
        $panelHeaderHeight = 82;
        $rowHeight = 74;
        $rowGap = 10;
        $panelPaddingBottom = $maxEntries > 4 ? 46 : 18;
        $panelHeight = $panelHeaderHeight
            + ($visibleEntries * $rowHeight)
            + (max(0, $visibleEntries - 1) * $rowGap)
            + $panelPaddingBottom;

        return [
            'height' => $panelsY + $panelHeight + 28,
            'header_divider_y' => $headerDividerY,
            'panels_y' => $panelsY,
            'panel_height' => $panelHeight,
            'glyph_y' => $panelsY + $panelHeaderHeight + (int) floor($rowHeight / 2),
        ];
    }

    /**
     * @return array<int,array{team:?PlatformTeam,entries:\Illuminate\Support\Collection<int,PlatformTransactionEntry>}>
     */
    private function tradeTeams(PlatformTransaction $transaction): array
    {
        $entries = $transaction->entries;
        $fromTeams = $entries
            ->map(fn (PlatformTransactionEntry $entry): ?PlatformTeam => $entry->fromTeam)
            ->filter()
            ->unique(fn (PlatformTeam $team): int => (int) $team->id)
            ->values();

        if ($fromTeams->count() >= 2) {
            return $fromTeams
                ->take(2)
                ->map(fn (PlatformTeam $team): array => [
                    'team' => $team,
                    'entries' => $entries
                        ->filter(fn (PlatformTransactionEntry $entry): bool => (int) $entry->from_platform_team_id === (int) $team->id)
                        ->values(),
                ])
                ->all();
        }

        $teams = $entries
            ->flatMap(fn (PlatformTransactionEntry $entry): array => [$entry->fromTeam, $entry->toTeam])
            ->filter()
            ->unique(fn (PlatformTeam $team): int => (int) $team->id)
            ->values();

        return collect([0, 1])
            ->map(fn (int $index): array => [
                'team' => $teams->get($index),
                'entries' => $index === 0 ? $entries->values() : collect(),
            ])
            ->all();
    }

    /**
     * @param array<string,int> $colors
     */
    private function drawHeader(mixed $image, PlatformTransaction $transaction, array $colors, ?string $font): void
    {
        $this->filledRoundedRectangle($image, 54, 50, 102, 98, 11, $colors['blueSoft']);
        $this->roundedRectangle($image, 54, 50, 102, 98, 11, $colors['blueSoftBorder']);
        $this->drawArrowsRightLeftIcon($image, 78, 74, $colors['blue']);
        $this->drawText($image, 'Trade', 33, 126, 92, $colors['ink'], $font);

        $time = $transaction->occurred_at
            ? $transaction->occurred_at->copy()->timezone('America/Toronto')->format('M j, Y • g:i A T')
            : 'Date unavailable';
        $this->drawFittedText($image, $time, 18, 14, 360, 1000, 84, $colors['muted'], $font);

    }

    private function drawArrowsRightLeftIcon(mixed $image, int $centerX, int $centerY, int $color): void
    {
        imagesetthickness($image, 4);

        imageline($image, $centerX - 18, $centerY - 9, $centerX + 18, $centerY - 9, $color);
        imageline($image, $centerX + 18, $centerY - 9, $centerX + 8, $centerY - 19, $color);
        imageline($image, $centerX + 18, $centerY - 9, $centerX + 8, $centerY + 1, $color);

        imageline($image, $centerX + 18, $centerY + 9, $centerX - 18, $centerY + 9, $color);
        imageline($image, $centerX - 18, $centerY + 9, $centerX - 8, $centerY - 1, $color);
        imageline($image, $centerX - 18, $centerY + 9, $centerX - 8, $centerY + 19, $color);

        imagesetthickness($image, 1);
    }

    /**
     * @param array{team:?PlatformTeam,entries:\Illuminate\Support\Collection<int,PlatformTransactionEntry>}|null $team
     * @param array<string,int> $colors
     */
    private function drawTeamPanel(mixed $image, ?array $team, int $x, int $y, int $width, int $height, array $colors, ?string $font, string $tone): void
    {
        $accent = $tone === 'green' ? $colors['green'] : $colors['blue'];
        $soft = $tone === 'green' ? $colors['greenPanel'] : $colors['bluePanel'];
        $panelBorder = $tone === 'green' ? $colors['greenPanelBorder'] : $colors['bluePanelBorder'];
        $teamModel = $team['team'] ?? null;
        $entries = $team['entries'] ?? collect();

        $this->filledRoundedRectangle($image, $x, $y, $x + $width, $y + $height, 15, $soft);
        $this->roundedRectangle($image, $x, $y, $x + $width, $y + $height, 15, $panelBorder);
        $this->drawTeamLogo($image, $teamModel, $x + 52, $y + 44, 64, $colors, $font, $accent);
        $this->drawFittedText($image, $teamModel?->name ?: 'Unknown Team', 24, 17, 420, $x + 98, $y + 36, $tone === 'green' ? $colors['greenDark'] : $colors['navy'], $font);
        $this->filledRoundedRectangle($image, $x + 98, $y + 45, $x + 162, $y + 68, 6, $tone === 'green' ? $colors['greenSoft'] : $colors['blueSoft']);
        $this->drawText($image, 'SENDS', 11, $x + 110, $y + 61, $accent, $font);

        $rowY = $y + 82;
        foreach ($entries->take(4) as $entry) {
            $this->drawAssetRow($image, $entry, $x + 18, $rowY, $width - 36, $colors, $font, $tone);
            $rowY += 84;
        }

        if ($entries->count() > 4) {
            $this->drawText($image, '+' . ($entries->count() - 4) . ' more assets', 17, $x + 42, $rowY + 22, $colors['muted'], $font);
        }
    }

    /**
     * @param array<string,int> $colors
     */
    private function drawAssetRow(mixed $image, PlatformTransactionEntry $entry, int $x, int $y, int $width, array $colors, ?string $font, string $tone): void
    {
        $accentSoft = $tone === 'green' ? $colors['greenSoft'] : $colors['blueSoft'];
        $accent = $tone === 'green' ? $colors['greenDark'] : $colors['blue'];
        $contractLabel = $this->contractLabel($entry);
        $contractWidth = $contractLabel !== null ? 136 : 0;
        $contractReservedWidth = $contractLabel !== null ? $contractWidth + 28 : 0;

        $this->filledRoundedRectangle($image, $x + 2, $y + 4, $x + $width + 2, $y + 76, 12, $colors['rowShadow']);
        $this->filledRoundedRectangle($image, $x, $y, $x + $width, $y + 74, 12, $colors['row']);
        $this->roundedRectangle($image, $x, $y, $x + $width, $y + 74, 12, $colors['border']);

        if ($entry->asset_type === 'draft_pick') {
            $this->drawDraftPickIcon($image, $x + 12, $y + 5, $colors, $font);
        } else {
            $avatar = $this->remoteImage((string) ($entry->player?->head_shot_url ?? ''));
            imagefilledellipse($image, $x + 40, $y + 37, 48, 48, $colors['slateSoft']);

            if ($avatar) {
                $this->drawCircularImage($image, $avatar, $x + 40, $y + 37, 48);
                imagedestroy($avatar);
            } else {
                $this->drawCenteredText($image, $this->initials($this->assetName($entry)), 14, $x + 16, $x + 64, $y + 43, $colors['muted'], $font);
            }
        }

        $this->drawFittedText($image, $this->assetName($entry), 21, 14, $width - 118 - $contractReservedWidth, $x + 82, $y + 33, $colors['navy'], $font);
        $this->drawFittedText($image, $this->assetMeta($entry), 14, 11, $width - 132 - $contractReservedWidth, $x + 82, $y + 57, $colors['muted'], $font);

        if ($contractLabel !== null) {
            $chipRight = $x + $width - 18;
            $chipLeft = $chipRight - $contractWidth;
            $this->filledRoundedRectangle($image, $chipLeft, $y + 21, $chipRight, $y + 53, 9, $colors['contractSoft']);
            $this->drawCenteredText($image, $contractLabel, 13, $chipLeft, $chipRight, $y + 43, $colors['contractText'], $font);
        }

        if ($entry->asset_type === 'draft_pick') {
            $this->filledRoundedRectangle($image, $x + $width - 62, $y + 19, $x + $width - 24, $y + 55, 10, $accentSoft);
            $this->drawCenteredText($image, $this->assetBadge($entry), 13, $x + $width - 62, $x + $width - 24, $y + 43, $accent, $font);
        }
    }

    /**
     * @param array<string,int> $colors
     */
    private function drawDraftPickIcon(mixed $image, int $x, int $y, array $colors, ?string $font): void
    {
        $outer = [
            $x + 32, $y,
            $x + 58, $y + 7,
            $x + 56, $y + 38,
            $x + 46, $y + 55,
            $x + 32, $y + 65,
            $x + 18, $y + 55,
            $x + 8, $y + 38,
            $x + 6, $y + 7,
        ];
        $inner = [
            $x + 32, $y + 8,
            $x + 49, $y + 13,
            $x + 48, $y + 35,
            $x + 40, $y + 48,
            $x + 32, $y + 54,
            $x + 24, $y + 48,
            $x + 16, $y + 35,
            $x + 15, $y + 13,
        ];

        imagefilledpolygon($image, $outer, 8, $colors['blueDark']);
        imagepolygon($image, $outer, 8, $colors['draftBorder']);
        imagefilledpolygon($image, $inner, 8, $colors['draftBlue']);
        imageline($image, $x + 17, $y + 17, $x + 47, $y + 17, $colors['draftHighlight']);
        imageline($image, $x + 20, $y + 35, $x + 44, $y + 35, $colors['draftDivider']);

        $this->drawBoldCenteredText($image, 'DRAFT', 7, $x + 15, $x + 49, $y + 29, $colors['white'], $font);
        $this->drawBoldCenteredText($image, 'PICK', 7, $x + 15, $x + 49, $y + 45, $colors['white'], $font);
        $this->drawStar($image, $x + 32, $y + 57, 4, $colors['draftHighlight']);
    }

    private function drawStar(mixed $image, int $centerX, int $centerY, int $radius, int $color): void
    {
        $points = [];

        for ($i = 0; $i < 10; $i++) {
            $angle = deg2rad(-90 + ($i * 36));
            $pointRadius = $i % 2 === 0 ? $radius : max(2, (int) floor($radius / 2));
            $points[] = $centerX + (int) round(cos($angle) * $pointRadius);
            $points[] = $centerY + (int) round(sin($angle) * $pointRadius);
        }

        imagefilledpolygon($image, $points, 10, $color);
    }

    /**
     * @param array<string,int> $colors
     */
    private function drawTradeGlyph(mixed $image, int $centerX, int $centerY, array $colors, ?string $font): void
    {
        imagefilledellipse($image, $centerX + 2, $centerY + 4, 48, 48, $colors['glyphShadow']);
        imagefilledellipse($image, $centerX, $centerY, 46, 46, $colors['white']);
        imageellipse($image, $centerX, $centerY, 46, 46, $colors['border']);
        $this->drawCenteredText($image, '→', 17, $centerX - 22, $centerX + 22, $centerY - 3, $colors['blue'], $font);
        $this->drawCenteredText($image, '←', 17, $centerX - 22, $centerX + 22, $centerY + 14, $colors['green'], $font);
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

        $this->drawCenteredText($image, $this->initials((string) ($team?->name ?? 'Team')), 25, $centerX - 44, $centerX + 44, $centerY + 9, $colors['white'], $font);
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
        if ($entry->asset_type === 'draft_pick') {
            $parts = [];
            if ($entry->draft_year) {
                $parts[] = (string) $entry->draft_year;
            }
            if ($entry->draft_round) {
                $parts[] = 'Round ' . $entry->draft_round;
            }
            if ($entry->draft_pick) {
                $parts[] = 'Pick ' . $entry->draft_pick;
            }

            return $parts !== [] ? implode(' ', $parts) . ' Draft Pick' : ($entry->raw_name ?: 'Draft Pick');
        }

        return (string) ($entry->player?->full_name ?: $entry->raw_name ?: 'Unknown asset');
    }

    private function assetMeta(PlatformTransactionEntry $entry): string
    {
        if ($entry->asset_type === 'draft_pick') {
            return $entry->draft_original_team_name
                ? 'Original Pick (' . $entry->draft_original_team_name . ')'
                : 'Draft pick';
        }

        $parts = array_filter([
            $entry->player?->position,
            $entry->player?->team_abbrev,
        ], static fn (mixed $value): bool => filled($value));

        return $parts !== [] ? implode(' • ', $parts) : 'Player';
    }

    private function assetBadge(PlatformTransactionEntry $entry): string
    {
        if ($entry->asset_type === 'draft_pick') {
            return $entry->draft_round ? (string) $entry->draft_round : '#';
        }

        $position = trim((string) ($entry->player?->position ?? ''));

        if (str_contains($position, ',')) {
            return trim(strtok($position, ',') ?: 'P');
        }

        return $position !== '' ? substr($position, 0, 2) : 'P';
    }

    private function contractLabel(PlatformTransactionEntry $entry): ?string
    {
        if ($entry->asset_type !== 'player' || ! $entry->player_id) {
            return null;
        }

        $seasonKey = (int) $this->currentFantasySeasonKey();
        $contract = Contract::query()
            ->where('player_id', $entry->player_id)
            ->whereHas('seasons', static fn ($query) => $query->where('season_key', '>=', $seasonKey))
            ->with(['seasons' => static fn ($query) => $query
                ->where('season_key', '>=', $seasonKey)
                ->orderBy('season_key')])
            ->get()
            ->sortBy(static fn (Contract $contract): int => (int) ($contract->seasons->min('season_key') ?? PHP_INT_MAX))
            ->first();

        if (! $contract instanceof Contract || $contract->seasons->isEmpty()) {
            return null;
        }

        $years = $contract->seasons
            ->pluck('season_key')
            ->filter()
            ->unique()
            ->count();
        $value = (int) ($contract->seasons->firstWhere('aav', '>', 0)?->aav
            ?? $contract->seasons->firstWhere('cap_hit', '>', 0)?->cap_hit
            ?? 0);

        if ($years <= 0 && filled($contract->contract_length)) {
            preg_match('/\d+/', (string) $contract->contract_length, $matches);
            $years = isset($matches[0]) ? (int) $matches[0] : 0;
        }

        if ($value <= 0 && $years > 0 && $contract->contract_value) {
            $value = (int) round(((int) $contract->contract_value) / $years);
        }

        if ($years <= 0 || $value <= 0) {
            return null;
        }

        return $years . ($years === 1 ? 'yr' : 'yrs') . ' x ' . $this->moneyMillions($value);
    }

    private function currentFantasySeasonKey(): string
    {
        $now = now();
        $startYear = $now->month >= 7 ? $now->year : $now->year - 1;

        return (string) $startYear . (string) ($startYear + 1);
    }

    private function moneyMillions(int $value): string
    {
        $millions = $value / 1000000;
        $formatted = number_format($millions, $millions >= 10 || floor($millions) === $millions ? 0 : 1);

        return '$' . $formatted . 'M';
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
            throw new \RuntimeException('Required trade card image could not be downloaded and decoded.');
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
            'row' => imagecolorallocate($image, 252, 253, 255),
            'rowShadow' => imagecolorallocate($image, 234, 240, 248),
            'white' => imagecolorallocate($image, 255, 255, 255),
            'divider' => imagecolorallocate($image, 231, 237, 246),
            'glyphShadow' => imagecolorallocate($image, 226, 233, 244),
            'ink' => imagecolorallocate($image, 12, 26, 48),
            'navy' => imagecolorallocate($image, 20, 31, 78),
            'muted' => imagecolorallocate($image, 80, 94, 122),
            'border' => imagecolorallocate($image, 221, 228, 238),
            'contractSoft' => imagecolorallocate($image, 241, 244, 248),
            'contractText' => imagecolorallocate($image, 97, 111, 132),
            'blue' => imagecolorallocate($image, 37, 99, 235),
            'blueDark' => imagecolorallocate($image, 30, 64, 175),
            'draftBlue' => imagecolorallocate($image, 47, 117, 238),
            'draftBorder' => imagecolorallocate($image, 210, 225, 255),
            'draftHighlight' => imagecolorallocate($image, 222, 235, 255),
            'draftDivider' => imagecolorallocate($image, 124, 166, 246),
            'blueSoft' => imagecolorallocate($image, 234, 241, 255),
            'blueSoftBorder' => imagecolorallocate($image, 214, 226, 252),
            'bluePanel' => imagecolorallocate($image, 243, 248, 255),
            'bluePanelBorder' => imagecolorallocate($image, 207, 222, 252),
            'green' => imagecolorallocate($image, 34, 151, 102),
            'greenDark' => imagecolorallocate($image, 23, 94, 62),
            'greenSoft' => imagecolorallocate($image, 226, 247, 236),
            'greenPanel' => imagecolorallocate($image, 242, 252, 247),
            'greenPanelBorder' => imagecolorallocate($image, 199, 235, 218),
            'slateSoft' => imagecolorallocate($image, 239, 242, 247),
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

    private function drawBoldCenteredText(mixed $image, string $text, int $size, int $left, int $right, int $baselineY, int $color, ?string $font): void
    {
        $width = $this->textWidth($text, $size, $font);
        $x = max($left, (int) round($left + (($right - $left - $width) / 2)));

        $this->drawText($image, $text, $size, $x, $baselineY, $color, $font);
        $this->drawText($image, $text, $size, $x + 1, $baselineY, $color, $font);
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
