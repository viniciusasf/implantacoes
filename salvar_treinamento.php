<?php
require_once 'config.php';
require_once __DIR__ . '/google_oauth_token_helper.php';
require_once __DIR__ . '/google_calendar_functions.php';

function normalizarDataTreinamento($valor)
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return null;
    }

    $timezone = new DateTimeZone('America/Sao_Paulo');
    $formatos = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];

    foreach ($formatos as $formato) {
        $dt = DateTime::createFromFormat($formato, $valor, $timezone);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    try {
        $dt = new DateTime($valor, $timezone);
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Aplicando cast para int para segurança e tipagem
    $id_cliente    = (int)$_POST['id_cliente'];
    $id_treinamento = isset($_POST['id_treinamento']) && !empty($_POST['id_treinamento']) ? (int)$_POST['id_treinamento'] : null;
    $id_contato    = isset($_POST['id_contato']) && !empty($_POST['id_contato']) ? (int)$_POST['id_contato'] : null;

    $tema             = $_POST['tema'];
    $data_treinamento = normalizarDataTreinamento($_POST['data_treinamento'] ?? '');
    $status           = $_POST['status'] ?? 'PENDENTE';
    $google_event_link = $_POST['google_event_link'] ?? '';
    $observacoes      = $_POST['observacoes'] ?? '';
    // Campo livre para digitação de contato
    $nome_contato_livre = trim($_POST['nome_contato'] ?? '');

    // Validar dados obrigatórios
    if (empty($id_cliente) || empty($tema) || empty($data_treinamento)) {
        header("Location: treinamentos_cliente.php?id_cliente=" . $id_cliente . "&error=Dados incompletos");
        exit;
    }

    $redirect_to = $_POST['redirect_to'] ?? '';
    // Valida o redirect para aceitar apenas páginas do sistema
    $paginas_permitidas = ['clientes.php', 'treinamentos_cliente.php', 'treinamentos.php', 'monitoramento_gestaopro.php', 'chamados_gestaopro.php'];
    $redirect_base = basename($redirect_to);
    if (!in_array($redirect_base, $paginas_permitidas)) {
        $redirect_base = 'treinamentos_cliente.php';
    }

    if (!empty($id_treinamento)) {
        // -----------------------------------------------
        // Atualizar treinamento existente
        // -----------------------------------------------
        $stmt = $pdo->prepare("UPDATE treinamentos SET 
            id_contato = ?, 
            nome_contato = ?, 
            tema = ?, 
            data_treinamento = ?, 
            status = ?, 
            google_event_link = ?,
            observacoes = ?
            WHERE id_treinamento = ?");
        $stmt->execute([
            $id_contato, $nome_contato_livre, $tema, $data_treinamento, $status,
            $google_event_link, $observacoes, $id_treinamento
        ]);
        $msg = "Treinamento atualizado com sucesso";

    } else {
        // -----------------------------------------------
        // Inserir novo treinamento
        // -----------------------------------------------
        $stmt = $pdo->prepare("INSERT INTO treinamentos 
            (id_cliente, id_contato, nome_contato, tema, data_treinamento, status, google_event_link, observacoes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $id_cliente, $id_contato, $nome_contato_livre, $tema, $data_treinamento, $status,
            $google_event_link, $observacoes
        ]);
        $novo_id_treinamento = (int) $pdo->lastInsertId();

        // -----------------------------------------------
        // Sincronização automática com Google Meet/Agenda
        // (apenas quando não há link manual informado)
        // -----------------------------------------------
        $syncResult            = ['success' => false];
        $manual_link_provided  = !empty($google_event_link);

        if (!$manual_link_provided) {
            $syncResult = sincronizarGoogleMeetAutomatico($pdo, $novo_id_treinamento);
        }

        // Se o Google precisar de re-autenticação, redireciona para o fluxo OAuth
        if (!$manual_link_provided && !empty($syncResult['needs_auth']) && !empty($syncResult['auth_start_url'])) {
            // Salva o id_treinamento na sessão para após o OAuth
            session_start();
            $_SESSION['sync_training_id']        = $novo_id_treinamento;
            $_SESSION['sync_redirect_id_cliente'] = $id_cliente;
            header("Location: " . $syncResult['auth_start_url']);
            exit;
        }

        if ($manual_link_provided) {
            $msg = "Treinamento agendado com link salvo manualmente";
        } elseif (!empty($syncResult['success'])) {
            $msg = "Treinamento agendado e sincronizado com Google Meet";
        } elseif (!empty($syncResult['message'])) {
            $msg = "Treinamento agendado. Google Meet: " . $syncResult['message'];
        } else {
            $msg = "Treinamento agendado com sucesso";
        }
    }

    if ($redirect_base === 'clientes.php') {
        header("Location: clientes.php?msg=" . urlencode($msg));
    } elseif (in_array($redirect_base, ['monitoramento_gestaopro.php', 'chamados_gestaopro.php'])) {
        header("Location: " . $redirect_base . "?msg=" . urlencode($msg));
    } else {
        header("Location: treinamentos_cliente.php?id_cliente=" . $id_cliente . "&msg=" . urlencode($msg));
    }
    exit;
}

// Se não for POST, redirecionar
header("Location: clientes.php");
exit;
?>
