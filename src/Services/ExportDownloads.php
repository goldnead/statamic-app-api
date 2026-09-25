<?php

namespace Goldnead\AppApi\Services;

use Goldnead\AppApi\Exceptions\ApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Statamic\Auth\User;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Hands a built personal data export over in two steps.
 *
 * statamic-accounts writes the export to a temporary file and expects the
 * caller to send and delete it. A JSON client cannot receive a file from a
 * POST that also answers with JSON, so the file is parked under
 * `storage/app/app-api-exports` and the answer carries a signed link that
 * works for a few minutes, once, and only for the same user.
 */
class ExportDownloads
{
    public const DIRECTORY = 'app/app-api-exports';

    /**
     * @param  array{path: string, filename: string, mime: string}  $file
     * @return array{download_url: string, filename: string, expires_at: string}
     */
    public function store(User $user, array $file): array
    {
        $id = Str::random(40);
        $directory = storage_path(self::DIRECTORY);
        File::ensureDirectoryExists($directory);

        $target = $directory.'/'.$id;
        File::move($file['path'], $target);

        $expires = now()->addMinutes(max(1, (int) config('app-api.export.link_minutes', 10)));

        Cache::put($this->key($id), [
            'user' => (string) $user->id(),
            'path' => $target,
            'filename' => $file['filename'],
            'mime' => $file['mime'],
        ], $expires);

        return [
            'download_url' => URL::temporarySignedRoute('app-api.account.export.download', $expires, ['export' => $id]),
            'filename' => $file['filename'],
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    public function send(User $user, string $id): BinaryFileResponse
    {
        $entry = Cache::get($this->key($id));

        if (! is_array($entry) || $entry['user'] !== (string) $user->id() || ! is_file($entry['path'])) {
            throw ApiException::notFound();
        }

        Cache::forget($this->key($id));

        return response()
            ->download($entry['path'], $entry['filename'], ['Content-Type' => $entry['mime']])
            ->deleteFileAfterSend();
    }

    /** Remove parked exports whose link has run out. */
    public function prune(): int
    {
        $directory = storage_path(self::DIRECTORY);
        $removed = 0;

        if (! is_dir($directory)) {
            return 0;
        }

        $cutoff = now()->subMinutes(max(1, (int) config('app-api.export.link_minutes', 10)))->getTimestamp();

        foreach (File::files($directory) as $file) {
            if ($file->getMTime() < $cutoff) {
                File::delete($file->getPathname());
                $removed++;
            }
        }

        return $removed;
    }

    protected function key(string $id): string
    {
        return 'app-api:export:'.$id;
    }
}
