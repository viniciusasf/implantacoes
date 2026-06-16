<?php
$f = 'clientes.php';
$c = file_get_contents($f);

// Remove RDP/Obs data attributes and add data-api
$c = preg_replace(
    '/data-obs="[^"]*"\s*data-nf="[^"]*"\s*data-cfg="[^"]*"\s*data-licencas="[^"]*"\s*data-anexo="[^"]*"\s*data-chamados="[^"]*"\s*data-recursos="[^"]*"\s*data-computador-rdp="[^"]*"\s*data-usuario-rdp="[^"]*"\s*data-senha-rdp="[^"]*"/',
    'data-api="<?= htmlspecialchars($c[\'id_cliente_api\'] ?? \'\') ?>"' . " \n                                    " .
    'data-nf="<?= $c[\'emitir_nf\'] ?>"' . " \n                                    " .
    'data-cfg="<?= $c[\'configurado\'] ?>"' . " \n                                    " .
    'data-licencas="<?= $c[\'num_licencas\'] ?>"' . " \n                                    " .
    'data-anexo="<?= $c[\'anexo\'] ?>"' . " \n                                    " .
    'data-chamados="<?= htmlspecialchars($c[\'chamados\'] ?? \'\') ?>"' . " \n                                    " .
    'data-recursos="<?= htmlspecialchars($c[\'recursos\'] ?? \'\') ?>"',
    $c
);

file_put_contents($f, $c);
echo "Cleaned data attributes\n";
