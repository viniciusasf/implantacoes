<?php 
require_once 'config.php';
require_once 'header.php'; 

// Buscar mapeamento de clientes
$stmt_map = $pdo->query("SELECT id_cliente, id_cliente_api, servidor FROM clientes WHERE id_cliente_api IS NOT NULL AND id_cliente_api != ''");
    $mapa_clientes_local = [];
    $mapa_servidor_local = [];
    while ($row_map = $stmt_map->fetch(PDO::FETCH_ASSOC)) {
        $mapa_clientes_local[$row_map['id_cliente_api']] = $row_map['id_cliente'];
        $mapa_servidor_local[$row_map['id_cliente_api']] = $row_map['servidor'];
    }

$stmt_retornos = $pdo->query("SELECT id_chamado FROM chamados_retornos");
$chamados_retornos_local = [];
while ($row_retorno = $stmt_retornos->fetch(PDO::FETCH_ASSOC)) {
    $chamados_retornos_local[] = (int)$row_retorno['id_chamado'];
}
?>
<div class="container-fluid px-0">

<!-- HEADER -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="mb-1" style="font-size:1.6rem;">
            <i class="bi bi-headset me-2" style="color:var(--danger)"></i>Chamados de Suporte
        </h1>
        <p class="mb-0" style="color:var(--text-muted);font-size:.85rem;">
            Leitura em tempo real de <strong>interno.gestaopro.srv.br/api/chamados</strong>
        </p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span id="badge-origem" class="badge rounded-pill" style="font-size:.75rem;background:var(--primary-light);color:var(--primary)">—</span>
        <span id="badge-hora" style="font-size:.75rem;color:var(--text-muted)"></span>
        <button id="btn-refresh" class="btn btn-primary btn-sm">
            <i class="bi bi-arrow-clockwise" id="ico-refresh"></i> Atualizar
        </button>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <?php
    $kpis = [
        ['id'=>'kpi-aguard-dev',   'label'=>'Aguard. Desenvolvimento','icon'=>'bi-code-slash',       'color'=>'warning'],
        ['id'=>'kpi-aguard-testes','label'=>'Aguardando Testes',     'icon'=>'bi-check2-circle',     'color'=>'success'],
        ['id'=>'kpi-aguard-suporte','label'=>'Aguardando Suporte',   'icon'=>'bi-headset',           'color'=>'purple'],
        ['id'=>'kpi-total',        'label'=>'Total',                'icon'=>'bi-ticket-detailed',   'color'=>'primary'],
    ];
    foreach ($kpis as $k): ?>
    <div class="col-6 col-md-3">
        <div class="card h-100 border-0">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div style="width:44px;height:44px;border-radius:12px;background:var(--<?=$k['color']?>-light);display:flex;align-items:center;justify-content:center;">
                    <i class="bi <?=$k['icon']?>" style="font-size:1.3rem;color:var(--<?=$k['color']?>)"></i>
                </div>
                <div>
                    <div id="<?=$k['id']?>" style="font-size:1.6rem;font-weight:800;color:var(--text-dark);line-height:1">—</div>
                    <div style="font-size:.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em"><?=$k['label']?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- FILTROS -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <div class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" id="filtro-busca" class="form-control form-control-sm" placeholder="🔍  Buscar cliente, responsável, descrição...">
            </div>
            <div class="col-md-2">
                <div class="dropdown">
                    <button class="form-select form-select-sm dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" type="button" id="filtro-status-btn" data-bs-toggle="dropdown" aria-expanded="false">
                        <span id="filtro-status-label" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Todos os status</span>
                    </button>
                    <ul class="dropdown-menu w-100 shadow-sm p-2" aria-labelledby="filtro-status-btn" style="border-radius: var(--radius-md); border-color: var(--border-color); font-size: 0.9rem; max-height: 300px; overflow-y: auto;" id="filtro-status-menu">
                        <!-- Gerado via JS -->
                    </ul>
                </div>
            </div>
            <div class="col-md-2">
                <div class="dropdown">
                    <button class="form-select form-select-sm dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" type="button" id="filtro-tipo-btn" data-bs-toggle="dropdown" aria-expanded="false">
                        <span id="filtro-tipo-label" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Todos os tipos</span>
                    </button>
                    <ul class="dropdown-menu w-100 shadow-sm p-2" aria-labelledby="filtro-tipo-btn" style="border-radius: var(--radius-md); border-color: var(--border-color); font-size: 0.9rem; max-height: 300px; overflow-y: auto;" id="filtro-tipo-menu">
                        <!-- Gerado via JS -->
                    </ul>
                </div>
            </div>
            <div class="col-md-2">
                <select id="filtro-responsavel" class="form-select form-select-sm">
                    <option value="">Todos os responsáveis</option>
                </select>
            </div>
            <div class="col-md-2 text-end">
                <span id="lbl-contagem" style="font-size:.8rem;color:var(--text-muted)">—</span>
            </div>
        </div>
    </div>
</div>

<!-- TABELA -->
<div class="card">
    <div class="card-body p-0">
        <div id="estado-carregando" class="text-center py-5">
            <div class="spinner-border" style="color:var(--danger)" role="status"></div>
            <p class="mt-3 mb-0" style="color:var(--text-muted)">Buscando chamados da API...</p>
        </div>
        <div id="estado-erro" class="d-none text-center py-5">
            <i class="bi bi-exclamation-triangle-fill" style="font-size:2.5rem;color:var(--danger)"></i>
            <p class="mt-3 mb-1 fw-bold">Não foi possível carregar os chamados</p>
            <p id="msg-erro" style="color:var(--text-muted);font-size:.85rem"></p>
        </div>
        <div id="wrapper-tabela" class="d-none">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr style="background:var(--bg-body);font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)">
                            <th class="px-4 py-3 sortable" data-col="ID">#ID</th>
                            <th class="py-3 sortable" data-col="FANTASIA">Cliente</th>
                            <th class="py-3 sortable" data-col="CHAMADO_STATUS">Status</th>
                            <th class="py-3 sortable" data-col="SERVIDOR">SRV</th>
                            <th class="py-3 sortable" data-col="DESCRICAO">Descrição</th>
                            <th class="py-3 sortable" data-col="DATAPREV_RETORNO">Prev. Retorno</th>
                            <th class="py-3 text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-chamados"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</div>

<style>
.sortable{cursor:pointer;user-select:none}.sortable:hover{color:var(--primary)!important}
.badge-ch{font-size:.68rem;font-weight:700;padding:3px 9px;border-radius:20px;letter-spacing:.03em;white-space:nowrap}
.peso-chip{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:8px;font-weight:700;font-size:.8rem}
</style>

<script>
const MAPA_CLIENTES_LOCAL = <?php echo json_encode($mapa_clientes_local); ?>;
    const MAPA_SERVIDOR_LOCAL = <?php echo json_encode($mapa_servidor_local); ?>;

(function(){
    let todos=[], sortCol='DATAPREV_RETORNO', sortAsc=true;
    let chamadosBaixados = <?php echo json_encode($chamados_retornos_local); ?>;

    window.alternarBaixa = function(id_chamado, acao) {
        const fd = new FormData();
        fd.append('id_chamado', id_chamado);
        fd.append('acao', acao);
        
        fetch('salvar_chamado_retorno.php', {
            method: 'POST',
            body: fd
        }).then(res => res.json()).then(resp => {
            if (resp.sucesso) {
                if (acao === 'dar_baixa') {
                    if (!chamadosBaixados.includes(id_chamado)) chamadosBaixados.push(id_chamado);
                } else {
                    chamadosBaixados = chamadosBaixados.filter(id => id !== id_chamado);
                }
                aplicarFiltros();
            } else {
                alert('Erro ao atualizar: ' + (resp.erro || 'Erro desconhecido.'));
            }
        }).catch(err => {
            console.error(err);
            alert('Erro de rede ao atualizar baixa.');
        });
    };

    function fmtData(iso){
        if(!iso) return '<span style="color:var(--text-muted)">—</span>';
        return new Date(iso).toLocaleDateString('pt-BR');
    }

    function fmtDataTexto(iso){
        if(iso) return new Date(iso).toLocaleDateString('pt-BR');
        const semPrevisaoDesenvolvimento = todos.filter(r => r.CHAMADO_STATUS === 'Em Desenvolvimento' && !r.DATAPREV_RETORNO).length;
        if(semPrevisaoDesenvolvimento > 3) return 'Mais de 3 chamados em desenvolvimento, sem previsão de retorno';
        return 'Sem previsão de retorno';
    }

    const STATUS_COR = {
        'Aguardando Desenvolvimento': ['var(--warning-light)','var(--warning)'],
        'Em Desenvolvimento':          ['var(--info-light)',   'var(--info)'],
        'Aguardando Cliente':          ['var(--purple-light)', 'var(--purple)'],
        'Aguardando Testes':           ['var(--success-light)','var(--success)'],
        'Resolvido':                   ['var(--success-light)','var(--success)'],
        'Cancelado':                   ['#f1f5f9',             'var(--text-muted)'],
    };

    function badgeStatus(s){
        if(!s) return '—';
        const [bg,fg] = STATUS_COR[s] || ['var(--primary-light)','var(--primary)'];
        return `<span class="badge-ch" style="background:${bg};color:${fg}">${s}</span>`;
    }

    function escapeHtmlAttribute(text){
        return String(text||'')
            .replace(/&/g,'&amp;')
            .replace(/"/g,'&quot;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/\r?\n/g,'&#13;&#10;');
    }

    function criarMensagemWhatsapp(chamado){
        const servidor = chamado.SERVIDOR || chamado.SERVIDORNUVEM || MAPA_SERVIDOR_LOCAL[chamado.ID_CLIENTE] || '—';
        const usuario = chamado.CHAMADO_USUARIO || chamado.USUARIO || 'cliente';
        const descricao = String(chamado.DESCRICAO || 'Sem descrição')
            .replace(/\r\n/g,'\n')
            .replace(/\r/g,'\n')
            .split('\n')
            .map(line => line.trim())
            .join('\r\n');
        const resumo = descricao.length > 500 ? descricao.slice(0, 500) + '...' : descricao;
        return `Olá, ${usuario} 👋\r\n\r\n` +
            `Seu chamado *#${chamado.ID}* foi aberto com sucesso ✅\r\n\r\n` +
            `*Status:* ${chamado.CHAMADO_STATUS || 'Não informado'} ⏳\r\n` +
            `*Tipo:* ${chamado.TIPOACOMP || 'Não informado'} 📄\r\n` +
            `*Servidor:* ${servidor} 🖥️\r\n` +
            `*Responsável:* ${chamado.RESPONSAVEL || 'Não informado'} 👤\r\n` +
            `*Resumo:* ${resumo} 🔍\r\n` +
            `*Previsão de retorno:* ${fmtDataTexto(chamado.DATAPREV_RETORNO)} 📅\r\n\r\n` +
            `Sigo acompanhando por aqui e, assim que houver novidade, te aviso. 🚀`;
    }

    function copiarTextoAreaTransferencia(texto, mensagemSucesso){
        if(navigator.clipboard && window.isSecureContext){
            navigator.clipboard.writeText(texto).then(()=>alert(mensagemSucesso)).catch(()=>fallbackCopy(texto, mensagemSucesso));
        } else {
            fallbackCopy(texto, mensagemSucesso);
        }
    }

    function fallbackCopy(texto, mensagemSucesso){
        const textarea = document.createElement('textarea');
        textarea.value = texto;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        try {
            document.execCommand('copy');
            alert(mensagemSucesso);
        } catch (e) {
            alert('Erro ao copiar mensagem para o WhatsApp.');
        }
        document.body.removeChild(textarea);
    }

    function chipPeso(v){
        const n=parseInt(v)||0;
        const cls = n>=8?'danger': n>=5?'warning':'success';
        return `<span class="peso-chip" style="background:var(--${cls}-light);color:var(--${cls})">${n}</span>`;
    }

    function populaSelect(id, valores, padrao){
        const sel=document.getElementById(id);
        sel.innerHTML = `<option value="">Todos os ${id==='filtro-responsavel'?'responsáveis':'itens'}</option>`;
        [...new Set(valores)].filter(Boolean).sort().forEach(v=>{
            const o=document.createElement('option'); o.value=v; o.textContent=v; sel.appendChild(o);
        });
        if (padrao && [...sel.options].some(o => o.value === padrao)) sel.value = padrao;
    }

    function atualizarLabelDropdown(idLabel, classeCb, textoTodos) {
        const selecionados = Array.from(document.querySelectorAll('.' + classeCb + ':checked'));
        const btnLabel = document.getElementById(idLabel);
        if (selecionados.length === 0) {
            btnLabel.textContent = textoTodos;
        } else if (selecionados.length === 1) {
            btnLabel.textContent = selecionados[0].nextElementSibling.textContent;
        } else {
            btnLabel.textContent = selecionados.length + ' selecionados';
        }
    }

    function populaDropdownCheckboxes(idMenu, idLabel, classeCb, valores, padroes, textoTodos) {
        const menu = document.getElementById(idMenu);
        menu.innerHTML = '';
        [...new Set(valores)].filter(Boolean).sort().forEach((v, i) => {
            const isChecked = padroes.includes(v) ? 'checked' : '';
            const chkId = idMenu + '-chk-' + i;
            const li = document.createElement('li');
            li.innerHTML = `
                <div class="form-check mb-1">
                    <input class="form-check-input ${classeCb}" type="checkbox" value="${v}" id="${chkId}" ${isChecked}>
                    <label class="form-check-label w-100" for="${chkId}" style="cursor:pointer;">${v}</label>
                </div>
            `;
            menu.appendChild(li);
        });
        
        document.querySelectorAll('.' + classeCb).forEach(cb => {
            cb.addEventListener('change', function() {
                atualizarLabelDropdown(idLabel, classeCb, textoTodos);
                aplicarFiltros();
            });
        });
        
        atualizarLabelDropdown(idLabel, classeCb, textoTodos);
    }

    function renderTabela(lista){
        const tbody=document.getElementById('tbody-chamados');
        document.getElementById('lbl-contagem').textContent=lista.length+' registro'+(lista.length!==1?'s':'');
        if(!lista.length){
            tbody.innerHTML='<tr><td colspan="10" class="text-center py-5" style="color:var(--text-muted)">Nenhum chamado encontrado.</td></tr>';
            return;
        }
        lista.sort((a,b)=>{
            const statusOrder = {
                'Aguardando Suporte': 1,
                'Aguardando Testes': 2,
                'Aguardando Desenvolvimento': 3
            };
            const wA = statusOrder[a.CHAMADO_STATUS] || 99;
            const wB = statusOrder[b.CHAMADO_STATUS] || 99;
            
            if (wA !== wB) {
                return wA - wB;
            } else if (a.CHAMADO_STATUS !== b.CHAMADO_STATUS) {
                return String(a.CHAMADO_STATUS).localeCompare(String(b.CHAMADO_STATUS), 'pt-BR');
            }

            let va=a[sortCol]??'', vb=b[sortCol]??'';
            
            if (sortCol === 'DATAPREV_RETORNO' || sortCol === 'DATA') {
                const ta = va ? new Date(va).getTime() : Infinity;
                const tb = vb ? new Date(vb).getTime() : Infinity;
                return sortAsc ? ta - tb : tb - ta;
            }

            if(typeof va==='number') return sortAsc?va-vb:vb-va;
            return sortAsc?String(va).localeCompare(String(vb),'pt-BR'):String(vb).localeCompare(String(va),'pt-BR');
        });
        tbody.innerHTML=lista.map(r=>{
            const idChamado = parseInt(r.ID);
            const isBaixado = chamadosBaixados.includes(idChamado);
                
            const btnBaixa = isBaixado ?
                `<button type="button" class="btn btn-sm btn-success fw-bold shadow-sm" style="padding: 0.2rem 0.6rem; font-size: 0.75rem;" title="Desfazer baixa" onclick="alternarBaixa(${idChamado}, 'remover_baixa')"><i class="bi bi-check-all"></i> VALIDADO</button>` :
                `<button type="button" class="btn btn-sm btn-outline-success fw-bold shadow-sm" style="padding: 0.2rem 0.6rem; font-size: 0.75rem;" title="Dar baixa" onclick="alternarBaixa(${idChamado}, 'dar_baixa')"><i class="bi bi-check2"></i> VALIDAR</button>`;

            const rowClass = isBaixado ? 'linha-baixada' : '';
            const servidor = (r.SERVIDOR || r.SERVIDORNUVEM || MAPA_SERVIDOR_LOCAL[r.ID_CLIENTE] || '—');
            const mensagemWhatsapp = criarMensagemWhatsapp(r);
            const msgAttr = escapeHtmlAttribute(mensagemWhatsapp);
            return `
        <tr class="${rowClass}">
            <td class="px-4 py-3" style="font-size:.78rem;font-family:monospace;color:var(--text-muted)">#${r.ID}</td>
            <td>
                <div style="font-weight:600;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${r.FANTASIA||'—'}</div>
                <div style="font-size:.72rem;color:var(--text-muted)">${r.SERIAL||''}</div>
            </td>
            <td>${badgeStatus(r.CHAMADO_STATUS)}</td>
            <td style="font-size:.75rem;background:var(--bg-body);padding:2px 7px;border-radius:6px;white-space:nowrap">${servidor}</td>
            <td style="font-size:.82rem;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${r.DESCRICAO||''}">${r.DESCRICAO||'—'}</td>
            <td style="font-size:.82rem">${fmtData(r.DATAPREV_RETORNO)}</td>
            <td style="text-align:center">
                <div class="d-flex gap-1 justify-content-center">
                    <button type="button" class="btn btn-sm btn-outline-success copy-whatsapp-message" data-bs-toggle="tooltip" data-bs-title="Copiar WhatsApp" data-message="${msgAttr}" title="Copiar WhatsApp">
                        <i class="bi bi-whatsapp"></i>
                    </button>
                    <a href="https://interno.gestaopro.srv.br/chamados/${r.ID}" target="_blank" class="btn btn-sm btn-primary fw-bold shadow-sm" style="padding: 0.2rem 0.6rem; font-size: 0.75rem;" title="Abrir chamado">
                        <i class="bi bi-box-arrow-up-right"></i> ABRIR
                    </a>
                    ${btnBaixa}
                </div>
            </td>
        </tr>`;
        }).join('');
    }

    function aplicarFiltros(){
        const busca=document.getElementById('filtro-busca').value.toLowerCase();
        const statusList = Array.from(document.querySelectorAll('.filtro-status-cb:checked')).map(cb => cb.value);
        const tipoList   = Array.from(document.querySelectorAll('.filtro-tipo-cb:checked')).map(cb => cb.value);
        const resp=document.getElementById('filtro-responsavel').value;

        const fil=todos.filter(r=>{
            const txt=`${r.FANTASIA} ${r.SERIAL} ${r.DESCRICAO} ${r.RESPONSAVEL} ${r.CHAMADO_USUARIO}`.toLowerCase();
            if(busca&&!txt.includes(busca)) return false;
            if(statusList.length > 0 && !statusList.includes(r.CHAMADO_STATUS)) return false;
            if(tipoList.length > 0 && !tipoList.includes(r.TIPOACOMP)) return false;
            if(resp&&r.RESPONSAVEL!==resp) return false;
            return true;
        });
        atualizarKPIs(fil);
        renderTabela(fil);
    }

    function atualizarKPIs(lista){
        document.getElementById('kpi-aguard-dev').textContent=lista.filter(r=>r.CHAMADO_STATUS==='Aguardando Desenvolvimento').length;
        document.getElementById('kpi-aguard-testes').textContent=lista.filter(r=>r.CHAMADO_STATUS==='Aguardando Testes').length;
        document.getElementById('kpi-aguard-suporte').textContent=lista.filter(r=>r.CHAMADO_STATUS==='Aguardando Suporte').length;
        document.getElementById('kpi-total').textContent=lista.length;
    }

    function carregarDados(){
        document.getElementById('estado-carregando').classList.remove('d-none');
        document.getElementById('estado-erro').classList.add('d-none');
        document.getElementById('wrapper-tabela').classList.add('d-none');
        document.getElementById('btn-refresh').disabled=true;

        const forcar=window._forcar||false; window._forcar=false;
        const url='api_gestaopro_bridge.php?endpoint=chamados'+(forcar?'&forcar=1':'');

        fetch(url).then(r=>r.json()).then(resp=>{
            if(!resp.sucesso) throw new Error(resp.erro||'Erro desconhecido');
            const lista=resp.dados.chamados||[];
            todos=lista;

            populaDropdownCheckboxes('filtro-status-menu', 'filtro-status-label', 'filtro-status-cb', lista.map(r=>r.CHAMADO_STATUS), ['Aguardando Desenvolvimento', 'Aguardando Suporte', 'Aguardando Testes'], 'Todos os status');
            populaDropdownCheckboxes('filtro-tipo-menu', 'filtro-tipo-label', 'filtro-tipo-cb', lista.map(r=>r.TIPOACOMP), [], 'Todos os tipos');
            populaSelect('filtro-responsavel', lista.map(r=>r.RESPONSAVEL), 'VINICIUS');
            aplicarFiltros();

            const ob=document.getElementById('badge-origem');
            ob.textContent=resp.origem==='cache'?'⚡ Cache':'🌐 API';
            ob.style.background=resp.origem==='cache'?'var(--success-light)':'var(--primary-light)';
            ob.style.color=resp.origem==='cache'?'var(--success)':'var(--primary)';
            document.getElementById('badge-hora').textContent='Atualizado: '+(resp.gerado_em||'—');

            document.getElementById('estado-carregando').classList.add('d-none');
            document.getElementById('wrapper-tabela').classList.remove('d-none');
        }).catch(err=>{
            document.getElementById('estado-carregando').classList.add('d-none');
            document.getElementById('estado-erro').classList.remove('d-none');
            document.getElementById('msg-erro').textContent=err.message;
        }).finally(()=>{
            document.getElementById('btn-refresh').disabled=false;
            document.getElementById('ico-refresh').className='bi bi-arrow-clockwise';
        });
    }

    document.getElementById('btn-refresh').addEventListener('click',function(){
        window._forcar=true;
        document.getElementById('ico-refresh').className='bi bi-arrow-clockwise spin';
        carregarDados();
    });

    ['filtro-busca','filtro-responsavel'].forEach(id=>{
        document.getElementById(id).addEventListener('input',aplicarFiltros);
        document.getElementById(id).addEventListener('change',aplicarFiltros);
    });

    document.getElementById('filtro-status-menu').addEventListener('click', function (e) { e.stopPropagation(); });
    document.getElementById('filtro-tipo-menu').addEventListener('click', function (e) { e.stopPropagation(); });

    document.querySelectorAll('.sortable').forEach(th=>{
        th.addEventListener('click',function(){
            const col=this.dataset.col;
            if(sortCol===col) sortAsc=!sortAsc; else{sortCol=col;sortAsc=true;}
            document.querySelectorAll('.sortable').forEach(t=>t.style.color='');
            this.style.color='var(--primary)';
            aplicarFiltros();
        });
    });

    document.addEventListener('click', function(event){
        const btn = event.target.closest('.copy-whatsapp-message');
        if (!btn) return;
        const msg = btn.dataset.message;
        if (msg) {
            copiarTextoAreaTransferencia(msg, 'Mensagem WhatsApp copiada!');
        }
    });

    carregarDados();
})();
</script>

<style>
@keyframes spin{to{transform:rotate(360deg)}}
.spin{animation:spin .7s linear infinite;display:inline-block}

/* Linha com cor verde suave quando tem baixa/retorno dado */
tr.linha-baixada > td {
    background-color: var(--success-light) !important;
}

/* Modo escuro: verde mais visível */
[data-theme="dark"] tr.linha-baixada > td {
    background-color: rgba(16, 185, 129, 0.18) !important;
}
</style>

<?php $js_extra=''; include 'footer.php'; ?>
