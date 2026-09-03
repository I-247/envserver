<?php

namespace App\Http\Resources;

use App\Models\Release;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A release without its values, for listing history.
 *
 * @mixin Release
 */
class ReleaseSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->version,
            'message' => $this->message,
            'published_by' => $this->publisher?->name,
            'published_at' => $this->created_at?->toISOString(),
            'variables_count' => $this->items()->count(),
        ];
    }
}
