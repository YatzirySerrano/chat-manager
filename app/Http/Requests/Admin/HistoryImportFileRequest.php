<?php

namespace App\Http\Requests\Admin;

use App\Enums\HistoryImportFileKind;
use App\Models\DataTransfer;
use App\Policies\HistoryImportPolicy;
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
            'file' => ['required', 'file', 'max:'.(int) config('history-import.max_kilobytes', 512000), 'extensions:csv,xlsx,zip,txt'],
        ];
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
            'file.max' => 'El archivo no puede pesar más de '.round((int) config('history-import.max_kilobytes', 512000) / 1024).' MB. Exporta el periodo en partes.',
            'file.extensions' => 'Solo se aceptan archivos .csv, .xlsx, .zip o .txt.',
        ];
    }
}
