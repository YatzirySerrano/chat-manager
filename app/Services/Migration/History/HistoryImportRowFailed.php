<?php

namespace App\Services\Migration\History;

use RuntimeException;

/**
 * One conversation of a history import batch cannot be created; the
 * message (Spanish, for the admin, never message content) is stored as the
 * row's error and the rest of the batch goes on.
 */
class HistoryImportRowFailed extends RuntimeException {}
