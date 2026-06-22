<?php

namespace App\Services\Chat;

use App\Models\ChatbotListing;
use Throwable;

class PropertyDetailService
{
    /**
     * @return array<string, mixed>
     */
    public function detail(array $property, array $nlu = []): array
    {
        $listing = $this->loadListing((int) ($property['id'] ?? 0));
        if ($listing instanceof ChatbotListing) {
            $features = is_array($listing->features) ? $listing->features : json_decode($listing->features ?? '[]', true);
            if (! is_array($features)) {
                $features = is_string($features) ? array_map('trim', explode(',', $features)) : [];
            }
            $property = array_replace($property, [
                'title' => $listing->title,
                'url' => 'http://localhost/properties/' . $listing->id,
                'price' => $listing->price,
                'area' => $listing->area_sqm,
                'bedrooms' => $listing->bedrooms,
                'bathrooms' => $listing->bathrooms,
                'furnished_status' => $listing->is_furnished ? 'Furnished' : 'Unfurnished',
                'location' => $listing->region,
                'features' => $features,
                'status' => 'active',
            ]);
        }

        $detail = [
            'id' => (int) ($property['id'] ?? 0),
            'title' => (string) ($property['title'] ?? ''),
            'url' => (string) ($property['url'] ?? ''),
            'price' => $property['price'] ?? null,
            'area' => $property['area'] ?? null,
            'bedrooms' => $property['bedrooms'] ?? null,
            'bathrooms' => $property['bathrooms'] ?? null,
            'furnished_status' => $property['furnished_status'] ?? null,
            'location' => $property['location'] ?? null,
            'floor_details' => $property['floor_details'] ?? null,
            'map_available' => (bool) ($property['map_available'] ?? false),
            'features' => array_values($property['features'] ?? $property['matched_features'] ?? []),
        ];

        $detail['missing_fields'] = array_keys(array_filter([
            'price' => $detail['price'] === null,
            'area' => $detail['area'] === null,
            'bedrooms' => $detail['bedrooms'] === null,
            'bathrooms' => $detail['bathrooms'] === null,
            'furnished_status' => empty($detail['furnished_status']),
            'location' => empty($detail['location']),
        ]));

        return array_filter($detail, static fn ($value): bool => $value !== null);
    }

    private function loadListing(int $id): ?ChatbotListing
    {
        if ($id <= 0) {
            return null;
        }

        try {
            return ChatbotListing::query()->find($id);
        } catch (Throwable) {
            return null;
        }
    }
}
