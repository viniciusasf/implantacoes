<?php
$data = json_decode(file_get_contents('logs/gp_cache_chamados.json'), true);
$list = $data['chamados'] ?? $data['dados']['chamados'] ?? [];
foreach($list as $c) {
    if ($c['ID'] == '102472') {
        echo "TAMANHO DA DESCRICAO NO CACHE DA API: " . strlen($c['DESCRICAO']) . " bytes\n";
        break;
    }
}
