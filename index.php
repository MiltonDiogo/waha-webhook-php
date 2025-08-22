<?php
// webhook.php

$logFile = __DIR__ . "/webhook_log.txt";
$input = file_get_contents("php://input");
file_put_contents($logFile, date("Y-m-d H:i:s") . " - " . $input . "\n", FILE_APPEND);

$data = json_decode($input, true);
if (!$data || !isset($data["event"])) {
    http_response_code(400);
    echo "Invalid payload";
    exit;
}

if ($data["event"] === "message") {
    $from = $data["payload"]["from"] ?? "";
    $body = $data["payload"]["body"] ?? "";

    // Só responde se não for mensagem nossa
    if (!($data["payload"]["fromMe"] ?? false)) {

        // Chama a IA
        $reply = callGeminiAI($body);

        // Envia de volta pelo WAHA
        $wahaUrl = "https://waha-bot-8dux.onrender.com/api/sendText";
        $payload = [
            "session" => "default",
            "chatId" => $from,
            "text" => $reply,
        ];

        $ch = curl_init($wahaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        $resp = curl_exec($ch);
        curl_close($ch);

        file_put_contents($logFile, date("Y-m-d H:i:s") . " - Resposta enviada: $resp\n", FILE_APPEND);
    }
}

http_response_code(200);
echo "OK";


// ----------------------------
// Função que chama a API Gemini
function callGeminiAI($message) {
    $apiKey = "AIzaSyD7DTONO7vq9jws-pIihvoiQd4RI03pRTU"; // <-- coloque sua key
    $url = "https://generativeai.googleapis.com/v1beta2/models/text-bison-001:generateText";

    $data = [
        "prompt" => $message,
        "temperature" => 0.7,
        "maxOutputTokens" => 200
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $apiKey"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $resp = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($resp, true);
    return $json['candidates'][0]['output'] ?? "Desculpe, não entendi.";
}
