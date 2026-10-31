<?php

namespace App\Services\Cooperative;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Menyimpan file import sementara (di storage lokal, tidak publik) di antara
 * langkah pratinjau dan simpan.
 */
class ImportFileStore
{
    private const DIR = 'cooperative-imports';

    public function put(UploadedFile $file, string $kind): string
    {
        $this->prune();
        $token = Str::uuid()->toString();
        $extension = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        Storage::disk('local')->putFileAs(self::DIR, $file, "{$kind}-{$token}.{$extension}");

        return $token;
    }

    public function path(string $kind, ?string $token): ?string
    {
        if (! $token || ! Str::isUuid($token)) {
            return null;
        }
        foreach (['xlsx', 'xls', 'csv'] as $extension) {
            $relative = self::DIR."/{$kind}-{$token}.{$extension}";
            if (Storage::disk('local')->exists($relative)) {
                return Storage::disk('local')->path($relative);
            }
        }

        return null;
    }

    public function forget(string $kind, string $token): void
    {
        foreach (['xlsx', 'xls', 'csv'] as $extension) {
            Storage::disk('local')->delete(self::DIR."/{$kind}-{$token}.{$extension}");
        }
    }

    private function prune(): void
    {
        $disk = Storage::disk('local');
        foreach ($disk->files(self::DIR) as $file) {
            if ($disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }
}
