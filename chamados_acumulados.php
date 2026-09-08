<?php 
require_once 'config.php';
require_once 'header.php'; 

// Load CSS and Logo for HTML export
$css_premium = '';
if (file_exists(__DIR__ . '/css/premium-base.css')) {
    $css_premium = file_get_contents(__DIR__ . '/css/premium-base.css');
}
$logo_base64 = '';
if (file_exists(__DIR__ . '/css/pics/logo.webp')) {
    $logo_base64 = 'data:image/webp;base64,' . base64_encode(file_get_contents(__DIR__ . '/css/pics/logo.webp'));
}

// Busca chamados pendentes e enviados para permitir revisão e limpeza posterior.
$stmt = $pdo->query("SELECT * FROM chamados_espelho_local ORDER BY nome_fantasia ASC");
$chamados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por cliente, ignorando apenas TECHSOLUS e chamados finalizados.
// Não remover chamados apenas porque o cliente apareceu como encerrado no cadastro base,
// pois o item salvo localmente deve permanecer disponível para envio até ser marcado.
$agrupados = [];
$agrupadosEnviados = [];
foreach ($chamados as $ch) {
    $cliente = trim($ch['nome_fantasia'] ?: 'Cliente Desconhecido');
    $clienteUpper = mb_strtoupper($cliente);

    // 1. Não trazer TECHSOLUS
    if (strpos($clienteUpper, 'TECHSOLUS') !== false) {
        continue;
    }

    // 2. Não trazer chamados com status encerrado ou cancelado
    $statusChamadoUpper = mb_strtoupper(trim($ch['status_chamado'] ?? ''));
    if (in_array($statusChamadoUpper, ['ENCERRADO', 'ENCERRADA', 'CANCELADO', 'CANCELADA'])) {
        continue;
    }

    if ((int)($ch['notificado'] ?? 0) === 1) {
        if (!isset($agrupadosEnviados[$cliente])) {
            $agrupadosEnviados[$cliente] = [];
        }
        $agrupadosEnviados[$cliente][] = $ch;
    } else {
        if (!isset($agrupados[$cliente])) {
            $agrupados[$cliente] = [];
        }
        $agrupados[$cliente][] = $ch;
    }
}

function obterTimestampPrevRetornoTela($valor): int
{
    $texto = trim((string)$valor);
    if ($texto === '' || in_array($texto, ['0000-00-00 00:00:00', '1970-01-01 00:00:00'], true)) {
        return 0;
    }

    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d'] as $formato) {
        $data = DateTime::createFromFormat($formato, $texto);
        if ($data instanceof DateTime) {
            return $data->getTimestamp();
        }
    }

    return 0;
}

function formatarPrevRetornoTela($valor): string
{
    $timestamp = obterTimestampPrevRetornoTela($valor);
    return $timestamp > 0 ? date('d/m/Y H:i', $timestamp) : '-';
}

function ordenarChamadosPorPrevRetorno(array &$agrupamentos): void
{

    foreach ($agrupamentos as &$lista) {
        usort($lista, static function (array $a, array $b): int {
            $timestampA = obterTimestampPrevRetornoTela($a['data_prev_retorno'] ?? null);
            $timestampB = obterTimestampPrevRetornoTela($b['data_prev_retorno'] ?? null);

            if ($timestampA === 0) return 1;
            if ($timestampB === 0) return -1;
            return $timestampA <=> $timestampB;
        });
    }
    unset($lista);
}

ordenarChamadosPorPrevRetorno($agrupados);
ordenarChamadosPorPrevRetorno($agrupadosEnviados);
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
        <?php $index = 0; ?>
        <?php if (empty($agrupados) && empty($agrupadosEnviados)): ?>
        <div class="card border-0 text-center py-5 shadow-sm">
            <div class="card-body">
                <i class="bi bi-emoji-smile" style="font-size:3rem;color:var(--text-muted)"></i>
                <h4 class="mt-3 text-muted">Nenhum chamado acumulado.</h4>
                <p class="mb-0">Vá até a tela de <a href="chamados_gestaopro.php">Chamados de Suporte</a> e salve chamados localmente.</p>
            </div>
        </div>
        <?php elseif (!empty($agrupados)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="accordion" id="accordionAcumulados">
                    <?php 
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
                                    <button class="btn btn-sm btn-outline-danger fw-bold btn-deletar-selecionados" data-classe="chk-cliente-<?= $index ?>">
                                        <i class="bi bi-trash"></i> DELETAR CHAMADO
                                    </button>
                                    <button class="btn btn-sm btn-danger fw-bold btn-deletar-todos-cliente" data-cliente="<?= htmlspecialchars($cliente, ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="bi bi-trash3-fill"></i> Excluir Todos os Chamados
                                    </button>
                                    <button class="btn btn-sm btn-outline-primary fw-bold btn-gerar-html" data-cliente="<?= htmlspecialchars($cliente) ?>" data-chamados='<?= htmlspecialchars(json_encode($lista), ENT_QUOTES, 'UTF-8') ?>'>
                                        <i class="bi bi-file-earmark-code"></i> Gerar HTML WhatsApp
                                    </button>
                                    <button class="btn btn-sm btn-success fw-bold btn-marcar-enviado" data-ids='<?= json_encode(array_column($lista, 'id_chamado_api')) ?>'>
                                        <i class="bi bi-check-all"></i> Marcar como Enviado
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0" style="font-size:.85rem; color:var(--text-dark);">
                                        <thead style="background:var(--bg-body); color:var(--text-dark);">
                                            <tr>
                                                <th class="text-center" style="width: 40px;">
                                                    <input type="checkbox" class="form-check-input chk-all" data-target="chk-cliente-<?= $index ?>" title="Selecionar todos">
                                                </th>
                                                <th>ID</th>
                                                <th>Status</th>
                                                <th>Prev.Retorno</th>
                                                <th>Tipo</th>
                                                <th>Descrição</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($lista as $ch): 
                                                $isErro = strcasecmp(trim($ch['tipo_acompanhamento']), 'Erro de Sistema') === 0;
                                                
                                                $prevRetorno = formatarPrevRetornoTela($ch['data_prev_retorno'] ?? null);
                                            ?>
                                            <tr style="<?= $isErro ? 'opacity:0.6;' : '' ?>">
                                                <td class="text-center align-middle">
                                                    <input type="checkbox" class="form-check-input chk-chamado chk-cliente-<?= $index ?>" value="<?= $ch['id_chamado_api'] ?>">
                                                </td>
                                                <td>#<?= $ch['id_chamado_api'] ?></td>
                                                <td><?= htmlspecialchars($ch['status_chamado'] ?? '') ?></td>
                                                <td><?= $prevRetorno ?></td>
                                                <td>
                                                    <?= htmlspecialchars($ch['tipo_acompanhamento'] ?? '') ?>
                                                    <?php if($isErro): ?><br><span class="badge bg-secondary" style="font-size:0.65rem">Oculto no TXT</span><?php endif; ?>
                                                </td>
                                                <td style="max-width: 400px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($ch['descricao_problema'] ?? '') ?></td>
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

        <?php if (!empty($agrupadosEnviados)): ?>
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header border-0 py-3" style="background:var(--bg-card);">
                <h4 class="mb-0 fw-bold"><i class="bi bi-check-circle-fill text-success me-2"></i>Chamados Enviados</h4>
                <small class="text-muted">Confira os chamados enviados e selecione os que deseja excluir do banco.</small>
            </div>
            <div class="card-body p-0">
                <div class="accordion" id="accordionEnviados">
                    <?php foreach ($agrupadosEnviados as $cliente => $lista):
                        $index++;
                        $headerId = "heading-enviado-$index";
                        $collapseId = "collapse-enviado-$index";
                    ?>
                    <div class="accordion-item border-0 border-bottom">
                        <h2 class="accordion-header" id="<?= $headerId ?>">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>">
                                <div class="d-flex align-items-center justify-content-between w-100 pe-3">
                                    <div class="fw-bold" style="color: inherit;"><i class="bi bi-building me-2 text-primary"></i><?= htmlspecialchars($cliente) ?></div>
                                    <span class="badge bg-success rounded-pill"><?= count($lista) ?> chamado(s)</span>
                                </div>
                            </button>
                        </h2>
                        <div id="<?= $collapseId ?>" class="accordion-collapse collapse" data-bs-parent="#accordionEnviados">
                            <div class="accordion-body" style="background:var(--bg-body); color:var(--text-dark);">
                                <div class="d-flex justify-content-end mb-3 gap-2">
                                    <button class="btn btn-sm btn-outline-danger fw-bold btn-deletar-selecionados" data-classe="chk-enviado-cliente-<?= $index ?>">
                                        <i class="bi bi-trash"></i> DELETAR CHAMADO
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0" style="font-size:.85rem; color:var(--text-dark);">
                                        <thead style="background:var(--bg-body); color:var(--text-dark);">
                                            <tr>
                                                <th class="text-center" style="width: 40px;"><input type="checkbox" class="form-check-input chk-all" data-target="chk-enviado-cliente-<?= $index ?>" title="Selecionar todos"></th>
                                                <th>ID</th><th>Status</th><th>Prev.Retorno</th><th>Tipo</th><th>Descrição</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($lista as $ch):
                                                $prevRetorno = formatarPrevRetornoTela($ch['data_prev_retorno'] ?? null);
                                            ?>
                                            <tr>
                                                <td class="text-center align-middle"><input type="checkbox" class="form-check-input chk-chamado chk-enviado-cliente-<?= $index ?>" value="<?= $ch['id_chamado_api'] ?>"></td>
                                                <td>#<?= $ch['id_chamado_api'] ?></td>
                                                <td><?= htmlspecialchars($ch['status_chamado'] ?? '') ?></td>
                                                <td><?= $prevRetorno ?></td>
                                                <td><?= htmlspecialchars($ch['tipo_acompanhamento'] ?? '') ?></td>
                                                <td style="max-width: 400px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($ch['descricao_problema'] ?? '') ?></td>
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

    // Checkbox Selecionar Todos
    document.querySelectorAll('.chk-all').forEach(chk => {
        chk.addEventListener('change', function() {
            const targetClass = this.dataset.target;
            const checkboxes = document.querySelectorAll('.' + targetClass);
            checkboxes.forEach(c => {
                c.checked = this.checked;
            });
        });
    });

    // Atualiza o Checkbox "Selecionar Todos" quando os filhos mudam
    document.querySelectorAll('.chk-chamado').forEach(chk => {
        chk.addEventListener('change', function() {
            const match = this.className.match(/(chk-cliente-\d+)/);
            if (match) {
                const targetClass = match[1];
                const allCheckboxes = document.querySelectorAll('.' + targetClass);
                const allChecked = Array.from(allCheckboxes).every(c => c.checked);
                const isIndeterminate = Array.from(allCheckboxes).some(c => c.checked) && !allChecked;
                
                const chkAll = document.querySelector('.chk-all[data-target="' + targetClass + '"]');
                if (chkAll) {
                    chkAll.checked = allChecked;
                    chkAll.indeterminate = isIndeterminate;
                }
            }
        });
    });

    // Botão Deletar Selecionados
    document.querySelectorAll('.btn-deletar-selecionados').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetClass = this.dataset.classe;
            const checkboxes = document.querySelectorAll('.' + targetClass + ':checked');
            
            if (checkboxes.length === 0) {
                alert('Nenhum chamado selecionado para deletar.');
                return;
            }

            if (!confirm(`Tem certeza que deseja remover os ${checkboxes.length} chamados selecionados desta tela?\nEles voltarão a ficar disponíveis para serem importados novamente da API.`)) return;

            const ids = Array.from(checkboxes).map(c => c.value);
            
            const fd = new FormData();
            fd.append('ids', JSON.stringify(ids));

            fetch('deletar_chamados_acumulados.php', {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(resp => {
                if(resp.sucesso) {
                    location.reload();
                } else {
                    alert("Erro ao deletar: " + resp.erro);
                }
            }).catch(e => {
                console.error(e);
                alert("Erro de comunicação ao deletar.");
            });
        });
    });

    // Botão Excluir Todos os Chamados do Cliente
    document.querySelectorAll('.btn-deletar-todos-cliente').forEach(btn => {
        btn.addEventListener('click', function() {
            const cliente = this.dataset.cliente;
            const mensagem = `Esta ação é irreversível e excluirá todos os chamados de "${cliente}", incluindo os que já estão marcados como "Enviado". Deseja continuar?`;

            if (!confirm(mensagem)) return;

            const fd = new FormData();
            fd.append('cliente', cliente);

            fetch('deletar_chamados_acumulados.php', {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(resp => {
                if (resp.sucesso) {
                    location.reload();
                } else {
                    alert("Erro ao excluir chamados: " + resp.erro);
                }
            }).catch(e => {
                console.error(e);
                alert("Erro de comunicação ao excluir chamados.");
            });
        });
    });

    function criarHTMLWhatsAppModelo(cliente, chamados) {
        const clienteNome = cliente || 'Cliente';
        const chamadosFiltrados = chamados;

        if (chamadosFiltrados.length === 0) return '';

        function escapeHTML(valor) {
            return String(valor ?? '').replace(/[&<>'"]/g, function(caractere) {
                const caracteres = {'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'};
                return caracteres[caractere];
            });
        }

        function linkify(texto) {
            return texto.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank">$1</a>');
        }

        function formatarData(valor) {
            if (!valor || valor === '0000-00-00 00:00:00') return '';
            const partes = String(valor).split(/[- :]/);
            return partes.length >= 3 ? `${partes[2]}/${partes[1]}/${partes[0]}` : '';
        }

        function obterTimestampPrevRetorno(valor) {
            const texto = String(valor || '').trim();
            if (!texto || texto === '0000-00-00 00:00:00' || texto === '1970-01-01 00:00:00') return 0;

            const iso = texto.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/);
            if (iso) {
                return new Date(Number(iso[1]), Number(iso[2]) - 1, Number(iso[3]), Number(iso[4] || 0), Number(iso[5] || 0), Number(iso[6] || 0)).getTime();
            }

            const brasileira = texto.match(/^(\d{2})\/(\d{2})\/(\d{4})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/);
            if (brasileira) {
                return new Date(Number(brasileira[3]), Number(brasileira[2]) - 1, Number(brasileira[1]), Number(brasileira[4] || 0), Number(brasileira[5] || 0), Number(brasileira[6] || 0)).getTime();
            }

            return 0;
        }

        function classeStatus(status) {
            const texto = String(status || '').toLowerCase();
            if (texto.includes('desenvolvimento')) return 'warning';
            if (texto.includes('teste')) return 'primary';
            if (texto.includes('cancel') || texto.includes('erro')) return 'danger';
            if (texto.includes('resol') || texto.includes('encerr')) return 'success';
            return 'neutral';
        }

        const grupos = {};
        chamadosFiltrados.forEach(ch => {
            const status = String(ch.status_chamado || 'Sem status').trim();
            if (!grupos[status]) grupos[status] = [];
            grupos[status].push(ch);
        });

        Object.values(grupos).forEach(lista => {
            lista.sort((a, b) => {
                const dataA = obterTimestampPrevRetorno(a.data_prev_retorno);
                const dataB = obterTimestampPrevRetorno(b.data_prev_retorno);
                if (dataA === 0) return 1;
                if (dataB === 0) return -1;
                return dataA - dataB;
            });
        });

        const resumo = Object.entries(grupos).map(([status, lista]) => {
            const classe = classeStatus(status);
            return `<div class="dashboard-card st-${classe}"><span class="count">${lista.length}</span><span class="status-name">${escapeHTML(status)}</span></div>`;
        }).join('');

        const secoes = Object.entries(grupos).map(([status, lista]) => {
            const classe = classeStatus(status);
            const cards = lista.map(ch => {
                let descricao = limparTextoTecnico(ch.descricao_problema);
                descricao = linkify(escapeHTML(descricao)).replace(/\r\n|\r|\n/g, '<br>');
                const prevRetorno = formatarData(ch.data_prev_retorno);
                return `<article class="ticket">
                    <div class="ticket-header">
                        <div class="ticket-id"><span class="ticket-number">#${escapeHTML(ch.id_chamado_api)}</span><span class="ticket-status st-${classe}">${escapeHTML(status)}</span></div>
                        <div class="ticket-meta">${prevRetorno ? `<span class="ticket-type ticket-prev">Prev: ${prevRetorno}</span>` : ''}<span class="ticket-type">${escapeHTML(ch.tipo_acompanhamento || 'Não informado')}</span></div>
                    </div>
                    <div class="ticket-body"><div class="ticket-label">Descrição</div><div class="ticket-description">${descricao}</div></div>
                </article>`;
            }).join('');
            return `<section class="status-group"><div class="section-title st-${classe}">${escapeHTML(status)} <span class="badge-count">${lista.length}</span></div><div class="tickets">${cards}</div></section>`;
        }).join('');

        const logoImg = window.appDataLogo ? `<img src="${window.appDataLogo}" alt="Logo" class="header-logo">` : '';
        const dataGeracao = new Date().toLocaleString('pt-BR', {dateStyle: 'short', timeStyle: 'short'});

        return `<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chamados — ${escapeHTML(clienteNome)}</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#3b5bdb;--primary-soft:#edf2ff;--primary-dark:#364fc7;--success:#0ca678;--success-soft:#e6fcf5;--success-dark:#087f5b;--warning:#f08c00;--warning-soft:#fff9db;--warning-dark:#e67700;--danger:#e03131;--danger-soft:#fff5f5;--danger-dark:#c92a2a;--neutral:#495057;--neutral-soft:#f1f3f5;--neutral-dark:#343a40;--text:#1a1b1e;--text-secondary:#5c5f66;--text-muted:#868e96;--border:#e9ecef;--bg:#f1f3f5;--card-bg:#fff;--radius:16px;--radius-sm:10px;--shadow:0 1px 2px rgba(0,0,0,.04),0 4px 16px rgba(0,0,0,.04)}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);line-height:1.6;padding:40px 20px 60px;min-height:100vh}.wrapper{max-width:720px;margin:0 auto}.accent-bar{height:4px;background:linear-gradient(90deg,var(--primary),var(--success));border-radius:0 0 4px 4px;margin:-40px auto 32px;max-width:720px}.header{text-align:center;margin-bottom:28px}.header-logo{display:block;max-width:140px;height:auto;margin:0 auto 24px;filter:drop-shadow(0 2px 8px rgba(0,0,0,.06))}.header h1{font-size:1.65rem;font-weight:700;margin-bottom:6px}.client-name{font-size:.95rem;font-weight:500;color:var(--text-secondary)}.generated-at{font-size:.8rem;color:var(--text-muted);margin-top:4px}.intro{background:linear-gradient(135deg,#e6fcf5,#d3f9d8);border:1px solid rgba(12,166,120,.15);border-radius:var(--radius);padding:18px 22px;margin-bottom:28px;display:flex;align-items:flex-start;gap:14px}.intro-icon{flex-shrink:0;width:36px;height:36px;background:var(--success);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px}.intro-text{font-size:.95rem;color:var(--success-dark);line-height:1.55;padding-top:6px}.dashboard{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:22px;margin-bottom:32px}.dashboard-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:16px}.dashboard-grid{display:flex;flex-wrap:wrap;align-items:center;gap:8px}.dashboard-card{border-radius:var(--radius-sm);padding:8px 12px;display:flex;align-items:baseline;gap:6px;border:1px solid var(--border);flex:0 0 auto}.dashboard-card .count{font-size:1rem;font-weight:700}.status-name{font-size:.68rem;font-weight:600;text-transform:uppercase;line-height:1.3}.st-success{background:var(--success-soft);border-color:rgba(12,166,120,.2)}.st-success .count,.st-success.section-title{color:var(--success-dark)}.st-warning{background:var(--warning-soft);border-color:rgba(240,140,0,.2)}.st-warning .count,.st-warning.section-title{color:var(--warning-dark)}.st-danger{background:var(--danger-soft);border-color:rgba(224,49,49,.2)}.st-danger .count,.st-danger.section-title{color:var(--danger-dark)}.st-primary{background:var(--primary-soft);border-color:rgba(59,91,219,.2)}.st-primary .count,.st-primary.section-title{color:var(--primary-dark)}.st-neutral{background:var(--neutral-soft);border-color:rgba(73,80,87,.2)}.st-neutral .count,.st-neutral.section-title{color:var(--neutral-dark)}.status-group{margin-bottom:32px}.section-title{display:flex;align-items:center;gap:10px;font-size:1.05rem;font-weight:700;margin-bottom:14px;padding:0 2px}.badge-count{font-size:.68rem;font-weight:700;background:rgba(0,0,0,.06);border-radius:100px;padding:2px 8px}.tickets{display:flex;flex-direction:column;gap:16px}.ticket{background:var(--card-bg);border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border);overflow:hidden}.ticket-header{display:flex;align-items:center;justify-content:space-between;padding:16px 22px;border-bottom:1px solid var(--border);background:linear-gradient(180deg,#fafbfc,#fff);flex-wrap:wrap;gap:10px}.ticket-id{display:flex;align-items:center;gap:10px}.ticket-number{font-size:1.05rem;font-weight:700}.ticket-status{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.03em;padding:3px 8px;border-radius:100px}.ticket-meta{display:flex;align-items:center;flex-wrap:wrap;justify-content:flex-end}.ticket-type{font-size:11px;font-weight:500;color:var(--primary);background:var(--primary-soft);padding:3px 9px;border-radius:100px;white-space:nowrap;line-height:1.4;margin-left:8px}.ticket-prev{color:var(--success-dark);background:var(--success-soft)}.ticket-body{padding:18px 22px 22px}.ticket-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:8px}.ticket-description{font-size:.925rem;color:var(--text-secondary);line-height:1.65;white-space:pre-line}.ticket-description a{color:var(--primary);text-decoration:none;word-break:break-all}.footer-note{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);padding:22px 24px;text-align:center;box-shadow:var(--shadow)}.footer-note p{font-size:.9rem;color:var(--text-secondary);margin-bottom:6px}.footer-note .highlight{font-weight:600;color:var(--text);font-size:.95rem}@media(max-width:540px){body{padding:24px 14px 40px}.header h1{font-size:1.35rem}.ticket-header{flex-direction:column;align-items:flex-start}.ticket-header,.ticket-body{padding-left:16px;padding-right:16px}.intro{padding:14px 16px}}
 </style></head><body><div class="accent-bar"></div><div class="wrapper"><header class="header">${logoImg}<h1>Relatório de Chamados</h1><p class="client-name">${escapeHTML(clienteNome)}</p><p class="generated-at">Gerado em ${dataGeracao}</p></header><section class="dashboard"><div class="dashboard-title">Resumo Geral</div><div class="dashboard-grid">${resumo}</div></section>${secoes}<footer class="footer-note"><p class="highlight">Total de chamados: ${chamadosFiltrados.length}</p><p>Aguarde, novas instruções serão enviadas.</p><p class="highlight">Qualquer dúvida, estou à disposição! 🚀</p></footer></div></body></html>`;
    }

    // Função legada mantida para compatibilidade interna
    function criarHTMLWhatsApp(cliente, chamados) {
        const clienteNome = cliente ? cliente : 'Cliente';
        const chamadosFiltrados = chamados.filter(ch => (ch.tipo_acompanhamento || '').trim().toLowerCase() !== 'erro de sistema');
        
        if (chamadosFiltrados.length === 0) return '';

        const logoBase64Str = window.appDataLogo || '';

        // Transformar URLs em links âncora
        function linkify(text) {
            const urlRegex = /(https?:\/\/[^\s]+)/g;
            return text.replace(urlRegex, function(url) {
                return '<a href="' + url + '" target="_blank">' + url + '</a>';
            });
        }

        // Escapar caracteres HTML para não quebrar a tela
        function escapeHTML(str) {
            return String(str).replace(/[&<>'"]/g, function(tag) {
                const charsToReplace = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    "'": '&#39;',
                    '"': '&quot;'
                };
                return charsToReplace[tag] || tag;
            });
        }

        let cardsHtml = chamadosFiltrados.map(ch => {
            let descricao = limparTextoTecnico(ch.descricao_problema);
            descricao = escapeHTML(descricao);
            descricao = linkify(descricao);
            descricao = descricao.replace(/\r\n/g,'<br>').replace(/\r/g,'<br>').replace(/\n/g,'<br>');
            
            let prevRetorno = '';
            if (ch.data_prev_retorno && ch.data_prev_retorno !== '0000-00-00 00:00:00') {
                const parts = ch.data_prev_retorno.split(/[- :]/);
                if (parts.length >= 3) {
                    prevRetorno = `${parts[2]}/${parts[1]}/${parts[0]}`;
                }
            }
            
            let badgesExtra = '';
            if (prevRetorno) {
                badgesExtra = `<span class="ticket-type" style="background:var(--success-soft); color:var(--success-dark); margin-right:8px;">⏳ Prev: ${prevRetorno}</span>`;
            }

            return `
            <!-- Ticket -->
            <article class="ticket">
                <div class="ticket-header">
                    <div class="ticket-id">
                        <span class="ticket-number">#${ch.id_chamado_api}</span>
                        <span class="ticket-status">${ch.status_chamado || 'Resolvido'}</span>
                    </div>
                    <div style="display:flex; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
                        ${badgesExtra}
                        <span class="ticket-type">${ch.tipo_acompanhamento || 'Não informado'}</span>
                    </div>
                </div>
                <div class="ticket-body">
                    <div class="ticket-label">Descrição</div>
                    <div class="ticket-description">${descricao}</div>
                </div>
            </article>`;
        }).join('');

        let logoImg = '';
        if (logoBase64Str) {
            logoImg = '<img src="' + logoBase64Str + '" alt="Logo" class="header-logo">';
        }

        return `<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chamados Resolvidos — ${clienteNome}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #3b5bdb;
            --primary-soft: #edf2ff;
            --primary-dark: #364fc7;
            --success: #0ca678;
            --success-soft: #e6fcf5;
            --success-dark: #087f5b;
            --text: #1a1b1e;
            --text-secondary: #5c5f66;
            --text-muted: #868e96;
            --border: #e9ecef;
            --bg: #f1f3f5;
            --card-bg: #ffffff;
            --radius: 16px;
            --radius-sm: 10px;
            --shadow: 0 1px 2px rgba(0,0,0,0.04), 0 4px 16px rgba(0,0,0,0.04);
            --shadow-hover: 0 4px 12px rgba(0,0,0,0.06), 0 12px 28px rgba(0,0,0,0.06);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding: 40px 20px 60px;
            min-height: 100vh;
        }

        .wrapper { max-width: 720px; margin: 0 auto; }

        /* Header */
        .header { text-align: center; margin-bottom: 36px; }
        .header-logo { display: block; max-width: 140px; height: auto; margin: 0 auto 24px; filter: drop-shadow(0 2px 8px rgba(0,0,0,0.06)); }
        .header h1 { font-size: 1.65rem; font-weight: 700; color: var(--text); letter-spacing: -0.03em; margin-bottom: 6px; }
        .header .client-name { font-size: 0.95rem; font-weight: 500; color: var(--text-secondary); letter-spacing: 0.01em; }

        /* Intro message */
        .intro {
            background: linear-gradient(135deg, #e6fcf5 0%, #d3f9d8 100%);
            border: 1px solid rgba(12, 166, 120, 0.15); border-radius: var(--radius); padding: 18px 22px;
            margin-bottom: 28px; display: flex; align-items: flex-start; gap: 14px;
        }
        .intro-icon {
            flex-shrink: 0; width: 36px; height: 36px; background: var(--success); border-radius: 10px;
            display: flex; align-items: center; justify-content: center; color: white; font-size: 16px;
        }
        .intro-text { font-size: 0.95rem; color: var(--success-dark); line-height: 1.55; padding-top: 6px; }
        .intro-text strong { font-weight: 600; }

        /* Ticket cards */
        .tickets { display: flex; flex-direction: column; gap: 16px; margin-bottom: 32px; }
        .ticket {
            background: var(--card-bg); border-radius: var(--radius); box-shadow: var(--shadow);
            border: 1px solid var(--border); overflow: hidden; transition: box-shadow 0.25s ease, transform 0.25s ease;
        }
        .ticket:hover { box-shadow: var(--shadow-hover); transform: translateY(-1px); }
        .ticket-header {
            display: flex; align-items: center; justify-content: space-between; padding: 16px 22px;
            border-bottom: 1px solid var(--border); background: linear-gradient(180deg, #fafbfc 0%, #ffffff 100%);
        }
        .ticket-id { display: flex; align-items: center; gap: 10px; }
        .ticket-number { font-size: 1.05rem; font-weight: 700; color: var(--text); letter-spacing: -0.02em; }
        .ticket-status {
            font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;
            color: var(--success-dark); background: var(--success-soft); padding: 4px 10px; border-radius: 100px;
        }
        .ticket-type {
            font-size: 12px; font-weight: 500; color: var(--primary); background: var(--primary-soft);
            padding: 5px 12px; border-radius: 100px; white-space: nowrap;
        }
        .ticket-body { padding: 18px 22px 22px; }
        .ticket-label {
            font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;
            color: var(--text-muted); margin-bottom: 8px;
        }
        .ticket-description { font-size: 0.925rem; color: var(--text-secondary); line-height: 1.65; white-space: pre-line; }
        .ticket-description a { color: var(--primary); text-decoration: none; word-break: break-all; }
        .ticket-description a:hover { text-decoration: underline; }

        /* Footer note */
        .footer-note {
            background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 22px 24px; text-align: center; box-shadow: var(--shadow);
        }
        .footer-note p { font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 6px; }
        .footer-note .highlight { font-weight: 600; color: var(--text); font-size: 0.95rem; }
        .accent-bar {
            height: 4px; background: linear-gradient(90deg, var(--primary) 0%, var(--success) 100%);
            border-radius: 0 0 4px 4px; margin: -40px auto 32px; max-width: 720px;
        }

        @media (max-width: 540px) {
            body { padding: 24px 14px 40px; }
            .header h1 { font-size: 1.35rem; }
            .ticket-header { flex-direction: column; align-items: flex-start; gap: 10px; }
            .intro { padding: 14px 16px; }
            .ticket-header, .ticket-body { padding-left: 16px; padding-right: 16px; }
        }
    </style>
</head>
<body>
    <div class="accent-bar"></div>

    <div class="wrapper">
        <!-- Header -->
        <header class="header">
            ${logoImg}
            <h1>Relatório de Chamados</h1>
            <p class="client-name">${clienteNome}</p>
        </header>

        <!-- Intro -->
        <div class="intro">
            <div class="intro-icon">✓</div>
            <p class="intro-text">
                Olá! Gostaria de informar a lista de chamados em aberto
            </p>
        </div>

        <!-- Tickets -->
        <div class="tickets">
            ${cardsHtml}
        </div>

        <!-- Footer -->
        <footer class="footer-note">
            <p>Aguarde, novas instruções serão enviadas.</p>
            <p class="highlight">Qualquer dúvida, estou à disposição! 🚀</p>
        </footer>
    </div>
</body>
</html>`;
    }

    // Botão Gerar HTML
    document.querySelectorAll('.btn-gerar-html').forEach(btn => {
        btn.addEventListener('click', function() {
            const cliente = this.dataset.cliente;
            const chamados = JSON.parse(this.dataset.chamados);
            const msg = criarHTMLWhatsAppModelo(cliente, chamados);
            
            if (!msg) {
                alert("Nenhum chamado relevante para envio. (Chamados do tipo 'Erro de Sistema' são ignorados).");
                return;
            }

            const nomeArquivo = cliente.replace(/[^a-z0-9]/gi, '_').toLowerCase();
            const blob = new Blob([msg], { type: 'text/html;charset=utf-8' });
            const urlObj = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = urlObj;
            a.download = `listagem_chamados_${nomeArquivo}.html`;
            document.body.appendChild(a);
            a.click();
            setTimeout(() => {
                document.body.removeChild(a);
                window.URL.revokeObjectURL(urlObj);
            }, 100);
        });
    });
});
</script>

<script>
    window.appDataCss = <?= json_encode($css_premium, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    window.appDataLogo = <?= json_encode($logo_base64, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>

<?php include 'footer.php'; ?>
