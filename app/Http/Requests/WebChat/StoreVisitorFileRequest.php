<?php

namespace App\Http\Requests\WebChat;

use App\Services\WebChat\VisitorFiles;
use App\Support\Attachments\ServerUploadLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/widget/session/files (multipart). One file the visitor sends
 * from the widget, with an optional caption cleaned like a text message
 * (StoreVisitorMessageRequest). The file's real type is read from its
 * bytes and its size checked against the limit of its kind
 * (VisitorFiles); a file PHP could not receive is explained, never
 * reported as the visitor's fault. "website" is the same honeypot.
 */
class StoreVisitorFileRequest extends FormRequest
{
    /**
     * Kind and real type of the accepted file (set by the validation).
     *
     * @var array{kind: string, mime: string}|null
     */
    private ?array $inspected = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (is_string($body)) {
            $body = str_replace(["\r\n", "\r"], "\n", $body);
            $body = preg_replace('/[^\P{Cc}\n\t]|[\p{Cf}]/u', '', $body) ?? '';

            $this->merge(['body' => trim($body)]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $maxKb = (int) ceil(app(VisitorFiles::class)->hardCapBytes() / 1024);

        return [
            'client_message_id' => ['required', 'string', 'uuid'],
            'file' => ['required', 'file', 'max:'.$maxKb],
            'body' => ['nullable', 'string', 'max:'.(int) config('webchat.visitor_files.caption_max_length', 1000)],
            'website' => ['prohibited'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');

                if ($validator->errors()->has('file') || ! $file instanceof UploadedFile) {
                    return;
                }

                $inspected = app(VisitorFiles::class)->inspect($file);

                if (is_string($inspected)) {
                    $validator->errors()->add('file', $inspected);

                    return;
                }

                $this->inspected = $inspected;
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Elige un archivo para enviar.',
            'file.file' => 'El archivo no se recibió correctamente. Vuelve a enviarlo.',
            'file.uploaded' => $this->uploadFailure(),
            'file.max' => 'El archivo supera el tamaño permitido.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['client_message_id' => 'identificador del mensaje', 'file' => 'archivo', 'body' => 'mensaje', 'website' => 'sitio web'];
    }

    public function uploadedFile(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }

    /**
     * @return array{kind: string, mime: string}
     */
    public function inspected(): array
    {
        if ($this->inspected !== null) {
            return $this->inspected;
        }

        $inspected = app(VisitorFiles::class)->inspect($this->uploadedFile());

        if (is_string($inspected)) {
            throw ValidationException::withMessages(['file' => $inspected]);
        }

        return $inspected;
    }

    public function caption(): ?string
    {
        $body = $this->validated('body');

        return is_string($body) && $body !== '' ? $body : null;
    }

    /**
     * What the visitor reads when PHP refused the upload (explained without
     * blaming them: the server's limit or a dropped connection).
     */
    private function uploadFailure(): string
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            return 'No se pudo recibir el archivo. Inténtalo de nuevo.';
        }

        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE => 'El archivo es más grande de lo que este chat puede recibir'.$this->serverLimit().'.',
            UPLOAD_ERR_PARTIAL => 'La subida del archivo se interrumpió. Revisa tu conexión y vuelve a enviarlo.',
            default => 'No se pudo recibir el archivo. Inténtalo de nuevo en unos minutos.',
        };
    }

    private function serverLimit(): string
    {
        $limit = app(ServerUploadLimits::class)->maxFileBytes();

        return $limit === null ? '' : ' (hasta '.rtrim(rtrim(number_format($limit / 1_000_000, 1), '0'), '.').' MB)';
    }
}
