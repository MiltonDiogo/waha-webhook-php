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
    $body = trim($data["payload"]["body"] ?? "");
    $senderName = $data["payload"]["senderName"] ?? "Usuário";

    // Evita responder mensagens enviadas por ele mesmo
    if (!($data["payload"]["fromMe"] ?? false)) {
        
        // Comandos especiais
        if ($body === "/start" || $body === "oi" || $body === "olá" || $body === "ola") {
            $reply = "Olá $senderName! 👋 Eu sou o AssistenteIA, criado por Milton Diogo. Como posso ajudar você hoje?";
        } 
        elseif ($body === "/ajuda" || $body === "ajuda" || $body === "help") {
            $reply = "🤖 *Comandos disponíveis:*\n\n".
                     "• /start - Iniciar conversa\n".
                     "• /ajuda - Ver esta mensagem\n".
                     "• /sobre - Informações sobre mim\n".
                     "• /criador - Quem me desenvolveu\n\n".
                     "Ou simplemente faça uma pergunta e eu tentarei ajudar!";
        }
        elseif ($body === "/sobre" || $body === "sobre") {
            $reply = "🤖 *Sobre mim:*\n\n".
                     "Eu sou um assistente virtual inteligente baseado na tecnologia Gemini AI 2.5 Flash.\n".
                     "Fui desenvolvido para responder perguntas e ajudar com informações diversas.\n\n".
                     "Versão: 1.0";
        }
        elseif ($body === "/criador" || $body === "criador") {
            $reply = "👨‍💻 *Meu criador:*\n\n".
                     "Fui desenvolvido por Milton Diogo como projeto de chatbot WhatsApp.\n".
                     "Estou sempre evoluindo com novas funcionalidades!";
        }
        else {
            // Chama a Gemini para outras mensagens
            $reply = callGeminiAI($body, $senderName);
            
            // Adiciona assinatura no final de cada resposta
            $reply .= "\n\n---\n_Respondido por AssistenteIA_";
        }

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
        file_put_contents($logFile, date("Y-m-d H:i:s") . " - Resposta enviada para $senderName: " . substr($reply, 0, 100) . "...\n", FILE_APPEND);
    }
}

http_response_code(200);
echo "OK";

// ----------------------------
// Função para chamar a API Gemini 2.5 Flash via REST
function callGeminiAI($message, $userName) {
    $apiKey = "AIzaSyD7DTONO7vq9jws-pIihvoiQd4RI03pRTU";
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent";

    // Personalidade do assistente
    $systemInstruction = "Você é um assistente prestativo chamado 'AssistenteIA', criado por [Seu Nome]. " .
                         "Responda de forma amigável e concisa. Use emojis ocasionalmente para tornar a conversa mais amigável. " .
                         "Se não souber algo, admita honestamente. Mantenha respostas preferencialmente em português.";

    // Monta JSON no padrão oficial da Gemini
    $data = [
        "systemInstruction" => [
            "parts" => [
                ["text" => $systemInstruction]
            ]
        ],
        "contents" => [
            [
                "parts" => [
                    ["text" => "Usuário $userName pergunta: $message"]
                ]
            ]
        ],
        "generationConfig" => [
            "temperature" => 0.7,
            "topK" => 40,
            "topP" => 0.95,
            "maxOutputTokens" => 1024,
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout de 30 segundos
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Log completo para debug
    $logFile = __DIR__ . "/webhook_log.txt";
    file_put_contents($logFile, date("Y-m-d H:i:s") . " - Gemini HTTP Code: " . $httpCode . "\n", FILE_APPEND);
    
    if ($curlError) {
        file_put_contents($logFile, date("Y-m-d H:i:s") . " - Gemini cURL Error: " . $curlError . "\n", FILE_APPEND);
        return "Desculpe, estou com dificuldades técnicas no momento. Por favor, tente novamente em alguns instantes.";
    }
    
    // Log truncado para não lotar o arquivo
    $respLog = strlen($resp) > 500 ? substr($resp, 0, 500) . "..." : $resp;
    file_put_contents($logFile, date("Y-m-d H:i:s") . " - Gemini raw response: " . $respLog . "\n", FILE_APPEND);

    $json = json_decode($resp, true);

    // Verifica se há erro na resposta
    if (isset($json['error'])) {
        file_put_contents($logFile, date("Y-m-d H:i:s") . " - Gemini error: " . $json['error']['message'] . "\n", FILE_APPEND);
        return "Desculpe, estou com problemas técnicos no momento. Por favor, tente novamente mais tarde.";
    }

    // Extrai o texto retornado pela Gemini
    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
        return $json['candidates'][0]['content']['parts'][0]['text'];
    }

    // Log de estrutura inesperada para debug
    file_put_contents($logFile, date("Y-m-d H:i:s") . " - Estrutura inesperada: " . substr(print_r($json, true), 0, 500) . "\n", FILE_APPEND);
    return "Desculpe, não consegui processar sua solicitação. Poderia reformular sua pergunta?";
}
