<?php

namespace App\Ai\Features;

use App\Ai\AiException;
use App\Ai\AiGateway;
use App\Domain\Ai\Grounding;
use App\Models\Fabric;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * "What could I make with this?" — outfit ideas for one real fabric, from its actual attributes.
 * Ideas never mention price, stock or delivery. Cached per fabric version to control cost.
 */
class FabricIdeas
{
    /** Style-guide ideas by fabric type, used without AI. */
    private const GUIDE = [
        'ankara' => [['gown', 'A fitted Ankara gown with a structured bodice and flared hem.'], ['skirt_blouse', 'A peplum blouse with a pencil skirt in the same print.'], ['kids', 'Matching children\'s pieces from the same bolt.']],
        'adire' => [['kaftan', 'A relaxed adire kaftan with contrast piping.'], ['iro_buba', 'A modern iro and buba with a cropped buba.'], ['skirt_blouse', 'A wrap skirt with a plain blouse to let the dye pattern lead.']],
        'aso_oke' => [['agbada', 'An agbada with aṣọ-òkè panels and matching fila.'], ['iro_buba', 'A ceremonial iro and buba with gele.'], ['bridal', 'A traditional bridal set with a beaded neckline.']],
        'lace' => [['gown', 'A lace evening gown with a lined sheath and scalloped hem.'], ['iro_buba', 'A lace buba over a silk iro.'], ['bridal', 'A lace bridal look with appliqué on a sheer yoke.']],
        'senator' => [['senator', 'A classic senator with subtle chest embroidery.'], ['kaftan', 'A kaftan with a concealed placket.'], ['agbada', 'A light agbada over a senator base.']],
        'suiting' => [['suit', 'A two-piece suit with a soft shoulder.'], ['senator', 'A tailored senator with sharp cuffs.']],
        'george' => [['iro_buba', 'A george wrapper with a lace buba.'], ['gown', 'A draped george gown for evening.']],
        'damask' => [['agbada', 'A damask agbada with tonal embroidery.'], ['kaftan', 'A damask kaftan with a mandarin collar.']],
    ];

    public function __construct(private readonly AiGateway $ai) {}

    /** @return array{source:string, ideas:list<array<string,mixed>>, notice:?string} */
    public function for(Fabric $fabric, ?User $user, ?string $ip): array
    {
        $garments = array_keys(config('marketplace.garment_categories'));
        $occasions = array_keys(config('marketplace.occasions'));

        if ($this->ai->available()) {
            try {
                $raw = Cache::remember("ai:fabric-ideas:{$fabric->id}:{$fabric->updated_at?->timestamp}", now()->addWeek(), fn () => $this->ai->json(
                    'fabric_ideas',
                    'You are a creative director at an African couture house. Suggest up to three distinct outfits that suit this exact fabric — its type, colours, pattern and material. Be specific about cut and finishing. Mention who or what occasion each suits.',
                    [['type' => 'text', 'text' => json_encode([
                        'name' => $fabric->name, 'type' => $fabric->category, 'colors' => $fabric->colors,
                        'pattern' => $fabric->pattern, 'material' => $fabric->material, 'sold_by' => $fabric->unit,
                    ])]],
                    ['type' => 'object', 'additional' => false, 'required' => ['ideas'], 'properties' => [
                        'ideas' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'object', 'additional' => false, 'required' => ['title', 'garment_category', 'description'], 'properties' => [
                            'title' => ['type' => 'string', 'maxLength' => 80],
                            'garment_category' => ['type' => 'string', 'enum' => $garments],
                            'occasion' => ['type' => 'string', 'nullable' => true, 'enum' => $occasions],
                            'description' => ['type' => 'string', 'maxLength' => 300],
                            'styling_note' => ['type' => 'string', 'maxLength' => 160, 'nullable' => true],
                        ]]],
                    ]],
                    $user, $ip, false, 500,
                ));

                return ['source' => 'ai', 'notice' => null, 'ideas' => array_map(fn ($i) => [
                    'title' => Grounding::scrubClaims($i['title']),
                    'garment_category' => $i['garment_category'],
                    'occasion' => $i['occasion'] ?? null,
                    'description' => Grounding::scrubClaims($i['description']),
                    'styling_note' => isset($i['styling_note']) ? Grounding::scrubClaims($i['styling_note']) : null,
                ], $raw['ideas'])];
            } catch (AiException $e) {
                $notice = 'Ideas from our style guide while the AI stylist is unavailable.';
            }
        }

        $labels = config('marketplace.garment_categories');

        return ['source' => 'guide', 'notice' => $notice ?? null, 'ideas' => array_map(fn ($g) => [
            'title' => $labels[$g[0]] ?? $g[0],
            'garment_category' => $g[0],
            'occasion' => null,
            'description' => $g[1],
            'styling_note' => null,
        ], self::GUIDE[$fabric->category] ?? [])];
    }
}
