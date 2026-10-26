<?php

namespace App\Services\Migration\History;

use App\Exceptions\StorageUnavailable;
use RuntimeException;

/**
 * The execution of a history import batch must stop without touching the
 * conversation in progress (it stays pending): the media disk is not
 * available. The batch is left failed and resumable with this message.
 */
class HistoryImportPaused extends RuntimeException
{
    public static function storage(StorageUnavailable $previous): self
    {
        return new self('La importación se pausó porque el almacenamiento de archivos no está disponible. Lo importado se conserva; cuando el almacenamiento vuelva a funcionar, usa «Reanudar».', 0, $previous);
    }
}
