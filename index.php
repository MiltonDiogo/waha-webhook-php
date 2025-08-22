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
    $isFromMe = $data["payload"]["fromMe"] ?? false;
    
    // Extrai o número para usar como identificador
    $phoneNumber = extractPhoneNumber($from);

    // Evita responder mensagens enviadas por ele mesmo
    if (!$isFromMe) {
        // Verifica se é um comando especial primeiro
        $reply = handleSpecialCommands($body, $phoneNumber);
        
        // Se não é comando especial, chama a Gemini
        if ($reply === null) {
            $reply = callGeminiAI($body);
            
            // Adiciona assinatura do autor
            $reply .= "\n\n---\n*Assistente IA criado por [Seu Nome]*";
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Timeout de 10 segundos
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        file_put_contents($logFile, date("Y-m-d H:i:s") . " - WAHA HTTP Code: " . $httpCode . "\n", FILE_APPEND);
        file_put_contents($logFile, date("Y-m-d H:i:s") . " - WAHA Response: " . $resp . "\n", FILE_APPEND);
        file_put_contents($logFile, date("Y-m-d H:i:s") . " - Resposta enviada para $phoneNumber: " . substr($reply, 0, 100) . "...\n", FILE_APPEND);
    }
}

http_response_code(200);
echo "OK";

// ----------------------------
// Funções auxiliares

function extractPhoneNumber($from) {
    // Remove @c.us e outros sufixos para obter apenas o número
    return preg_replace('/@.*/', '', $from);
}

function handleSpecialCommands($message, $phoneNumber) {
    $message = strtolower(trim($message));
    
    switch ($message) {
        case '/start':
        case 'iniciar':
        case 'oi':
        case 'olá':
        case 'ola':
            return "Olá! 👋 Eu sou um assistente virtual inteligente.\n\n".
                   "Digite /ajuda para ver os comandos disponíveis.\n".
                   "Digite /sobre para saber mais sobre mim.\n".
                   "Ou faça qualquer pergunta que eu tentarei ajudar!\n\n".
                   "*Desenvolvido por [Seu Nome]*";
            
        case '/ajuda':
        case 'ajuda':
        case 'help':
            return "🤖 *Comandos disponíveis:*\n\n".
                   "• /start - Iniciar conversa\n".
                   "• /ajuda - Ver esta mensagem\n".
                   "• /sobre - Informações sobre mim\n".
                   "• /criador - Quem me desenvolveu\n\n".
                   "Ou simplemente faça uma pergunta e eu tentarei ajudar!";
            
        case '/sobre':
        case 'sobre':
            return "🤖 *Sobre mim:*\n\n".
                   "Eu sou um assistente virtual baseado na tecnologia Gemini AI 2.5 Flash.\n".
                   "Fui desenvolvido para responder perguntas e ajudar com informações diversas.\n\n".
                   "Versão: 1.0\n".
                   "Criador: [Seu Nome]";
            
        case '/criador':
        case 'criador':
            return "👨‍💻 *Meu criador:*\n\n".
                   "Fui desenvolvido por [Seu Nome] como projeto de chatbot WhatsApp.\n".
                   "Estou sempre evoluindo com novas funcionalidades!\n\n".
                   "Entre em contato: [seu-email@exemplo.com]";
            
        default:
            return null; // Não é um comando especial
    }
}

// Função para chamar a API Gemini 2.5 Flash via REST
function callGeminiAI($message) {
    $apiKey = "AIzaSyD7DTONO7vq9jws-pIihvoiQd4RI03pRTU"; // <-- coloque sua key
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent";

    // Personalidade do assistente
    $systemInstruction = "Você é um assistente prestativo chamado 'AssistenteIA'. " .
                         "Responda de forma amigável e concisa. Use emojis ocasionalmente. " .
                         "Se não souber algo, admita honestamente. Mantenha respostas em português.";

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
                    ["text" => $message]
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Timeout de 15 segundos
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

    // Extrai o texto retornado pela Gemini (estrutura correta conforme documentação)
    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
        return $json['candidates'][0]['content']['parts'][0]['text'];
    }

    // Log de estrutura inesperada para debug
    file_put_contents($logFile, date("Y-m-d H:i:s") . " - Estrutura inesperada: " . substr(print_r($json, true), 0, 500) . "\n", FILE_APPEND);
    return "Desculpe, não consegui processar sua solicitação. Poderia reformular sua pergunta?";
}
