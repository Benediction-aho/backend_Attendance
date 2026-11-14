<?php

namespace App\Domain\Reports;

use App\Exceptions\ApiError;
use App\Services\FileUrls;
use App\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * One PDF sent by a user (weekly report, activity report): the same checks and the same private storage
 * for every kind of report. Real PDF (magic bytes, the declared type is not trusted), size limit from
 * `report_pdf_max_mb`, no password, no active content (P5-03 … P5-13).
 */
class PdfUpload
{
    public function __construct(private readonly SettingsService $settings) {}

    public function maxMb(): int
    {
        return $this->settings->int('report_pdf_max_mb');
    }

    /**
     * Validation rules of the `file` field.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return ['required', 'file', 'max:'.($this->maxMb() * 1024)];
    }

    /** The uploaded PDF once the content checks pass (call after validating with rules()). */
    public function file(Request $request, string $field = 'file'): UploadedFile
    {
        $file = $request->file($field);
        if (! $file instanceof UploadedFile || ! $file->isValid() || ! WeeklyReportService::isPdf($file)) {
            throw new ApiError(422, 'Please upload a PDF file.', 'invalid_pdf');
        }
        if (WeeklyReportService::isEncrypted($file)) {
            throw new ApiError(422, 'Password-protected PDFs are not accepted. Please export the report without a password.', 'pdf_encrypted');
        }
        if (WeeklyReportService::hasActiveContent($file)) {
            throw new ApiError(422, 'This PDF contains scripts, actions or embedded files. Please export it again as a plain PDF.', 'pdf_active_content');
        }

        return $file;
    }

    /** Stores the file on the private disk under a server-generated path. */
    public function store(UploadedFile $file, string $path): void
    {
        Storage::disk(FileUrls::disk())->putFileAs(dirname($path), $file, basename($path), ['ContentType' => 'application/pdf']);
    }

    public function delete(string $path): void
    {
        Storage::disk(FileUrls::disk())->delete($path);
    }

    /** The name the user gave, made harmless (shown as text only; never used as a path). */
    public static function displayName(UploadedFile $file): string
    {
        $name = trim((string) preg_replace('/[^\w .()-]+/u', '_', $file->getClientOriginalName()));

        return mb_substr($name !== '' ? $name : 'report.pdf', 0, 200);
    }
}
