<?php

namespace Goldnead\AppApi\Commands;

use Goldnead\AppApi\Support\OpenApi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes the OpenAPI description to a file, for generating client types
 * (`openapi-typescript openapi.json -o api.d.ts`).
 */
class GenerateOpenApi extends Command
{
    protected $signature = 'app-api:openapi
        {path=openapi.json : Where to write it, relative to the project}
        {--all : Every area, also those not active on this site}
        {--server= : The server URL written into the description}';

    protected $description = 'Write the OpenAPI description of the App API.';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $target = str_starts_with($path, '/') ? $path : base_path($path);

        $spec = OpenApi::build(! $this->option('all'), $this->option('server') ? (string) $this->option('server') : null);

        File::ensureDirectoryExists(dirname($target));
        File::put($target, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->components->info(sprintf('%d paths written to %s.', count($spec['paths']), $target));

        return self::SUCCESS;
    }
}
