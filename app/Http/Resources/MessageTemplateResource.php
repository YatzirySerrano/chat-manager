<?php

namespace App\Http\Resources;

use App\Models\MessageTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A WhatsApp template as Meta reports it, with its structure (header, body,
 * footer, buttons), the variables to fill and a preview with Meta's
 * examples. "sendable" is true only when Meta approved it and the inbox can
 * fill every part.
 *
 * @mixin MessageTemplate
 */
class MessageTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $structure = $this->structure();
        $parameters = $structure->parameters();
        $examples = [];

        foreach ($parameters as $parameter) {
            if ($parameter['example'] !== null && $parameter['example'] !== '') {
                $examples[$parameter['key']] = $parameter['example'];
            }
        }

        $category = $this->categoryEnum();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'language' => $this->language,
            'category' => ['value' => $this->category, 'label' => $category?->label() ?? $this->category],
            'status' => ['value' => $this->status->value, 'label' => $this->status->label(), 'tone' => $this->status->tone()],
            'quality_score' => $this->quality_score,
            'rejected_reason' => $this->rejected_reason,
            'connection' => $this->whenLoaded('channelConnection', fn () => [
                'id' => $this->channelConnection->id,
                'name' => $this->channelConnection->name,
                'display_phone_number' => $this->channelConnection->display_phone_number,
            ]),
            'structure' => $structure->describe(),
            'parameters' => $parameters,
            'preview' => $structure->preview($examples),
            'sendable' => $this->isUsable(),
            'unsupported_reason' => $structure->unsupportedReason(),
            'is_deleted' => $this->isDeleted(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'status_updated_at' => $this->status_updated_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
        ];
    }
}
