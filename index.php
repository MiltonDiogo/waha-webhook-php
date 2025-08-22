<?php
// webhook.php

// Log para debug
$logFile = __DIR__ . "/webhook_log.txt";
$input = file_get_contents("php://input");
file_put_contents($logFile, date("Y-m-d H:i:s") . " - " . $input . "\n", FILE_APPEND);

// Decodifica JSON
$data = json_decode($input, true);

if (!$data || !isset($data["event"])) {
    http_response_code(400);
    echo "Invalid payload";
    exit;
}

// Se for mensagem recebida
if ($data["event"] === "message") {
    $from = $data["payload"]["from"] ?? "";
    $body = $data["payload"]["body"] ?? "";

    // Só responde se não for mensagem nossa (fromMe = false)
    if (!($data["payload"]["fromMe"] ?? false)) {
        // Monta a resposta
        $reply = "Recebi sua mensagem: " . $body;

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

// Retorno pro WAHA (importante devolver 200)
http_response_code(200);
echo "OK";
