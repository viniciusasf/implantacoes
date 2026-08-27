<?php
require_once 'config.php';
require_once 'vendor/autoload.php';
require_once 'google_drive_functions.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');

// Sistema de logging robusto - escreve direto no arquivo
$logFile = __DIR__ . '/logs/pdf_generator.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}

function logDebug($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] {$message}\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
}

logDebug('=== INICIANDO GERAÇÃO DE PDF ===');
logDebug('GET params: ' . json_encode($_GET));

if (empty($_GET['id']) || !is_numeric($_GET['id'])) {
    logDebug('ERRO: ID inválido');
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'ID do chamado inválido.']);
    exit;
}

$idChamado = (int) $_GET['id'];

// Altere para o ID da pasta 'PDF' no Google Drive, dentro de Meu Drive > CLIENTES > PDF.
const GOOGLE_DRIVE_PDF_ROOT_FOLDER_ID = '1FSF59RSUBdsk8654DHJs9uFd4ZyVfW3k';

try {
    $stmt = $pdo->prepare('SELECT * FROM chamados_espelho_local WHERE id_chamado_api = ?');
    $stmt->execute([$idChamado]);
    $chamado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$chamado) {
        http_response_code(404);
        echo json_encode(['sucesso' => false, 'erro' => 'Chamado não encontrado no espelho local.']);
        exit;
    }

    // Sincroniza os dados mais recentes da API antes de gerar o comprovante
    try {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        $baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $apiUrl = $protocol . $host . $baseDir . "/api_gestaopro_bridge.php?endpoint=chamados/" . $idChamado;

        $context = stream_context_create([
            "ssl" => [
                "verify_peer" => false,
                "verify_peer_name" => false,
            ]
        ]);

        $apiResponse = @file_get_contents($apiUrl, false, $context);
        if ($apiResponse) {
            $apiData = json_decode($apiResponse, true);

            if (!empty($apiData['sucesso']) && !empty($apiData['dados']) && is_array($apiData['dados'])) {
                $apiDados = $apiData['dados'];

                $apiStatus = $apiDados['CHAMADO_STATUS'] ?? $apiDados['STATUS'] ?? $apiDados['status'] ?? null;
                if ($apiStatus !== null && trim((string) $apiStatus) !== '') {
                    $chamado['status_chamado'] = (string) $apiStatus;
                }

                if (isset($apiDados['status']) && is_string($apiDados['status']) && trim($apiDados['status']) !== '' && !isset($apiDados['CHAMADO_STATUS'])) {
                    $chamado['status_chamado'] = trim($apiDados['status']);
                }

                $apiTipo = $apiDados['TIPOACOMP'] ?? $apiDados['TIPO_ACOMPANHAMENTO'] ?? $apiDados['tipo'] ?? null;
                if ($apiTipo !== null && trim((string) $apiTipo) !== '') {
                    $chamado['tipo_acompanhamento'] = (string) $apiTipo;
                }

                $apiResponsavel = $apiDados['RESPONSAVEL'] ?? $apiDados['responsavel'] ?? null;
                if ($apiResponsavel !== null && trim((string) $apiResponsavel) !== '') {
                    $chamado['responsavel'] = (string) $apiResponsavel;
                }

                $apiDescricao = $apiDados['DESCRICAO'] ?? $apiDados['descricao'] ?? null;
                if ($apiDescricao !== null && trim((string) $apiDescricao) !== '') {
                    $chamado['descricao_problema'] = (string) $apiDescricao;
                }

                $apiDataPrev = $apiDados['DATAPREV_RETORNO'] ?? $apiDados['DATAPREV'] ?? $apiDados['data_prev_retorno'] ?? null;
                if ($apiDataPrev !== null && trim((string) $apiDataPrev) !== '') {
                    $chamado['data_prev_retorno'] = (string) $apiDataPrev;
                }

                $stmtSync = $pdo->prepare('UPDATE chamados_espelho_local SET status_chamado = ?, tipo_acompanhamento = ?, responsavel = ?, descricao_problema = ?, data_prev_retorno = ? WHERE id_chamado_api = ?');
                $stmtSync->execute([
                    $chamado['status_chamado'] ?? null,
                    $chamado['tipo_acompanhamento'] ?? null,
                    $chamado['responsavel'] ?? null,
                    $chamado['descricao_problema'] ?? null,
                    $chamado['data_prev_retorno'] ?? null,
                    $idChamado,
                ]);
            }
        }
    } catch (Throwable $e) {
        // Ignora erro e continua com o valor local se a API falhar
    }

    $html = gerarHtmlComprovanteChamado($chamado);

    // O comprovante passa a ser persistido como HTML, preservando o CSS do layout.
    $htmlContent = $html;

    if ($htmlContent === '') {
        throw new RuntimeException('HTML do comprovante gerado vazio ou inválido.');
    }

    $clienteNome = trim((string) ($chamado['nome_fantasia'] ?? ''));
    $clienteId = !empty($chamado['id_cliente_api']) ? (int) $chamado['id_cliente_api'] : null;
    $folderName = sanitizeFolderName($clienteNome);
    if ($folderName === '') {
        $folderName = $clienteId ? 'cliente_' . $clienteId : 'cliente_desconhecido';
    } elseif ($clienteId) {
        $folderName .= '_' . $clienteId;
    }

    logDebug('HTML gerado com sucesso');

    $service = null;
    $fileLink = null;
    $folderLink = null;

    try {
        logDebug('Iniciando upload Google Drive');
        set_time_limit(60);
        
        $service = driveGetService();
        $clientFolderId = driveFindOrCreateFolder($service, $folderName, GOOGLE_DRIVE_PDF_ROOT_FOLDER_ID);

        $fileName = sprintf('chamado_suporte_%d.html', $idChamado);
        $fileId = null;

        if (!empty($chamado['drive_file_id'])) {
            try {
                $updatedFile = driveUpdateExistingFile($service, $chamado['drive_file_id'], $fileName, $htmlContent, 'text/html');
                $fileId = $updatedFile->id;
                driveMoveFileToFolder($service, $fileId, $clientFolderId);
                logDebug('Arquivo atualizado no Drive');
            } catch (Throwable $e) {
                logDebug('Erro ao atualizar arquivo: ' . $e->getMessage());
                $fileId = null;
            }
        }

        if (empty($fileId)) {
            $existingFileId = driveFindFileInFolder($service, $clientFolderId, $fileName, 'text/html');
            if ($existingFileId) {
                $updatedFile = driveUpdateExistingFile($service, $existingFileId, $fileName, $htmlContent, 'text/html');
                $fileId = $updatedFile->id;
                logDebug('Arquivo existente atualizado');
            }
        }

        if (empty($fileId)) {
            $createdFile = driveUploadNewFile($service, $clientFolderId, $fileName, $htmlContent, 'text/html');
            $fileId = $createdFile->id;
            logDebug('Novo arquivo criado no Drive');
        }

        if (!empty($fileId)) {
            driveMoveFileToFolder($service, $fileId, $clientFolderId);
            driveEnsureAnyoneLink($service, $fileId);
            $fileLink = driveGetFileLink($service, $fileId);
            logDebug('Link gerado: ' . substr($fileLink, 0, 50) . '...');
        }
        
        $folderLink = 'https://drive.google.com/drive/folders/' . $clientFolderId;
        logDebug('Folder link: ' . $folderLink);

    } catch (Throwable $e) {
        logDebug('Erro ao fazer upload no Drive: ' . $e->getMessage());
        // Fallback: sem link do Drive
        $fileLink = null;
        $folderLink = null;
    }

    // Se conseguiu gerar o PDF mas não conseguiu colocar no Drive, tenta apenas retornar sucesso
    // (o usuário pode regenerar se necessário)
    if (empty($fileLink) && !empty($htmlContent)) {
        logDebug('Fallback: gerando link local como backup');
        // Gera um link de download direto como fallback
        $pdfDir = __DIR__ . '/pdfs';
        if (!is_dir($pdfDir)) {
            @mkdir($pdfDir, 0777, true);
        }
        $htmlFile = $pdfDir . '/chamado_' . $idChamado . '_' . time() . '.html';
        if (file_put_contents($htmlFile, $htmlContent)) {
            $fileLink = 'pdfs/' . basename($htmlFile);
            logDebug('Link local gerado: ' . $fileLink);
        }
    }

    // Tenta atualizar o banco mesmo se o Drive falhou
    try {
        $columnsToAdd = [
            'drive_file_id' => 'VARCHAR(255) NULL',
            'drive_file_name' => 'VARCHAR(255) NULL',
            'drive_pdf_link' => 'VARCHAR(500) NULL',
            'drive_pdf_gerado_em' => 'DATETIME NULL',
        ];

        foreach ($columnsToAdd as $column => $definition) {
            $columnName = $column;
            $checkColumn = $pdo->query("SHOW COLUMNS FROM chamados_espelho_local LIKE '{$columnName}'");
            if (!$checkColumn->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec("ALTER TABLE chamados_espelho_local ADD COLUMN {$columnName} {$definition}");
            }
        }

        if (!empty($fileLink)) {
            $stmtUpdate = $pdo->prepare('UPDATE chamados_espelho_local SET drive_pdf_link = ?, drive_pdf_gerado_em = NOW() WHERE id_chamado_api = ?');
            $stmtUpdate->execute([$fileLink, $idChamado]);
            logDebug('Banco de dados atualizado');
        }
    } catch (Throwable $e) {
        logDebug('Erro ao atualizar banco: ' . $e->getMessage());
    }

    logDebug('Finalizando com sucesso');

    echo json_encode([
        'sucesso' => true,
        'link' => $fileLink ?: '',
        'html' => $html,
        'filename' => 'chamado_suporte_' . $idChamado . '.html',
        'folder_link' => $folderLink ?: ''
    ]);
    exit;
} catch (Throwable $e) {
    logDebug('ERRO FATAL: ' . $e->getMessage() . ' - ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro ao gerar comprovante: ' . $e->getMessage()]);
    exit;
}

function sanitizeFolderName(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/[\x00-\x1f\x7f]/u', '', $text);
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = preg_replace('/[^a-zA-Z0-9\- _]/', '', $text);
    $text = preg_replace('/[\s_]+/', '-', $text);
    $text = trim($text, '-');
    return mb_strtolower($text, 'UTF-8');
}

function gerarHtmlComprovanteChamado(array $chamado)
{
    $idChamado = (int) ($chamado['id_chamado_api'] ?? 0);
    $cliente = trim((string) ($chamado['nome_fantasia'] ?: 'Cliente'));
    $status = trim((string) ($chamado['status_chamado'] ?: 'Aguardando Suporte'));
    $tipo = trim((string) ($chamado['tipo_acompanhamento'] ?: '—'));
    $responsavel = trim((string) ($chamado['responsavel'] ?: '—'));
    $descricao = trim((string) ($chamado['descricao_problema'] ?: '—'));
    $dataPrev = $chamado['data_prev_retorno'] ? (new DateTime($chamado['data_prev_retorno']))->format('d/m/Y') : '—';
    $dataImportacao = $chamado['data_importacao'] ? (new DateTime($chamado['data_importacao']))->format('d/m/Y H:i') : date('d/m/Y H:i');

    // Carrega o logotipo real da empresa em base64 para ficar embutido no HTML
    $logoPath = __DIR__ . '/css/pics/logo.webp';
    $logoBase64 = '';
    if (file_exists($logoPath)) {
        $logoBase64 = 'data:image/webp;base64,' . base64_encode(file_get_contents($logoPath));
    }
    $responsavelInicial = strtoupper(mb_substr($responsavel === '—' ? 'V' : $responsavel, 0, 1, 'UTF-8'));

    $textoPrazo = '';
    if (!empty($chamado['data_prev_retorno'])) {
        $dtPrev = new DateTime($chamado['data_prev_retorno']);
        $dtPrev->setTime(0, 0, 0);

        $dtImp = !empty($chamado['data_importacao']) ? new DateTime($chamado['data_importacao']) : new DateTime();
        $dtImp->setTime(0, 0, 0);

        $diff = $dtImp->diff($dtPrev);
        $dias = $diff->days;

        if ($diff->invert && $dias > 0) {
            $textoPrazo = 'atrasado ' . $dias . ($dias == 1 ? ' dia' : ' dias');
        } elseif ($dias == 0) {
            $textoPrazo = 'hoje';
        } else {
            $textoPrazo = 'em ' . $dias . ($dias == 1 ? ' dia útil' : ' dias úteis');
        }
    }

    $clienteEsc = htmlspecialchars($cliente, ENT_QUOTES, 'UTF-8');
    $statusEsc = htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
    $tipoEsc = htmlspecialchars($tipo, ENT_QUOTES, 'UTF-8');
    $responsavelEsc = htmlspecialchars($responsavel, ENT_QUOTES, 'UTF-8');
    $responsavelInicialEsc = htmlspecialchars($responsavelInicial, ENT_QUOTES, 'UTF-8');
    $descricaoEsc = htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8');
    $dataPrevEsc = htmlspecialchars($dataPrev, ENT_QUOTES, 'UTF-8');
    $dataImportacaoEsc = htmlspecialchars($dataImportacao, ENT_QUOTES, 'UTF-8');
    $idChamadoEsc = htmlspecialchars((string) $idChamado, ENT_QUOTES, 'UTF-8');
    $textoPrazoEsc = $textoPrazo !== '' ? '<div class="eta-note">' . htmlspecialchars($textoPrazo, ENT_QUOTES, 'UTF-8') . '</div>' : '';

    // Tag HTML do logo pré-computada (ternário não funciona dentro de heredoc)
    if ($logoBase64 !== '') {
        $logoHtml = '<img src="' . $logoBase64 . '" alt="GestãoPro" class="brand-logo">';
    } else {
        $logoHtml = '<div class="brand-name">Gestão<span>Pro</span></div>';
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Chamado #{$idChamadoEsc} · GestãoPro</title>
  <style>
    :root {
      --navy-900: #10213d;
      --blue-600: #2d6df6;
      --blue-100: #eaf1ff;
      --amber-600: #f4b942;
      --amber-100: #fff4d6;
      --amber-700: #8d5a05;
      --slate-100: #f3f6fb;
      --slate-200: #e7edf6;
      --slate-500: #5d6980;
      --slate-700: #2d3a53;
      --ink: #132238;
      --white: #ffffff;
    }
    * { box-sizing: border-box; }
    html, body {
      margin: 0;
      padding: 0;
      font-family: "Segoe UI", Arial, Helvetica, sans-serif;
      background: linear-gradient(180deg, #eef3fb 0%, #f8faff 100%);
      color: var(--ink);
      line-height: 1.5;
    }
    body { padding: 12px 12px 14px; }
    .pdf-shell { max-width: 820px; margin: 0 auto; }
    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 6px;
      flex-wrap: wrap;
    }
    .brand { display: flex; align-items: center; gap: 12px; }
    .brand-logo {
      height: 42px;
      width: auto;
      display: block;
      object-fit: contain;
    }
    .brand-name { font-size: 18px; font-weight: 800; letter-spacing: -0.04em; color: var(--navy-900); }
    .brand-name span { color: var(--blue-600); }
    .ticket-tag {
      background: rgba(255, 255, 255, 0.9);
      border: 1px solid var(--slate-200);
      border-radius: 999px;
      padding: 4px 8px;
      font-size: 10px;
      color: var(--slate-500);
      letter-spacing: 0.02em;
    }
    .ticket-tag strong { color: var(--navy-900); font-weight: 700; }
    .card {
      background: var(--white);
      border: 1px solid var(--slate-200);
      border-radius: 24px;
      overflow: hidden;
      box-shadow: 0 18px 42px -30px rgba(16, 33, 61, 0.18);
    }
    .card-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 10px;
      padding: 10px 12px 8px;
      background: linear-gradient(135deg, #f9fbff 0%, #eef4ff 100%);
      border-bottom: 1px solid var(--slate-200);
    }
    .eyebrow {
      display: inline-block;
      font-size: 10px;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: var(--slate-500);
      font-weight: 700;
      margin-bottom: 8px;
    }
    .card-header h1 {
      margin: 0;
      font-size: 20px;
      line-height: 1.2;
      letter-spacing: -0.04em;
      font-weight: 800;
      color: var(--navy-900);
    }
    .meta-line { margin-top: 4px; font-size: 10px; color: var(--slate-500); }
    .pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-top: 6px;
      background: var(--blue-100);
      color: var(--blue-600);
      border-radius: 999px;
      padding: 3px 7px;
      font-size: 9px;
      font-weight: 700;
    }
    .pill svg { width: 14px; height: 14px; }
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 5px 8px;
      border-radius: 999px;
      background: var(--amber-100);
      border: 1px solid rgba(244, 185, 66, 0.6);
      color: var(--amber-700);
      font-size: 9px;
      font-weight: 800;
      white-space: nowrap;
    }
    .status-dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: var(--amber-600);
      box-shadow: 0 0 0 4px rgba(244, 185, 66, 0.18);
    }
    .summary-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 6px;
      padding: 6px 10px 2px;
    }
    .info-card {
      background: linear-gradient(180deg, #fbfcff, #f4f7fc);
      border: 1px solid var(--slate-200);
      border-radius: 10px;
      min-height: 56px;
      padding: 6px 8px;
    }
    .info-label {
      display: flex;
      align-items: center;
      gap: 5px;
      font-size: 7px;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--slate-500);
      font-weight: 700;
      margin-bottom: 4px;
    }
    .info-label svg { width: 10px; height: 10px; color: var(--blue-600); }
    .info-value {
      font-size: 12px;
      font-weight: 700;
      color: var(--navy-900);
      line-height: 1.2;
    }
    .person-chip {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      max-width: 100%;
    }
    .person-avatar {
      width: 26px; height: 26px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--blue-600), #477ef7);
      color: var(--white);
      display: flex; align-items: center; justify-content: center;
      font-size: 11px;
      font-weight: 800;
      flex-shrink: 0;
    }
    .eta-card {
      grid-column: 1 / -1;
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 8px;
      background: linear-gradient(135deg, rgba(244,185,66,0.12), rgba(45,109,246,0.06));
      border: 1px solid rgba(244,185,66,0.38);
      border-radius: 10px;
      padding: 8px 10px;
    }
    .eta-left { display: flex; align-items: flex-start; gap: 6px; }
    .eta-icon {
      width: 24px; height: 24px;
      border-radius: 8px;
      background: linear-gradient(135deg, var(--amber-600), #f0aa2d);
      display: flex; align-items: center; justify-content: center;
      color: var(--white);
      box-shadow: 0 12px 14px -12px rgba(244,185,66,0.9);
    }
    .eta-icon svg { width: 12px; height: 12px; }
    .eta-label {
      font-size: 7px;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--slate-500);
      font-weight: 700;
      margin-bottom: 2px;
    }
    .eta-value {
      font-size: 16px;
      letter-spacing: -0.04em;
      color: var(--navy-900);
      font-weight: 800;
      line-height: 1.1;
    }
    .eta-note {
      background: rgba(255, 255, 255, 0.7);
      border: 1px solid rgba(244, 185, 66, 0.5);
      color: var(--amber-700);
      padding: 4px 7px;
      border-radius: 999px;
      font-size: 10px;
      font-weight: 700;
    }
    .request-box { padding: 0 10px 10px; }
    .section-title {
      display: flex; align-items: center; gap: 8px;
      font-size: 7px; letter-spacing: 0.14em; text-transform: uppercase;
      color: var(--slate-500); font-weight: 800; margin: 7px 0 5px;
    }
    .section-title svg { width: 12px; height: 12px; color: var(--blue-600); }
    .request-path {
      font-size: 10px;
      color: var(--slate-500);
      margin-bottom: 6px;
    }
    .request-path strong { color: var(--navy-900); }
    .request-body {
      background: linear-gradient(180deg, #f8faff, #f3f7fd);
      border: 1px solid var(--slate-200);
      border-radius: 10px;
      padding: 8px 10px;
      font-size: 11px;
      color: var(--navy-900);
      line-height: 1.45;
      white-space: pre-wrap;
      word-break: break-word;
    }
    .footer {
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      padding: 8px 10px 0;
      color: var(--slate-500); font-size: 9px;
    }
    .footer a { color: var(--blue-600); text-decoration: none; font-weight: 700; }
    @media (max-width: 640px) {
      body { padding: 16px 14px 24px; }
      .topbar, .card-header, .footer { flex-direction: column; align-items: flex-start; }
      .status-badge { width: 100%; justify-content: center; }
      .summary-grid { grid-template-columns: 1fr; }
      .eta-card { flex-direction: column; align-items: flex-start; }
      .eta-value { font-size: 22px; }
      .ticket-tag { width: 100%; text-align: left; }
    }
  </style>
</head>
<body>
  <div class="pdf-shell">
    <header class="topbar">
      <div class="brand">
        {$logoHtml}
      </div>
      <div class="ticket-tag">Chamado <strong>#{$idChamadoEsc}</strong> · gerado em {$dataImportacaoEsc}</div>
    </header>

    <main class="card">
      <div class="card-header">
        <div>
          <div class="eyebrow">Cliente</div>
          <h1>{$clienteEsc}</h1>
          <div class="meta-line">Solicitação registrada no painel de suporte GestãoPro</div>
          <div class="pill">
            <svg viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h10M4 18h7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            {$tipoEsc}
          </div>
        </div>
        <div class="status-badge"><span class="status-dot"></span>{$statusEsc}</div>
      </div>

      <div class="summary-grid">
        <div class="info-card">
          <div class="info-label">
            <svg viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="16" height="16" rx="3" stroke="currentColor" stroke-width="1.8"/><path d="M4 10h16" stroke="currentColor" stroke-width="1.8"/></svg>
            Tipo
          </div>
          <div class="info-value">{$tipoEsc}</div>
        </div>

        <div class="info-card">
          <div class="info-label">
            <svg viewBox="0 0 24 24" fill="none"><path d="M4 20c0-3.5 3.5-6 8-6s8 2.5 8 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.8"/></svg>
            Status
          </div>
          <div class="info-value">{$statusEsc}</div>
        </div>

        <div class="eta-card">
          <div class="eta-left">
            <div class="eta-icon">
              <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </div>
            <div>
              <div class="eta-label">Previsão de retorno</div>
              <div class="eta-value">{$dataPrevEsc}</div>
            </div>
          </div>
          {$textoPrazoEsc}
        </div>
      </div>

      <div class="request-box">
        <div class="section-title">
          <svg viewBox="0 0 24 24" fill="none"><path d="M7 8h10M7 12h10M7 16h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          Descrição da solicitação
        </div>
        <div class="request-path">Suporte <span>›</span> Chamado <span>›</span> <strong>#{$idChamadoEsc}</strong></div>
        <div class="request-body">{$descricaoEsc}</div>
      </div>
    </main>

    <footer class="footer">
      <span>Documento gerado por GestãoPro</span>
      <a href="https://gestaopro.com.br/" target="_blank" rel="noopener">gestaopro.com.br</a>
    </footer>
  </div>
</body>
</html>
HTML;

    return $html;
}
