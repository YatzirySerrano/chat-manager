<?php

namespace App\Http\Middleware\Api;

use App\Exceptions\ConversationConflict;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Response contract of /api/v1 (integration and widget surfaces):
 *
 * - every request is treated as a JSON client and every response carries
 *   Cache-Control: no-store (X-Request-Id comes from AssignRequestId);
 * - every error is {message, code} (plus "errors" on 422), whatever raised
 *   it, built by errorResponse() from bootstrap/app.php. A 404 is the same
 *   for a missing record and one outside the principal's reach, a 401 never
 *   says why, and a 500 never carries details.
 */
class ApiResponseHeaders
{
    /**
     * Codes a ConversationConflict may carry as its message on /api/v1, with
     * the Spanish explanation answered for each.
     *
     * @var array<string, string>
     */
    public const CONFLICT_CODES = [
        'conflict' => 'La operación entra en conflicto con el estado actual del recurso.',
        'idempotency_key_reused' => 'La clave de idempotencia ya se usó con otra solicitud.',
        'widget_unavailable' => 'El chat no está disponible en este momento.',
        'conversation_closed' => 'La conversación está cerrada.',
        'connection_unavailable' => 'La conexión de la conversación no está disponible.',
        'customer_window_closed' => 'La ventana de atención del cliente está cerrada.',
    ];

    /**
     * @var array<int, array{string, string}>
     */
    private const STATUS_CODES = [
        400 => ['bad_request', 'La solicitud no es válida.'],
        401 => ['unauthenticated', 'No autenticado.'],
        403 => ['forbidden', 'No tienes permiso para realizar esta acción.'],
        404 => ['not_found', 'No encontrado.'],
        405 => ['method_not_allowed', 'Método no permitido.'],
        409 => ['conflict', self::CONFLICT_CODES['conflict']],
        413 => ['payload_too_large', 'La solicitud es demasiado grande.'],
        415 => ['unsupported_media_type', 'Tipo de contenido no admitido.'],
        422 => ['validation_failed', 'Los datos enviados no son válidos.'],
        429 => ['rate_limited', 'Demasiadas solicitudes. Inténtalo de nuevo más tarde.'],
        500 => ['server_error', 'Ocurrió un error interno.'],
        503 => ['service_unavailable', 'El servicio no está disponible en este momento.'],
    ];

    /** Headers of the rendered response worth keeping on the JSON error. */
    private const KEPT_HEADERS = ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'Allow'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::applies($request)) {
            return $next($request);
        }

        if (! $request->wantsJson()) {
            $request->headers->set('Accept', 'application/json');
        }

        /** @var Response $response */
        $response = $next($request);

        return self::withoutCaching($response);
    }

    public static function applies(Request $request): bool
    {
        return $request->is('api/v1', 'api/v1/*');
    }

    /**
     * Rebuilds a response rendered for an exception as the /api/v1 error
     * body. A response the application built on purpose (HttpResponseException)
     * is kept as is.
     */
    public static function errorResponse(Response $rendered, Throwable $exception): Response
    {
        if ($exception instanceof HttpResponseException) {
            return self::withoutCaching($rendered);
        }

        [$status, $code, $message, $errors] = self::describe($rendered, $exception);

        $response = new JsonResponse(
            ['message' => $message, 'code' => $code, ...($errors === null ? [] : ['errors' => $errors])],
            $status,
        );

        foreach (self::KEPT_HEADERS as $header) {
            if ($rendered->headers->has($header)) {
                $response->headers->set($header, (string) $rendered->headers->get($header));
            }
        }

        if ($status === 401) {
            $response->headers->set('WWW-Authenticate', 'Bearer');
        }

        if (self::isConcurrencyError($exception)) {
            $response->headers->set('Retry-After', '1');
        }

        return self::withoutCaching($response);
    }

    /**
     * @return array{int, string, string, array<string, list<string>>|null}
     */
    private static function describe(Response $rendered, Throwable $exception): array
    {
        if ($exception instanceof ValidationException) {
            /** @var array<string, list<string>> $errors */
            $errors = $exception->errors();

            return [422, 'validation_failed', self::STATUS_CODES[422][1], $errors];
        }

        if ($exception instanceof ConversationConflict) {
            $reason = $exception->getMessage();

            return array_key_exists($reason, self::CONFLICT_CODES)
                ? [409, $reason, self::CONFLICT_CODES[$reason], null]
                : [409, 'conflict', $reason, null];
        }

        // A deadlock or lock wait timeout is transient and every /api/v1
        // write is safe to repeat (Idempotency-Key, client_message_id): it is
        // a retryable 503, never a permanent conflict.
        if (self::isConcurrencyError($exception)) {
            return [503, 'temporarily_unavailable', 'Otra operación usaba el recurso al mismo tiempo. Reintenta la solicitud.', null];
        }

        $status = match (true) {
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => $exception->status() ?? 403,
            $exception instanceof ModelNotFoundException => 404,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => $rendered->getStatusCode() >= 400 ? $rendered->getStatusCode() : 500,
        };

        // Server errors never tell what failed; unknown client statuses keep
        // their status with a generic body. A 404 (including authorization
        // denied "as not found") is always the same body.
        if ($status >= 500 && $status !== 503) {
            $status = 500;
        }

        [$code, $message] = self::STATUS_CODES[$status] ?? ['error', 'No fue posible completar la solicitud.'];

        return [$status, $code, $message, null];
    }

    private static function isConcurrencyError(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            && app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($exception);
    }

    private static function withoutCaching(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
