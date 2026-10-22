<?php

namespace App\Http\Resources;

use App\Models\DataTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DataTransfer
 */
class DataTransferResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'report' => $this->report,
            'report_title' => (string) ($this->getAttribute('report_title') ?? $this->report),
            'format' => ['value' => $this->format->value, 'label' => $this->format->label()],
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'filters' => array_values((array) ($this->options['filter_labels'] ?? [])),
            'file_name' => $this->file_name,
            'rows_total' => $this->rows_total,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'download_url' => $this->isDownloadable() ? route('exports.transfers.download', $this->resource) : null,
        ];
    }
}
