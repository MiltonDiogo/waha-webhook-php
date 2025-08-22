<?php
// webhook.php

// Configurações de tempo máximo de execução
set_time_limit(30); // 30 segundos máximo
ini_set('max_execution_time', 30);

$logFile = __DIR__ . "/webhook_log.txt";

// Função para logging com formatação consistente
function logMessage($message) {
    file_put_contents($GLOBALS['logFile'], date("Y-m-d H:i:s") . " - " . $message . "\n", FILE_APPEND);
}

// Recebe POST do WAHA
try {
    $input = file_get_contents("php://input");
    logMessage("Recebido: " . $input);
    
    if (empty($input)) {
        http_response_code(400);
        echo "Empty payload";
        exit;
    }
    
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
                $reply .= "\n\n---\n*Assistente IA criado por Milton Diogo*";
            }

            // Envia resposta pelo WAHA
            sendWahaMessage($from, $reply, $phoneNumber);
        }
    }

    http_response_code(200);
    echo "OK";
    
} catch (Exception $e) {
    logMessage("ERRO GRAVE: " . $e->getMessage());
    http_response_code(500);
    echo "Internal Server Error";
}
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
                   "*Desenvolvido por Milton Diogo*";
            
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
                   "Eu sou um assistente virtual inteligente baseado na Gemini AI!\n".
                   "Fui desenvolvido para responder perguntas e ajudar com informações diversas.\n\n".
                   "Versão: 1.0\n".
                   "Criador: Milton Diogo";
            
        case '/criador':
        case 'criador':
            return "👨‍💻 *Meu criador:*\n\n".
                   "Fui desenvolvido pelo Milton Diogo como projeto de chatbot WhatsApp.\n".
                   "Estou sempre evoluindo com novas funcionalidades!\n\n".
                   "Entre em contato: mwmprogramador@gmail.com ou 959642430";
            
        default:
            return null; // Não é um comando especial
    }
}

function sendWahaMessage($chatId, $message, $phoneNumber) {
    $wahaUrl = "https://waha-bot-8dux.onrender.com/api/sendText";
    $payload = [
        "session" => "default",
        "chatId" => $chatId,
        "text" => $message,
    ];

    $ch = curl_init($wahaUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FAILONERROR => true
    ]);
    
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    logMessage("WAHA HTTP Code: " . $httpCode);
    logMessage("WAHA Response: " . $resp);
    logMessage("Resposta enviada para $phoneNumber: " . substr($message, 0, 100) . "...");
    
    if ($error) {
        logMessage("ERRO WAHA: " . $error);
    }
}

// Função para chamar a API Gemini 2.5 Flash via REST
function callGeminiAI($message) {
    $apiKey = "AIzaSyD7DTONO7vq9jws-pIihvoiQd4RI03pRTU";
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent";

    // Personalidade do assistente
    $systemInstruction = "Você é um assistente prestativo chamado 'AssistenteIA'. " .
                         "Responda de forma amigável e concisa. Use emojis ocasionalmente. " .
                         "Se não souber algo, admita honestamente. Mantenha respostas em português. " .
                         "Seja direto e objetivo nas respostas.";

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
            "maxOutputTokens" => 800, // Reduzido para respostas mais curtas
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "x-goog-api-key: $apiKey"
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FAILONERROR => true
    ]);
    
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    logMessage("Gemini HTTP Code: " . $httpCode);
    
    if ($error) {
        logMessage("Gemini cURL Error: " . $error);
        return "Desculpe, estou com dificuldades técnicas no momento. Por favor, tente novamente em alguns instantes.";
    }
    
    // Log truncado para não lotar o arquivo
    $respLog = strlen($resp) > 500 ? substr($resp, 0, 500) . "..." : $resp;
    logMessage("Gemini raw response: " . $respLog);

    $json = json_decode($resp, true);

    // Verifica se há erro na resposta
    if (isset($json['error'])) {
        logMessage("Gemini error: " . $json['error']['message']);
        return "Desculpe, estou com problemas técnicos no momento. Por favor, tente novamente mais tarde.";
    }

    // Extrai o texto retornado pela Gemini
    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
        return $json['candidates'][0]['content']['parts'][0]['text'];
    }

    // Log de estrutura inesperada para debug
    logMessage("Estrutura inesperada: " . substr(print_r($json, true), 0, 500));
    return "Desculpe, não consegui processar sua solicitação. Poderia reformular sua pergunta?";
}
