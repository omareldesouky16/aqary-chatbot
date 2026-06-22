<?php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,"http://127.0.0.1:8001/api/chat");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'session_id' => '123e4567-e89b-12d3-a456-426614174000',
    'message' => '500m'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$server_output = curl_exec($ch);
echo $server_output;
curl_close ($ch);
