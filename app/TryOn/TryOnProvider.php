<?php

namespace App\TryOn;

interface TryOnProvider
{
    public function available(): bool;

    /**
     * Returns a PNG/JPEG of the person wearing the described outfit, as raw bytes.
     *
     * @param  string  $photo  raw bytes of the person's own photo
     * @param  string|null  $fabricPhoto  raw bytes of a real photo of the fabric, if the vendor uploaded one
     */
    public function render(string $photo, string $photoMime, ?string $fabricPhoto, ?string $fabricMime, string $prompt): string;
}
