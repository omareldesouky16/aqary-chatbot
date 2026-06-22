<?php

namespace App\Services\Chat;

final class SearchData
{
    /**
     * @param array<int> $featureIds
     * @param array<int, string> $featureNames
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly int $propertyTypeId,
        public readonly string $propertyTypeName,
        public readonly int $locationId,
        public readonly string $locationName,
        public readonly int $maxBudget,
        public readonly int $budgetWindowMax,
        public readonly ?int $area = null,
        public readonly ?int $bedrooms = null,
        public readonly ?int $bathrooms = null,
        public readonly array $featureIds = [],
        public readonly array $featureNames = [],
        public readonly ?string $language = null,
    ) {
    }

    public static function fromState(array $state): ?self
    {
        $slots = $state['slots'] ?? [];
        $resolution = $state['resolution']['outcomes'] ?? [];

        $propertyType = $resolution['propertyType'] ?? null;
        $location = $resolution['location'] ?? null;
        $price = $slots['price'] ?? null;

        if (! is_array($propertyType) || ! is_array($location) || ! is_numeric($price)) {
            return null;
        }

        if (($propertyType['status'] ?? null) !== 'resolved' || ($location['status'] ?? null) !== 'resolved') {
            return null;
        }

        $featureIds = [];
        $featureNames = [];
        foreach (($resolution['features'] ?? []) as $featureOutcome) {
            if (! is_array($featureOutcome)) {
                continue;
            }

            if (($featureOutcome['status'] ?? null) !== 'resolved') {
                continue;
            }

            if (isset($featureOutcome['canonical_id'])) {
                $featureIds[] = (int) $featureOutcome['canonical_id'];
            }
            if (isset($featureOutcome['canonical_name'])) {
                $featureNames[] = (string) $featureOutcome['canonical_name'];
            }
        }

        $budget = (int) $price;

        $extractInt = function(mixed $val): ?int {
            if (is_array($val)) {
                $v = $val['value'] ?? $val['amount'] ?? $val['count'] ?? $val[0] ?? null;
                return is_numeric($v) ? (int) $v : null;
            }
            return is_numeric($val) ? (int) $val : null;
        };

        return new self(
            sessionId: (string) ($state['session_id'] ?? ''),
            propertyTypeId: (int) ($propertyType['canonical_id'] ?? 0),
            propertyTypeName: (string) ($propertyType['canonical_name'] ?? $slots['propertyType'] ?? ''),
            locationId: (int) ($location['canonical_id'] ?? 0),
            locationName: (string) ($location['canonical_name'] ?? $slots['location'] ?? ''),
            maxBudget: $budget,
            budgetWindowMax: (int) ceil($budget * 1.2),
            area: isset($slots['area']) ? $extractInt($slots['area']) : null,
            bedrooms: isset($slots['bedrooms']) ? $extractInt($slots['bedrooms']) : null,
            bathrooms: isset($slots['bathrooms']) ? $extractInt($slots['bathrooms']) : null,
            featureIds: array_values(array_unique($featureIds)),
            featureNames: array_values(array_unique($featureNames)),
            language: $state['language'] ?? null,
        );
    }

    public function isReady(): bool
    {
        return $this->sessionId !== ''
            && $this->propertyTypeId > 0
            && $this->locationId > 0
            && $this->maxBudget > 0
            && $this->budgetWindowMax >= $this->maxBudget;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'property_type_id' => $this->propertyTypeId,
            'property_type_name' => $this->propertyTypeName,
            'location_id' => $this->locationId,
            'location_name' => $this->locationName,
            'max_budget' => $this->maxBudget,
            'budget_window_max' => $this->budgetWindowMax,
            'area' => $this->area,
            'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms,
            'feature_ids' => $this->featureIds,
            'feature_names' => $this->featureNames,
            'language' => $this->language,
            'search_ready' => $this->isReady(),
        ];
    }

    public function digest(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
