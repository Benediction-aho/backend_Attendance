<?php

namespace App\Http\Controllers;

use App\Services\FileUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a private file from the local disk through a short-lived signed URL (development/tests;
 * online, S3 pre-signed URLs are used instead). The signature covers the path and the expiry.
 */
class FileController
{
    public function show(Request $request, string $path): Response
    {
        abort_unless($request->hasValidSignature(), 404);
        abort_if(str_contains($path, '..'), 404);

        $disk = Storage::disk(FileUrls::disk());
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
