<?php

namespace App\Services\Chat;

class ResolutionStateService
{
    public function __construct(
        private readonly LocationResolutionService $locations = new LocationResolutionService(),
        private readonly PropertyTypeResolutionService $propertyTypes = new PropertyTypeResolutionService(),
        private readonly FeatureResolutionService $features = new FeatureResolutionService(),
        private readonly ResolutionReviewService $review = new ResolutionReviewService(),
    ) {
    }

    private function extractString(mixed $value): string
    {
        if (is_array($value)) {
            return (string) ($value['value'] ?? $value['amount'] ?? $value['name'] ?? $value[0] ?? '');
        }
        return (string) $value;
    }

    private function extractStringArray(mixed $value): array
    {
        if (!is_array($value)) {
            return array_filter([$this->extractString($value)]);
        }
        
        // If it's an associative array like ['value' => 'pool'], extract it
        if (isset($value['value'])) {
            return [(string) $value['value']];
        }

        $result = [];
        foreach ($value as $item) {
            $str = $this->extractString($item);
            if ($str !== '') {
                $result[] = $str;
            }
        }
        return $result;
    }

    public function apply(array $state, array $nlu): array
    {
        $state = array_replace_recursive(SlotExtractor::emptyState((string) ($state['session_id'] ?? '')), $state);
        $resolution = $state['resolution'] ?? ResolutionData::emptyState();
        $reviewItemIds = $resolution['review_item_ids'] ?? [];

        $propertyTypeRaw = $this->extractString($state['slots']['propertyType'] ?? '');
        $locationRaw = $this->extractString($state['slots']['location'] ?? '');
        $featuresRaw = $this->extractStringArray($state['slots']['features'] ?? []);

        $propertyTypeOutcome = $propertyTypeRaw !== '' ? $this->propertyTypes->resolve($propertyTypeRaw) : ResolutionData::outcome('propertyType', 'unresolved', null, null, null, null, [], true);
        $locationOutcome = $locationRaw !== '' ? $this->locations->resolve($locationRaw) : ResolutionData::outcome('location', 'unresolved', null, null, null, null, [], true);
        $featureOutcome = $this->features->resolve($featuresRaw);

        $resolution['outcomes']['propertyType'] = $propertyTypeOutcome;
        $resolution['outcomes']['location'] = $locationOutcome;
        $resolution['outcomes']['features'] = $featureOutcome['outcomes'];

        if ($propertyTypeOutcome['status'] === 'resolved') {
            $state['slots']['propertyType'] = $propertyTypeOutcome['canonical_name'];
        }
        if ($locationOutcome['status'] === 'resolved') {
            $state['slots']['location'] = $locationOutcome['canonical_name'];
            $state['slots']['location_id'] = $locationOutcome['canonical_id'];
        }
        if ($featureOutcome['resolved'] !== []) {
            $state['slots']['features'] = $featureOutcome['resolved'];
        }

        $pendingClarification = null;

        if (in_array($locationOutcome['status'], ['ambiguous', 'unresolved'], true) && ($locationRaw !== '')) {
            $pendingClarification = ResolutionData::clarification('location', $locationOutcome['status'], $locationOutcome['raw_text'], $locationOutcome['candidates']);
            $reviewItemIds[] = $this->review->record((string) $state['session_id'], 'location', $locationOutcome)['id'];
        } elseif (in_array($propertyTypeOutcome['status'], ['ambiguous', 'unresolved'], true) && ($propertyTypeRaw !== '')) {
            $pendingClarification = ResolutionData::clarification('propertyType', $propertyTypeOutcome['status'], $propertyTypeOutcome['raw_text'], $propertyTypeOutcome['candidates']);
            $reviewItemIds[] = $this->review->record((string) $state['session_id'], 'propertyType', $propertyTypeOutcome)['id'];
        } elseif ($featureOutcome['reviewable'] !== []) {
            $pendingClarification = ResolutionData::clarification('features', 'ambiguous', (string) ($featureOutcome['reviewable'][0]['raw_text'] ?? null), $featureOutcome['reviewable'][0]['candidates'] ?? []);
            foreach ($featureOutcome['reviewable'] as $outcome) {
                $reviewItemIds[] = $this->review->record((string) $state['session_id'], 'features', $outcome)['id'];
            }
        }

        $resolution['pending_clarification'] = $pendingClarification;
        $resolution['review_item_ids'] = array_values(array_unique($reviewItemIds));
        $state['resolution'] = $resolution;
        $state['clarification'] = $pendingClarification;

        return SlotCollectionState::hydrate($state);
    }
}
