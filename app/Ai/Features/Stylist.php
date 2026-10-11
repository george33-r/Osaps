<?php

namespace App\Ai\Features;

use App\Ai\AiException;
use App\Ai\AiGateway;
use App\Domain\Ai\BudgetEstimate;
use App\Domain\Ai\FabricCriteria;
use App\Domain\Ai\Grounding;
use App\Domain\Ai\KeywordInterpreter;
use App\Domain\Ai\StylistConsultation;
use App\Models\Fabric;
use App\Models\JobRequest;
use App\Models\User;
use App\Services\MatchingService;
use Illuminate\Support\Collection;

/**
 * The personal stylist consultation.
 *  - The question order is deterministic (StylistConsultation): one useful question at a time.
 *  - The AI reads free-text answers into the same structured slots, and writes the final concept.
 *  - Fabrics and tailors come from the database; the AI may only choose among the ids it is shown.
 *  - Without an AI provider the same flow runs on rules and says so.
 */
class Stylist
{
    public function __construct(
        private readonly AiGateway $ai,
        private readonly MatchingService $matching,
    ) {}

    public function consultation(): StylistConsultation
    {
        return new StylistConsultation(config('marketplace.occasions'), config('marketplace.garment_categories'), config('marketplace.colors'));
    }

    /**
     * @param  array<string,mixed>  $known  what the client already collected
     * @return array<string,mixed>
     */
    public function turn(array $known, ?string $message, ?string $slot, ?User $user, ?string $ip): array
    {
        $flow = $this->consultation();
        $known = $flow->clean($known);
        $source = $this->ai->available() ? 'ai' : 'guided';
        $notice = null;

        if ($message !== null && trim($message) !== '') {
            [$known, $source, $notice] = $this->readFreeText($flow, $known, mb_substr(trim($message), 0, 500), $slot, $user, $ip);
        }

        $next = $flow->next($known);
        if ($next !== null) {
            return ['source' => $source, 'notice' => $notice, 'known' => $known, 'next_question' => $next, 'result' => null];
        }

        return ['source' => $source, 'notice' => $notice, 'known' => $known, 'next_question' => null, 'result' => $this->conclude($known, $user, $ip)];
    }

    /** @return array{0:array<string,mixed>,1:string,2:?string} */
    private function readFreeText(StylistConsultation $flow, array $known, string $message, ?string $slot, ?User $user, ?string $ip): array
    {
        if ($this->ai->available()) {
            try {
                $raw = $this->ai->json(
                    'stylist_extract',
                    'You are the intake assistant of an African fashion house. Read the customer\'s message and fill only the fields they actually expressed. Leave out anything they did not say. "budget_naira" is the total they want to spend in whole naira. Put comfort, fit, modesty or style wishes in "preferences" in their own words.',
                    [['type' => 'text', 'text' => 'Current question: '.($slot ?? 'none').'. Already known: '.json_encode($known).'. <user_input>'.$message.'</user_input>']],
                    $this->extractSchema(), $user, $ip, false, 300,
                );
                $merged = $flow->clean(array_merge($known, array_filter($raw, fn ($v) => $v !== null)));

                return [$merged, 'ai', null];
            } catch (AiException $e) {
                $notice = 'Your stylist is answering in guided mode for a moment.';
            }
        }

        $kw = KeywordInterpreter::read($message);
        $add = array_filter([
            'occasion' => $kw['occasion'],
            'garment' => $kw['garment'],
            'colors' => $kw['colors'] ?: null,
            'budget_naira' => $kw['budget_naira'],
        ], fn ($v) => $v !== null);
        if ($slot === 'colors' && ! isset($add['colors'])) {
            $add['colors'] = [];
            $add['colors_open'] = true;
        }
        if (! $add && ! isset($known['preferences'])) {
            $add['preferences'] = $message;
        }

        return [$flow->clean(array_merge($known, $add)), 'guided', $notice ?? null];
    }

    /** @return array<string,mixed> */
    private function conclude(array $known, ?User $user, ?string $ip): array
    {
        $garment = $known['garment'];
        $needsFabric = $known['needs'] !== 'tailoring_only';
        $needsTailoring = $known['needs'] !== 'fabric_only';

        $candidates = $needsFabric ? $this->candidateFabrics($garment, $known['colors'] ?? []) : collect();
        $tailors = $needsTailoring ? $this->matchTailors($garment, $user) : collect();

        $concept = null;
        $source = 'guided';
        $notice = null;
        if ($this->ai->available()) {
            try {
                $concept = $this->aiConcept($known, $candidates, $user, $ip);
                $source = 'ai';
            } catch (AiException $e) {
                $notice = 'Your concept was written from our style guide because the AI stylist is unavailable right now.';
            }
        }
        $concept ??= $this->guideConcept($known, $candidates);

        // Fabrics: the AI's picks first (already grounded), then the rest of the real shortlist.
        $chosen = collect($concept['fabric_ids'])->map(fn ($id) => $candidates->firstWhere('id', $id))->filter();
        $fabrics = $chosen->concat($candidates->reject(fn ($f) => $chosen->contains('id', $f->id)))->take(4)->values();

        $budget = BudgetEstimate::build(
            $fabrics->first()?->price_minor,
            config("marketplace.typical_yards.{$garment}"),
            $tailors->pluck('profile.starting_price_minor')->filter()->values()->all(),
            $needsFabric,
            $needsTailoring,
            (int) config('marketplace.fees.customer_service_fee_bps'),
            (int) config('marketplace.fees.customer_service_fee_cap_minor'),
            ($known['budget_naira'] ?? 0) > 0 ? $known['budget_naira'] * 100 : null,
        );

        $occasionLabel = isset($known['occasion']) ? config('marketplace.occasions.'.$known['occasion']) : null;

        return [
            'source' => $source,
            'notice' => $notice,
            'concept' => array_diff_key($concept, ['fabric_ids' => 1]),
            'fabrics' => $fabrics,
            'tailors' => $tailors,
            'budget' => $budget,
            // A starting point for a tailoring request — the customer edits everything before posting.
            'request_draft' => [
                'garment_category' => $garment,
                'occasion' => $known['occasion'] ?? null,
                'title' => mb_substr($concept['title'], 0, 140),
                'description' => mb_substr(trim($concept['summary'].' '.$concept['silhouette'].' '.$concept['finishing_details'].(isset($known['preferences']) ? ' Preferences: '.$known['preferences'] : '')), 0, 3000),
                'style_tags' => array_slice($concept['style_tags'] ?? [], 0, 6),
                'mode' => ($known['budget_naira'] ?? 0) > 0 ? 'budget' : 'open',
                'fabric_status' => match ($known['needs']) {
                    'tailoring_only' => 'own_fabric',
                    default => $fabrics->isNotEmpty() ? 'buying_on_platform' : 'need_advice',
                },
                'fabric_id' => $fabrics->first()?->id,
                'occasion_label' => $occasionLabel,
            ],
        ];
    }

    /** @return Collection<int, Fabric> */
    private function candidateFabrics(string $garment, array $colors): Collection
    {
        $cats = config("marketplace.garment_fabrics.{$garment}", []);
        $criteria = new FabricCriteria($cats, $colors, null, true);
        $query = fn (FabricCriteria $c) => Fabric::query()->listed()->with('vendor')->filter($c->toFilters())->orderBy('price_minor')->limit(8)->get();

        $found = $query($criteria);
        foreach ($criteria->relaxations() as $step) {
            if ($found->isNotEmpty()) {
                break;
            }
            $found = $query($step['criteria']);
        }
        if ($found->isEmpty() && $cats !== []) {
            $found = $query(new FabricCriteria($cats, [], null, true));
        }

        return $found;
    }

    private function matchTailors(string $garment, ?User $user): Collection
    {
        // An unsaved request object carries the brief into the existing, explainable matcher.
        $probe = new JobRequest(['garment_category' => $garment, 'city' => $user?->city ?? '', 'style_tags' => []]);
        $probe->customer_id = $user?->id ?? 0;

        return $this->matching->rankFor($probe, 3);
    }

    /** @return array<string,mixed> */
    private function aiConcept(array $known, Collection $candidates, ?User $user, ?string $ip): array
    {
        $catalogue = $candidates->map(fn (Fabric $f) => [
            'id' => $f->id, 'name' => $f->name, 'type' => $f->category, 'colors' => $f->colors, 'pattern' => $f->pattern, 'material' => $f->material,
        ])->values()->all();

        $raw = $this->ai->json(
            'stylist_concept',
            'You are the head stylist of an international African couture house. Write one outfit concept for this customer: warm, specific and practical, in British English, in a confident editorial voice. Respect any comfort, fit or modesty preferences. Choose up to three fabrics only from the catalogue list by id, or none if none suit. Suggest finishing details a skilled Nigerian tailor can make.',
            [['type' => 'text', 'text' => 'Customer brief: '.json_encode(array_diff_key($known, ['budget_naira' => 1]))."\nCatalogue fabrics available now: ".json_encode($catalogue)]],
            $this->conceptSchema($candidates->pluck('id')->all()),
            $user, $ip, false, 700,
        );

        $scrub = fn ($s) => Grounding::scrubClaims((string) $s);

        return [
            'title' => $scrub($raw['title']),
            'summary' => $scrub($raw['summary']),
            'silhouette' => $scrub($raw['silhouette']),
            'colour_direction' => $scrub($raw['colour_direction']),
            'fabric_characteristics' => $scrub($raw['fabric_characteristics']),
            'finishing_details' => $scrub($raw['finishing_details']),
            'why' => $scrub($raw['why'] ?? ''),
            'questions_for_tailor' => array_map($scrub, $raw['questions_for_tailor'] ?? []),
            'style_tags' => array_map(fn ($t) => mb_strtolower(mb_substr($t, 0, 30)), $raw['style_tags'] ?? []),
            'fabric_ids' => Grounding::pick($raw['fabric_ids'] ?? [], $candidates->pluck('id')->all(), 3),
        ];
    }

    /** Rule-based concept used when no AI is available. Plain, accurate and labelled as such in the UI. */
    private function guideConcept(array $known, Collection $candidates): array
    {
        $garment = config('marketplace.garment_categories.'.$known['garment']);
        $occasion = isset($known['occasion']) ? mb_strtolower(config('marketplace.occasions.'.$known['occasion'])) : null;
        $colors = array_map(fn ($c) => str_replace('_', ' ', $c), $known['colors'] ?? []);
        $colourText = $colors ? implode(' and ', $colors) : 'a colour you love';
        $types = array_map(fn ($k) => config('marketplace.fabric_categories.'.$k), config('marketplace.garment_fabrics.'.$known['garment'], []));

        return [
            'title' => trim(($colors ? ucfirst($colors[0]).' ' : '').mb_strtolower($garment).($occasion ? ' for a '.$occasion : '')),
            'summary' => "A {$colourText} ".mb_strtolower($garment).($occasion ? " made for a {$occasion}" : '').', cut to your measurements by a tailor you choose.',
            'silhouette' => 'Discuss the cut with your tailor; share a reference photo if you have one.',
            'colour_direction' => $colors ? 'Built around '.implode(', ', $colors).'.' : 'Open — pick from the fabrics below.',
            'fabric_characteristics' => $types ? 'Usually made in '.implode(', ', array_filter($types)).'.' : 'Any fabric you already own.',
            'finishing_details' => 'Agree lining, closures and any embroidery in the offer before you accept.',
            'why' => '',
            'questions_for_tailor' => ['How many fittings are included?', 'Is lining included in the price?'],
            'style_tags' => array_slice($known['colors'] ?? [], 0, 2),
            'fabric_ids' => $candidates->take(3)->pluck('id')->all(),
        ];
    }

    private function extractSchema(): array
    {
        return ['type' => 'object', 'additional' => false, 'properties' => [
            'occasion' => ['type' => 'string', 'nullable' => true, 'enum' => array_keys(config('marketplace.occasions'))],
            'garment' => ['type' => 'string', 'nullable' => true, 'enum' => array_keys(config('marketplace.garment_categories'))],
            'colors' => ['type' => 'array', 'nullable' => true, 'maxItems' => 4, 'items' => ['type' => 'string', 'enum' => config('marketplace.colors')]],
            'budget_naira' => ['type' => 'integer', 'nullable' => true, 'min' => 1000, 'max' => 100_000_000],
            'needs' => ['type' => 'string', 'nullable' => true, 'enum' => array_keys(StylistConsultation::NEEDS)],
            'timing' => ['type' => 'string', 'nullable' => true, 'enum' => array_keys(StylistConsultation::TIMING)],
            'preferences' => ['type' => 'string', 'nullable' => true, 'maxLength' => 300],
        ]];
    }

    /** @param list<int> $ids */
    private function conceptSchema(array $ids): array
    {
        $text = fn (int $max) => ['type' => 'string', 'maxLength' => $max];

        return ['type' => 'object', 'additional' => false,
            'required' => ['title', 'summary', 'silhouette', 'colour_direction', 'fabric_characteristics', 'finishing_details', 'fabric_ids'],
            'properties' => [
                'title' => $text(80), 'summary' => $text(400), 'silhouette' => $text(240), 'colour_direction' => $text(240),
                'fabric_characteristics' => $text(240), 'finishing_details' => $text(300), 'why' => $text(300),
                'questions_for_tailor' => ['type' => 'array', 'maxItems' => 3, 'items' => $text(160)],
                'style_tags' => ['type' => 'array', 'maxItems' => 6, 'items' => $text(30)],
                'fabric_ids' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'integer', 'enum' => $ids]],
            ]];
    }
}
