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
                        <th class="px-4 py-3">Cliente</th><th>Último Acesso</th><th>ID API</th><th>SERIAL cadastrado</th><th>Conferência SERIAL</th><th>Detalhes</th>
                    </tr></thead>
                    <tbody id="uso-clientes"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<style>
.uso-acesso-verde > * { background-color:#dcfce7 !important; color:#166534; }
.uso-acesso-laranja > * { background-color:#ffedd5 !important; color:#9a3412; }
.uso-acesso-vermelho > * { background-color:#fee2e2 !important; color:#991b1b; }
.table-hover > tbody > .uso-acesso-verde:hover > * { background-color:#bbf7d0 !important; }
.table-hover > tbody > .uso-acesso-laranja:hover > * { background-color:#fed7aa !important; }
.table-hover > tbody > .uso-acesso-vermelho:hover > * { background-color:#fecaca !important; }
</style>
<div class="modal fade" id="modalDetalhesUso" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content gp-usage-modal">
            <div class="modal-header gp-usage-head">
                <div class="gp-usage-heading"><div class="gp-usage-mark"><i class="bi bi-activity"></i></div><div><span class="gp-usage-kicker">GESTAOPRO / ATIVIDADE</span><h5 class="gp-usage-title" id="modalDetalhesUsoTitulo">Uso do cliente</h5><div class="gp-usage-status">Consulta atualizada pela API da mesa</div></div></div>
                <span class="gp-usage-live"><span class="gp-usage-live-dot"></span> Atualiza&ccedil;&atilde;o for&ccedil;ada</span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body gp-usage-body" id="modalDetalhesUsoBody"></div>
        </div>
    </div>
</div>
<style>

.gp-usage-modal { border:1px solid var(--border-color); border-radius:22px; overflow:hidden; background:var(--bg-card); box-shadow:0 24px 80px rgba(15,23,42,.28); }
.gp-usage-head { min-height:116px; padding:22px 26px; border:0; color:#fff; background:radial-gradient(circle at 88% 0%,rgba(45,212,191,.28),transparent 28%),linear-gradient(125deg,#182353 0%,#273b83 58%,#3155a5 100%); }
.gp-usage-head .btn-close { filter:invert(1); opacity:.82; }
.gp-usage-heading { display:flex; align-items:center; gap:15px; min-width:0; }
.gp-usage-mark { display:grid; place-items:center; flex:0 0 48px; height:48px; border:1px solid rgba(255,255,255,.22); border-radius:15px; color:#b8fff2; background:rgba(255,255,255,.12); font-size:1.3rem; }
.gp-usage-kicker { display:block; margin-bottom:4px; color:#b8c6f3; font-size:.64rem; font-weight:800; letter-spacing:.16em; }
.gp-usage-title { margin:0; color:#fff; font-size:1.18rem; font-weight:750; letter-spacing:-.02em; }
.gp-usage-status { margin-top:4px; color:#d4defa; font-size:.76rem; }
.gp-usage-live { display:inline-flex; align-items:center; gap:8px; margin:0 20px 0 auto; padding:7px 11px; border:1px solid rgba(255,255,255,.2); border-radius:999px; color:#eef2ff; background:rgba(255,255,255,.1); font-size:.72rem; white-space:nowrap; }
.gp-usage-live-dot { width:7px; height:7px; border-radius:50%; background:#5eead4; box-shadow:0 0 0 4px rgba(94,234,212,.15); }
.gp-usage-body { padding:24px 26px 28px; background:var(--bg-body); }
.gp-usage-toolbar { display:flex; align-items:end; justify-content:space-between; gap:12px; margin-bottom:16px; }
.gp-usage-section-kicker { color:var(--primary); font-size:.62rem; font-weight:800; letter-spacing:.14em; text-transform:uppercase; }
.gp-usage-section-title { margin:4px 0 0; color:var(--text-dark); font-size:1rem; font-weight:750; }
.gp-usage-period { display:inline-flex; align-items:center; gap:7px; padding:8px 11px; border:1px solid var(--border-color); border-radius:10px; color:var(--text-muted); background:var(--bg-card); font-size:.74rem; white-space:nowrap; }
.gp-usage-kpis { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:11px; margin-bottom:19px; }
.gp-usage-metrics { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:9px; }
.gp-usage-card { position:relative; display:flex; align-items:center; gap:11px; min-width:0; min-height:86px; padding:14px 12px; overflow:hidden; border:1px solid var(--border-color); border-radius:15px; background:var(--bg-card); animation:gpUsageEnter .36s both; }
.gp-usage-card:before { position:absolute; inset:0 auto 0 0; width:3px; background:var(--usage-accent,var(--primary)); content:""; }
.gp-usage-card-icon { display:grid; place-items:center; flex:0 0 34px; height:34px; border-radius:11px; color:var(--usage-accent,var(--primary)); background:rgba(67,97,238,.10); font-size:.95rem; }
.gp-usage-card-copy { min-width:0; }
.gp-usage-card-label { display:block; color:var(--text-muted); font-size:.66rem; line-height:1.25; }
.gp-usage-card-value { display:block; margin-top:5px; overflow:hidden; color:var(--text-dark); font-size:.98rem; font-weight:800; line-height:1.1; text-overflow:ellipsis; white-space:nowrap; }
.gp-usage-panel { margin-top:14px; padding:17px 18px; border:1px solid var(--border-color); border-radius:16px; background:var(--bg-card); }
.gp-usage-panel-head { display:flex; align-items:center; gap:10px; margin-bottom:13px; }
.gp-usage-panel-icon { display:grid; place-items:center; flex:0 0 31px; height:31px; border-radius:10px; color:var(--primary); background:var(--primary-light); }
.gp-usage-panel-title { margin:0; color:var(--text-dark); font-size:.86rem; font-weight:750; }
.gp-usage-table-wrap { overflow:auto; border:1px solid var(--border-color); border-radius:11px; }
.gp-usage-table { min-width:520px; margin:0; color:var(--text-dark); font-size:.77rem; }
.gp-usage-table thead th { padding:10px 12px; border-bottom:1px solid var(--border-color); color:var(--text-muted); background:var(--bg-body); font-size:.64rem; letter-spacing:.06em; text-transform:uppercase; }
.gp-usage-table tbody td { padding:10px 12px; border-color:var(--border-color); }
.gp-usage-raw { margin-top:15px; border-top:1px solid var(--border-color); padding-top:12px; }
.gp-usage-raw summary { color:var(--text-muted); cursor:pointer; font-size:.75rem; font-weight:650; }
.gp-usage-raw pre { max-height:280px; margin:12px 0 0; padding:14px; overflow:auto; border:1px solid var(--border-color); border-radius:12px; color:var(--text-dark); background:var(--bg-body); font-size:.72rem; white-space:pre-wrap; }
.gp-usage-state { display:grid; min-height:230px; place-content:center; justify-items:center; gap:12px; color:var(--text-muted); text-align:center; }
.gp-usage-state-icon { display:grid; place-items:center; width:50px; height:50px; border-radius:16px; color:var(--primary); background:var(--primary-light); font-size:1.2rem; }
.gp-usage-state.is-error .gp-usage-state-icon { color:var(--danger); background:var(--danger-light); }
@keyframes gpUsageEnter { from { opacity:0; transform:translateY(7px); } to { opacity:1; transform:translateY(0); } }
@media (max-width:1100px) { .gp-usage-metrics { grid-template-columns:repeat(3,minmax(0,1fr)); } }
@media (max-width:575px) {
    .gp-usage-head { align-items:flex-start; padding:17px 16px; }
    .gp-usage-heading { gap:10px; }
    .gp-usage-mark { flex-basis:40px; height:40px; border-radius:12px; }
    .gp-usage-title { font-size:1rem; }
    .gp-usage-live { display:none; }
    .gp-usage-body { padding:17px 14px 20px; }
    .gp-usage-kpis, .gp-usage-metrics { grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
    .gp-usage-card { min-height:76px; padding:11px 9px; gap:8px; }
    .gp-usage-card-icon { flex-basis:30px; height:30px; }
    .gp-usage-card-label { font-size:.62rem; }
    .gp-usage-card-value { font-size:.87rem; }
    .gp-usage-toolbar { align-items:flex-start; flex-direction:column; }
    .gp-usage-panel { padding:14px 12px; }
}
@media (prefers-reduced-motion:reduce) { .gp-usage-card { animation:none; } }

</style>

<script>
(() => {
    const tbody = document.getElementById('uso-clientes');
    const status = document.getElementById('uso-status');
    const btn = document.getElementById('btn-atualizar-uso');
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const findSerial = value => {
        if (!value || typeof value !== 'object') return '';
        for (const [key, item] of Object.entries(value)) {
            const normalizedKey = key.toUpperCase();
            if ((normalizedKey === 'SERIAL' || normalizedKey === 'SERIAIS') && (typeof item === 'string' || typeof item === 'number')) return String(item);
            if (normalizedKey === 'SERIAIS' && Array.isArray(item)) {
                const serial = item.find(entry => typeof entry === 'string' || typeof entry === 'number');
                if (serial !== undefined) return String(serial);
            }
            const found = findSerial(item);
            if (found) return found;
        }
        return '';
    };
    const normalizeSerial = value => String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]/gi, '')
        .toUpperCase();
    const lastAccess = data => {
        const values = [data?.resumo?.ULTIMO, data?.utilizacao_mesa?.ultimoLogin, data?.utilizacao_mesa?.ULTIMO_LOGIN];
        if (Array.isArray(data?.usuarios)) data.usuarios.forEach(user => values.push(user?.ULTIMO, user?.ultimo, user?.ultimoAcesso));
        return values.reduce((latest, value) => {
            if (!value) return latest;
            const date = new Date(value);
            return !Number.isNaN(date.getTime()) && (!latest || date > latest) ? date : latest;
        }, null);
    };
    const formatLastAccess = date => date
        ? new Intl.DateTimeFormat('pt-BR', {dateStyle:'short', timeStyle:'short'}).format(date)
        : 'Sem acesso no período';
    const accessRowLevel = date => {
        if (!date) return 'uso-acesso-vermelho';
        const today = new Date();
        const todayOrdinal = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());
        const accessOrdinal = Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
        const daysSinceAccess = Math.floor((todayOrdinal - accessOrdinal) / 86400000);
        if (daysSinceAccess >= 0 && daysSinceAccess <= 1) return 'uso-acesso-verde';
        if (daysSinceAccess === 2) return 'uso-acesso-laranja';
        return 'uso-acesso-vermelho';
    };
    const updateAccessRowLevel = (row, date) => {
        if (!row) return;
        row.classList.remove('uso-acesso-verde', 'uso-acesso-laranja', 'uso-acesso-vermelho');
        row.classList.add(accessRowLevel(date));
    };
    async function carregar(forcar = false, somenteCache = false) {
        btn.disabled = true;
        status.textContent = somenteCache ? 'Carregando última consulta salva…' : 'Consultando uso dos clientes…';
        try {
            const parametros = new URLSearchParams({endpoint:'uso', dias:'30'});
            if (forcar) parametros.set('forcar', '1');
            if (somenteCache) parametros.set('somente_cache', '1');
            const url = `api_gestaopro_bridge.php?${parametros.toString()}`;
            const response = await fetch(url, {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            const json = await response.json();
            if (!response.ok || !json.sucesso) {
                const erro = new Error(json.erro || 'Não foi possível consultar a API.');
                erro.status = response.status;
                throw erro;
            }
            const clientes = [...(json.dados?.clientes || [])].sort((a, b) => {
                const acessoA = lastAccess(a.dados)?.getTime() ?? Number.NEGATIVE_INFINITY;
                const acessoB = lastAccess(b.dados)?.getTime() ?? Number.NEGATIVE_INFINITY;
                return acessoA - acessoB || String(a.fantasia || '').localeCompare(String(b.fantasia || ''), 'pt-BR');
            });
            tbody.innerHTML = clientes.map(cliente => {
                const ultimoAcesso = lastAccess(cliente.dados);
                const apiSerial = findSerial(cliente.dados);
                const cadastrado = String(cliente.serial_cadastro || '').trim();
                const serialApiNormalizado = normalizeSerial(apiSerial);
                const serialCadastradoNormalizado = normalizeSerial(cadastrado);
                const fantasiaNormalizada = normalizeSerial(cliente.fantasia);
                const confere = !apiSerial
                    ? 'Não informado pela API'
                    : (serialCadastradoNormalizado && serialApiNormalizado === serialCadastradoNormalizado
                        ? 'Confere'
                        : (!serialCadastradoNormalizado && serialApiNormalizado && serialApiNormalizado === fantasiaNormalizada
                            ? 'Confere pela fantasia'
                            : 'Divergente'));
                const botaoUso = cliente.id_cliente_api
                    ? `<button type="button" class="btn btn-sm btn-outline-primary btn-detalhes-uso" data-id-api="${esc(cliente.id_cliente_api)}" data-cliente="${esc(cliente.fantasia)}"><i class="bi bi-activity me-1"></i>Uso</button>`
                    : '<span class="text-muted">-</span>';
                return `<tr class="${accessRowLevel(ultimoAcesso)}"><td class="px-4 fw-semibold">${esc(cliente.fantasia)}</td><td>${esc(formatLastAccess(ultimoAcesso))}</td><td>${esc(cliente.id_cliente_api)}</td><td><code>${esc(cadastrado || '-')}</code></td><td>${esc(confere)}${apiSerial ? `<div class="small text-muted"><code>${esc(apiSerial)}</code></div>` : ''}</td><td>${botaoUso}</td></tr>`;
            }).join('') || '<tr><td colspan="6" class="text-center py-4 text-muted">Nenhum cliente tem ID Cliente API cadastrado.</td></tr>';
            status.textContent = `Per\u00edodo: 30 dias \u00b7 Atualizado em ${json.gerado_em || 'agora'} \u00b7 Origem: ${json.origem === 'cache' ? 'cache salvo' : 'API'}`;
        } catch (error) {
            if (somenteCache && error.status === 404) {
                status.textContent = 'Ainda não há uma consulta salva. Clique em Atualizar para buscar os dados.';
                tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">Nenhum resultado em cache.</td></tr>';
            } else {
                status.textContent = error.message;
                tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger">Falha ao carregar os dados de uso.</td></tr>';
            }
        } finally { btn.disabled = false; }
    }
    btn.addEventListener('click', () => carregar(true, false));
    tbody.addEventListener('click', async event => {
        const button = event.target.closest('.btn-detalhes-uso');
        if (!button) return;
        const modalEl = document.getElementById('modalDetalhesUso');
        const body = document.getElementById('modalDetalhesUsoBody');
        document.getElementById('modalDetalhesUsoTitulo').textContent = `Uso \u2014 ${button.dataset.cliente || 'Cliente'}`;
        body.innerHTML = '<div class="gp-usage-state"><span class="spinner-border text-primary"></span><div>Consultando os dados mais recentes do cliente...</div></div>';
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
        try {
            const response = await fetch(`api_gestaopro_bridge.php?endpoint=uso&id_cliente_api=${encodeURIComponent(button.dataset.idApi)}&dias=30&forcar=1&apenas_mesa=1`, {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            const json = await response.json();
            if (!response.ok || !json.sucesso) throw new Error(json.erro || 'Falha ao consultar uso.');
            const data = json.dados || {};
            const modules = Array.isArray(data.modulos) ? data.modulos : [];
            const users = Array.isArray(data.usuarios) ? data.usuarios : [];
            const summary = data.resumo || {};
            const safe = value => esc(value ?? '-');
            const countLabel = value => value === null || value === undefined || value === '' ? '-' : Number(value).toLocaleString('pt-BR');
            const latest = lastAccess(data);
            const latestText = latest ? new Intl.DateTimeFormat('pt-BR',{dateStyle:'short',timeStyle:'short'}).format(latest) : 'Sem acesso no per\u00edodo';
            const latestCell = button.closest('tr')?.children[1];
            if (latestCell) latestCell.textContent = latestText;
            updateAccessRowLevel(latestCell?.closest('tr'), latest);
            const utilizationRecord = data.utilizacao_mesa && typeof data.utilizacao_mesa === 'object' ? data.utilizacao_mesa : {};
            const formatLastUse = value => {
                if (value === null || value === undefined || value === '') return '-';
                const text = String(value).trim();
                const brazilianDate = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/);
                let date;
                if (brazilianDate) {
                    date = new Date(
                        Number(brazilianDate[3]),
                        Number(brazilianDate[2]) - 1,
                        Number(brazilianDate[1]),
                        Number(brazilianDate[4] || 0),
                        Number(brazilianDate[5] || 0),
                        Number(brazilianDate[6] || 0)
                    );
                    if (date.getFullYear() !== Number(brazilianDate[3]) || date.getMonth() !== Number(brazilianDate[2]) - 1 || date.getDate() !== Number(brazilianDate[1])) return text;
                } else {
                    date = new Date(text);
                }
                if (Number.isNaN(date.getTime())) return text;
                const options = /\d{1,2}:\d{2}/.test(text)
                    ? {dateStyle:'short',timeStyle:'short'}
                    : {dateStyle:'short'};
                return new Intl.DateTimeFormat('pt-BR', options).format(date);
            };
            const formatMonthCount = value => {
                if (value === null || value === undefined || value === '') return '-';
                const text = String(value).trim();
                if (!/^-?\d+(?:[.,]\d+)?$/.test(text)) return text;
                return Number(text.replace(',', '.')).toLocaleString('pt-BR');
            };
            const renderUsageGroup = (key, title, labels, icon) => {
                const metrics = utilizationRecord[key];
                if (!metrics || typeof metrics !== 'object' || Array.isArray(metrics)) return '';
                const cards = Object.entries(metrics).map(([metric, value]) => {
                    const label = labels[metric] || metric;
                    const formattedValue = key === 'mes' ? formatMonthCount(value) : formatLastUse(value);
                    return `<article class="gp-usage-card"><span class="gp-usage-card-icon"><i class="bi ${icon}"></i></span><span class="gp-usage-card-copy"><small class="gp-usage-card-label">${safe(label)}</small><strong class="gp-usage-card-value">${safe(formattedValue)}</strong></span></article>`;
                }).join('');
                return cards ? `<section class="gp-usage-panel"><div class="gp-usage-panel-head"><span class="gp-usage-panel-icon"><i class="bi ${icon}"></i></span><h6 class="gp-usage-panel-title">${title}</h6></div><div class="gp-usage-metrics">${cards}</div></section>` : '';
            };
            const lastUsage = renderUsageGroup('ultimas', '\u00daltimas movimenta\u00e7\u00f5es', {
                clientes:'Clientes', fornecedores:'Fornecedores', nfs:'Notas fiscais', orcamentos:'Or\u00e7amentos',
                pagar:'Contas a pagar', pedidos:'Pedidos', produtos:'Produtos', receber:'Contas a receber'
            }, 'bi-clock-history');
            const monthUsage = renderUsageGroup('mes', 'Resumo do m\u00eas', {
                financeiro:'Financeiro', nfs:'Notas fiscais', orcamentos:'Or\u00e7amentos', pedidos:'Pedidos'
            }, 'bi-calendar-month');
            const cards = [['\u00daltimo acesso',latestText,'bi-clock-history'],['Logins',countLabel(summary.LOGINS),'bi-box-arrow-in-right'],['Usu\u00e1rios',countLabel(summary.USUARIOS ?? (users.length || null)),'bi-people']];
            const moduleRows = modules.map(item=>`<tr><td>${safe(item.APP||item.MODULO)}</td><td>${safe(item.PAGEVIEWS||0)}</td><td>${safe(item.ULTIMO||item.ultimoAcesso)}</td></tr>`).join('');
            const userRows = users.map(item=>`<tr><td>${safe(item.USUARIO||item.LOGIN||item.NOME)}</td><td>${safe(item.PAGEVIEWS||0)}</td><td>${safe(item.LOGINS||0)}</td><td>${safe(item.ULTIMO||item.ultimoAcesso)}</td></tr>`).join('');
            const detailSections = `${moduleRows?`<section class="gp-usage-panel"><div class="gp-usage-panel-head"><span class="gp-usage-panel-icon"><i class="bi bi-grid-3x3-gap"></i></span><h6 class="gp-usage-panel-title">Uso por m\u00f3dulo</h6></div><div class="gp-usage-table-wrap"><table class="table gp-usage-table"><thead><tr><th>M\u00f3dulo</th><th>Pageviews</th><th>\u00daltimo acesso</th></tr></thead><tbody>${moduleRows}</tbody></table></div></section>`:''}${userRows?`<section class="gp-usage-panel"><div class="gp-usage-panel-head"><span class="gp-usage-panel-icon"><i class="bi bi-people"></i></span><h6 class="gp-usage-panel-title">Usu\u00e1rios</h6></div><div class="gp-usage-table-wrap"><table class="table gp-usage-table"><thead><tr><th>Usu\u00e1rio</th><th>Pageviews</th><th>Logins</th><th>\u00daltimo acesso</th></tr></thead><tbody>${userRows}</tbody></table></div></section>`:''}`;
            body.innerHTML = `<div class="gp-usage-toolbar"><div><span class="gp-usage-section-kicker">Resumo do cliente</span><h6 class="gp-usage-section-title">Atividade e utiliza\u00e7\u00e3o</h6></div><span class="gp-usage-period"><i class="bi bi-calendar3"></i> \u00daltimos 30 dias</span></div>
                <div class="gp-usage-kpis">${cards.map(([label,value,icon])=>`<article class="gp-usage-card"><span class="gp-usage-card-icon"><i class="bi ${icon}"></i></span><span class="gp-usage-card-copy"><small class="gp-usage-card-label">${label}</small><strong class="gp-usage-card-value">${safe(value)}</strong></span></article>`).join('')}</div>
                ${lastUsage}${monthUsage}${detailSections}
                <details class="gp-usage-raw"><summary><i class="bi bi-braces me-1"></i> Ver resposta completa da API</summary><pre>${esc(JSON.stringify(data,null,2))}</pre></details>`;
        } catch (error) {
            body.innerHTML = `<div class="gp-usage-state is-error"><span class="gp-usage-state-icon"><i class="bi bi-exclamation-triangle"></i></span><strong>N\u00e3o foi poss\u00edvel carregar o uso</strong><span>${esc(error.message || 'Tente novamente em instantes.')}</span></div>`;
        }
    });
    carregar(false, true);
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
