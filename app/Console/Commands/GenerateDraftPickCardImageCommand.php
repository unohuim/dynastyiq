<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DraftPickCardRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class GenerateDraftPickCardImageCommand extends Command
{
    protected $signature = 'draft:image {--output=docs/designs/draft-card-preview/nathan-aspinall.png}';
    protected $description = 'Generate a local preview image for the Fantrax draft pick Discord card.';

    public function handle(DraftPickCardRenderer $renderer): int
    {
        $output = (string) $this->option('output');
        $path = str_starts_with($output, DIRECTORY_SEPARATOR)
            ? $output
            : base_path($output);

        File::ensureDirectoryExists(dirname($path));

        $rendered = $renderer->render($this->sampleCard(), $path);

        if ($rendered === null) {
            $this->error('Unable to generate draft image. Confirm PHP GD is installed and the output path is writable.');

            return self::FAILURE;
        }

        $this->info('Draft image generated: ' . $rendered);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function sampleCard(): array
    {
        return [
            'overall_pick' => 43,
            'round' => 3,
            'pick_in_round' => 7,
            'player_name' => 'Aspinall, Nathan',
            'position' => 'L',
            'avatar_url' => null,
            'team_name' => 'Northumberland Nitro',
            'team_abbrev' => 'NYR',
            'drafting_owner_avatar_url' => null,
            'stats' => [
                [
                    'season_id' => '20252026',
                    'league_abbrev' => 'OHL',
                    'gp' => 65,
                    'g' => 33,
                    'a' => 61,
                    'pts' => 94,
                    'team_name' => 'Flint Firebirds',
                ],
                [
                    'season_id' => '20242025',
                    'league_abbrev' => 'OHL',
                    'gp' => 62,
                    'g' => 17,
                    'a' => 30,
                    'pts' => 47,
                    'team_name' => 'Flint Firebirds',
                ],
            ],
        ];
    }
}
