<?php

namespace App\Console\Commands;

use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Enums\HistoryImportFileKind;
use App\Enums\Permission;
use App\Models\DataTransfer;
use App\Models\User;
use App\Services\Migration\History\HistoryFileIngestor;
use App\Services\Migration\History\HistoryImportConflict;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Adds a Whaticket file that is already on the server to a draft history
 * import batch, exactly as the upload form does (same content checks,
 * fingerprint, private copy, audit entry and queued reading), for files the
 * web server or PHP would refuse because of their size (a messages CSV of
 * 450,000 rows is 60 to 100 MB; a media ZIP may be much larger).
 *
 * The file is added on behalf of the account that created the batch, which
 * must still hold history-import.classify. The original file is not moved
 * nor deleted: remove it once the batch shows it as read.
 */
class AddHistoryImportFile extends Command
{
    protected $signature = 'history-import:add-file
        {batch : Número del lote en borrador (el de «Importar de Whaticket»)}
        {path : Ruta del archivo en el servidor}
        {--kind=messages : Tipo de archivo: chats, messages, contacts o media}';

    protected $description = 'Add a Whaticket file already on the server to a draft history import batch (for files too large to upload)';

    public function handle(HistoryFileIngestor $ingestor): int
    {
        $option = $this->option('kind');
        $kind = is_string($option) ? HistoryImportFileKind::tryFrom($option) : null;

        if ($kind === null) {
            $this->error('Tipo de archivo desconocido. Usa --kind=chats, messages, contacts o media.');

            return self::FAILURE;
        }

        $batch = DataTransfer::query()
            ->where('type', DataTransferType::HistoryImport)
            ->find((int) $this->argument('batch'));

        if ($batch === null) {
            $this->error('No existe una importación de historial con ese número.');

            return self::FAILURE;
        }

        if ($batch->status !== DataTransferStatus::Draft) {
            $this->error('Esa importación ya se confirmó o se canceló: solo se agregan archivos a un borrador.');

            return self::FAILURE;
        }

        $actor = User::query()->find($batch->user_id);

        if ($actor === null || ! $actor->holdsPermission(Permission::ViewHistoryImports) || ! $actor->holdsPermission(Permission::ClassifyHistoryImports)) {
            $this->error('La cuenta que creó la importación ya no puede clasificarla. Crea un lote nuevo con una cuenta autorizada.');

            return self::FAILURE;
        }

        $argument = $this->argument('path');
        $path = is_string($argument) ? realpath($argument) : false;

        if ($path === false || ! is_file($path) || ! is_readable($path)) {
            $this->error('No se encontró el archivo o no se puede leer.');

            return self::FAILURE;
        }

        $maxKilobytes = max(1, (int) config('history-import.max_cli_kilobytes', 2097152));

        if ((int) ceil(((int) filesize($path)) / 1024) > $maxKilobytes) {
            $this->error('El archivo pesa más de '.round($maxKilobytes / 1024).' MB (HISTORY_IMPORT_MAX_CLI_KILOBYTES). Exporta el periodo en partes.');

            return self::FAILURE;
        }

        try {
            $file = $ingestor->add($batch, new UploadedFile($path, basename($path), null, null, true), $kind, $actor);
        } catch (ValidationException $exception) {
            $this->error($exception->validator->errors()->first());

            return self::FAILURE;
        } catch (HistoryImportConflict $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Archivo agregado al lote #{$batch->id} como «{$kind->label()}» (archivo #{$file->id}).");
        $this->line('La lectura quedó en la cola «exports»; el avance se ve en la pantalla del lote. El archivo original no se movió: bórralo cuando el lote lo muestre como leído.');

        return self::SUCCESS;
    }
}
