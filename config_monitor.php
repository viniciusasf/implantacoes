<?php
/**
 * Configuração Isolada do Monitor de Chamados
 * Preencha as credenciais abaixo para habilitar o monitoramento.
 */

define('MONITOR_USER', 'VINICIUS'); // Seu e-mail ou usuário
define('MONITOR_PASS', 'codigoc123'); // Sua senha
define('MONITOR_URL_LOGIN', 'https://interno.gestaopro.srv.br/login');
define('MONITOR_URL_CHAMADOS', 'https://interno.gestaopro.srv.br/chamados');

// Caminhos para arquivos de sessão e log
define('MONITOR_COOKIE_FILE', __DIR__ . '/logs/monitor_cookie.txt');
define('MONITOR_LOG_FILE', __DIR__ . '/logs/monitor_chamados.log');

// Configurações de Filtro
define('MONITOR_RESPONSAVEL', 'Vinicius Ferreira');
define('MONITOR_STATUS_VALIDOS', [
    'Aguardando Suporte',
    'Aguardando Testes',
    'Aguardando Desenvolvimento',
    'Aguardando Desenv.' // Incluindo variação comum
]);
?>
