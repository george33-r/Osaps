<?php

namespace App\Ai\Features;

use App\Ai\AiException;
use App\Ai\AiGateway;
use App\Domain\Ai\FabricCriteria;
use App\Domain\Ai\PromptText;
use App\Domain\Ai\KeywordInterpreter;
use App\Models\Fabric;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * "Soft lace for a classy evening dress" → structured filters → real catalogue rows.
 * The AI only translates words into filters; the database decides what exists.
 */
class FabricSearch
{
    public function __construct(private readonly AiGateway $ai) {}

    /** @return array{source:string, understood:string, criteria:FabricCriteria, results:Collection<int,Fabric>, relaxed:list<string>, notice:?string} */
    public function search(string $query, ?User $user, ?string $ip): array
    {
        $query = mb_substr(trim($query), 0, 200);
        $cats = array_keys(config('marketplace.fabric_categories'));
        $colors = config('marketplace.colors');
        $occasions = array_keys(config('marketplace.occasions'));

        $source = 'keywords';
        $notice = null;
        $understood = '';
        $keywords = KeywordInterpreter::read($query);
        $criteria = FabricCriteria::sanitize($keywords, $cats, $colors, $occasions);

        if ($this->ai->available()) {
            try {
                $raw = Cache::remember('ai:fabric-search:'.sha1(mb_strtolower($query)), now()->addDay(), fn () => $this->ai->json(
                    'fabric_search',
                    'You turn a shopper\'s request for fabric into search filters for an African fabric catalogue. Choose only from the allowed values. Use "terms" only for concrete material or texture words that could appear in a product name (e.g. "cashmere", "beaded", "linen"); never for occasions or feelings.',
                    [['type' => 'text', 'text' => PromptText::block($query, 200)]],
                    self::schema($cats, $colors, $occasions),
                    $user, $ip, false, 300,
                ));
                $criteria = FabricCriteria::sanitize($raw, $cats, $colors, $occasions);
                $understood = (string) ($raw['understood'] ?? '');
                $source = 'ai';
            } catch (AiException $e) {
                $notice = $e->kind === 'rate_limited' ? $e->getMessage() : 'Smart search is resting, so this uses basic keyword matching.';
            }
        }

        // "Material for senator", "aso ebi": a garment with no fabric named (or none in stock) means
        // the fabrics that usually make that garment.
        $garmentCats = $keywords['garment'] ? array_values(array_intersect((array) config('marketplace.garment_fabrics.'.$keywords['garment'], []), $cats)) : [];
        if ($garmentCats !== [] && $criteria->categories === []) {
            $criteria = $criteria->withCategories($garmentCats);
        }
        $results = $this->run($criteria);
        if ($results->isEmpty() && $garmentCats !== [] && $criteria->categories !== $garmentCats) {
            $alt = $criteria->withCategories($garmentCats);
            $altResults = $this->run($alt);
            if ($altResults->isNotEmpty()) {
                [$criteria, $results] = [$alt, $altResults];
                $label = (string) (config('marketplace.garment_categories.'.$keywords['garment']) ?? str_replace('_', ' ', (string) $keywords['garment']));
                $understood = 'Fabrics usually used for '.mb_strtolower($label).'.';
            }
        }
        $relaxed = [];
        if ($results->isEmpty() && ! $criteria->isEmpty()) {
            foreach ($criteria->relaxations() as $step) {
                $relaxed[] = $step['dropped'];
                $results = $this->run($step['criteria']);
                if ($results->isNotEmpty()) {
                    break;
                }
            }
        }

        if ($understood === '') {
            $understood = self::describe($criteria, $query);
        }

        return compact('source', 'understood', 'criteria', 'results', 'relaxed', 'notice');
    }

    /** @return Collection<int, Fabric> */
    private function run(FabricCriteria $criteria): Collection
    {
        return Fabric::query()->listed()->with('vendor')->filter($criteria->toFilters())
            ->orderBy('price_minor')->limit(12)->get();
    }

    private static function describe(FabricCriteria $c, string $query): string
    {
        $labels = config('marketplace.fabric_categories');
        $parts = [];
        if ($c->colors) {
            $parts[] = implode(' or ', array_map(fn ($x) => str_replace('_', ' ', $x), $c->colors));
        }
        $parts[] = $c->categories ? implode(' or ', array_map(fn ($k) => $labels[$k] ?? $k, $c->categories)) : 'fabric';
        $text = implode(' ', $parts);
        if ($c->maxPriceMinor) {
            $text .= ' up to ₦'.number_format(intdiv($c->maxPriceMinor, 100)).' per unit';
        }

        return $c->isEmpty() ? 'Showing all fabric in stock — try naming a type, colour or budget.' : ucfirst($text).'.';
    }

    /** @param list<string> $cats @param list<string> $colors @param list<string> $occasions */
    public static function schema(array $cats, array $colors, array $occasions): array
    {
        return ['type' => 'object', 'additional' => false, 'required' => ['categories', 'colors', 'understood'], 'properties' => [
            'categories' => ['type' => 'array', 'maxItems' => 4, 'items' => ['type' => 'string', 'enum' => $cats]],
            'colors' => ['type' => 'array', 'maxItems' => 4, 'items' => ['type' => 'string', 'enum' => $colors]],
            'max_price_naira' => ['type' => 'integer', 'nullable' => true, 'min' => 100, 'max' => 10_000_000],
            'terms' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string', 'maxLength' => 30]],
            'occasion' => ['type' => 'string', 'nullable' => true, 'enum' => $occasions],
            'understood' => ['type' => 'string', 'maxLength' => 140],
        ]];
    }
}
