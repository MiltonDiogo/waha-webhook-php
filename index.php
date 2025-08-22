<?php
// webhook.php

$logFile = __DIR__ . "/webhook_log.txt";

// Recebe POST do WAHA
$input = file_get_contents("php://input");
file_put_contents($logFile, date("Y-m-d H:i:s") . " - Recebido: " . $input . "\n", FILE_APPEND);

$data = json_decode($input, true);
if (!$data || !isset($data["event"])) {
    http_response_code(400);
    echo "Invalid payload";
    exit;
}

// Processa apenas eventos de mensagem
if ($data["event"] === "message") {
    $from = $data["payload"]["from"] ?? "";
    $body = $data["payload"]["body"] ?? "";

    // Evita responder mensagens enviadas por ele mesmo
    if (!($data["payload"]["fromMe"] ?? false)) {
        // Chama a Gemini
        $reply = callGeminiAI($body);

        // Envia resposta pelo WAHA
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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        file_put_contents($logFile, date("Y-m-d H:i:s") . " - WAHA HTTP Code: " . $httpCode . "\n", FILE_APPEND);
        file_put_contents($logFile, date("Y-m-d H:i:s") . " - WAHA Response: " . $resp . "\n", FILE_APPEND);
        file_put_contents($logFile, date("Y-m-d H:i:s") . " - Resposta enviada: " . $reply . "\n", FILE_APPEND);
    }
}

http_response_code(200);
echo "OK";

// ----------------------------
// Função para chamar a API Gemini 2.5 Flash via REST
function callGeminiAI($message) {
    $apiKey = "AIzaSyD7DTONO7vq9jws-pIihvoiQd4RI03pRTU"; // <-- coloque sua key
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent";

    // Monta JSON no padrão oficial da Gemini
    $data = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $message]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "x-goog-api-key: $apiKey"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log completo para debug
    $logFile = __DIR__ . "/webhook_log.txt";
    file_put_contents($logFile, date("Y-m-d H:i:s") . " - Gemini HTTP Code: " . $httpCode . "\n", FILE_APPEND);
    file_put_contents($logFile, date("Y-m-d H:i:s") . " - Gemini raw response: " . $resp . "\n", FILE_APPEND);

    $json = json_decode($resp, true);

    // Verifica se há erro na resposta
    if (isset($json['error'])) {
        file_put_contents($logFile, date("Y-m-d H:i:s") . " - Gemini error: " . $json['error']['message'] . "\n", FILE_APPEND);
        return "Desculpe, estou com problemas técnicos no momento.";
    }

    // Extrai o texto retornado pela Gemini (estrutura correta conforme documentação)
    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
        return $json['candidates'][0]['content']['parts'][0]['text'];
    }

    // Log de estrutura inesperada para debug
    file_put_contents($logFile, date("Y-m-d H:i:s") . " - Estrutura inesperada: " . print_r($json, true) . "\n", FILE_APPEND);
    return "Desenvolvendo";
}
