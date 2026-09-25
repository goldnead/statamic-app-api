<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\AppApi\Services\ExportDownloads;
use Goldnead\AppApi\Tests\TestCase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

class CommandsTest extends TestCase
{
    #[Test]
    public function the_openapi_command_writes_every_area_with_all(): void
    {
        $path = sys_get_temp_dir().'/app-api-openapi-'.uniqid().'.json';

        $this->artisan('app-api:openapi', ['path' => $path, '--all' => true])->assertSuccessful();

        $spec = json_decode((string) file_get_contents($path), true);
        @unlink($path);

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('/api/app/tokens', $spec['paths']);
        $this->assertArrayHasKey('/api/app/teams/{team}/members', $spec['paths']);
        $this->assertSame(['$ref' => '#/components/parameters/TeamHeader'], collect($spec['paths']['/api/app/access']['get']['parameters'])->last());
    }

    #[Test]
    public function the_prune_command_removes_expired_exports_only(): void
    {
        $directory = storage_path(ExportDownloads::DIRECTORY);
        File::ensureDirectoryExists($directory);
        File::put($directory.'/alt', 'x');
        touch($directory.'/alt', now()->subHour()->getTimestamp());
        File::put($directory.'/neu', 'x');

        $this->artisan('app-api:prune-exports')->assertSuccessful();

        $this->assertFileDoesNotExist($directory.'/alt');
        $this->assertFileExists($directory.'/neu');

        File::deleteDirectory($directory);
    }
}
