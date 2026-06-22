<?php
function send_chat($msg) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,"http://127.0.0.1:8001/api/chat");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'session_id' => '123e4567-e89b-12d3-a456-426614174001',
        'message' => $msg
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Accept: application/json'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $server_output = curl_exec($ch);
    curl_close ($ch);
    return $server_output;
}
echo "Sending first message...\n";
echo send_chat('I want an apartment in smouha for 1 million');
echo "\n\nSending second message...\n";
echo send_chat('3 bedrooms');
echo "\n";
