<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Create a scoped server-to-server API client token.
 */
class CreateApiClientCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'api-client:create
                            {name : Human-readable client name, such as gner8}
                            {--scope=* : Allowed API scope. Repeat for multiple scopes.}';

    /**
     * @var string
     */
    protected $description = 'Create a scoped server-to-server API client token';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $slug = Str::slug($name);

        if ($slug === '') {
            $this->error('Client name must produce a non-empty slug.');

            return self::FAILURE;
        }

        if (ApiClient::query()->where('slug', $slug)->exists()) {
            $this->error("API client [{$slug}] already exists. Revoke or rotate it before creating another.");

            return self::FAILURE;
        }

        $scopes = collect($this->option('scope'))
            ->map(fn (mixed $scope): string => trim((string) $scope))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($scopes === []) {
            $this->error('At least one --scope value is required.');

            return self::FAILURE;
        }

        $token = 'diq_' . str_replace('-', '_', $slug) . '_' . Str::random(64);

        ApiClient::query()->create([
            'name' => $name,
            'slug' => $slug,
            'token_prefix' => substr($token, 0, 24),
            'token_hash' => ApiClient::hashToken($token),
            'scopes' => $scopes,
        ]);

        $this->info('API client created.');
        $this->line('');
        $this->line("Name: {$name}");
        $this->line('Slug: ' . $slug);
        $this->line('Scopes: ' . implode(', ', $scopes));
        $this->line('Token: ' . $token);
        $this->line('');
        $this->warn('Store this token now. It will not be shown again.');

        return self::SUCCESS;
    }
}
