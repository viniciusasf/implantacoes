<?php
$f = 'clientes.php';
$c = file_get_contents($f);

// 1. UPDATE Query
$c = preg_replace('/`emitir_nf`=\?, `configurado`=\?, `num_licencas`=\?, `anexo`=\?, `chamados`=\?, `recursos`=\?,\s*`computador_rdp`=\?, `usuario_rdp`=\?, `senha_rdp`=\?\s*WHERE `id_cliente`=\?/', "`emitir_nf`=?, `configurado`=?, `num_licencas`=?, `anexo`=?, `chamados`=?, `recursos`=?\n            WHERE `id_cliente`=?", $c);

// 2. UPDATE params
$c = preg_replace('/\$_POST\[\'computador_rdp\'\] \?\? \'\',\s*\$_POST\[\'usuario_rdp\'\] \?\? \'\',\s*\$_POST\[\'senha_rdp\'\] \?\? \'\',\s*\$_POST\[\'id_cliente\'\]/', "\$_POST['id_cliente']", $c);

// 3. INSERT Query
$c = preg_replace('/`data_inicio`, `data_fim`, `data_previsao_encerramento`, `observacao`,\s*`emitir_nf`, `configurado`, `num_licencas`, `anexo`, `chamados`, `recursos`,\s*`computador_rdp`, `usuario_rdp`, `senha_rdp`\s*\) VALUES \(\?, \?, \?, \?, \?, \?, \?, \?, \?, \?, \?, \?, \?, \?, \?, \?, \?\)/', "`data_inicio`, `data_fim`, `data_previsao_encerramento`, `id_cliente_api`,\n            `emitir_nf`, `configurado`, `num_licencas`, `anexo`, `chamados`, `recursos`\n        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", $c);

// 4. INSERT params
$c = preg_replace('/\$_POST\[\'computador_rdp\'\] \?\? \'\',\s*\$_POST\[\'usuario_rdp\'\] \?\? \'\',\s*\$_POST\[\'senha_rdp\'\] \?\? \'\'\s*\]\)/', "]\)", $c);

file_put_contents($f, $c);
echo "Replaced POST processing\n";
