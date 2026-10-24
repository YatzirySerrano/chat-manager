<?php

namespace App\Http\Controllers\WebChat;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectionResource;
use App\Models\WebChatWidget;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Example host page with the real embed snippet and buttons for the
 * loader's API (GET /widget/v1/demo/{publicKey}). Its route only exists in
 * the local environment with webchat.demo_enabled, so it is never
 * published in production or testing. The page is served by the
 * application, so its origin must be among the widget's allowed origins
 * for the frame to open a session.
 */
class WidgetDemoController extends Controller
{
    public function __invoke(string $publicKey): View
    {
        $widget = WebChatWidget::query()->with('connection')->where('public_key', $publicKey)->first();

        if ($widget === null || $widget->connection === null || $widget->connection->isDeleted()) {
            throw new NotFoundHttpException;
        }

        return view('widget.demo', [
            'title' => $widget->title,
            'snippet' => ConnectionResource::snippet($widget->public_key),
            'allowedOrigins' => $widget->allowed_origins,
        ]);
    }
}
