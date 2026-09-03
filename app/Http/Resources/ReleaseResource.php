<?php

namespace App\Http\Resources;

use App\Models\Release;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Release
 */
class ReleaseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $environment = $this->environment;

        return [
            'version' => $this->version,
            'message' => $this->message,
            'project' => $environment->project->slug,
            'environment' => $environment->slug,
            'published_at' => $this->created_at?->toISOString(),
            'variables' => $this->toValueMap(),
        ];
    }
}
