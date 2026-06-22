<?php

namespace App\Services\Chat;

use App\Models\ChatbotListing;
use Throwable;

class PropertyReferenceResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(array $state, array $nlu): array
    {
        $shown = array_values($state['shown_properties'] ?? []);
        $reference = strtolower(trim((string) ($nlu['user_reference'] ?? '')));
        $explicitId = $nlu['resolved_property_id'] ?? null;

        if ($explicitId !== null) {
            foreach ($shown as $property) {
                if ((int) ($property['id'] ?? 0) === (int) $explicitId) {
                    return $this->resolved($property, 'id_explicit');
                }
            }
        }

        $position = $this->positionFromReference($reference);
        if ($position !== null) {
            foreach ($shown as $property) {
                if ((int) ($property['position'] ?? 0) === $position) {
                    return $this->resolved($property, 'position');
                }
            }

            return $this->unresolved($shown === [] ? 'missing' : 'stale', $shown);
        }

        if ($reference !== '') {
            $matches = array_values(array_filter($shown, static function (array $property) use ($reference): bool {
                $title = strtolower((string) ($property['title'] ?? ''));

                return $title !== '' && str_contains($title, $reference);
            }));

            if (count($matches) === 1) {
                return $this->resolved($matches[0], 'title_match');
            }

            if (count($matches) > 1) {
                return $this->unresolved('ambiguous', $matches);
            }
        }

        $context = $this->contextProperty($state);
        if ($context !== null && ($shown === [] || $this->isContextReference($reference))) {
            return $this->resolved($context, 'page_context');
        }

        return $this->unresolved($shown === [] ? 'missing' : 'ambiguous', $shown);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function contextProperty(array $state): ?array
    {
        $context = $state['property_page_context'] ?? null;
        if (is_array($context) && ($context['status'] ?? null) === 'valid' && ! empty($context['property'])) {
            return $context['property'];
        }

        $id = $state['context_property_id'] ?? null;
        if (! is_numeric($id) || (int) $id <= 0) {
            return null;
        }

        try {
            $listing = ChatbotListing::query()->find((int) $id);
        } catch (Throwable) {
            return null;
        }
        if (! $listing instanceof ChatbotListing) {
            return null;
        }

        return $this->listingToReference($listing);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildContextState(array $state, ?int $propertyId): array
    {
        if ($propertyId === null || $propertyId <= 0) {
            return $state;
        }

        $listing = ChatbotListing::query()->find($propertyId);
        $state['context_property_id'] = $propertyId;
        $state['property_page_context'] = [
            'context_property_id' => $propertyId,
            'status' => $listing instanceof ChatbotListing ? 'valid' : 'invalid',
            'applies_to_turn' => true,
            'validated_at' => date(DATE_ATOM),
            'property' => $listing instanceof ChatbotListing ? $this->listingToReference($listing) : null,
        ];

        return $state;
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    private function unresolved(string $status, array $candidates): array
    {
        $options = array_map(static fn (array $property): array => [
            'position' => (int) ($property['position'] ?? 0),
            'property_id' => (int) ($property['id'] ?? 0),
            'title' => (string) ($property['title'] ?? ''),
        ], array_slice($candidates, 0, 5));

        return [
            'status' => $status,
            'id' => null,
            'resolved_by' => null,
            'property' => null,
            'property_reference' => [
                'status' => $status,
                'reference_type' => null,
                'candidates' => $options,
                'clarification_prompt' => 'Which property do you mean? Please choose one of the current options.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolved(array $property, string $by): array
    {
        return [
            'status' => 'resolved',
            'id' => (int) ($property['id'] ?? 0),
            'resolved_by' => $by,
            'property' => $property,
            'property_reference' => [
                'status' => 'resolved',
                'reference_type' => $by === 'position' ? 'position' : ($by === 'page_context' ? 'context' : 'title'),
                'candidates' => [],
                'clarification_prompt' => null,
            ],
        ];
    }

    private function positionFromReference(string $reference): ?int
    {
        return match (true) {
            preg_match('/\b(2|second|two)\b/', $reference) === 1 => 2,
            preg_match('/\b(3|third|three)\b/', $reference) === 1 => 3,
            preg_match('/\b(4|fourth|four)\b/', $reference) === 1 => 4,
            preg_match('/\b(5|fifth|five|last)\b/', $reference) === 1 => 5,
            preg_match('/\b(1|first|one)\b/', $reference) === 1 => 1,
            default => null,
        };
    }

    private function isContextReference(string $reference): bool
    {
        return $reference === '' || preg_match('/\b(it|this|property|listing|place|unit)\b/', $reference) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function listingToReference(ChatbotListing $listing): array
    {
        $features = is_array($listing->features) ? $listing->features : json_decode($listing->features ?? '[]', true);
        if (! is_array($features)) {
            $features = is_string($features) ? array_map('trim', explode(',', $features)) : [];
        }
        
        $images = is_array($listing->images) ? $listing->images : json_decode($listing->images ?? '[]', true);
        if (! is_array($images)) {
            $images = is_string($images) ? array_map('trim', explode(',', $images)) : [];
        }
        $cover = !empty($images) ? '/storage/' . $images[0] : null;

        return [
            'id' => $listing->id,
            'position' => 1,
            'rank_position' => null,
            'title' => $listing->title,
            'url' => 'http://localhost/properties/' . $listing->id,
            'price' => $listing->price,
            'area' => $listing->area_sqm,
            'bedrooms' => $listing->bedrooms,
            'bathrooms' => $listing->bathrooms,
            'furnished_status' => $listing->is_furnished ? 'Furnished' : 'Unfurnished',
            'location' => $listing->region,
            'cover_image_url' => $cover,
            'has_cover_image' => !empty($cover),
            'matched_features' => $features,
        ];
    }
}
