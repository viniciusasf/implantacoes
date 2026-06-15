<?php
require_once 'config.php';
header('Content-Type: application/json');

// Receber o corpo da requisição (JSON)
$input = json_decode(file_get_contents('php://input'), true);
$mensagem = isset($input['mensagem']) ? strtolower(trim($input['mensagem'])) : '';

if (empty($mensagem)) {
    echo json_encode(['contexto' => '']);
    exit;
}

$contextos = [];

// 1. GATILHO: Pesquisa de Cliente Específico
// Se o usuário falar sobre um cliente, tenta extrair palavras-chave e buscar no banco
if (strpos($mensagem, 'cliente') !== false) {
    // Remove palavras comuns para tentar isolar o nome do cliente
    $palavras_ignoradas = ['cliente', 'o', 'a', 'do', 'da', 'de', 'sobre', 'como', 'está', 'quem', 'é'];
    $palavras_mensagem = explode(' ', $mensagem);
    
    $termos_busca = [];
    foreach ($palavras_mensagem as $palavra) {
        $palavra_limpa = preg_replace('/[^a-z0-9]/', '', $palavra);
        if (strlen($palavra_limpa) > 3 && !in_array($palavra_limpa, $palavras_ignoradas)) {
            $termos_busca[] = $palavra_limpa;
        }
    }
    
    if (!empty($termos_busca)) {
        try {
            $condicoes = [];
            $parametros = [];
            foreach ($termos_busca as $termo) {
                $condicoes[] = "(fantasia LIKE ? OR razao_social LIKE ? OR vendedor LIKE ?)";
                $parametros[] = "%{$termo}%";
                $parametros[] = "%{$termo}%";
                $parametros[] = "%{$termo}%";
            }
            
            $sql = "SELECT id_cliente, fantasia, razao_social, vendedor, data_inicio, data_fim 
                    FROM clientes 
                    WHERE " . implode(' OR ', $condicoes) . " LIMIT 10";
                    
            $stmt = $pdo->prepare($sql);
            $stmt->execute($parametros);
            $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($clientes) > 0) {
                $ctx = "=== DADOS DE CLIENTES NO BANCO ===\n";
                foreach ($clientes as $c) {
                    $status = (empty($c['data_fim']) || $c['data_fim'] == '0000-00-00') ? "ATIVO (Em implantação)" : "ENCERRADO em " . date('d/m/Y', strtotime($c['data_fim']));
                    $data_inicio = !empty($c['data_inicio']) ? date('d/m/Y', strtotime($c['data_inicio'])) : 'N/A';
                    $ctx .= "- Cliente: {$c['fantasia']} (Vendedor: {$c['vendedor']}) | Início: {$data_inicio} | Status: {$status}\n";
                    
                    // Só busca o histórico e contatos se a busca for muito específica (poucos clientes)
                    // para evitar entupir a IA com excesso de texto
                    if (count($clientes) <= 3) {
                        
                        // Buscar os contatos deste cliente
                        $stmt_contato = $pdo->prepare("SELECT * FROM contatos WHERE id_cliente = ? LIMIT 5");
                        $stmt_contato->execute([$c['id_cliente']]);
                        $contatos = $stmt_contato->fetchAll(PDO::FETCH_ASSOC);

                        if (count($contatos) > 0) {
                            $ctx .= "  Contatos cadastrados:\n";
                            foreach ($contatos as $cont) {
                                $nome_contato = $cont['nome'] ?? 'Sem nome';
                                $email_contato = $cont['email'] ?? $cont['email_contato'] ?? $cont['e_mail'] ?? 'Sem email';
                                $telefone_contato = $cont['telefone'] ?? $cont['celular'] ?? $cont['whatsapp'] ?? 'Sem telefone';
                                $ctx .= "  * Nome: {$nome_contato} | Email: {$email_contato} | Tel: {$telefone_contato}\n";
                            }
                        } else {
                            $ctx .= "  Nenhum contato registrado para este cliente.\n";
                        }

                        // Buscar os treinamentos deste cliente
                        $stmt_trein = $pdo->prepare("SELECT tema, status, data_treinamento FROM treinamentos WHERE id_cliente = ? ORDER BY data_treinamento DESC LIMIT 15");
                        $stmt_trein->execute([$c['id_cliente']]);
                        $treinamentos = $stmt_trein->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (count($treinamentos) > 0) {
                            $ctx .= "  Treinamentos encontrados:\n";
                            foreach ($treinamentos as $t) {
                                $data_t = !empty($t['data_treinamento']) ? date('d/m/Y H:i', strtotime($t['data_treinamento'])) : 'Sem data';
                                $ctx .= "  * {$data_t} | Tema: {$t['tema']} | Status: {$t['status']}\n";
                            }
                        }
                        $ctx .= "\n";
                    }
                }
                $ctx .= "-----------------------------------\n";
                $contextos[] = $ctx;
            }
        } catch (Exception $e) {
            // Silencioso
        }
    }
}

// 2. GATILHO: Resumo Geral de Implantação
if (strpos($mensagem, 'resumo') !== false || strpos($mensagem, 'quantos clientes') !== false || strpos($mensagem, 'geral') !== false) {
    try {
        $ativos = $pdo->query("SELECT COUNT(*) FROM clientes WHERE (data_fim IS NULL OR data_fim = '0000-00-00')")->fetchColumn();
        $treinamentos_pend = $pdo->query("SELECT COUNT(*) FROM treinamentos WHERE UPPER(status) = 'PENDENTE'")->fetchColumn();
        
        $ctx = "=== RESUMO GERAL DO SISTEMA ===\n";
        $ctx .= "- Total de Clientes Ativos (Em implantação): {$ativos}\n";
        $ctx .= "- Total de Treinamentos Pendentes na fila: {$treinamentos_pend}\n";
        $ctx .= "-------------------------------\n";
        $contextos[] = $ctx;
    } catch (Exception $e) {
        // Silencioso
    }
}

// 3. GATILHO: Resumo de Vendedores (Quantidades)
if (strpos($mensagem, 'vendedor') !== false || strpos($mensagem, 'quantos clientes') !== false) {
    try {
        $sql_vendedores = "SELECT vendedor, COUNT(*) as total_clientes FROM clientes GROUP BY vendedor HAVING vendedor IS NOT NULL AND vendedor != '' ORDER BY total_clientes DESC";
        $stmt_vend = $pdo->query($sql_vendedores);
        $vendedores = $stmt_vend->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($vendedores) > 0) {
            $ctx = "=== QUANTIDADE DE CLIENTES POR VENDEDOR ===\n";
            foreach ($vendedores as $v) {
                $ctx .= "- Vendedor {$v['vendedor']} possui {$v['total_clientes']} cliente(s).\n";
            }
            $ctx .= "-------------------------------------------\n";
            $contextos[] = $ctx;
        }
    } catch (Exception $e) {
        // Silencioso
    }
}

// Junta todos os contextos encontrados
$resposta_contexto = implode("\n", $contextos);

echo json_encode([
    'contexto' => $resposta_contexto
]);
