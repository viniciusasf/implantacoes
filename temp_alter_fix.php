<?php
$f = 'clientes.php';
$c = file_get_contents($f);
$c = str_replace("            ]\);", "        ]);", $c);
file_put_contents($f, $c);
echo "Fixed syntax error\n";
