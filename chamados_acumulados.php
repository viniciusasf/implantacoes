<?php 
require_once 'config.php';
require_once 'header.php'; 

// Busca chamados não notificados
$stmt = $pdo->query("SELECT * FROM chamados_espelho_local WHERE notificado = 0 ORDER BY nome_fantasia ASC");
$chamados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por cliente
$agrupados = [];
foreach ($chamados as $ch) {
    $cliente = $ch['nome_fantasia'] ?: 'Cliente Desconhecido';
    if (!isset($agrupados[$cliente])) {
        $agrupados[$cliente] = [];
    }
    $agrupados[$cliente][] = $ch;
}
?>

<div class="container-fluid px-0">

<!-- HEADER -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="mb-1" style="font-size:1.6rem;">
            <i class="bi bi-inbox-fill me-2" style="color:var(--success)"></i>Chamados Acumulados
        </h1>
        <p class="mb-0" style="color:var(--text-muted);font-size:.85rem;">
            Gerencie os chamados salvos localmente para notificar os clientes em lote.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <?php if (empty($agrupados)): ?>
        <div class="card border-0 text-center py-5 shadow-sm">
            <div class="card-body">
                <i class="bi bi-emoji-smile" style="font-size:3rem;color:var(--text-muted)"></i>
                <h4 class="mt-3 text-muted">Nenhum chamado acumulado.</h4>
                <p class="mb-0">Vá até a tela de <a href="chamados_gestaopro.php">Chamados de Suporte</a> e salve chamados localmente.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="accordion" id="accordionAcumulados">
                    <?php 
                    $index = 0;
                    foreach ($agrupados as $cliente => $lista): 
                        $index++;
                        $headerId = "heading-$index";
                        $collapseId = "collapse-$index";
                    ?>
                    <div class="accordion-item border-0 border-bottom">
                        <h2 class="accordion-header" id="<?= $headerId ?>">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>">
                                <div class="d-flex align-items-center justify-content-between w-100 pe-3">
                                    <div class="fw-bold" style="color: inherit;">
                                        <i class="bi bi-building me-2 text-primary"></i> <?= htmlspecialchars($cliente) ?>
                                    </div>
                                    <span class="badge bg-primary rounded-pill"><?= count($lista) ?> chamado(s)</span>
                                </div>
                            </button>
                        </h2>
                        <div id="<?= $collapseId ?>" class="accordion-collapse collapse" data-bs-parent="#accordionAcumulados">
                            <div class="accordion-body" style="background:var(--bg-body); color:var(--text-dark);">
                                <div class="d-flex justify-content-end mb-3 gap-2">
                                    <button class="btn btn-sm btn-outline-success fw-bold btn-gerar-txt" data-cliente="<?= htmlspecialchars($cliente) ?>" data-chamados='<?= htmlspecialchars(json_encode($lista), ENT_QUOTES, 'UTF-8') ?>'>
                                        <i class="bi bi-file-earmark-text"></i> Gerar TXT WhatsApp
                                    </button>
                                    <button class="btn btn-sm btn-success fw-bold btn-marcar-enviado" data-ids='<?= json_encode(array_column($lista, 'id_chamado_api')) ?>'>
                                        <i class="bi bi-check-all"></i> Marcar como Enviado
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0" style="font-size:.85rem; color:var(--text-dark);">
                                        <thead style="background:var(--bg-body); color:var(--text-dark);">
                                            <tr>
                                                <th>ID</th>
                                                <th>Status</th>
                                                <th>Tipo</th>
                                                <th>Descrição</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($lista as $ch): 
                                                $isErro = strcasecmp(trim($ch['tipo_acompanhamento']), 'Erro de Sistema') === 0;
                                            ?>
                                            <tr style="<?= $isErro ? 'opacity:0.6;' : '' ?>">
                                                <td>#<?= $ch['id_chamado_api'] ?></td>
                                                <td><?= htmlspecialchars($ch['status_chamado']) ?></td>
                                                <td>
                                                    <?= htmlspecialchars($ch['tipo_acompanhamento']) ?>
                                                    <?php if($isErro): ?><br><span class="badge bg-secondary" style="font-size:0.65rem">Oculto no TXT</span><?php endif; ?>
                                                </td>
                                                <td style="max-width: 400px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($ch['descricao_problema']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

</div>

<style>
/* Corrige as cores da sanfona (accordion) do Bootstrap no modo escuro */
[data-theme="dark"] .accordion-button:not(.collapsed) {
    background-color: rgba(0, 0, 0, 0.2);
    color: var(--text-dark);
}
[data-theme="dark"] .accordion-button {
    background-color: var(--bg-card);
    color: var(--text-dark);
}
[data-theme="dark"] .accordion-item {
    background-color: var(--bg-card);
    border-color: rgba(255, 255, 255, 0.1);
}
.accordion-button:not(.collapsed) .fw-bold {
    color: inherit !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // Limpa lixo técnico (logs de erro do Delphi) das descrições
    function limparTextoTecnico(texto) {
        let limpo = String(texto || 'Sem descrição');
        const marcadores = ['Exception class:', 'cdsCadUsuario:', 'Exception message:', 'Stack trace:', 'Erro técnico:'];
        for (let m of marcadores) {
            const idx = limpo.indexOf(m);
            if (idx !== -1) {
                limpo = limpo.substring(0, idx); // Corta o texto a partir do erro
            }
        }
        return limpo;
    }

    // Função para criar o texto para o TXT
    function criarTextoWhatsApp(cliente, chamados) {
        const clienteNome = cliente ? `"${cliente}"` : 'o cliente';
        
        // Remove chamados de Erro de Sistema
        const chamadosFiltrados = chamados.filter(ch => (ch.tipo_acompanhamento || '').trim().toLowerCase() !== 'erro de sistema');
        
        if (chamadosFiltrados.length === 0) return '';

        const linhas = chamadosFiltrados.map(ch => {
            const descricao = limparTextoTecnico(ch.descricao_problema)
                .replace(/\r\n/g,'\n').replace(/\r/g,'\n').split('\n').map(line => line.trim()).join('\r\n');
            return `📋 Chamado #${ch.id_chamado_api}\r\n` +
                   `🏷️ Tipo: ${ch.tipo_acompanhamento || 'Não informado'}\r\n` +
                   //`⏳ Status: ${ch.status_chamado}\r\n` +
                   `📝 Descrição:\r\n${descricao}`;
        });
        
        return `Olá ${clienteNome}! Gostaria de informar que os chamados abaixo foram *resolvidos*\r\n\r\n` +
               linhas.join('\r\n\r\n') +
               `\r\n\r\nPara que receba essa atualização, deslogue e logue do sistema. *Qualquer dúvida, estou à disposição! 🚀*`;
    }

    // Botão Gerar TXT
    document.querySelectorAll('.btn-gerar-txt').forEach(btn => {
        btn.addEventListener('click', function() {
            const cliente = this.dataset.cliente;
            const chamados = JSON.parse(this.dataset.chamados);
            const msg = criarTextoWhatsApp(cliente, chamados);
            
            if (!msg) {
                alert("Nenhum chamado relevante para envio. (Chamados do tipo 'Erro de Sistema' são ignorados).");
                return;
            }

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(msg).catch(e => console.error(e));
            }
            
            const nomeArquivo = cliente.replace(/[^a-z0-9]/gi, '_').toLowerCase();
            const blob = new Blob([msg], { type: 'text/plain;charset=utf-8' });
            const urlObj = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = urlObj;
            a.download = `chamados_acumulados_${nomeArquivo}.txt`;
            document.body.appendChild(a);
            a.click();
            setTimeout(() => {
                document.body.removeChild(a);
                window.URL.revokeObjectURL(urlObj);
                alert("TXT Gerado e copiado para a área de transferência!");
            }, 100);
        });
    });

    // Botão Marcar como Enviado
    document.querySelectorAll('.btn-marcar-enviado').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!confirm("Tem certeza que deseja marcar estes chamados como enviados? Eles sumirão desta lista.")) return;
            
            const ids = this.dataset.ids;
            const fd = new FormData();
            fd.append('ids', ids);

            fetch('marcar_chamados_notificados.php', {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(resp => {
                if(resp.sucesso) {
                    location.reload();
                } else {
                    alert("Erro: " + resp.erro);
                }
            });
        });
    });
});
</script>

<?php include 'footer.php'; ?>
