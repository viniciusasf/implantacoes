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

try {
    $stmt = $pdo->prepare('SELECT * FROM chamados_espelho_local WHERE id_chamado_api = ?');
    $stmt->execute([$idChamado]);
    $chamado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$chamado) {
        http_response_code(404);
        echo json_encode(['sucesso' => false, 'erro' => 'Chamado não encontrado no espelho local.']);
        exit;
    }

    $html = gerarHtmlPdfChamado($chamado);

    $dompdf = new Dompdf\Dompdf([ 'isRemoteEnabled' => true ]);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdfContent = $dompdf->output();

    $service = driveGetService();
    $folderId = driveFindOrCreateFolder($service, 'PDF Chamados');

    $dataNow = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $fileName = sprintf('chamado_%d_%s.pdf', $idChamado, $dataNow->format('Ymd'));

    $fileId = null;
    if (!empty($chamado['drive_file_id'])) {
        try {
            $updatedFile = driveUpdateExistingFile($service, $chamado['drive_file_id'], $fileName, $pdfContent);
            $fileId = $updatedFile->id;
        } catch (Throwable $e) {
            $fileId = null;
        }
    }

    if (empty($fileId)) {
        $createdFile = driveUploadNewFile($service, $folderId, $fileName, $pdfContent);
        $fileId = $createdFile->id;
    }

    driveEnsureAnyoneLink($service, $fileId);
    $fileLink = driveGetFileLink($service, $fileId);

    $columnsToAdd = [
        'drive_file_id' => 'VARCHAR(255) NULL',
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

    $stmtUpdate = $pdo->prepare('UPDATE chamados_espelho_local SET drive_file_id = ?, drive_pdf_link = ?, drive_pdf_gerado_em = NOW() WHERE id_chamado_api = ?');
    $stmtUpdate->execute([$fileId, $fileLink, $idChamado]);

    echo json_encode(['sucesso' => true, 'link' => $fileLink, 'drive_file_id' => $fileId]);
    exit;
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'NO_DRIVE_TOKEN') {
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'erro' => 'Token do Google Drive ausente ou expirado. Autentique-se em google_drive_auth.php.']);
        exit;
    }
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro ao gerar PDF: ' . $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno: ' . $e->getMessage()]);
    exit;
}

function gerarHtmlPdfChamado(array $chamado)
{
    $dataPrev = $chamado['data_prev_retorno'] ? (new DateTime($chamado['data_prev_retorno']))->format('d/m/Y') : '—';
    $dataImportacao = $chamado['data_importacao'] ? (new DateTime($chamado['data_importacao']))->format('d/m/Y H:i') : '—';
    // embed logo as data URI to ensure Dompdf can render it reliably
    $logoPath = __DIR__ . '/css/pics/logo.webp';
    $logoData = '';
    if (file_exists($logoPath)) {
        $logoData = 'data:image/webp;base64,' . base64_encode(file_get_contents($logoPath));
    }

    $primaryColor = '#1A43A7';
    $bgAlt = '#f3f4f6';

    $title = 'Chamado #' . htmlspecialchars($chamado['id_chamado_api']);

    $html = '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title>';
    $html .= '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">';
    $html .= '<style>body{font-family:Poppins, Inter, Arial, Helvetica, sans-serif;background:#ffffff;color:#1f2937;margin:0;padding:18px;font-size:13px;} .paper{max-width:800px;margin:0 auto;} .header{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:4px solid ' . $primaryColor . ';} .logo{height:40px;} .title{color:' . $primaryColor . ';font-size:18px;font-weight:700;} .meta{font-size:11px;color:#6b7280;} .card{border-radius:8px;background:#ffffff;border:1px solid #e6edf8;padding:12px;margin-top:12px;box-shadow:0 1px 0 rgba(0,0,0,0.02);} h2{font-size:13px;margin:0 0 6px;color:' . $primaryColor . ';font-weight:600;} table{width:100%;border-collapse:separate;border-spacing:0 6px;margin-top:6px;} td, th{padding:8px;border:0;background:transparent;} tr.row{background:' . $bgAlt . ';border-radius:6px;} .label{width:180px;font-weight:600;color:#374151;font-size:12px;} .value{color:#111827;font-size:12px;} .desc{white-space:pre-wrap;line-height:1.35;margin-top:6px;color:#374151;font-size:13px;} .footer{margin-top:18px;font-size:11px;color:#6b7280;text-align:center;} .badge{display:inline-block;padding:5px 8px;border-radius:8px;background:rgba(26,67,167,0.08);color:' . $primaryColor . ';font-size:11px;margin-top:6px;font-weight:600;}</style>';
    $html .= '</head><body><div class="paper">';

    $html .= '<div class="header">';
    if ($logoData) {
        $html .= '<div><img class="logo" src="' . $logoData . '" alt="logo"></div>';
    } else {
        $html .= '<div style="font-weight:700;color:' . $primaryColor . '">GESTAOPRO</div>';
    }
    $html .= '<div style="text-align:right"><div class="title">' . $title . '</div><div class="meta">Gerado em: ' . htmlspecialchars($dataImportacao) . '</div></div>';
    $html .= '</div>';

    $html .= '<div class="card">';
    $html .= '<h2>Informações do chamado</h2>';
    $html .= '<table role="presentation">';
    $html .= '<tr class="row"><td class="label">Cliente</td><td class="value">' . htmlspecialchars($chamado['nome_fantasia'] ?: '—') . '</td></tr>';
    $html .= '<tr class="row"><td class="label">Status</td><td class="value">' . htmlspecialchars($chamado['status_chamado'] ?: '—') . '</td></tr>';
    $html .= '<tr class="row"><td class="label">Tipo</td><td class="value">' . htmlspecialchars($chamado['tipo_acompanhamento'] ?: '—') . '</td></tr>';
    $html .= '<tr class="row"><td class="label">Responsável</td><td class="value">' . htmlspecialchars($chamado['responsavel'] ?: '—') . '</td></tr>';
    $html .= '<tr class="row"><td class="label">Previsão de retorno</td><td class="value">' . htmlspecialchars($dataPrev) . '</td></tr>';
    $html .= '</table>';
    $html .= '</div>';

    $html .= '<div class="card">';
    $html .= '<h2>Descrição</h2>';
    $html .= '<div class="desc">' . nl2br(htmlspecialchars($chamado['descricao_problema'] ?: '—')) . '</div>';
    $html .= '</div>';

    $html .= '<div class="footer">Documento gerado por Gestaopro &bull; https://gestaopro.com.br/</div>';
    $html .= '</div></body></html>';
    return $html;
}
