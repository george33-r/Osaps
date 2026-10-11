<?php

namespace App\Ai\Features;

use App\Ai\AiGateway;
use App\Domain\Ai\Grounding;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Inspiration photo → editable outfit brief.
 * The image is read in memory, sent to the vision model, and discarded: it is never written to disk
 * or the database. The customer edits and approves the brief before anything is posted.
 */
class InspirationBrief
{
    public const CAVEAT = 'This reads what is visible in the photo. It cannot tell the exact fabric composition, hidden construction, measurements or who designed the original, and your tailor will interpret it rather than copy it exactly.';

    public function __construct(private readonly AiGateway $ai) {}

    /** @return array<string,mixed> */
    public function analyse(UploadedFile $image, ?User $user, ?string $ip): array
    {
        $garments = array_keys(config('marketplace.garment_categories'));
        $colors = config('marketplace.colors');

        $raw = $this->ai->json(
            'inspiration',
            'You are a couture pattern-cutter at an African fashion house. Describe the outfit in this photo so a tailor could quote for something in its spirit. Describe only what is visible. Mark the fabric as an appearance ("looks like a stiff woven fabric with a sheen"), never as a certain composition. If the photo shows no garment, set garment_category to null and say so in summary.',
            [
                ['type' => 'image', 'media_type' => $image->getMimeType(), 'data' => base64_encode((string) file_get_contents($image->getRealPath()))],
                ['type' => 'text', 'text' => 'Describe the outfit in this image as an editable tailoring brief.'],
            ],
            $this->schema($garments, $colors),
            $user, $ip, true, 700,
        );

        $s = fn ($v) => $v === null ? null : Grounding::scrubClaims((string) $v);

        return [
            'garment_category' => $raw['garment_category'] ?? null,
            'title' => $s($raw['title']),
            'summary' => $s($raw['summary']),
            'silhouette' => $s($raw['silhouette'] ?? null),
            'neckline' => $s($raw['neckline'] ?? null),
            'sleeves' => $s($raw['sleeves'] ?? null),
            'fabric_appearance' => $s($raw['fabric_appearance'] ?? null),
            'embellishments' => array_map($s, $raw['embellishments'] ?? []),
            'colors' => array_values(array_intersect($raw['colors'] ?? [], $colors)),
            'aesthetic' => $s($raw['aesthetic'] ?? null),
            'confidence' => $raw['confidence'],
            'caveat' => self::CAVEAT,
        ];
    }

    /** @param list<string> $garments @param list<string> $colors */
    private function schema(array $garments, array $colors): array
    {
        $text = fn (int $n, bool $nullable = true) => ['type' => 'string', 'maxLength' => $n, 'nullable' => $nullable];

        return ['type' => 'object', 'additional' => false, 'required' => ['garment_category', 'title', 'summary', 'confidence'], 'properties' => [
            'garment_category' => ['type' => 'string', 'nullable' => true, 'enum' => $garments],
            'title' => $text(90, false),
            'summary' => $text(500, false),
            'silhouette' => $text(200), 'neckline' => $text(120), 'sleeves' => $text(120),
            'fabric_appearance' => $text(200),
            'embellishments' => ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'string', 'maxLength' => 80]],
            'colors' => ['type' => 'array', 'maxItems' => 4, 'items' => ['type' => 'string', 'enum' => $colors]],
            'aesthetic' => $text(160),
            'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
        ]];
    }
}
