<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/header.php';
?>
<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h1 class="mb-1" style="font-size:1.6rem"><i class="bi bi-activity me-2" style="color:var(--primary)"></i>Uso do GestãoPro</h1>
            <p class="mb-0" style="color:var(--text-muted);font-size:.85rem">Acessos dos clientes nos últimos 30 dias, associados pelo ID da API e conferidos pelo SERIAL.</p>
        </div>
        <button id="btn-atualizar-uso" class="btn btn-primary btn-sm"><i class="bi bi-arrow-clockwise me-1"></i>Atualizar</button>
    </div>
    <div class="card border-0">
        <div class="card-body p-0">
            <div id="uso-status" class="px-4 py-3 small" style="color:var(--text-muted)">Carregando dados de uso…</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr style="background:var(--bg-body);font-size:.72rem;text-transform:uppercase;color:var(--text-muted)">
                        <th class="px-4 py-3">Cliente</th><th>ID API</th><th>SERIAL cadastrado</th><th>Atividade</th><th>Conferência SERIAL</th><th>Resposta da API</th>
                    </tr></thead>
                    <tbody id="uso-clientes"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script>
(() => {
    const tbody = document.getElementById('uso-clientes');
    const status = document.getElementById('uso-status');
    const btn = document.getElementById('btn-atualizar-uso');
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const findSerial = value => {
        if (!value || typeof value !== 'object') return '';
        for (const [key, item] of Object.entries(value)) {
            if (key.toUpperCase() === 'SERIAL' && (typeof item === 'string' || typeof item === 'number')) return String(item);
            const found = findSerial(item);
            if (found) return found;
        }
        return '';
    };
    const activityLabel = data => {
        if (data === null) return 'Sem resposta';
        if (data === false || (data && (data.sucesso === false || data.success === false))) return 'Falha na consulta';
        if (Array.isArray(data)) return `${data.length} registro(s)`;
        if (typeof data === 'object') {
            for (const [key, value] of Object.entries(data)) {
                if (/(total|quantidade|acessos|eventos|dias_ativos|sessoes)/i.test(key) && (typeof value === 'number' || /^\d+$/.test(String(value)))) return `${key.replaceAll('_',' ')}: ${value}`;
            }
            return Object.keys(data).length ? 'Dados recebidos' : 'Sem atividade no período';
        }
        return String(data);
    };
    async function carregar(forcar = false) {
        btn.disabled = true;
        status.textContent = 'Consultando uso dos clientes…';
        try {
            const url = `api_gestaopro_bridge.php?endpoint=uso&dias=30${forcar ? '&forcar=1' : ''}`;
            const response = await fetch(url, {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            const json = await response.json();
            if (!response.ok || !json.sucesso) throw new Error(json.erro || 'Não foi possível consultar a API.');
            const clientes = json.dados?.clientes || [];
            tbody.innerHTML = clientes.map(cliente => {
                const apiSerial = findSerial(cliente.dados);
                const cadastrado = String(cliente.serial_cadastro || '').trim();
                const confere = !apiSerial ? 'Não informado pela API' : (apiSerial.toLowerCase() === cadastrado.toLowerCase() ? 'Confere' : 'Divergente');
                const estado = cliente.dados === null ? '<span class="badge text-bg-secondary">Indisponível</span>' : `<span class="badge ${/(sem atividade|zero|0 registro)/i.test(activityLabel(cliente.dados)) ? 'text-bg-warning' : 'text-bg-success'}">${esc(activityLabel(cliente.dados))}</span>`;
                const detalhe = esc(JSON.stringify(cliente.dados, null, 2) || 'Sem dados');
                return `<tr><td class="px-4 fw-semibold">${esc(cliente.fantasia)}</td><td>${esc(cliente.id_cliente_api)}</td><td><code>${esc(cadastrado || '—')}</code></td><td>${estado}</td><td>${esc(confere)}${apiSerial ? `<div class="small text-muted"><code>${esc(apiSerial)}</code></div>` : ''}</td><td><details><summary style="cursor:pointer">Ver dados</summary><pre class="small mt-2 mb-0" style="max-width:480px;white-space:pre-wrap">${detalhe}</pre></details></td></tr>`;
            }).join('') || '<tr><td colspan="6" class="text-center py-4 text-muted">Nenhum cliente tem ID Cliente API cadastrado.</td></tr>';
            status.textContent = `Período: 30 dias · Atualizado em ${json.gerado_em || 'agora'} · Origem: ${json.origem === 'cache' ? 'cache (até 5 minutos)' : 'API'}`;
        } catch (error) {
            status.textContent = error.message;
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger">Falha ao carregar os dados de uso.</td></tr>';
        } finally { btn.disabled = false; }
    }
    btn.addEventListener('click', () => carregar(true));
    carregar();
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
