<?php

namespace Database\Factories;

use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Enums\ExportFormat;
use App\Models\DataTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataTransfer>
 */
class DataTransferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => DataTransferType::Export,
            'report' => 'tags',
            'format' => ExportFormat::Xlsx,
            'status' => DataTransferStatus::Queued,
            'user_id' => User::factory(),
            'filters' => [],
            'disk' => 'local',
        ];
    }

    /**
     * A finished export whose file is available until it expires.
     */
    public function completed(?string $path = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DataTransferStatus::Completed,
            'path' => $path ?? 'exports/'.fake()->uuid().'.xlsx',
            'file_name' => 'etiquetas-'.now()->format('Y-m-d-Hi').'.xlsx',
            'rows_total' => 3,
            'rows_processed' => 3,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    }

    /**
     * A completed export whose retention window already ended.
     */
    public function pastExpiration(): static
    {
        return $this->completed()->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DataTransferStatus::Failed,
            'error' => 'No se pudo generar el archivo.',
            'finished_at' => now(),
        ]);
    }
}
