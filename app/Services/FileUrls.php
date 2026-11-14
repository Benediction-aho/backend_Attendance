<?php

namespace App\Services;

use App\Http\Controllers\FileController;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Private files are never public: the API returns short-lived URLs only.
 * S3-compatible disks (Supabase Storage) use pre-signed URLs; the local disk uses a signed API route.
 */
class FileUrls
{
    public function __construct(private readonly SettingsService $settings) {}

    public static function disk(): string
    {
        return (string) config('filesystems.private_disk', 'local');
    }

    public function temporaryUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        $expires = Carbon::now()->addMinutes($this->settings->int('file_url_ttl_minutes'));
        $disk = self::disk();

        if (config("filesystems.disks.$disk.driver") === 's3') {
            // P5-03: the storage domain also serves the file as a download with a fixed safe name.
            return Storage::disk($disk)->temporaryUrl($path, $expires, [
                'ResponseContentDisposition' => 'attachment; filename="'.FileController::safeName($path).'"',
            ]);
        }

        return URL::temporarySignedRoute('files.show', $expires, ['path' => $path]);
    }
}
