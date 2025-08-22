<?php
// webhook.php

$logFile = __DIR__ . "/webhook_log.txt";
$contextFile = __DIR__ . "/context_cache.json";

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
        // Carrega contexto anterior se existir
        $context = loadContext($phoneNumber);
        
        // Verifica se é um comando especial primeiro
        $reply = handleSpecialCommands($body, $phoneNumber, $context);
        
        // Se não é comando especial, chama a Gemini com contexto
        if ($reply === null) {
            $reply = callGeminiAI($body, $context);
            
            // Atualiza o contexto com a nova interação
            updateContext($phoneNumber, $body, $reply);
            
            // Adiciona assinatura do autor (apenas na primeira mensagem da conversa)
            if (empty($context['conversation'])) {
                $reply .= "\n\n---\n*Assistente IA criado por Milton Diogo*";
            }
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
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
    return preg_replace('/@.*/', '', $from);
}

function loadContext($phoneNumber) {
    $contextFile = __DIR__ . "/context_cache.json";
    
    if (!file_exists($contextFile)) {
        return ['conversation' => []];
    }
    
    $contextData = json_decode(file_get_contents($contextFile), true) ?? ['conversations' => []];
    
    // Limpa contextos antigos (mais de 1 hora)
    if (isset($contextData['conversations'])) {
        foreach ($contextData['conversations'] as $number => $conversation) {
            if (time() - $conversation['last_activity'] > 3600) { // 1 hora
                unset($contextData['conversations'][$number]);
            }
        }
    }
    
    return $contextData['conversations'][$phoneNumber] ?? [
        'conversation' => [],
        'last_activity' => time(),
        'message_count' => 0
    ];
}

function saveContext($contextData) {
    $contextFile = __DIR__ . "/context_cache.json";
    
    // Mantém apenas as 50 conversas mais recentes para não sobrecarregar
    if (count($contextData['conversations']) > 50) {
        // Ordena por última atividade e mantém apenas as 50 mais recentes
        uasort($contextData['conversations'], function($a, $b) {
            return $b['last_activity'] - $a['last_activity'];
        });
        $contextData['conversations'] = array_slice($contextData['conversations'], 0, 50, true);
    }
    
    file_put_contents($contextFile, json_encode($contextData));
}

function updateContext($phoneNumber, $userMessage, $assistantReply) {
    $contextFile = __DIR__ . "/context_cache.json";
    
    // Carrega contexto existente
    $contextData = file_exists($contextFile) ? 
                  json_decode(file_get_contents($contextFile), true) : 
                  ['conversations' => []];
    
    // Inicializa se não existir
    if (!isset($contextData['conversations'][$phoneNumber])) {
        $contextData['conversations'][$phoneNumber] = [
            'conversation' => [],
            'last_activity' => time(),
            'message_count' => 0
        ];
    }
    
    // Adiciona nova mensagem ao histórico (limita a 10 trocas)
    $contextData['conversations'][$phoneNumber]['conversation'][] = [
        'user' => $userMessage,
        'assistant' => $assistantReply,
        'timestamp' => time()
    ];
    
    // Mantém apenas as últimas 10 trocas
    if (count($contextData['conversations'][$phoneNumber]['conversation']) > 10) {
        array_shift($contextData['conversations'][$phoneNumber]['conversation']);
    }
    
    // Atualiza contador e última atividade
    $contextData['conversations'][$phoneNumber]['last_activity'] = time();
    $contextData['conversations'][$phoneNumber]['message_count']++;
    
    saveContext($contextData);
}

function handleSpecialCommands($message, $phoneNumber, $context) {
    $message = strtolower(trim($message));
    
    switch ($message) {
        case '/start':
        case 'iniciar':
        case 'oi':
        case 'olá':
        case 'ola':
            // Limpa o contexto ao iniciar nova conversa
            clearContext($phoneNumber);
            return "Olá! 👋 Eu sou um assistente virtual inteligente.\n\n".
                   "Digite /ajuda para ver os comandos disponíveis.\n".
                   "Digite /sobre para saber mais sobre mim.\n".
                   "Ou faça qualquer pergunta que eu tentarei ajudar!\n\n".
                   "*Desenvolvido por Milton Diogo*";
            
        case '/ajuda':
        case 'ajuda':
        case 'help':
            return "🤖 *Comandos disponíveis:*\n\n".
                   "• /start - Iniciar nova conversa (limpa histórico)\n".
                   "• /ajuda - Ver esta mensagem\n".
                   "• /sobre - Informações sobre mim\n".
                   "• /criador - Quem me desenvolveu\n".
                   "• /limpar - Limpar o histórico da conversa\n\n".
                   "Ou simplemente faça uma pergunta e eu tentarei ajudar!";
            
        case '/sobre':
        case 'sobre':
            return "🤖 *Sobre mim:*\n\n".
                   "Eu sou um assistente virtual baseado na tecnologia Gemini AI 2.5 Flash.\n".
                   "Fui desenvolvido para responder perguntas e ajudar com informações diversas.\n\n".
                   "Versão: 1.0\n".
                   "Criador: Milton Diogo";
            
        case '/criador':
        case 'criador':
            return "👨‍💻 *Meu criador:*\n\n".
                   "Fui desenvolvido por Milton Diogo como projeto de chatbot WhatsApp.\n".
                   "Estou sempre evoluindo com novas funcionalidades!\n\n".
                   "Email: mwmprogramador@gmail.com\n".
                   "Celular: 959642430";
            
        case '/limpar':
        case 'limpar':
        case 'clear':
            clearContext($phoneNumber);
            return "Histórico da conversa limpo! Começamos uma nova conversa.";
            
        default:
            return null; // Não é um comando especial
    }
}

function clearContext($phoneNumber) {
    $contextFile = __DIR__ . "/context_cache.json";
    
    if (file_exists($contextFile)) {
        $contextData = json_decode(file_get_contents($contextFile), true) ?? ['conversations' => []];
        unset($contextData['conversations'][$phoneNumber]);
        saveContext($contextData);
    }
}

// Função para chamar a API Gemini com contexto
function callGeminiAI($message, $context) {
    $apiKey = "AIzaSyD7DTONO7vq9jws-pIihvoiQd4RI03pRTU";
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent";

    // Personalidade do assistente com instrução para usar contexto
    $systemInstruction = "Você é um assistente prestativo chamado 'AssistenteIA'. " .
                         "Responda de forma amigável e concisa. Use emojis ocasionalmente. " .
                         "Se não souber algo, admita honestamente. Mantenha respostas em português. " .
                         "Use o histórico da conversa para contextualizar suas respostas quando relevante.";

    // Prepara o contexto da conversa
    $contextMessages = [];
    if (!empty($context['conversation'])) {
        foreach ($context['conversation'] as $exchange) {
            $contextMessages[] = ["role" => "user", "parts" => [["text" => $exchange['user']]]];
            $contextMessages[] = ["role" => "model", "parts" => [["text" => $exchange['assistant']]]];
        }
    }
    
    // Adiciona a mensagem atual
    $contextMessages[] = ["role" => "user", "parts" => [["text" => $message]]];

    // Monta JSON no padrão oficial da Gemini
    $data = [
        "systemInstruction" => [
            "parts" => [
                ["text" => $systemInstruction]
            ]
        ],
        "contents" => $contextMessages,
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
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
