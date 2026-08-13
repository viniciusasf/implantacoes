<?php
require_once 'config.php';
require_once 'vendor/autoload.php';
require_once 'google_drive_functions.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');

if (empty($_GET['id']) || !is_numeric($_GET['id'])) {
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

    // Salva o HTML temporariamente e gera o PDF via Puppeteer
    $tempHtmlFile = __DIR__ . '/pdfs/temp_chamado_' . $idChamado . '_' . uniqid() . '.html';
    $tempPdfFile  = __DIR__ . '/pdfs/temp_chamado_' . $idChamado . '_' . uniqid() . '.pdf';
    
    file_put_contents($tempHtmlFile, $html);
    
    $nodeScript = __DIR__ . '/js/html_to_pdf.js';
    $cacheDir = __DIR__ . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'puppeteer';
    putenv('PUPPETEER_CACHE_DIR=' . $cacheDir);
    putenv('HOME=' . __DIR__);
    putenv('USERPROFILE=' . __DIR__);

    $command = 'node ' . escapeshellarg($nodeScript) . ' ' . escapeshellarg($tempHtmlFile) . ' ' . escapeshellarg($tempPdfFile);
    exec($command . ' 2>&1', $cmdOutput, $returnVar);
    
    if ($returnVar !== 0 || !file_exists($tempPdfFile)) {
        @unlink($tempHtmlFile);
        throw new Exception("Falha ao gerar PDF via Puppeteer. " . implode("\n", $cmdOutput));
    }
    
    $pdfContent = file_get_contents($tempPdfFile);
    @unlink($tempHtmlFile);
    @unlink($tempPdfFile);

    $clienteNome = trim((string) ($chamado['nome_fantasia'] ?? ''));
    $clienteId = !empty($chamado['id_cliente_api']) ? (int) $chamado['id_cliente_api'] : null;
    $folderName = sanitizeFolderName($clienteNome);
    if ($folderName === '') {
        $folderName = $clienteId ? 'cliente_' . $clienteId : 'cliente_desconhecido';
    } elseif ($clienteId) {
        $folderName .= '_' . $clienteId;
    }

    $service = driveGetService();
    $clientFolderId = driveFindOrCreateFolder($service, $folderName, GOOGLE_DRIVE_PDF_ROOT_FOLDER_ID);

    $fileName = sprintf('chamado_suporte_%d.pdf', $idChamado);
    $fileId = null;

    if (!empty($chamado['drive_file_id'])) {
        try {
            $updatedFile = driveUpdateExistingFile($service, $chamado['drive_file_id'], $fileName, $pdfContent, 'application/pdf');
            $fileId = $updatedFile->id;
            driveMoveFileToFolder($service, $fileId, $clientFolderId);
        } catch (Throwable $e) {
            $fileId = null;
        }
    }

    if (empty($fileId)) {
        $existingFileId = driveFindFileInFolder($service, $clientFolderId, $fileName, 'application/pdf');
        if ($existingFileId) {
            $updatedFile = driveUpdateExistingFile($service, $existingFileId, $fileName, $pdfContent, 'application/pdf');
            $fileId = $updatedFile->id;
        }
    }

    if (empty($fileId)) {
        $createdFile = driveUploadNewFile($service, $clientFolderId, $fileName, $pdfContent, 'application/pdf');
        $fileId = $createdFile->id;
    }

    driveMoveFileToFolder($service, $fileId, $clientFolderId);
    driveEnsureAnyoneLink($service, $fileId);
    $fileLink = driveGetFileLink($service, $fileId);

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

    $stmtUpdate = $pdo->prepare('UPDATE chamados_espelho_local SET drive_file_id = ?, drive_file_name = ?, drive_pdf_link = ?, drive_pdf_gerado_em = NOW() WHERE id_chamado_api = ?');
    $stmtUpdate->execute([$fileId, $fileName, $fileLink, $idChamado]);
    
    $folderLink = 'https://drive.google.com/drive/folders/' . $clientFolderId;

    echo json_encode(['sucesso' => true, 'link' => $fileLink, 'html' => $html, 'filename' => $fileName, 'folder_link' => $folderLink]);
    exit;
} catch (Throwable $e) {
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

    $logoSvg = '<svg viewBox="0 0 24 24" fill="none"><path d="M4 17V9.5L12 4l8 5.5V17l-8 3-8-3Z" stroke="white" stroke-width="1.8" stroke-linejoin="round"/><path d="M4 9.5 12 13l8-3.5" stroke="white" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 13v7" stroke="white" stroke-width="1.8"/></svg>';
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
            $textoPrazo = "atrasado " . $dias . ($dias == 1 ? " dia" : " dias");
        } elseif ($dias == 0) {
            $textoPrazo = "hoje";
        } else {
            $textoPrazo = "em " . $dias . ($dias == 1 ? " dia útil" : " dias úteis");
        }
    }

    $html = '<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Chamado #' . htmlspecialchars($idChamado) . ' · GestãoPro</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
  <style>
    :root{--navy-950:#0b1220;--navy-900:#101a2e;--navy-700:#1e2b47;--blue-600:#2f6fed;--blue-500:#4a86ff;--blue-100:#e8f0ff;--amber-500:#f5a524;--amber-100:#fff2da;--amber-700:#8a5a06;--slate-50:#f6f7fb;--slate-100:#eef1f6;--slate-200:#e2e6ee;--slate-400:#8892a6;--slate-500:#5c667c;--slate-700:#334059;--ink:#0f1626;--white:#ffffff;--radius:14px;}
    *{box-sizing:border-box;} body{margin:0;background:radial-gradient(1100px 500px at 15% -10%, rgba(47,111,237,0.10), transparent 60%), radial-gradient(900px 500px at 100% 0%, rgba(245,165,36,0.08), transparent 55%), var(--slate-50);font-family:"Inter", -apple-system, BlinkMacSystemFont, sans-serif;color:var(--ink);padding:40px 20px 60px;display:flex;justify-content:center;line-height:1.5;} .sheet{width:100%;max-width:760px;} .top-bar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px;flex-wrap:wrap;} .brand{display:flex;align-items:center;gap:10px;} .brand-mark{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg, var(--blue-600), #1c4fd6);display:flex;align-items:center;justify-content:center;box-shadow:0 6px 16px -4px rgba(47,111,237,0.55);} .brand-mark svg{width:20px;height:20px;} .brand-name{font-family:"Space Grotesk", sans-serif;font-weight:700;font-size:17px;color:var(--navy-900);letter-spacing:-0.02em;} .brand-name span{color:var(--blue-600);} .ticket-id{font-family:"JetBrains Mono", monospace;font-size:13px;color:var(--slate-500);background:var(--white);border:1px solid var(--slate-200);padding:6px 12px;border-radius:999px;} .ticket-id b{color:var(--navy-900);font-weight:600;} .card{background:var(--white);border-radius:20px;border:1px solid var(--slate-200);box-shadow:0 1px 2px rgba(15,22,38,0.04), 0 20px 40px -24px rgba(15,22,38,0.15);overflow:hidden;} .card-head{padding:28px 32px 24px;border-bottom:1px solid var(--slate-100);display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap;} .card-head h1{font-family:"Space Grotesk", sans-serif;font-size:24px;font-weight:700;margin:0 0 6px;letter-spacing:-0.02em;color:var(--navy-900);} .card-head .meta-line{font-size:13px;color:var(--slate-500);} .type-chip{display:inline-flex;align-items:center;gap:6px;background:var(--blue-100);color:var(--blue-600);font-size:12.5px;font-weight:600;padding:5px 11px;border-radius:999px;margin-top:10px;} .type-chip svg{width:13px;height:13px;} .status-badge{display:inline-flex;align-items:center;gap:8px;background:var(--amber-100);border:1px solid #f0d18f;color:var(--amber-700);font-weight:600;font-size:13px;padding:9px 14px 9px 10px;border-radius:999px;white-space:nowrap;} .status-dot{width:8px;height:8px;border-radius:50%;background:var(--amber-500);box-shadow:0 0 0 4px rgba(245,165,36,0.25);animation:pulse 2.2s ease-in-out infinite;flex-shrink:0;} @keyframes pulse{0%,100%{box-shadow:0 0 0 4px rgba(245,165,36,0.25);}50%{box-shadow:0 0 0 7px rgba(245,165,36,0.12);}} .info-grid{display:grid;grid-template-columns:repeat(3, 1fr);gap:1px;background:var(--slate-100);border-bottom:1px solid var(--slate-100);} .info-cell{background:var(--white);padding:20px 22px;} .info-cell .label{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:var(--slate-400);margin-bottom:8px;} .info-cell .label svg{width:13px;height:13px;flex-shrink:0;} .info-cell .value{font-size:15.5px;font-weight:600;color:var(--navy-900);} .avatar-chip{display:inline-flex;align-items:center;gap:8px;} .avatar-chip .dot{width:24px;height:24px;border-radius:50%;background:linear-gradient(135deg,#4a86ff,#1c4fd6);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;font-family:"Space Grotesk",sans-serif;} .eta-cell{grid-column:1 / -1;background:linear-gradient(90deg, #fff8ec, #fffdf8);border-top:1px solid var(--slate-100);padding:18px 22px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;} .eta-left{display:flex;align-items:center;gap:14px;} .eta-icon{width:42px;height:42px;border-radius:12px;background:var(--amber-100);border:1px solid #f0d18f;display:flex;align-items:center;justify-content:center;flex-shrink:0;} .eta-icon svg{width:20px;height:20px;color:var(--amber-700);} .eta-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:var(--amber-700);margin-bottom:3px;} .eta-value{font-family:"Space Grotesk",sans-serif;font-size:19px;font-weight:700;color:var(--navy-900);} .eta-countdown{font-size:12.5px;font-weight:600;color:var(--amber-700);background:var(--white);border:1px solid #f0d18f;padding:6px 12px;border-radius:999px;} .desc-section{padding:26px 32px 30px;} .desc-heading{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--blue-600);margin-bottom:14px;} .desc-heading svg{width:14px;height:14px;} .path-trail{font-family:"JetBrains Mono", monospace;font-size:12.5px;color:var(--slate-500);background:var(--slate-50);border:1px solid var(--slate-200);display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:8px;margin-bottom:16px;} .path-trail .seg-strong{color:var(--navy-900);font-weight:600;background:var(--blue-100);padding:2px 6px;border-radius:5px;color:var(--blue-600);} .request-box{background:var(--slate-50);border:1px solid var(--slate-200);border-left:4px solid var(--blue-600);border-radius:0 12px 12px 0;padding:18px 20px;font-size:15px;color:var(--navy-900);line-height:1.65;} .request-box b{color:var(--blue-600);} .footer{margin-top:22px;display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:12px;color:var(--slate-400);flex-wrap:wrap;} .footer a{color:var(--slate-500);text-decoration:none;font-weight:500;} .footer a:hover{color:var(--blue-600);} @media (max-width:640px){body{padding:20px 12px 40px;} .card-head{padding:22px 20px 20px;} .card-head h1{font-size:20px;} .info-grid{grid-template-columns:1fr 1fr;} .desc-section{padding:22px 20px 26px;} .eta-cell{padding:16px 20px;} .status-badge{font-size:12px;}} @media (max-width:420px){.info-grid{grid-template-columns:1fr;} .eta-cell{flex-direction:column;align-items:flex-start;}}</style>
</head>
<body>
<div class="sheet">
  <div class="top-bar">
    <div></div>
    <div class="ticket-id">Chamado <b>#' . htmlspecialchars($idChamado) . '</b> · gerado em ' . htmlspecialchars($dataImportacao) . '</div>
  </div>

  <div class="card">
    <div class="card-head">
      <div>
        <h1>' . htmlspecialchars($cliente) . '</h1>
        <div class="meta-line">Solicitação registrada no painel de suporte GestãoPro</div>
        <div class="type-chip">
          <svg viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h10M4 18h7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          ' . htmlspecialchars($tipo) . '
        </div>
      </div>
      <div class="status-badge"><span class="status-dot"></span>' . htmlspecialchars($status) . '</div>
    </div>

    <div class="info-grid">
      <div class="info-cell">
        <div class="label">
          <svg viewBox="0 0 24 24" fill="none"><path d="M4 20c0-3.5 3.5-6 8-6s8 2.5 8 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.8"/></svg>
          Responsável
        </div>
        <div class="value"><span class="avatar-chip"><span class="dot">' . htmlspecialchars($responsavelInicial) . '</span>' . htmlspecialchars($responsavel) . '</span></div>
      </div>
      <div class="info-cell">
        <div class="label">
          <svg viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="16" height="16" rx="3" stroke="currentColor" stroke-width="1.8"/><path d="M4 10h16" stroke="currentColor" stroke-width="1.8"/></svg>
          Tipo de Chamado
        </div>
        <div class="value">' . htmlspecialchars($tipo) . '</div>
      </div>
      <div class="info-cell">
        <div class="label">
          <svg viewBox="0 0 24 24" fill="none"><rect x="3.5" y="3.5" width="17" height="17" rx="3.5" stroke="currentColor" stroke-width="1.8"/><path d="M3.5 8.5h17" stroke="currentColor" stroke-width="1.8"/></svg>
          ID do Chamado
        </div>
        <div class="value">#' . htmlspecialchars($idChamado) . '</div>
      </div>

      <div class="eta-cell">
        <div class="eta-left">
          <div class="eta-icon">
            <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          </div>
          <div>
            <div class="eta-label">Previsão de Retorno</div>
            <div class="eta-value">' . htmlspecialchars($dataPrev) . '</div>
          </div>
        </div>
        ' . ($textoPrazo ? '<div class="eta-countdown">' . htmlspecialchars($textoPrazo) . '</div>' : '') . '
      </div>
    </div>

    <div class="desc-section">
      <div class="desc-heading">
        <svg viewBox="0 0 24 24" fill="none"><path d="M7 8h10M7 12h10M7 16h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        Descrição da Solicitação
      </div>

      <div class="path-trail">
        Suporte <span>›</span> Chamado <span>›</span> <span class="seg-strong">#' . htmlspecialchars($idChamado) . '</span>
      </div>

      <div class="request-box">' . nl2br(htmlspecialchars($descricao)) . '</div>
    </div>
  </div>

  <div class="footer">
    <span>Documento gerado por Gestaopro</span>
    <a href="https://gestaopro.com.br/" target="_blank" rel="noopener">gestaopro.com.br</a>
  </div>
</div>
</body>
</html>';

    return $html;
}
