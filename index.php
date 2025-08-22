<?php
// Receber o JSON enviado pelo WAHA
$input = file_get_contents("php://input");
$data = json_decode($input, true);

// Log simples (para debug)
// Isso cria/atualiza um arquivo "log.txt" no teu servidor
file_put_contents("log.txt", print_r($data, true), FILE_APPEND);

// Verifica se há mensagens
if (isset($data['messages'])) {
    foreach ($data['messages'] as $msg) {
        $from = $msg['from'] ?? '';
        $text = $msg['text'] ?? '';

        // Só para debug inicial
        file_put_contents("log.txt", "Mensagem de $from: $text\n", FILE_APPEND);

        // Se a pessoa disser "oi", responde
        if (strtolower(trim($text)) == "oi") {
            $wahaUrl = "https://waha-bot-8dux.onrender.com/api/sendText";
            $payload = [
                "session" => "default",
                "chatId" => $from,
                "text" => "Olá, tudo bem? 👋 Sou o bot em PHP!"
            ];

            $ch = curl_init($wahaUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_exec($ch);
            curl_close($ch);
        }
    }
}

// O WAHA precisa sempre de resposta 200 OK
http_response_code(200);
