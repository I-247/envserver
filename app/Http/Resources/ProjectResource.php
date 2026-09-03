<?php

namespace App\Http\Resources;

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'team' => $this->team->slug,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'environments' => $this->environments->map(fn (Environment $environment) => [
                'slug' => $environment->slug,
                'name' => $environment->name,
                'auto_publish' => $environment->auto_publish,
            ]),
        ];
    }
}
