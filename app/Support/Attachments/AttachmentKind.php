<?php

namespace App\Support\Attachments;

use App\Enums\AttachmentDeliveryMethod;
use App\Enums\MessageType;

/**
 * One kind of file a channel accepts from the composer (images, audio,
 * documents, archives...): the real content types it covers, the file
 * name extensions each of them may carry, its size limit, whether text may
 * travel with it and how it reaches the customer (natively or as a secure
 * download link).
 */
final readonly class AttachmentKind
{
    /**
     * @param  array<string, list<string>>  $extensions  Real content type => extensions (lowercase, without dot) the file name may carry.
     */
    public function __construct(
        public string $key,
        public MessageType $type,
        public string $label,
        public array $extensions,
        public int $maxBytes,
        public bool $allowsCaption,
        public AttachmentDeliveryMethod $delivery = AttachmentDeliveryMethod::Native,
    ) {}

    /**
     * @return list<string>
     */
    public function mimes(): array
    {
        return array_keys($this->extensions);
    }

    public function covers(string $mime): bool
    {
        return isset($this->extensions[$mime]);
    }

    /**
     * Whether the file name's extension matches its real content (an empty
     * extension is accepted: the canonical one is added to the stored name).
     */
    public function acceptsExtension(string $mime, string $extension): bool
    {
        $extension = strtolower($extension);

        return $extension === '' || in_array($extension, $this->extensions[$mime] ?? [], true);
    }

    /**
     * The usual extension of a content type of this kind.
     */
    public function canonicalExtension(string $mime): ?string
    {
        return $this->extensions[$mime][0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function allExtensions(): array
    {
        return array_values(array_unique(array_merge(...array_values($this->extensions))));
    }

    /**
     * What the composer receives to validate before uploading.
     *
     * @return array{key: string, type: string, label: string, mimes: list<string>, extensions: list<string>, max_bytes: int, caption: bool, delivery: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'label' => $this->label,
            'mimes' => $this->mimes(),
            'extensions' => $this->allExtensions(),
            'max_bytes' => $this->maxBytes,
            'caption' => $this->allowsCaption,
            'delivery' => $this->delivery->value,
        ];
    }
}
