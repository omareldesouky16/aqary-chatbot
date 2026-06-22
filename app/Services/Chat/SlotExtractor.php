<?php

namespace App\Services\Chat;

class SlotExtractor
{
    public static function emptyState(string $sessionId): array
    {
        return [
            'session_id' => $sessionId,
            'slots' => [
                'propertyType' => null,
                'location' => null,
                'location_id' => null,
                'price' => null,
                'area' => null,
                'bedrooms' => null,
                'bathrooms' => null,
                'features' => [],
            ],
            'shown_properties' => [],
            'shown_properties_data' => [],
            'ranked_result_ids' => [],
            'results_shown_count' => 0,
            'search' => [
                'status' => 'not_ready',
                'search_id' => null,
                'criteria_digest' => null,
                'criteria_snapshot' => null,
                'ranked_listing_ids' => [],
                'ranking_scores' => [],
                'result_items' => [],
                'visible_reference_map' => [],
                'result_count' => 0,
                'shown_count' => 0,
                'page_size' => 5,
                'has_more' => false,
                'min_price_fallback' => null,
                'last_shown_at' => null,
                'created_at' => null,
            ],
            'context_property_id' => null,
            'failed_searches' => 0,
            'repeat_count' => 0,
            'slot_contradiction_count' => 0,
            'isComplaint' => false,
            'needsCheckIn' => false,
            'complaint_case' => null,
            'complaint_events' => [],
            'optional_collection_status' => 'not_asked',
            'clarification' => null,
            'search_ready' => false,
            'language' => null,
            'new_search_requested' => false,
        ];
    }

    public function merge(array $state, array $nlu): array
    {
        $state = array_replace_recursive(self::emptyState($state['session_id'] ?? ''), $state);

        if ($this->shouldResetSearch($state, $nlu)) {
            $state = $this->resetSearchSpecificState($state);
            $state['new_search_requested'] = true;
        }

        foreach (($nlu['slots'] ?? []) as $slot => $value) {
            if ($value === null || $value === '' || $this->isPaymentSlot($slot)) {
                continue;
            }

            if (array_key_exists($slot, $state['slots'])) {
                if ($slot === 'price') {
                    $value = $this->normalizePrice($value, $nlu['raw_message'] ?? '');
                }
                $state['slots'][$slot] = $value;
            }
        }

        if (isset($nlu['clarification']) && is_array($nlu['clarification'])) {
            $state['clarification'] = $nlu['clarification'];
        }

        if (isset($state['clarification']['slot_name'])) {
            $clarifiedSlot = (string) $state['clarification']['slot_name'];
            if (($nlu['slots'][$clarifiedSlot] ?? null) !== null && $nlu['slots'][$clarifiedSlot] !== '') {
                $state['clarification'] = null;
            }
        }

        if (isset($nlu['optional_collection_status'])) {
            $state['optional_collection_status'] = (string) $nlu['optional_collection_status'];
        }

        // Server-side automatic fallback: if the bot already asked about optional
        // preferences and the LLM didn't set optional_collection_status, auto-advance
        // so the conversation progresses to search instead of getting stuck forever.
        $currentStatus = $state['optional_collection_status'] ?? 'not_asked';
        $rawMessage = $nlu['raw_message'] ?? '';

        if ($currentStatus === 'asked' && (! isset($nlu['optional_collection_status']) || $nlu['optional_collection_status'] === 'asked')) {
            if ($nlu['intent'] !== 'system_error') {
                if ($this->isDeclineResponse($rawMessage)) {
                    $state['optional_collection_status'] = 'declined';
                } else {
                    $state['optional_collection_status'] = 'answered';
                }
            }
        }

        // Also auto-answer on first turn if all required slots filled and user
        // is clearly providing optional data (extracted optional slots exist).
        if ($currentStatus === 'not_asked' && ! isset($nlu['optional_collection_status']) && $nlu['intent'] !== 'system_error') {
            $optionalSlots = ['area', 'bedrooms', 'bathrooms', 'features'];
            $hasOptional = false;
            foreach ($optionalSlots as $s) {
                $val = $nlu['slots'][$s] ?? null;
                if ($val !== null && $val !== '' && $val !== []) {
                    $hasOptional = true;
                    break;
                }
            }
            if ($hasOptional) {
                $state['optional_collection_status'] = 'answered';
            }
        }

        $state['search_ready'] = false;

        $detectedLanguage = $nlu['language'] ?? null;
        if ($detectedLanguage === null || $detectedLanguage === '') {
            $detectedLanguage = $this->detectLanguageFromMessage($nlu['raw_message'] ?? '');
        }
        if (! empty($detectedLanguage)) {
            $state['language'] = $detectedLanguage;
        }

        return $state;
    }

    public function awaitingSlots(array $state): array
    {
        $collection = SlotCollectionState::build($state);
        $missing = $collection['missing_required_slots'];

        if ($missing !== []) {
            return [$missing[0]];
        }

        if ($collection['next_question_slot'] === 'optional_preferences') {
            return ['optional_preferences'];
        }

        return [];
    }

    public function shownProperties(array $state): array
    {
        return array_values($state['shown_properties'] ?? []);
    }

    public function resolvePropertyReference(array $state, array $nlu): array
    {
        $shown = $this->shownProperties($state);
        $id = $nlu['resolved_property_id'] ?? null;

        if ($id !== null) {
            foreach ($shown as $property) {
                if ((int) ($property['id'] ?? 0) === (int) $id) {
                    return ['id' => (int) $id, 'resolved_by' => $nlu['resolved_by'] ?? 'id_explicit'];
                }
            }
        }

        $reference = strtolower((string) ($nlu['user_reference'] ?? ''));
        $position = $this->positionFromReference($reference);
        if ($position !== null) {
            foreach ($shown as $property) {
                if ((int) ($property['position'] ?? 0) === $position) {
                    return ['id' => (int) $property['id'], 'resolved_by' => 'position'];
                }
            }
        }

        foreach ($shown as $property) {
            $title = strtolower((string) ($property['title'] ?? ''));
            if ($reference !== '' && $title !== '' && str_contains($title, $reference)) {
                return ['id' => (int) $property['id'], 'resolved_by' => 'title_match'];
            }
        }

        return ['id' => null, 'resolved_by' => null];
    }

    private function shouldResetSearch(array $state, array $nlu): bool
    {
        if ((bool) ($nlu['new_search_requested'] ?? false)) {
            return true;
        }

        if (empty($state['shown_properties'])) {
            return false;
        }

        $slots = $nlu['slots'] ?? [];
        $currentType = $state['slots']['propertyType'] ?? null;
        $currentLocationId = $state['slots']['location_id'] ?? null;
        $currentLocationName = $state['slots']['location'] ?? null;

        return (! empty($slots['propertyType']) && $currentType !== null && strcasecmp((string) $slots['propertyType'], (string) $currentType) !== 0)
            || (! empty($slots['location_id']) && $currentLocationId !== null && (int) $slots['location_id'] !== (int) $currentLocationId)
            || (! empty($slots['location']) && $currentLocationName !== null && $currentLocationId === null && strcasecmp((string) $slots['location'], (string) $currentLocationName) !== 0);
    }

    private function resetSearchSpecificState(array $state): array
    {
        $sessionCounters = [
            'failed_searches' => $state['failed_searches'] ?? 0,
            'repeat_count' => $state['repeat_count'] ?? 0,
            'slot_contradiction_count' => $state['slot_contradiction_count'] ?? 0,
            'isComplaint' => $state['isComplaint'] ?? false,
            'needsCheckIn' => $state['needsCheckIn'] ?? false,
            'language' => $state['language'] ?? null,
        ];

        $reset = array_replace(self::emptyState((string) $state['session_id']), $sessionCounters);
        $reset['search']['status'] = 'not_ready';

        return $reset;
    }

    private function normalizePrice(mixed $price, string $rawMessage = ''): int|string
    {
        // If price is a small number (< 1000) but the message contains
        // "million"/"thousand"/"آلاف"/"مليون" etc., multiply accordingly.
        $messageHasUnit = (bool) preg_match('/\b(million|m|billion|b|thousand|k|آلاف|مليون|مليار|الف)\b/i', $rawMessage);

        if (is_numeric($price)) {
            $num = (int) $price;
            if ($num < 1000 && $messageHasUnit && $num > 0) {
                if (preg_match('/\b(million|m|مليون)\b/i', $rawMessage)) {
                    return $num * 1000000;
                }
                if (preg_match('/\b(billion|b|مليار)\b/i', $rawMessage)) {
                    return $num * 1000000000;
                }
                if (preg_match('/\b(thousand|k|آلاف|الف)\b/i', $rawMessage)) {
                    return $num * 1000;
                }
            }
            return $num;
        }

        if (is_array($price)) {
            $price = $price['amount'] ?? $price['value'] ?? $price['min'] ?? (string) ($price[0] ?? '0');
        }

        $raw = trim((string) $price);

        // Handle Arabic-Indic digits (٠-٩) before any parsing
        $arabicDigits = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $latinDigits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $raw = str_replace($arabicDigits, $latinDigits, $raw);

        // Check for "X million/thousand" pattern BEFORE stripping units
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(million|m|billion|b|thousand|k|آلاف|مليون|مليار|الف)\b/i', $raw, $m)) {
            $num = (float) $m[1];
            $unit = strtolower($m[2]);
            return match ($unit) {
                'billion', 'b', 'مليار' => (int) ($num * 1000000000),
                'million', 'm', 'مليون' => (int) ($num * 1000000),
                'thousand', 'k', 'آلاف', 'الف' => (int) ($num * 1000),
                default => (int) $num,
            };
        }

        // Check with any currency suffix attached (e.g. "3 million EGP")
        $clean = preg_replace('/\b(egp|usd|eur|gbp|sar|aed)\b/i', '', $raw);
        $clean = trim($clean);
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(million|m|billion|b|thousand|k|آلاف|مليون|مليار|الف)\b/i', $clean, $m)) {
            $num = (float) $m[1];
            $unit = strtolower($m[2]);
            return match ($unit) {
                'billion', 'b', 'مليار' => (int) ($num * 1000000000),
                'million', 'm', 'مليون' => (int) ($num * 1000000),
                'thousand', 'k', 'آلاف', 'الف' => (int) ($num * 1000),
                default => (int) $num,
            };
        }

        // Strip known currency suffixes and non-numeric chars
        $clean = preg_replace('/\b(egp|usd|eur|gbp|sar|aed)\b/i', '', $clean);
        $clean = preg_replace('/[^0-9.\s,]/', '', $clean);

        $clean = trim($clean);
        if ($clean === '') {
            return $price;
        }

        // Strip commas from numeric strings: "3,000,000"
        $stripped = str_replace(',', '', $clean);
        if (is_numeric($stripped)) {
            return (int) $stripped;
        }

        // Last resort: try extracting any numeric value
        if (preg_match('/[0-9]+(?:\.[0-9]+)?/', $clean, $m)) {
            $num = (int) (float) $m[0];
            if ($num < 1000 && $messageHasUnit && $num > 0) {
                if (preg_match('/\b(million|m|مليون)\b/i', $rawMessage)) {
                    return $num * 1000000;
                }
                if (preg_match('/\b(billion|b|مليار)\b/i', $rawMessage)) {
                    return $num * 1000000000;
                }
                if (preg_match('/\b(thousand|k|آلاف|الف)\b/i', $rawMessage)) {
                    return $num * 1000;
                }
            }
            return $num;
        }

        return $price;
    }

    private function isDeclineResponse(string $message): bool
    {
        $normalized = trim(strtolower($message));
        // Exact short declines (English + Arabic/Egyptian colloquial)
        $exact = [
            'no', 'n', 'nah', 'nope', 'no thanks', 'no thank you', 'not now',
            'skip', 'none', 'nothing', 'no preferences', 'no preference', 'no prefs',
            'لا', 'لا شكرا', 'لا شكراً', 'مش عايز', 'مش عايزة',
            'ولا حاجة', 'ولا ايه', 'ولا أي حاجة', 'ولا اي حاجة',
            'مش مهم', 'خلاص', 'كفاية', 'مفيش', 'من غير', 'انا كويس',
        ];
        if (in_array($normalized, $exact, true)) {
            return true;
        }
        // Pattern: "i don't have/need/want ..."
        if (preg_match('/\b(no|don\'t|dont|do not|not)\b.*\b(have|need|want|prefer|care|mind)\b/i', $message)) {
            return true;
        }
        // Arabic pattern: مش/ما/لا + want/need/have/important
        if (preg_match('/\b(مش|ما|لا)\s*(عايز|عايزة|احتاج|أحتاج|مهم|نفسي|عندي)\b/u', $message)) {
            return true;
        }
        return false;
    }

    private function detectLanguageFromMessage(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'en';
        }

        // Check for Arabic script characters (Unicode range 0600-06FF)
        if (preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $message)) {
            return 'ar';
        }

        return 'en';
    }

    private function isPaymentSlot(string $slot): bool
    {
        return in_array($slot, ['paymentMethod', 'installment', 'downPayment', 'monthlyPayment'], true);
    }

    private function positionFromReference(string $reference): ?int
    {
        return match (true) {
            preg_match('/\b(2|second|two)\b/', $reference) === 1 => 2,
            preg_match('/\b(3|third|three)\b/', $reference) === 1 => 3,
            preg_match('/\b(4|fourth|four)\b/', $reference) === 1 => 4,
            preg_match('/\b(5|fifth|five)\b/', $reference) === 1 => 5,
            preg_match('/\b(1|first|one)\b/', $reference) === 1 => 1,
            default => null,
        };
    }
}
