<?php
date_default_timezone_set('America/Sao_Paulo');
require_once 'config.php';
require_once 'config_monitor.php';

/**
 * Realiza o login via Next.js Server Action e retorna o HTML da página de chamados.
 * O sistema GestãoPRO usa Next.js com Server Actions.
 * O actionId '40a8914592f6484943d9ac95221f66e3c59bf3283b' corresponde à função de login.
 * Os campos são 'login' e 'senha'.
 */
function obterHtmlChamados() {
    $user = MONITOR_USER;
    $pass = MONITOR_PASS;

    if (empty($user) || empty($pass)) {
        registrarLog("ERRO: Usuário ou senha não configurados em config_monitor.php");
        return false;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, MONITOR_COOKIE_FILE);
    curl_setopt($ch, CURLOPT_COOKIEFILE, MONITOR_COOKIE_FILE);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    
    // 1. Tentar acessar a página de chamados diretamente (sessão pode já estar ativa)
    curl_setopt($ch, CURLOPT_URL, MONITOR_URL_CHAMADOS);
    $output = curl_exec($ch);
    
    // Verificar se fomos redirecionados para o login
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if (strpos($effectiveUrl, '/login') !== false) {
        registrarLog("Sessão expirada ou inexistente. Tentando login via Server Action...");
        
        // 2. Primeiro GET na página de login para obter cookies iniciais
        curl_setopt($ch, CURLOPT_URL, MONITOR_URL_LOGIN);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_exec($ch);
        
        // 3. Realizar o login via Next.js Server Action
        // O Server Action usa POST com o header 'Next-Action' contendo o actionId
        $actionId = '40a8914592f6484943d9ac95221f66e3c59bf3283b';
        
        // O payload é enviado como JSON array (formato RSC)
        $payload = json_encode([
            ['login' => $user, 'senha' => $pass, 'from' => '/chamados']
        ]);
        
        $routerStateTree = json_encode(["", [["login", [["__PAGE__", new stdClass()]]], "\$undefined", "\$undefined", true]]);
        
        curl_setopt($ch, CURLOPT_URL, MONITOR_URL_LOGIN);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Não seguir redirect para capturar cookies
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: text/plain;charset=UTF-8',
            'Accept: text/x-component',
            'Next-Action: ' . $actionId,
            'Next-Router-State-Tree: ' . $routerStateTree,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        
        $loginResponse = curl_exec($ch);
        $loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        registrarLog("Login Server Action respondeu HTTP $loginCode");
        
        // Salvar resposta para debug
        file_put_contents(__DIR__ . '/logs/debug_last_login_response.txt', $loginResponse);
        
        // HTTP 303 = redirect pós-login = SUCESSO
        // HTTP 200 com erro no body = FALHA
        if ($loginCode === 200 && strpos($loginResponse, 'error') !== false) {
            registrarLog("ERRO: Credenciais inválidas. Verifique login e senha.");
            curl_close($ch);
            return false;
        }
        
        if ($loginCode !== 303 && $loginCode !== 302 && $loginCode !== 200) {
            registrarLog("ERRO: Resposta inesperada HTTP $loginCode.");
            curl_close($ch);
            return false;
        }
        
        registrarLog("Login aceito (HTTP $loginCode). Acessando chamados...");
        
        // 4. Após login, acessar a página de chamados
        curl_setopt($ch, CURLOPT_URL, MONITOR_URL_CHAMADOS);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, []);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Reativar redirect
        $output = curl_exec($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        
        if (strpos($effectiveUrl, '/login') !== false) {
            registrarLog("ERRO: Login aparentemente não funcionou. Ainda sendo redirecionado para /login.");
            registrarLog("Resposta do Server Action salva em logs/debug_last_login_response.txt");
            curl_close($ch);
            return false;
        }
        
        registrarLog("Login realizado com sucesso!");
    }
    
    // Salvar HTML para debug (pode ser removido depois)
    file_put_contents(__DIR__ . '/logs/debug_chamados_html.html', $output);
    
    curl_close($ch);
    return $output;
}

/**
 * Extrai os dados da grade HTML
 * Nota: Esta função precisará ser ajustada conforme a estrutura real do HTML
 */
function extrairDadosGrade($html) {
    if (!$html) return [];
    
    $dados = [];
    
    // Tenta usar DOMDocument para fazer o parse da tabela
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    
    // Procura por tabelas ou linhas que pareçam ser da grade de chamados
    // Se o sistema usa Next.js, os dados podem estar em um JSON dentro de uma tag <script id="__NEXT_DATA__">
    $nextData = $xpath->query('//script[@id="__NEXT_DATA__"]');
    if ($nextData->length > 0) {
        $json = json_decode($nextData->item(0)->nodeValue, true);
        // Aqui precisaríamos navegar no JSON para encontrar a lista de chamados
        // Exemplo: $chamados = $json['props']['pageProps']['initialData']['tickets'] ?? [];
        // Como não sabemos a estrutura exata, vamos tentar o parsing via DOM também.
    }

    // Fallback: Parsing via DOM table rows
    $rows = $xpath->query('//table//tr');
    if ($rows->length > 0) {
        foreach ($rows as $i => $row) {
            if ($i === 0) continue; // Pular cabeçalho
            
            $cols = $xpath->query('td', $row);
            if ($cols->length >= 8) {
                $item = [
                    'codigo' => trim($cols->item(0)->nodeValue),
                    'cliente' => trim($cols->item(1)->nodeValue),
                    'tipo' => trim($cols->item(2)->nodeValue),
                    'status' => normalizarStatus(trim($cols->item(3)->nodeValue)),
                    'data' => trim($cols->item(4)->nodeValue),
                    'responsavel' => trim($cols->item(5)->nodeValue),
                    'previsao' => trim($cols->item(6)->nodeValue),
                    'resp_tecnico' => trim($cols->item(7)->nodeValue),
                    'descricao' => trim($cols->item(8)->nodeValue ?? ''),
                    'retorno' => trim($cols->item(9)->nodeValue ?? '')
                ];
                
                // Aplicar filtros
                if ($item['responsavel'] === MONITOR_RESPONSAVEL && in_array($item['status'], MONITOR_STATUS_VALIDOS)) {
                    $item['hash'] = hash('sha256', json_encode($item));
                    $dados[] = $item;
                }
            }
        }
    }
    
    return $dados;
}

function normalizarStatus($status) {
    if ($status === 'Aguardando Desenv.') return 'Aguardando Desenvolvimento';
    return $status;
}

function registrarLog($mensagem) {
    $data = date('Y-m-d H:i:s');
    file_put_contents(MONITOR_LOG_FILE, "[$data] $mensagem\n", FILE_APPEND);
}

function carregarSnapshotAnterior($pdo) {
    $stmt = $pdo->query("SELECT * FROM monitor_chamados_snapshot");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function registrarEvento($pdo, $evento, $codigo, $anterior = null, $novo = null) {
    $sql = "INSERT INTO monitor_chamados_historico (
        codigo_chamado, evento, status_anterior, status_novo, 
        previsao_anterior, previsao_nova, resp_tecnico_anterior, resp_tecnico_novo,
        payload_anterior, payload_novo, data_evento
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $codigo,
        $evento,
        $anterior['status_atual'] ?? null,
        $novo['status'] ?? null,
        $anterior['previsao'] ?? null,
        $novo['previsao'] ?? null,
        $anterior['responsavel_tecnico'] ?? null,
        $novo['resp_tecnico'] ?? null,
        $anterior ? json_encode($anterior) : null,
        $novo ? json_encode($novo) : null
    ]);
}

function atualizarSnapshot($pdo, $itens) {
    // Limpar snapshot antigo e inserir novo (ou fazer sync inteligente)
    // Para simplificar, vamos usar REPLACE INTO ou UPDATE/INSERT
    foreach ($itens as $item) {
        $sql = "REPLACE INTO monitor_chamados_snapshot (
            codigo_chamado, cliente, tipo, status_atual, data_chamado, 
            responsavel, previsao, responsavel_tecnico, descricao, retorno, 
            hash_linha, ultima_leitura
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $item['codigo'], $item['cliente'], $item['tipo'], $item['status'], $item['data'],
            $item['responsavel'], $item['previsao'], $item['resp_tecnico'], $item['descricao'], $item['retorno'],
            $item['hash']
        ]);
    }
}
?>
