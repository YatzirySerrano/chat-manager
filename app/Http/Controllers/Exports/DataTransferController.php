<?php

namespace App\Http\Controllers\Exports;

use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Http\Controllers\Controller;
use App\Http\Resources\DataTransferResource;
use App\Models\DataTransfer;
use App\Models\User;
use App\Reports\ReportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataTransferController extends Controller
{
    /**
     * How many recently prepared files the export menu of a module lists.
     */
    public const RECENT_LIMIT = 5;

    /**
     * The actor's own exports, newest first ("Archivos preparados"). It is
     * no longer in the navigation: it is reached from the export menus and
     * keeps old links working.
     */
    public function index(Request $request, ReportRegistry $registry): Response
    {
        Gate::authorize('viewAny', DataTransfer::class);

        /** @var User $actor */
        $actor = $request->user();

        $transfers = DataTransfer::query()
            ->ownedBy($actor)
            ->where('type', DataTransferType::Export)
            ->latest()
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $transfers->getCollection()->each(fn (DataTransfer $transfer) => $transfer->setAttribute('report_title', $registry->title($transfer->report)));

        return Inertia::render('exports/Index', [
            'transfers' => DataTransferResource::collection($transfers),
            'retentionHours' => (int) config('exports.retention_hours'),
        ]);
    }

    /**
     * The actor's files of one report that are still being prepared or can
     * still be downloaded (the export menu of the module lists them).
     */
    public function recent(Request $request, ReportRegistry $registry): JsonResponse
    {
        Gate::authorize('viewAny', DataTransfer::class);

        $validated = $request->validate([
            'report' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $transfers = DataTransfer::query()
            ->ownedBy($actor)
            ->where('type', DataTransferType::Export)
            ->where('report', $validated['report'])
            ->where(fn ($query) => $query
                ->whereIn('status', [DataTransferStatus::Queued, DataTransferStatus::Processing])
                ->orWhere(fn ($query) => $query
                    ->where('status', DataTransferStatus::Completed)
                    ->whereNotNull('path')
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))))
            ->latest()
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->filter(fn (DataTransfer $transfer): bool => $transfer->status !== DataTransferStatus::Completed || Gate::forUser($actor)->allows('download', $transfer))
            ->each(fn (DataTransfer $transfer) => $transfer->setAttribute('report_title', $registry->title($transfer->report)))
            ->values();

        return response()->json(['data' => DataTransferResource::collection($transfers)->resolve($request)]);
    }

    /**
     * Only the owner can download, and only until the file expires.
     *
     * Opened from inside the application (an Inertia visit, e.g. the
     * «Descargar» action of a notification), a downloadable file is sent
     * through a full browser navigation, since a file cannot be an Inertia
     * page; a file that expired or may no longer be downloaded is explained
     * with a notice on the page the person is on.
     */
    public function download(Request $request, DataTransfer $transfer): StreamedResponse|SymfonyResponse|RedirectResponse
    {
        if ($request->header('X-Inertia')) {
            $decision = Gate::inspect('download', $transfer);

            if ($decision->allowed()) {
                return Inertia::location(route('exports.transfers.download', $transfer));
            }

            abort_if($decision->status() === 404, 404);

            Inertia::flash('toast', ['type' => 'error', 'message' => (string) $decision->message()]);

            return back(fallback: route('dashboard'));
        }

        Gate::authorize('download', $transfer);

        return Storage::disk((string) $transfer->disk)->download(
            (string) $transfer->path,
            $transfer->file_name ?? basename((string) $transfer->path),
            ['Content-Type' => $transfer->format->mimeType()],
        );
    }
}
