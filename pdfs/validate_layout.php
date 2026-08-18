<?php
require 'c:\wamp64\www\implanta\config.php';
require 'c:\wamp64\www\implanta\vendor\autoload.php';
require 'c:\wamp64\www\implanta\gerar_pdf_chamado.php';

$data = [
    'id_chamado_api' => 999,
    'nome_fantasia' => 'Cliente Demo',
    'status_chamado' => 'Aguardando Suporte',
    'tipo_acompanhamento' => 'Instalação',
    'responsavel' => 'Maria Silva',
    'descricao_problema' => "Solicitação de implantação do sistema com testes finais.\n\nPrecisamos validar acesso e cronograma.",
    'data_prev_retorno' => '2026-08-20',
    'data_importacao' => '2026-08-14 09:30:00',
];

$html = gerarHtmlComprovanteChamado($data);
file_put_contents('c:\wamp64\www\implanta\pdfs\validation_layout.html', $html);

$options = new Dompdf\Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf\Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$pdf = $dompdf->output();
file_put_contents('c:\wamp64\www\implanta\pdfs\validation_layout.pdf', $pdf);

echo file_exists('c:\wamp64\www\implanta\pdfs\validation_layout.pdf') ? 'PDF_OK' : 'PDF_FAIL';
