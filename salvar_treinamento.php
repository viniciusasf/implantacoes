<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_cliente = $_POST['id_cliente'];
    $id_treinamento = $_POST['id_treinamento'] ?? null;
    $data_treinamento = $_POST['data_treinamento'];
    $tema = $_POST['tema'];
    $observacoes = $_POST['observacoes'] ?? '';
    $instrutor = $_POST['instrutor'] ?? '';
    $duracao = $_POST['duracao'] ?? '';
    $status = $_POST['status'] ?? 'REALIZADO';
    $tipo = $_POST['tipo'] ?? 'INICIAL';
    $participantes = $_POST['participantes'] ?? 0;
    $satisfacao = $_POST['satisfacao'] ?? null;
    $material = $_POST['material'] ?? 'NÃO';

    if (!empty($id_treinamento)) {
        // Atualizar treinamento existente
        $stmt = $pdo->prepare("UPDATE treinamentos SET 
            data_treinamento = ?, 
            tema = ?, 
            observacoes = ?, 
            instrutor = ?, 
            duracao = ?, 
            status = ?,
            tipo = ?,
            participantes = ?,
            satisfacao = ?,
            material = ?
            WHERE id_treinamento = ?");
        $stmt->execute([
            $data_treinamento, $tema, $observacoes, $instrutor, $duracao, $status,
            $tipo, $participantes, $satisfacao, $material, $id_treinamento
        ]);
    } else {
        // Inserir novo treinamento
        $stmt = $pdo->prepare("INSERT INTO treinamentos 
            (id_cliente, data_treinamento, tema, observacoes, instrutor, duracao, status, tipo, participantes, satisfacao, material) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $id_cliente, $data_treinamento, $tema, $observacoes, $instrutor, $duracao, $status,
            $tipo, $participantes, $satisfacao, $material
        ]);
    }

    header("Location: treinamentos_cliente.php?id_cliente=" . $id_cliente . "&msg=Treinamento salvo com sucesso");
    exit;
}

header("Location: clientes.php");
exit;