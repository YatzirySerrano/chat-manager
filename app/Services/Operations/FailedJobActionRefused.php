<?php

namespace App\Services\Operations;

use RuntimeException;

/**
 * A retry or discard of a failed job that was refused for a reason the
 * administrator should read (the message is Spanish and safe to show).
 */
class FailedJobActionRefused extends RuntimeException {}
