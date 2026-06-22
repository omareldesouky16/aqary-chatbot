<?php

namespace App\Services\Chat;

class SearchCriteriaService
{
    public function fromState(array $state): ?SearchData
    {
        return SearchData::fromState($state);
    }

    public function searchReady(array $state): bool
    {
        return ($this->fromState($state)?->isReady()) ?? false;
    }

    public function __construct(
        private readonly LocationResolutionService $locations,
        private readonly PropertyTypeResolutionService $propertyTypes,
    ) {
    }

    private function extractString(mixed $value): string
    {
        if (is_array($value)) {
            return (string) ($value['value'] ?? $value['amount'] ?? $value['name'] ?? $value[0] ?? '');
        }
        return (string) $value;
    }

    public function digest(array $state): ?string
    {
        return $this->fromState($state)?->digest();
    }

    public function isCoreChange(array $state, array $nlu): bool
    {
        if (($nlu['new_search_requested'] ?? false) === true) {
            return true;
        }

        $slots = $nlu['slots'] ?? [];
        $current = $this->fromState($state);

        if (! $current) {
            return false;
        }

        if (! empty($slots['propertyType']) && strcasecmp($this->extractString($slots['propertyType']), $current->propertyTypeName) !== 0) {
            return true;
        }

        if (! empty($slots['location']) && strcasecmp($this->extractString($slots['location']), $current->locationName) !== 0) {
            return true;
        }

        if (! empty($slots['location_id']) && (int) $slots['location_id'] !== $current->locationId) {
            return true;
        }

        return false;
    }

    public function isRefinement(array $nlu): bool
    {
        $slots = $nlu['slots'] ?? [];

        return array_key_exists('price', $slots)
            || array_key_exists('area', $slots)
            || array_key_exists('bedrooms', $slots)
            || array_key_exists('bathrooms', $slots)
            || array_key_exists('features', $slots);
    }
}
