<?php

namespace Goldnead\AppApi\Commands;

use Goldnead\AppApi\Services\ExportDownloads;
use Illuminate\Console\Command;

/**
 * Removes personal data exports whose download link ran out without being
 * used. Scheduled hourly by the addon; a personal export should not sit on
 * the disk longer than its link.
 */
class PruneExports extends Command
{
    protected $signature = 'app-api:prune-exports';

    protected $description = 'Delete personal data exports whose download link has expired.';

    public function handle(ExportDownloads $downloads): int
    {
        $this->components->info(sprintf('%d expired exports removed.', $downloads->prune()));

        return self::SUCCESS;
    }
}
