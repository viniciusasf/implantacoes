<?php
require_once 'config.php';

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
    $id_cliente = $_POST['id_cliente'];
    $id_treinamento = $_POST['id_treinamento'] ?? null;
    $id_contato = $_POST['id_contato'] ?? null;
    $tema = $_POST['tema'];
    $data_treinamento = normalizarDataTreinamento($_POST['data_treinamento'] ?? '');
    $status = $_POST['status'] ?? 'PENDENTE';
    $google_event_link = $_POST['google_event_link'] ?? '';
    $observacoes = $_POST['observacoes'] ?? '';

    // Validar dados obrigatórios
    if (empty($id_cliente) || empty($tema) || empty($data_treinamento)) {
        header("Location: treinamentos_cliente.php?id_cliente=" . $id_cliente . "&error=Dados incompletos");
        exit;
    }

    if (!empty($id_treinamento)) {
        // Atualizar treinamento existente
        $stmt = $pdo->prepare("UPDATE treinamentos SET 
            id_contato = ?, 
            tema = ?, 
            data_treinamento = ?, 
            status = ?, 
            google_event_link = ?,
            observacoes = ?
            WHERE id_treinamento = ?");
        $stmt->execute([
            $id_contato, $tema, $data_treinamento, $status, 
            $google_event_link, $observacoes, $id_treinamento
        ]);
        $msg = "Treinamento atualizado com sucesso";
    } else {
        // Inserir novo treinamento
        $stmt = $pdo->prepare("INSERT INTO treinamentos 
            (id_cliente, id_contato, tema, data_treinamento, status, google_event_link, observacoes) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $id_cliente, $id_contato, $tema, $data_treinamento, $status, 
            $google_event_link, $observacoes
        ]);
        $msg = "Treinamento agendado com sucesso";
    }

    header("Location: treinamentos_cliente.php?id_cliente=" . $id_cliente . "&msg=" . urlencode($msg));
    exit;
}

// Se não for POST, redirecionar
header("Location: clientes.php");
exit;
?>
