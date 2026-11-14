<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\ActivityReportService;
use App\Domain\Reports\PdfUpload;
use App\Exceptions\ApiError;
use App\Models\ActivityReport;
use App\Models\User;
use App\Settings\SettingsService;
use App\Support\SignupText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The author's side of activity reports: send one at any time, list one's own, delete a recent one. */
class ActivityReportController
{
    private const MESSAGES = [
        'title.required' => 'Give the report a title.',
        'title.string' => 'Give the report a title.',
        'title.max' => 'The title must be 150 characters or fewer.',
        'title.not_regex' => 'The title cannot contain a link or an e-mail address.',
        'note.max' => 'The note must be 1000 characters or fewer.',
    ];

    public function __construct(
        private readonly ActivityReportService $service,
        private readonly PdfUpload $pdf,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $user = $this->user($request);
        $page = ActivityReport::query()->where('user_id', $user->id)
            ->orderByDesc('submitted_at')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 20));
        $perDay = $this->settings->int('activity_reports_per_day');
        $sentToday = $this->service->sentToday($user);

        return response()->json([
            'data' => collect($page->items())->map(fn (ActivityReport $r) => $this->service->present($r, true)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'limits' => [
                'per_day' => $perDay,
                'sent_today' => $sentToday,
                'remaining_today' => max(0, $perDay - $sentToday),
                'delete_minutes' => $this->settings->int('activity_report_delete_minutes'),
                'max_size_mb' => $this->pdf->maxMb(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150', 'not_regex:'.SignupText::LINK_PATTERN],
            'note' => ['nullable', 'string', 'max:1000'],
            'file' => $this->pdf->rules(),
        ], self::MESSAGES);
        $title = trim((string) preg_replace('/\s+/u', ' ', $data['title']));
        if ($title === '') {
            throw new ApiError(422, 'Give the report a title.', 'title_required');
        }
        $note = isset($data['note']) && trim($data['note']) !== '' ? trim($data['note']) : null;
        $report = $this->service->store($this->user($request), $title, $note, $this->pdf->file($request));

        return response()->json(['data' => $this->service->present($report, true)], 201);
    }

    public function destroy(Request $request, string $report): JsonResponse
    {
        $user = $this->user($request);
        // Someone else's report answers like an unknown id.
        $report = ActivityReport::query()->where('user_id', $user->id)->find((int) $report)
            ?? throw new ApiError(404, 'Not found.', 'not_found');
        $this->service->delete($user, $report);

        return response()->json(null, 204);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
