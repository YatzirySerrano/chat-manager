<?php

namespace App\Http\Controllers\Exports;

use App\Enums\DataTransferType;
use App\Http\Controllers\Controller;
use App\Http\Resources\DataTransferResource;
use App\Models\DataTransfer;
use App\Models\User;
use App\Reports\ReportRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataTransferController extends Controller
{
    /**
     * The actor's own exports, newest first.
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
     * Only the owner can download, and only until the file expires.
     */
    public function download(DataTransfer $transfer): StreamedResponse
    {
        Gate::authorize('download', $transfer);

        return Storage::disk((string) $transfer->disk)->download(
            (string) $transfer->path,
            $transfer->file_name ?? basename((string) $transfer->path),
            ['Content-Type' => $transfer->format->mimeType()],
        );
    }
}
