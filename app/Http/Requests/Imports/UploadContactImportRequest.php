<?php

namespace App\Http\Requests\Imports;

use App\Models\DataTransfer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * First barrier of the contacts importer: size, extension and detected MIME
 * type. The content itself is inspected afterwards (ImportFileReader).
 */
class UploadContactImportRequest extends FormRequest
{
    /**
     * MIME types finfo reports for genuine .xlsx and .csv files.
     *
     * @var list<string>
     */
    private const MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/octet-stream',
        'text/plain',
        'text/csv',
        'application/csv',
        'text/x-csv',
        'application/vnd.ms-excel',
    ];

    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('createImport', DataTransfer::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.(int) config('imports.max_kilobytes', 5120),
                'extensions:xlsx,csv',
                'mimetypes:'.implode(',', self::MIME_TYPES),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Selecciona el archivo que quieres importar.',
            'file.max' => 'El archivo no puede pesar más de '.((int) config('imports.max_kilobytes', 5120) / 1024).' MB.',
            'file.extensions' => 'Solo se aceptan archivos .xlsx o .csv (los libros con macros no se aceptan).',
            'file.mimetypes' => 'El contenido del archivo no corresponde a un libro de Excel (.xlsx) ni a un .csv.',
        ];
    }
}
