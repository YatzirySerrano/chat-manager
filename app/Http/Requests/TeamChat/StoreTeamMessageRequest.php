<?php

namespace App\Http\Requests\TeamChat;

use App\Enums\TeamChat\TeamMessageKind;
use App\Exceptions\AttachmentRejected;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Services\TeamChat\TeamAttachmentInspector;
use App\Support\Attachments\ChannelAttachmentRules;
use App\Support\Attachments\ServerUploadLimits;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

/**
 * A new internal message: multiline text and/or files. Files are checked by
 * their real content (finfo) and extension against the allowed list, with a
 * per-file size limit; a reply must point to a message of the same thread.
 * A file PHP refused (ServerUploadLimits) is answered on its own field with
 * its cause and code, never with "write a message".
 */
class StoreTeamMessageRequest extends FormRequest
{
    /**
     * Non-participants get a 404, exactly as for a thread that does not exist.
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('post', $this->route('thread'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $body = trim(str_replace(["\r\n", "\r"], "\n", $this->input('body')));
            $this->merge(['body' => $body === '' ? null : $body]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:'.(int) config('team-chat.message_max_length'), Rule::requiredIf(fn (): bool => $this->attachments() === [])],
            'attachments' => ['nullable', 'array', 'max:'.(int) config('team-chat.attachments.max_files')],
            'attachments.*' => ['required', 'file', 'max:'.(int) config('team-chat.attachments.max_kilobytes')],
            'reply_to_message_id' => ['nullable', 'integer'],
            'mentions' => ['nullable', 'array', 'max:'.(int) config('team-chat.max_mentions')],
            'mentions.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $inspector = app(TeamAttachmentInspector::class);

                foreach ($this->sentFiles() as $index => $file) {
                    if (! $validator->errors()->has("attachments.{$index}") && $inspector->acceptedMimeType($file) === null) {
                        $validator->errors()->add("attachments.{$index}", "El archivo «{$inspector->safeName($file)}» no es de un tipo permitido.");
                    }
                }

                /** @var TeamThread $thread */
                $thread = $this->route('thread');

                if (filled($this->input('mentions')) && ! $thread->isGroup()) {
                    $validator->errors()->add('mentions', 'Solo puedes mencionar a personas en los grupos.');
                }

                $replyTo = $this->input('reply_to_message_id');

                if ($replyTo === null || $validator->errors()->has('reply_to_message_id')) {
                    return;
                }

                $isValidReply = TeamMessage::query()
                    ->whereKey((int) $replyTo)
                    ->where('team_thread_id', $thread->id)
                    ->where('kind', TeamMessageKind::Message)
                    ->exists();

                if (! $isValidReply) {
                    $validator->errors()->add('reply_to_message_id', 'El mensaje al que respondes ya no está disponible.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $failures = array_map(fn (array $failure): string => $failure['message'], $this->uploadFailures());

        return [
            ...array_combine(array_map(fn (int|string $index): string => "attachments.{$index}.uploaded", array_keys($failures)), $failures),
            'body.required' => 'Escribe un mensaje o adjunta un archivo.',
            'body.max' => 'El mensaje no debe tener más de :max caracteres.',
            'attachments.max' => 'Puedes adjuntar como máximo :max archivos por mensaje.',
            'attachments.*.max' => 'Cada archivo debe pesar como máximo '.$this->maxSize().'.',
            'attachments.*.file' => 'No se pudo recibir el archivo. Inténtalo de nuevo.',
            'attachments.*.uploaded' => 'No se pudo subir el archivo; puede exceder el tamaño permitido.',
            'mentions.max' => 'Puedes mencionar como máximo a :max personas por mensaje.',
        ];
    }

    /**
     * A file PHP refused is answered with its own cause and code.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        foreach ($this->uploadFailures() as $index => $failure) {
            if ($validator->errors()->has("attachments.{$index}")) {
                throw AttachmentRejected::fromValidator($validator, $failure['code'])
                    ->errorBag($this->errorBag)
                    ->redirectTo($this->getRedirectUrl());
            }
        }

        parent::failedValidation($validator);
    }

    /**
     * Why PHP refused each sent file, by its position.
     *
     * @return array<int|string, array{code: string, message: string}>
     */
    private function uploadFailures(): array
    {
        $limits = app(ServerUploadLimits::class);

        return array_filter(array_map(fn (UploadedFile $file): ?array => $limits->failure($file), $this->sentFiles()));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['body' => 'mensaje', 'attachments' => 'archivos'];
    }

    public function body(): ?string
    {
        $body = $this->validated('body');

        return is_string($body) ? $body : null;
    }

    public function replyToMessageId(): ?int
    {
        $replyTo = $this->validated('reply_to_message_id');

        return $replyTo === null ? null : (int) $replyTo;
    }

    /**
     * Ids chosen in the composer's @ suggestions; PostTeamMessage keeps only
     * the current participants of the group.
     *
     * @return list<int>
     */
    public function mentions(): array
    {
        $mentions = $this->validated('mentions');

        return is_array($mentions) ? array_values(array_map('intval', $mentions)) : [];
    }

    /**
     * The files as PHP delivered them, also those whose upload failed
     * (an empty file input, UPLOAD_ERR_NO_FILE, is not a file).
     *
     * @return list<UploadedFile>
     */
    public function attachments(): array
    {
        return array_values($this->sentFiles());
    }

    /**
     * The sent files keyed by their position in the request.
     *
     * @return array<int|string, UploadedFile>
     */
    private function sentFiles(): array
    {
        // Read from the raw file bag, where a nested field (attachments[0][x])
        // shows up as an array instead of a file.
        $files = $this->files->get('attachments');
        $sent = [];

        foreach (is_array($files) ? $files : [] as $index => $file) {
            if ($file instanceof SymfonyUploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                $sent[$index] = UploadedFile::createFromBase($file);
            }
        }

        return $sent;
    }

    /**
     * "10.5 MB": in the same decimal units as the composer shows.
     */
    private function maxSize(): string
    {
        return ChannelAttachmentRules::humanSize(((int) config('team-chat.attachments.max_kilobytes')) * 1024);
    }
}
