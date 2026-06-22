<?php

namespace App\Http\Controllers;

use App\Services\Chat\ChatLogService;
use App\Services\Chat\ComplaintStateService;
use App\Services\Chat\PropertyDetailService;
use App\Services\Chat\PropertyGalleryService;
use App\Services\Chat\PropertyReferenceResolver;
use App\Services\Chat\PropertySearchService;
use App\Services\Chat\SellerContactService;
use App\Services\Chat\SlotCollectionState;
use App\Services\Chat\ComplaintSignalService;
use App\Services\Chat\IntentDetectionService;
use App\Services\Chat\ResolutionStateService;
use App\Services\Chat\SessionOwnershipService;
use App\Services\Chat\SlotExtractor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ChatController extends Controller
{
    public function __construct(
        private readonly SessionOwnershipService $sessions,
        private readonly ChatLogService $logs,
        private readonly IntentDetectionService $intent,
        private readonly ResolutionStateService $resolution,
        private readonly PropertySearchService $search,
        private readonly PropertyReferenceResolver $references,
        private readonly PropertyDetailService $details,
        private readonly PropertyGalleryService $galleries,
        private readonly SellerContactService $contacts,
        private readonly SlotExtractor $slots,
        private readonly ComplaintSignalService $complaints,
        private readonly ComplaintStateService $complaintState,
    ) {
    }

    public function chat(Request $request): JsonResponse
    {
        set_time_limit(120);
        $validated = $request->validate([
            'session_id' => ['required', 'uuid'],
            'message' => ['required', 'string', 'min:1'],
            'context_property_id' => ['nullable', 'integer'],
        ]);

        try {
            $session = $this->sessions->verifyOrCreate($validated['session_id'], (int) ($request->user()?->id ?? 1));
        } catch (AuthorizationException) {
            abort(403);
        } catch (InvalidArgumentException) {
            abort(422);
        }

        $state = $this->logs->latestState($session->session_id);
        if (($state['context_property_id'] ?? null) === null && isset($validated['context_property_id'])) {
            $state = $this->references->buildContextState($state, (int) $validated['context_property_id']);
        }

        $history = $this->logs->promptHistory($session->session_id);
        $nlu = $this->intent->detect($validated['message'], $history, $state);

        // Server-side property reference: resolve position from message to property ID
        if (in_array($nlu['intent'] ?? '', ['property_details', 'show_property_photos', 'seller_contact'], true)) {
            if (empty($nlu['resolved_property_id']) && empty(trim((string) ($nlu['user_reference'] ?? '')))) {
                $ref = $this->extractPropertyReference($validated['message'], $state['shown_properties'] ?? []);
                if ($ref !== null) {
                    $nlu['resolved_property_id'] = $ref;
                }
            }
        }

        $state = $this->slots->merge($state, $nlu);
        $state = $this->complaints->apply($state, $nlu['flags'] ?? []);
        $state = $this->complaintState->apply($state, $nlu, $validated['message']);
        $complaintActive = ! empty($state['complaint_case']) && in_array($state['complaint_case']['stage'] ?? null, ['awaiting_issue', 'awaiting_phone', 'invalid_phone_retry', 'saved', 'declined'], true);

        if ($nlu['intent'] !== 'installment_redirect') {
            try {
                $state = $this->resolution->apply($state, $nlu);
            } catch (\Throwable) {
                $nlu['fallback'] = true;
            }
        }
        $state = SlotCollectionState::hydrate($state);

        $propertyResolution = null;
        $propertyDetail = null;
        $propertyGallery = null;
        $sellerContact = null;
        $detailEvent = null;
        if (! $complaintActive && in_array($nlu['intent'], ['property_details', 'show_property_photos', 'seller_contact'], true)) {
            $propertyResolution = $this->references->resolve($state, $nlu);
            $state['property_reference'] = $propertyResolution['property_reference'];

            if (($propertyResolution['status'] ?? null) !== 'resolved') {
                $detailEvent = [
                    'event_type' => 'unresolved_reference',
                    'property_id' => null,
                    'reference_type' => null,
                    'fallback' => false,
                ];
            } else {
                $propertyDetail = $this->details->detail($propertyResolution['property'], $nlu);
                $state['resolved_property_context'] = $propertyDetail;
                $state['property_detail'] = $propertyDetail;

                if ($nlu['intent'] === 'show_property_photos') {
                    $propertyGallery = $this->galleries->gallery($propertyResolution['property']);
                    $state['property_gallery'] = $propertyGallery;
                }

                if ($nlu['intent'] === 'seller_contact') {
                    $sellerContact = $this->contacts->contact($propertyResolution['property'], (bool) ($nlu['flags']['contact_requested'] ?? true));
                    $state['seller_contact'] = $sellerContact;
                }

                $detailEvent = [
                    'event_type' => $nlu['intent'] === 'show_property_photos' ? ($propertyGallery['has_images'] ? 'photo_gallery' : 'no_photos') : ($nlu['intent'] === 'seller_contact' ? (! empty($sellerContact['contact_available']) ? 'contact_returned' : 'contact_unavailable') : 'detail_answer'),
                    'property_id' => $propertyResolution['id'],
                    'reference_type' => $propertyResolution['resolved_by'],
                    'missing_fields' => $propertyDetail['missing_fields'] ?? [],
                    'photo_count' => count($propertyGallery['images'] ?? []),
                    'contact_returned' => (bool) ($sellerContact['contact_available'] ?? false),
                    'fallback' => false,
                ];
            }
        }

        $awaitingSlots = $this->slots->awaitingSlots($state);
        if (($state['clarification']['preference_type'] ?? null) === 'location' || ($state['clarification']['preference_type'] ?? null) === 'propertyType' || ($state['clarification']['preference_type'] ?? null) === 'features') {
            $awaitingSlots = array_values(array_unique(array_merge(['resolution_clarification'], $awaitingSlots)));
        }
        if ($awaitingSlots === ['optional_preferences'] && ($state['optional_collection_status'] ?? 'not_asked') === 'not_asked') {
            $state['optional_collection_status'] = 'asked';
        $state = SlotCollectionState::hydrate($state);
            $awaitingSlots = $this->slots->awaitingSlots($state);
        }

        $searchOutcome = ['state' => $state, 'properties' => [], 'has_more' => false, 'min_price_fallback' => null, 'search_id' => null, 'event' => null];
        $intentIsSearch = in_array($nlu['intent'] ?? null, ['search_property', 'show_more_results', 'unclear'], true);
        $intentNonSearch = in_array($nlu['intent'] ?? null, ['complaint', 'installment_redirect', 'seller_contact', 'property_details', 'show_property_photos'], true);
        $shouldSearch = $intentIsSearch
            || (bool) ($nlu['new_search_requested'] ?? false)
            || (bool) (($nlu['search']['refinement_requested'] ?? false))
            || (! $intentNonSearch && ($state['slot_collection']['search_ready'] ?? false) === true);

        if (! $complaintActive && ($state['slot_collection']['search_ready'] ?? false) === true && $shouldSearch) {
            $searchOutcome = $this->search->search($state, $nlu);
            $state = $searchOutcome['state'];
            if (is_array($searchOutcome['event'] ?? null)) {
                $state = $this->logs->recordSearchEvent($state, $searchOutcome['event']);
            }
            if ($searchOutcome['properties'] !== []) {
                $state = (new \App\Services\Chat\SearchResultStateService())->supersedePageContext($state);
            }
            $awaitingSlots = $this->slots->awaitingSlots($state);
        }

        if (($state['property_reference']['status'] ?? null) !== null && ($state['property_reference']['status'] ?? null) !== 'resolved') {
            $awaitingSlots = array_values(array_unique(array_merge($awaitingSlots, ['property_reference_clarification'])));
        }

        if (is_array($detailEvent)) {
            $state = $this->logs->recordDetailEvent($state, $detailEvent);
        }

        $reply = $this->intent->replyFor($nlu, $state, $awaitingSlots, $propertyResolution);

        $this->logs->record($session->session_id, 'user', $validated['message'], $nlu['intent'], $state);
        if (! ($nlu['fallback'] ?? false)) {
            $this->logs->record($session->session_id, 'assistant', $reply, null, $state);
        }

        return response()->json([
            'reply' => $reply,
            'intent' => $nlu['intent'],
            'isComplaint' => (bool) ($state['isComplaint'] ?? false),
            'needsCheckIn' => (bool) ($state['needsCheckIn'] ?? false),
            'complaint_case' => $state['complaint_case'] ?? null,
            'installment_redirect' => $nlu['intent'] === 'installment_redirect',
            'awaiting_slots' => $awaitingSlots,
            'slot_collection' => $state['slot_collection'] ?? SlotCollectionState::build($state),
            'resolution' => $state['resolution'] ?? null,
            'resolved_property_id' => $propertyResolution['id'] ?? null,
            'resolved_by' => $propertyResolution['resolved_by'] ?? null,
            'user_reference' => $nlu['user_reference'] ?? null,
            'property_reference' => $state['property_reference'] ?? null,
            'property_detail' => $propertyDetail,
            'property_gallery' => $propertyGallery,
            'seller_contact' => $sellerContact,
            'properties' => $searchOutcome['properties'],
            'search' => $state['search'] ?? [
                'status' => 'not_ready',
                'result_count' => 0,
                'shown_count' => 0,
                'page_size' => 5,
                'has_more' => false,
                'visible_reference_map' => [],
                'min_price_fallback' => null,
                'budget_fallback' => null,
            ],
            'show_image_offer' => $searchOutcome['properties'] !== [],
            'has_more' => $searchOutcome['has_more'],
            'min_price_fallback' => $searchOutcome['min_price_fallback'],
            'session_id' => $session->session_id,
            'fallback' => (bool) ($nlu['fallback'] ?? false),
        ]);
    }

    /**
     * Extract property reference from user message and resolve to a property ID.
     */
    private function extractPropertyReference(string $message, array $shownProperties): ?int
    {
        $n = strtolower(trim($message));

        $position = match (true) {
            preg_match('/\b(the\s+)?(first|1st|one|1)\b/i', $n) === 1 => 1,
            preg_match('/\b(the\s+)?(second|2nd|two|2)\b/i', $n) === 1 => 2,
            preg_match('/\b(the\s+)?(third|3rd|three|3)\b/i', $n) === 1 => 3,
            preg_match('/\b(the\s+)?(fourth|4th|four|4)\b/i', $n) === 1 => 4,
            preg_match('/\b(the\s+)?(fifth|5th|five|5|last)\b/i', $n) === 1 => 5,
            preg_match('/\b(الأول|اول|واحد|١)\b/u', $n) === 1 => 1,
            preg_match('/\b(الثاني|ثاني|اتنين|٢)\b/u', $n) === 1 => 2,
            preg_match('/\b(الثالث|تالت|٣)\b/u', $n) === 1 => 3,
            preg_match('/\b(الرابع|رابع|٤)\b/u', $n) === 1 => 4,
            preg_match('/\b(الخامس|خامس|٥|اخر|آخر)\b/u', $n) === 1 => 5,
            default => null,
        };

        if ($position === null) {
            return null;
        }

        foreach ($shownProperties as $prop) {
            if ((int) ($prop['position'] ?? 0) === $position) {
                return (int) ($prop['id'] ?? 0);
            }
        }

        return null;
    }
}
