<?php

namespace App\Http\Resources;

use App\Data\VariableChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read VariableChange $resource
 */
class VariableChangeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->resource->key,
            'type' => $this->resource->type->value,
            'before' => $this->resource->before,
            'after' => $this->resource->after,
        ];
    }
}
