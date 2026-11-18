<?php

namespace App\Http\Requests\VideoExports;

use App\Services\Storage\MediaPaths;
use App\Services\VideoExports\VideoExports;
use App\Services\VideoExports\VideoFolders;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The «Guardar archivo» dialog (videos, images, PDFs, documents, audios):
 * the folder chosen in the NAS browser (relative to the root of saved
 * files, "" for the root) and the name of the file, without extension. Requires videos.save-to-nas; access to the
 * chat is checked by the controller with the chat's own policy.
 */
class SaveVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(VideoExports::class)->permits($this->user());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'folder' => ['present', 'nullable', 'string', 'max:700'],
            'file_name' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('file_name') && $this->fileName() === '') {
                    $validator->errors()->add('file_name', 'Escribe el nombre del archivo con letras o números.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'folder' => 'carpeta',
            'file_name' => 'nombre del archivo',
        ];
    }

    /**
     * @return list<string>
     */
    public function folder(): array
    {
        return VideoFolders::segments($this->string('folder')->value());
    }

    /**
     * The name as it is written on the NAS: SMB-safe, without extension
     * (the original's is added); a typed extension of the same file (or of
     * a video, as before) is dropped, so "Recibo.pdf" does not become
     * "Recibo.pdf.pdf".
     */
    public function fileName(?string $extension = null): string
    {
        $name = $this->string('file_name')->trim()->value();
        $name = (string) preg_replace('/\.(mp4|mov|3gp|webm|ogv|avi|m4v|mkv)$/i', '', $name);

        if ($extension !== null && $extension !== '') {
            $name = (string) preg_replace('/\.'.preg_quote($extension, '/').'$/i', '', $name);
        }

        return MediaPaths::clean($name, 120);
    }
}
