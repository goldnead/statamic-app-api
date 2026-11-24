<?php

namespace Goldnead\AppApi\Tests\Feature;

use Goldnead\AppApi\Services\ExportDownloads;
use Goldnead\AppApi\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Failures that used to turn a success into a 500 (or a lie) after the
 * irreversible step had already happened.
 */
class StilleFehlerTest extends TestCase
{
    #[Test]
    public function a_cache_failure_after_the_payment_started_still_answers_with_the_checkout_url(): void
    {
        $this->actingAs($this->makeUser('sina@example.com'));

        Cache::extend('putfails', fn ($app) => Cache::repository(new class extends ArrayStore
        {
            public function put($key, $value, $seconds)
            {
                throw new RuntimeException('cache down');
            }
        }));
        config()->set('cache.stores.putfails', ['driver' => 'putfails']);
        config()->set('cache.default', 'putfails');

        $this->buy(['product' => 'lifetime', 'confirmed' => true])
            ->assertCreated()
            ->assertJsonPath('checkout_url', 'https://pay.test/checkout/1');

        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function the_prune_count_only_includes_files_that_were_really_removed(): void
    {
        $directory = storage_path(ExportDownloads::DIRECTORY);
        File::ensureDirectoryExists($directory);
        File::put($directory.'/alt', 'x');
        touch($directory.'/alt', now()->subHour()->getTimestamp());
        chmod($directory, 0555);

        try {
            $this->assertSame(0, app(ExportDownloads::class)->prune());
            $this->assertFileExists($directory.'/alt');
        } finally {
            chmod($directory, 0755);
            File::deleteDirectory($directory);
        }
    }
}
