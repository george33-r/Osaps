<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base resource with a per-item "extra" bag for computed context (match score, comparison,
 * cost breakdown). Unlike JsonResource::$additional it is never merged into the top-level envelope.
 */
abstract class ApiResource extends JsonResource
{
    /** @var array<string,mixed> */
    public array $extra = [];

    /** @param array<string,mixed> $extra */
    public function withExtra(array $extra): static
    {
        $this->extra = $extra + $this->extra;

        return $this;
    }

    protected function extra(string $key): mixed
    {
        return $this->extra[$key] ?? null;
    }

    protected function hasExtra(string $key): bool
    {
        return array_key_exists($key, $this->extra);
    }
}
