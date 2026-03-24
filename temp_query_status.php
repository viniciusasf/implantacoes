<?php
$dir = __DIR__;
$files = scandir($dir);
foreach($files as $file) {
    if(pathinfo($file, PATHINFO_EXTENSION) == 'php') {
        $content = file_get_contents($dir . '/' . $file);
        if(stripos($content, 'UPDATE clientes') !== false) {
            echo "Encontrado em: $file\n";
            // Extrair as linhas onde isso ocorre
            $lines = explode("\n", $content);
            foreach($lines as $i => $line) {
                if(stripos($line, 'UPDATE clientes') !== false) {
                    echo "  Linha " . ($i+1) . ": " . trim($line) . "\n";
                }
            }
        }
    }
}
?>
