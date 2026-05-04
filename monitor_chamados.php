<?php
require_once 'funcoes_monitor.php';

registrarLog("Iniciando ciclo de monitoramento.");

$html = obterHtmlChamados();
if (!$html) {
    registrarLog("Falha ao obter HTML. Encerrando.");
    exit;
}

$itensAtuais = extrairDadosGrade($html);
$totalLidos = count($itensAtuais);
registrarLog("Total de chamados filtrados encontrados: $totalLidos");

$anteriores = carregarSnapshotAnterior($pdo);
$anterioresMap = [];
foreach ($anteriores as $a) {
    $anterioresMap[$a['codigo_chamado']] = $a;
}

$atuaisMap = [];
foreach ($itensAtuais as $i) {
    $atuaisMap[$i['codigo']] = $i;
}

$contadores = ['entradas' => 0, 'saidas' => 0, 'alteracoes' => 0];

// 1. Detectar Entradas e Alterações
foreach ($itensAtuais as $atual) {
    $codigo = $atual['codigo'];
    
    if (!isset($anterioresMap[$codigo])) {
        // ENTRADA
        registrarLog("NOVO CHAMADO: #$codigo - {$atual['cliente']}");
        registrarEvento($pdo, 'ENTRADA', $codigo, null, $atual);
        $contadores['entradas']++;
    } else {
        $anterior = $anterioresMap[$codigo];
        if ($atual['hash'] !== $anterior['hash_linha']) {
            // ALTERAÇÃO
            registrarLog("ALTERAÇÃO DETECTADA: #$codigo");
            registrarEvento($pdo, 'ALTERAÇÃO', $codigo, $anterior, $atual);
            $contadores['alteracoes']++;
            
            // Atualizar data de última mudança no snapshot
            $stmt = $pdo->prepare("UPDATE monitor_chamados_snapshot SET ultima_mudanca = NOW() WHERE codigo_chamado = ?");
            $stmt->execute([$codigo]);
        }
    }
}

// 2. Detectar Saídas
foreach ($anterioresMap as $codigo => $anterior) {
    if (!isset($atuaisMap[$codigo])) {
        // SAÍDA
        registrarLog("CHAMADO REMOVIDO/CONCLUÍDO: #$codigo");
        registrarEvento($pdo, 'SAÍDA', $codigo, $anterior, null);
        $contadores['saidas']++;
        
        // Remover do snapshot
        $stmt = $pdo->prepare("DELETE FROM monitor_chamados_snapshot WHERE codigo_chamado = ?");
        $stmt->execute([$codigo]);
    }
}

// 3. Atualizar/Inserir Snapshots atuais
atualizarSnapshot($pdo, $itensAtuais);

registrarLog("Ciclo finalizado. E:{$contadores['entradas']} | S:{$contadores['saidas']} | A:{$contadores['alteracoes']}");

// Exibição simples se rodar via CLI
if (php_sapi_name() === 'cli') {
    echo "Ciclo finalizado.\n";
    echo "Entradas: {$contadores['entradas']}\n";
    echo "Saídas: {$contadores['saidas']}\n";
    echo "Alterações: {$contadores['alteracoes']}\n";
}
?>
