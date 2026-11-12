<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The storage stopped answering in the middle of storage:reorganize-media:
 * the run ends without touching anything else and can be repeated later.
 */
final class MediaReorganizationStopped extends RuntimeException {}
