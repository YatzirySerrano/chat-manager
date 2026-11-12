<?php

namespace App\Http\Requests\Admin;

use App\Enums\HistoryImportFileKind;
use App\Models\DataTransfer;
use App\Policies\HistoryImportPolicy;
use App\Support\Attachments\ServerUploadLimits;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * First barrier of an uploaded Whaticket file: kind, size and extension.
 * The content (type detected from the bytes, ZIP entries, encoding) is
 * checked afterwards by HistoryFileIngestor.
 */
class HistoryImportFileRequest extends FormRequest
{
    public function authorize(HistoryImportPolicy $policy): Response
    {
        /** @var DataTransfer $batch */
        $batch = $this->route('transfer');

        return $policy->classify($this->user(), $batch);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(HistoryImportFileKind::values())],
            'file' => ['required', 'file', 'max:'.self::maxKilobytes(), 'extensions:csv,xlsx,zip,txt'],
        ];
    }

    /**
     * The largest file the browser may upload: the configured limit, or
     * less when the PHP serving the request accepts less
     * (upload_max_filesize / post_max_size), so the limit shown and
     * validated is the real one. Larger files are added from the server
     * with "php artisan history-import:add-file".
     */
    public static function maxKilobytes(): int
    {
        $configured = max(1, (int) config('history-import.max_kilobytes', 102400));
        $server = app(ServerUploadLimits::class)->maxFileBytes();

        return $server === null ? $configured : max(1, min($configured, intdiv($server, 1024)));
    }

    public function kind(): HistoryImportFileKind
    {
        return HistoryImportFileKind::from($this->string('kind')->value());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kind.in' => 'Elige qué archivo de Whaticket estás subiendo.',
            'file.required' => 'Selecciona el archivo que quieres subir.',
            'file.max' => 'El archivo no puede pesar más de '.round(self::maxKilobytes() / 1024).' MB. Exporta el periodo en partes o pide a quien administra el servidor que lo agregue con «php artisan history-import:add-file».',
            'file.uploaded' => 'El servidor no aceptó el archivo por su tamaño (máximo '.round(self::maxKilobytes() / 1024).' MB). Exporta el periodo en partes o pide a quien administra el servidor que lo agregue con «php artisan history-import:add-file».',
            'file.extensions' => 'Solo se aceptan archivos .csv, .xlsx, .zip o .txt.',
        ];
    }
}
