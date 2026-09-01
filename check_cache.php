<?php
$data = json_decode(file_get_contents('logs/gp_cache_chamados.json'), true);
if (isset($data['chamados'])) {
    foreach($data['chamados'] as $c) {
        if ($c['ID'] == '102472') {
            print_r($c['DESCRICAO']);
            break;
        }
    }
} else if (isset($data['dados']['chamados'])) {
    foreach($data['dados']['chamados'] as $c) {
        if ($c['ID'] == '102472') {
            print_r($c['DESCRICAO']);
            break;
        }
    }
}
