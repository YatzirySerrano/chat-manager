<?php

namespace App\Http\Controllers\Internal;

use App\Concerns\ServesStoredAttachments;
use App\Enums\AttachmentDeliveryMethod;
use App\Enums\AttachmentStatus;
use App\Enums\MessageDirection;
use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use App\Services\Meta\MediaFileName;
use App\Services\WhatsAppWeb\InternalSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * File of a reply sent through WhatsApp Web (QR), fetched by the private
 * Node service from the URL the transport put in the send call (contract
 * §6): GET /internal/whatsapp-web/media/{attachment}?ts=…&nonce=…&sig=….
 *
 * The URL is signed with the shared secret over the path without those
 * three parameters, is valid for 60 seconds and works once. Only an
 * available, native, outbound attachment of a conversation whose connection
 * is a WhatsApp Web one is served, streamed from the private media disk and
 * never cached; anything else is the same 404. It is never a public path of
 * the file.
 */
class WhatsAppWebMediaController extends Controller
{
    use ServesStoredAttachments;

    public function __invoke(Request $request, string $attachment, InternalSignature $signature): Response
    {
        if (! WhatsAppWebEventController::fromService($request)) {
            abort(401);
        }

        $refusal = $signature->refusal(
            self::queryString($request, 'ts'),
            self::queryString($request, 'nonce'),
            self::queryString($request, 'sig'),
            'GET',
            $request->getBaseUrl().$request->getPathInfo(),
            '',
        );

        if ($refusal !== null) {
            Log::warning('WhatsApp Web media request refused.', ['reason' => $refusal]);

            abort(401);
        }

        $file = MessageAttachment::query()
            ->with('message.conversation.channelConnection')
            ->whereKey((int) $attachment)
            ->where('status', AttachmentStatus::Available)
            ->where('delivery_method', AttachmentDeliveryMethod::Native)
            ->first();

        $connection = $file?->message?->conversation?->channelConnection;

        abort_if($file === null || blank($file->path) || $file->message->direction !== MessageDirection::Outbound
            || $connection === null || ! $connection->isWhatsAppWeb() || $connection->isDeleted(), 404);

        $disk = Storage::disk($file->disk);

        abort_unless($this->storedFileExists($disk, $file), 404);

        $name = MediaFileName::withExtension($file->file_name, $file->mime_type);
        $headers = [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        return $this->storedFileResponse($file, fn (): Response => config("filesystems.disks.{$file->disk}.driver") === 'local'
            ? response()->file($disk->path((string) $file->path), $headers)
            : $disk->response((string) $file->path, $name, $headers, 'attachment'));
    }

    private static function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
