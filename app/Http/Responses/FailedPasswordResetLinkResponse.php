<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Http\Responses\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Http\Responses\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "forgot password" form answers the same for an unknown e-mail, for an
 * account that already received a link a moment ago and for a link that was
 * actually sent, so it cannot be used to find out which e-mails have an
 * account. The shared message is worded so it is true in every case. Other
 * failures (for example an unavailable mailer) keep Fortify's error.
 */
class FailedPasswordResetLinkResponse extends FailedPasswordResetLinkRequestResponse
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if (in_array($this->status, [Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return (new SuccessfulPasswordResetLinkRequestResponse(Password::RESET_LINK_SENT))->toResponse($request);
        }

        return parent::toResponse($request);
    }
}
