<?php

namespace App\Services\Chat;

use App\Models\ChatbotListing;

class PropertySearchService
{
    public function __construct(
        private readonly PropertyScoringService $scoring = new PropertyScoringService(),
        private readonly SearchCriteriaService $criteria = new SearchCriteriaService(),
        private readonly SearchResultStateService $stateService = new SearchResultStateService(),
        private readonly SearchOutcomeService $outcomes = new SearchOutcomeService(),
        private readonly BudgetFallbackService $fallbacks = new BudgetFallbackService(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function search(array $state, array $nlu = []): array
    {
        $criteria = $this->criteria->fromState($state);
        if (! $criteria) {
            return [
                'state' => $this->stateService->clear($state),
                'properties' => [],
                'has_more' => false,
                'min_price_fallback' => null,
                'event' => $this->outcomes->replyFallback([]),
            ];
        }

        if (($nlu['flags']['show_more_requested'] ?? false) === true || ($nlu['intent'] ?? null) === 'show_more_results') {
            return $this->showMore($state, $criteria);
        }

        $listings = $this->fetchActiveCashListings($criteria);
        $ranked = $this->scoring->rank($listings, $criteria);
        $retained = array_slice($ranked, 0, 20);
        $visible = array_slice($retained, 0, 5);

        if ($ranked !== []) {
            $searchState = $this->stateService->store($state, $criteria, $retained, $visible, 'results');

            return [
                'state' => $searchState,
                'properties' => $searchState['shown_properties'],
                'has_more' => $searchState['search']['has_more'],
                'min_price_fallback' => null,
                'event' => $this->outcomes->searchResults($criteria->toArray(), count($listings), count($visible), count($retained)),
                'search_id' => $searchState['search']['search_id'],
            ];
        }

        $fallback = $this->fallbacks->sameScopeMinimum($criteria);
        if ($fallback === null) {
            $cleared = $this->stateService->clear($state);
            $cleared['search']['status'] = 'no_results';

            return [
                'state' => $cleared,
                'properties' => [],
                'has_more' => false,
                'min_price_fallback' => null,
                'event' => $this->outcomes->noResults($criteria->toArray(), 0),
                'search_id' => null,
            ];
        }

        $cleared = $this->stateService->clear($state);
        $cleared['search']['status'] = 'budget_fallback';
        $cleared['search']['min_price_fallback'] = $fallback['minimum_available_price'];
        $cleared['search']['budget_fallback'] = $fallback;
        $cleared['search']['criteria_snapshot'] = $criteria->toArray();
        $cleared['search']['criteria_digest'] = $criteria->digest();
        $cleared['search']['search_id'] = $criteria->digest();

        return [
            'state' => $cleared,
            'properties' => [],
            'has_more' => false,
            'min_price_fallback' => $fallback['minimum_available_price'],
            'event' => $this->outcomes->budgetFallback($criteria->toArray(), (int) $fallback['available_listing_count_in_scope'], (int) $fallback['minimum_available_price']),
            'search_id' => $criteria->digest(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function showMore(array $state, SearchData $criteria): array
    {
        $search = $state['search'] ?? [];
        $rankedIds = $search['ranked_listing_ids'] ?? [];
        if ($rankedIds === []) {
            $cleared = $this->stateService->clear($state);
            $cleared['search']['status'] = 'no_results';

            return [
                'state' => $cleared,
                'properties' => [],
                'has_more' => false,
                'min_price_fallback' => null,
                'event' => $this->outcomes->noResults($criteria->toArray(), 0),
                'search_id' => null,
            ];
        }

        $listings = ChatbotListing::query()
            ->whereIn('id', $rankedIds)
            ->get()
            ->keyBy('id');

        $scores = $search['ranking_scores'] ?? [];
        $ranked = [];
        foreach ($rankedIds as $listingId) {
            $listing = $listings->get($listingId);
            if (! $listing instanceof ChatbotListing) {
                continue;
            }

            $ranked[] = [
                'listing_id' => (int) $listingId,
                'listing' => $this->listingToArray($listing),
                'score' => $scores[$listingId] ?? $scores[(string) $listingId] ?? null,
            ];
        }

        $page = $this->stateService->nextPage($state, $ranked);

        return [
            'state' => $page['state'],
            'properties' => $page['items'],
            'has_more' => ! $page['exhausted'],
            'min_price_fallback' => null,
            'event' => $this->outcomes->showMore($criteria->toArray(), count($page['items']), count($ranked), $page['exhausted']),
            'search_id' => $page['state']['search']['search_id'] ?? null,
        ];
    }

    private function fetchActiveCashListings(SearchData $criteria): array
    {
        $locations = config('resolution.locations', []);
        $regionName = null;
        foreach ($locations as $loc) {
            if ($loc['canonical_id'] === $criteria->locationId) {
                $regionName = $loc['canonical_name'];
                break;
            }
        }

        $types = config('resolution.property_types', []);
        $typeName = null;
        foreach ($types as $type) {
            if ($type['canonical_id'] === $criteria->propertyTypeId) {
                $typeName = $type['canonical_name'];
                break;
            }
        }

        return ChatbotListing::query()
            ->when($regionName, fn ($q) => $q->where('region', $regionName))
            ->when($typeName, fn ($q) => $q->where('property_type', $typeName))
            ->where('price', '<=', $criteria->budgetWindowMax)
            ->get()
            ->map(fn (ChatbotListing $listing): array => $this->listingToArray($listing))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function listingToArray(ChatbotListing $listing): array
    {
        $features = is_array($listing->features) ? $listing->features : json_decode($listing->features ?? '[]', true);
        if (!is_array($features)) {
            $features = [];
        }
        $images = is_array($listing->images) ? $listing->images : json_decode($listing->images ?? '[]', true);
        if (!is_array($images)) {
            $images = [];
        }
        $cover = !empty($images) ? '/storage/' . $images[0] : null;

        // Try to reverse lookup IDs for the chatbot's internal state
        $locId = 0;
        foreach (config('resolution.locations', []) as $loc) {
            if ($loc['canonical_name'] === $listing->region) {
                $locId = $loc['canonical_id'];
            }
        }

        $typeId = 0;
        foreach (config('resolution.property_types', []) as $type) {
            if ($type['canonical_name'] === $listing->property_type) {
                $typeId = $type['canonical_id'];
            }
        }

        return [
            'id' => $listing->id,
            'title' => $listing->title,
            'url' => 'http://localhost/properties/' . $listing->id,
            'price' => $listing->price,
            'area' => $listing->area_sqm,
            'bedrooms' => $listing->bedrooms,
            'bathrooms' => $listing->bathrooms,
            'furnished_status' => $listing->is_furnished ? 'Furnished' : 'Unfurnished',
            'location_id' => $locId,
            'location_name' => $listing->region,
            'property_type_id' => $typeId,
            'feature_ids' => [], // Unused for now, we just pass names
            'feature_names' => $features,
            'cover_image_url' => $cover,
            'is_promoted' => false,
            'status' => 'active',
            'payment_type' => $listing->payment_type ?? 'Cash',
        ];
    }
}
