<?php
// Teste simples para diagnosticar PDF generation
require_once 'config.php';

// Pega primeiro chamado
$stmt = $pdo->query('SELECT id_chamado_api FROM chamados_espelho_local LIMIT 1');
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    die('Nenhum chamado encontrado!');
}

$testId = $row['id_chamado_api'];
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Teste PDF Generator</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        button { padding: 10px 20px; background: #2d6df6; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        button:hover { background: #1a4ec9; }
        .log { background: #222; color: #0f0; padding: 15px; border-radius: 4px; margin-top: 20px; font-family: monospace; font-size: 12px; max-height: 400px; overflow-y: auto; }
        .log-line { margin: 4px 0; }
        .error { color: #ff4444; }
        .success { color: #44ff44; }
        .info { color: #44ccff; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Teste do Gerador PDF</h1>
        <p>Chamado ID: <strong><?= htmlspecialchars($testId) ?></strong></p>
        
        <button onclick="testarPDF()">Gerar PDF (Teste)</button>
        <button onclick="limparLog()" style="background: #666;">Limpar Log</button>
        <button onclick="abrirLogFile()" style="background: #888;">Abrir Arquivo de Log</button>
        
        <div class="log" id="log">
            <div class="log-line info">[AGUARDANDO...]</div>
        </div>
    </div>

    <script>
        const logDiv = document.getElementById('log');
        
        function addLog(message, type = 'info') {
            const line = document.createElement('div');
            line.className = 'log-line ' + type;
            line.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
            logDiv.appendChild(line);
            logDiv.scrollTop = logDiv.scrollHeight;
        }
        
        function limparLog() {
            logDiv.innerHTML = '';
        }
        
        function abrirLogFile() {
            window.open('logs/pdf_generator.log', '_blank');
        }
        
        async function testarPDF() {
            limparLog();
            addLog('Iniciando teste de geração de PDF...', 'info');
            addLog('Chamado: <?= $testId ?>', 'info');
            
            try {
                addLog('Enviando requisição para gerar_pdf_chamado.php?id=<?= $testId ?>', 'info');
                
                const response = await fetch('gerar_pdf_chamado.php?id=<?= $testId ?>');
                
                addLog(`HTTP Status: ${response.status}`, 'info');
                addLog(`Content-Type: ${response.headers.get('content-type')}`, 'info');
                
                const text = await response.text();
                addLog(`Response length: ${text.length} bytes`, 'info');
                
                if (!response.ok) {
                    addLog(`⚠️ Response não OK: ${text}`, 'error');
                    return;
                }
                
                let data;
                try {
                    data = JSON.parse(text);
                    addLog('✓ JSON decodificado com sucesso', 'success');
                } catch (e) {
                    addLog(`✗ Erro ao decodificar JSON: ${e.message}`, 'error');
                    addLog(`Primeiro 200 chars: ${text.substring(0, 200)}`, 'error');
                    return;
                }
                
                addLog(`Sucesso: ${data.sucesso}`, data.sucesso ? 'success' : 'error');
                addLog(`Link: ${data.link || '(vazio)'}`, 'info');
                addLog(`Filename: ${data.filename || '(vazio)'}`, 'info');
                addLog(`Folder Link: ${data.folder_link || '(vazio)'}`, 'info');
                
                if (data.erro) {
                    addLog(`Erro: ${data.erro}`, 'error');
                }
                
                if (data.link) {
                    addLog(`✓ Link gerado! Abrindo em nova aba...`, 'success');
                    window.open(data.link, '_blank');
                } else {
                    addLog(`✗ Nenhum link foi gerado`, 'error');
                }
                
            } catch (err) {
                addLog(`✗ Erro de rede: ${err.message}`, 'error');
            }
            
            addLog('Verifique também: logs/pdf_generator.log', 'info');
        }
    </script>
</body>
</html>
