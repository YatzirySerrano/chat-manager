<?php

namespace App\Http\Controllers\Contacts;

use App\Enums\WhatsAppWebState;
use App\Http\Controllers\Controller;
use App\Jobs\RefreshWhatsAppWebContactProfile;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\User;
use App\Services\WhatsAppWeb\WhatsAppWebContactProfiles;
use App\Services\WhatsAppWeb\WhatsAppWebException;
use App\Support\Attachments\MediaResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The copy of a contact's WhatsApp profile picture (stored by
 * RefreshWhatsAppWebContactProfile on the private media disk), served
 * only to people who can see the contact or one of its conversations,
 * exactly like the files of a conversation (MessageAttachmentController):
 * from the disk it was saved on, never a redirect, with private caching in
 * the viewer's own browser only and an ETag. Anyone else gets a 404, as if
 * the contact did not exist.
 *
 * «Actualizar» reads the profile again through a WhatsApp Web connection
 * of the contact, at most once a minute per contact (and a few times a
 * minute per person).
 */
class ContactAvatarController extends Controller
{
    /** How long the viewer's browser keeps the picture before asking again. */
    public const BROWSER_CACHE_SECONDS = 86400;

    /** Seconds between two manual refreshes of the same contact. */
    public const REFRESH_COOLDOWN_SECONDS = 60;

    /**
     * Through a conversation the viewer can see (inbox rows, open
     * conversation, details panel): agents without access to the directory
     * see the picture of the customers they attend.
     */
    public function conversation(Request $request, Conversation $conversation): Response
    {
        abort_unless(Gate::allows('view', $conversation), 404);

        return $this->serve($request, $conversation->contact()->first());
    }

    /**
     * Through the contact (contact page): whoever can see the contact, or
     * one of its conversations.
     */
    public function contact(Request $request, Contact $contact): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();

        abort_unless(
            Gate::allows('view', $contact) || Conversation::query()->where('contact_id', $contact->id)->visibleTo($viewer)->exists(),
            404,
        );

        return $this->serve($request, $contact);
    }

    /**
     * «Actualizar» from the details panel of a conversation.
     */
    public function refreshFromConversation(Request $request, Conversation $conversation, WhatsAppWebContactProfiles $profiles): RedirectResponse
    {
        abort_unless(Gate::allows('view', $conversation), 404);

        $conversation->loadMissing(['contact', 'channelConnection']);

        return $this->refresh($request, $conversation->contact, $profiles, $conversation);
    }

    /**
     * «Actualizar» from the contact page.
     */
    public function refreshFromContact(Request $request, Contact $contact, WhatsAppWebContactProfiles $profiles): RedirectResponse
    {
        abort_unless(Gate::allows('view', $contact), 404);

        return $this->refresh($request, $contact, $profiles);
    }

    private function refresh(Request $request, ?Contact $contact, WhatsAppWebContactProfiles $profiles, ?Conversation $conversation = null): RedirectResponse
    {
        abort_if($contact === null, 404);

        $identity = $contact->whatsappIdentity()->first();
        $connection = $profiles->connectionFor($contact, $conversation);

        if (! $identity instanceof ContactIdentity || $connection === null) {
            return $this->toast('info', 'WhatsApp no comparte la foto ni la info en conexiones de la API oficial.');
        }

        if ($connection->webSessionState() !== WhatsAppWebState::Connected) {
            return $this->toast('error', 'La sesión de WhatsApp Web de esta conexión no está conectada; no se pudo leer el perfil.');
        }

        $key = 'whatsapp-profile-refresh:'.$identity->id;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return $this->toast('info', 'El perfil se actualizó hace un momento. Inténtalo de nuevo en '.max(1, RateLimiter::availableIn($key)).' s.');
        }

        RateLimiter::hit($key, self::REFRESH_COOLDOWN_SECONDS);

        try {
            // In the request, so the person sees the result at once (a few seconds at most).
            RefreshWhatsAppWebContactProfile::dispatchSync($identity->id, $connection->id);
        } catch (WhatsAppWebException $exception) {
            return $this->toast('error', $exception->kind === WhatsAppWebException::NOT_CONNECTED || $exception->kind === WhatsAppWebException::UNKNOWN_SESSION || $exception->kind === WhatsAppWebException::NOT_LINKED
                ? 'La sesión de WhatsApp Web de esta conexión no está conectada; no se pudo leer el perfil.'
                : 'No se pudo leer el perfil de WhatsApp en este momento. Inténtalo de nuevo en unos minutos.');
        }

        return $this->toast('success', 'Perfil de WhatsApp actualizado.');
    }

    private function toast(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }

    private function serve(Request $request, ?Contact $contact): Response
    {
        $identity = $contact?->whatsappIdentity()->first();

        abort_unless($identity instanceof ContactIdentity && $identity->hasAvatar(), 404);

        // The local copy first; the NAS only while it answers (a list of
        // chats asks for many pictures at once: never one SMB round per stat).
        $location = MediaResponse::open((string) $identity->avatar_disk, (string) $identity->avatar_path);
        abort_if($location === null, 404);
        $disk = $location['filesystem'];
        $path = $location['path'];

        try {
            $exists = $disk->exists($path);
            $modifiedAt = $exists ? $disk->lastModified($path) : 0;
            $mime = $exists ? strtolower((string) $disk->mimeType($path)) : '';
        } catch (Throwable $exception) {
            Log::warning('Media storage unavailable while serving a contact picture.', ['identity_id' => $identity->id, 'disk' => $identity->avatar_disk, 'exception' => $exception::class]);

            abort(503, 'El almacenamiento de archivos no responde en este momento.');
        }

        abort_unless($exists && in_array($mime, RefreshWhatsAppWebContactProfile::IMAGE_TYPES, true), 404);

        $lastModified = Date::createFromTimestamp($modifiedAt);
        $etag = sha1(implode('|', ['avatar', $identity->id, $path, (string) $identity->avatar_hash]));
        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="foto-de-perfil"',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        $notModified = $this->cached(new Response, $etag, $lastModified);

        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        $local = config("filesystems.disks.{$identity->avatar_disk}.driver") === 'local';

        $response = $local
            ? response()->file($disk->path($path), $headers)
            : response()->stream(function () use ($disk, $path): void {
                $stream = $disk->readStream($path);

                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            }, 200, $headers);

        return $this->cached($response, $etag, $lastModified);
    }

    /**
     * Private caching in the viewer's own browser only (never a shared
     * proxy), set after the response is built because a file response
     * marks itself public.
     */
    private function cached(Response $response, string $etag, \DateTimeInterface $lastModified): Response
    {
        $response->headers->set('Cache-Control', 'private, max-age='.self::BROWSER_CACHE_SECONDS);
        $response->setEtag($etag);
        $response->setLastModified($lastModified);

        return $response;
    }
}
