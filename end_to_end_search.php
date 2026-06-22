<?php

function uuid4(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0xffff), mt_rand(0,0x0fff)|0x4000,
        mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
    );
}

function callChat($sessionId, $message, $label): ?array {
    echo "=== $label ===\n";
    echo "> $message\n";

    $ch = curl_init('http://localhost:8999/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['session_id' => $sessionId, 'message' => $message]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode($resp, true);
    curl_close($ch);

    if ($data === null || ($http !== 200)) {
        echo "ERROR: Status=$http\n";
        echo substr($resp, 0, 300) . "\n\n";
        return null;
    }

    echo "Bot: {$data['reply']}\n";
    echo "Intent: {$data['intent']}" . (!empty($data['fallback']) ? ' (FALLBACK)' : '') . "\n";

    $loc = $data['resolution']['outcomes']['location'] ?? null;
    $pt = $data['resolution']['outcomes']['propertyType'] ?? null;
    if ($loc) echo "Location: {$loc['status']} -> {$loc['canonical_name']}\n";
    if ($pt) echo "PropertyType: {$pt['status']} -> {$pt['canonical_name']}\n";
    echo "Awaiting: " . json_encode($data['awaiting_slots'] ?? []) . "\n";

    if (($data['search']['status'] ?? null) === 'results') {
        echo "SEARCH RESULTS: " . count($data['properties'] ?? []) . " found\n";
        foreach ($data['properties'] as $i => $p) {
            echo "  " . ($i+1) . ". {$p['title']} - EGP {$p['price']} - {$p['location']}\n";
        }
    } else {
        echo "Search: {$data['search']['status']}\n";
    }
    echo "\n";
    return $data;
}

$sid = uuid4();

$d1 = callChat($sid, 'I want an apartment in New Cairo for up to 3 million', 'Turn 1');

$d2 = callChat($sid, 'no', 'Turn 2: Decline optional');

if ($d2 !== null && in_array('optional_preferences', $d2['awaiting_slots'] ?? [])) {
    $d3 = callChat($sid, 'area 150, 3 bedrooms, 2 bathrooms', 'Turn 3: Simple optional');
}

echo "--- Fresh session: everything at once ---\n";
$sid2 = uuid4();
$d4 = callChat($sid2, 'I want a 3 bedroom apartment in New Cairo for 2.5 million with parking and security', 'All-in-one');

echo "=== SUMMARY ===\n";
echo "English parsing: " . (($d1['fallback'] ?? false) ? 'FAIL' : 'OK') . "\n";
echo "Alias (Tagamoa): " . (($d4['resolution']['outcomes']['location']['canonical_name'] ?? '') === 'New Cairo' ? 'OK' : 'CHECK') . "\n";
echo "Search execution: " . (($d4['search']['status'] ?? 'not_ready') === 'results' ? 'OK' : 'not triggered') . "\n";

echo "END\n";
