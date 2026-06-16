<?php
$f = 'clientes.php';
$c = file_get_contents($f);

// 1. Data attributes in card view
$search1 = 'data-obs="<?= htmlspecialchars($c[\'observacao\']) ?>" 
                                    data-nf="<?= $c[\'emitir_nf\'] ?>" 
                                    data-cfg="<?= $c[\'configurado\'] ?>" 
                                    data-licencas="<?= $c[\'num_licencas\'] ?>" 
                                    data-anexo="<?= $c[\'anexo\'] ?>" 
                                    data-chamados="<?= htmlspecialchars($c[\'chamados\'] ?? \'\') ?>" 
                                    data-recursos="<?= htmlspecialchars($c[\'recursos\'] ?? \'\') ?>" 
                                    data-computador-rdp="<?= htmlspecialchars($c[\'computador_rdp\'] ?? \'\') ?>" 
                                    data-usuario-rdp="<?= htmlspecialchars($c[\'usuario_rdp\'] ?? \'\') ?>" 
                                    data-senha-rdp="<?= htmlspecialchars($c[\'senha_rdp\'] ?? \'\') ?>" 
                                    title="Editar" onclick="openEditModal(this)">';
$replace1 = 'data-api="<?= htmlspecialchars($c[\'id_cliente_api\'] ?? \'\') ?>" 
                                    data-nf="<?= $c[\'emitir_nf\'] ?>" 
                                    data-cfg="<?= $c[\'configurado\'] ?>" 
                                    data-licencas="<?= $c[\'num_licencas\'] ?>" 
                                    data-anexo="<?= $c[\'anexo\'] ?>" 
                                    data-chamados="<?= htmlspecialchars($c[\'chamados\'] ?? \'\') ?>" 
                                    data-recursos="<?= htmlspecialchars($c[\'recursos\'] ?? \'\') ?>" 
                                    title="Editar" onclick="openEditModal(this)">';

$c = str_replace($search1, $replace1, $c);

// 2. Data attributes in list view
$search2 = 'data-obs="<?= htmlspecialchars($c[\'observacao\']) ?>" 
                                                    data-nf="<?= $c[\'emitir_nf\'] ?>" 
                                                    data-cfg="<?= $c[\'configurado\'] ?>" 
                                                    data-licencas="<?= $c[\'num_licencas\'] ?>" 
                                                    data-anexo="<?= $c[\'anexo\'] ?>" 
                                                    data-chamados="<?= htmlspecialchars($c[\'chamados\'] ?? \'\') ?>" 
                                                    data-recursos="<?= htmlspecialchars($c[\'recursos\'] ?? \'\') ?>" 
                                                    onclick="openEditModal(this)">';
$replace2 = 'data-api="<?= htmlspecialchars($c[\'id_cliente_api\'] ?? \'\') ?>" 
                                                    data-nf="<?= $c[\'emitir_nf\'] ?>" 
                                                    data-cfg="<?= $c[\'configurado\'] ?>" 
                                                    data-licencas="<?= $c[\'num_licencas\'] ?>" 
                                                    data-anexo="<?= $c[\'anexo\'] ?>" 
                                                    data-chamados="<?= htmlspecialchars($c[\'chamados\'] ?? \'\') ?>" 
                                                    data-recursos="<?= htmlspecialchars($c[\'recursos\'] ?? \'\') ?>" 
                                                    onclick="openEditModal(this)">';

$c = str_replace($search2, $replace2, $c);

// 3. HTML Form replacements
$search3 = '<div class="col-12 mt-3">
                                    <h6 class="text-secondary fw-bold mb-3 d-flex align-items-center">
                                        <i class="bi bi-display me-2"></i> Área de Trabalho Remota (RDP)
                                    </h6>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-muted">Computador</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-pc text-info"></i></span>
                                        <input type="text" name="computador_rdp" id="computador_rdp" class="form-control border-start-0 ps-0" placeholder="Ex: SP21.GESTAOPRO.SRV.BR:15594">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-muted">Usuário</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-person text-info"></i></span>
                                        <input type="text" name="usuario_rdp" id="usuario_rdp" class="form-control border-start-0 ps-0" placeholder="Usuário">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-muted">Senha</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-key text-info"></i></span>
                                        <input type="password" name="senha_rdp" id="senha_rdp" class="form-control border-start-0 ps-0" placeholder="Senha">
                                    </div>
                                </div>';
$replace3 = '<div class="col-12">
                                    <label class="form-label small fw-bold text-muted">ID Cliente API (GestãoPro)</label>
                                    <div class="input-group mb-1">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-braces-asterisk text-primary"></i></span>
                                        <input type="number" name="id_cliente_api" id="id_cliente_api" class="form-control border-start-0 ps-0" placeholder="Ex: 5698">
                                    </div>
                                    <small class="text-muted" style="font-size: 0.7rem;">ID utilizado para vincular os dados da API ao sistema local.</small>
                                </div>';

// Need to use regex to handle any potential whitespace differences
$pattern3 = '/<div class="col-12 mt-3">\s*<h6 class="text-secondary fw-bold mb-3 d-flex align-items-center">\s*<i class="bi bi-display me-2"><\/i> Área de Trabalho Remota \(RDP\)\s*<\/h6>\s*<\/div>\s*<div class="col-md-4">\s*<label class="form-label small fw-bold text-muted">Computador<\/label>\s*<div class="input-group">\s*<span class="input-group-text bg-white border-end-0"><i class="bi bi-pc text-info"><\/i><\/span>\s*<input type="text" name="computador_rdp" id="computador_rdp" class="form-control border-start-0 ps-0" placeholder="Ex: SP21.GESTAOPRO.SRV.BR:15594">\s*<\/div>\s*<\/div>\s*<div class="col-md-4">\s*<label class="form-label small fw-bold text-muted">Usuário<\/label>\s*<div class="input-group">\s*<span class="input-group-text bg-white border-end-0"><i class="bi bi-person text-info"><\/i><\/span>\s*<input type="text" name="usuario_rdp" id="usuario_rdp" class="form-control border-start-0 ps-0" placeholder="Usuário">\s*<\/div>\s*<\/div>\s*<div class="col-md-4">\s*<label class="form-label small fw-bold text-muted">Senha<\/label>\s*<div class="input-group">\s*<span class="input-group-text bg-white border-end-0"><i class="bi bi-key text-info"><\/i><\/span>\s*<input type="password" name="senha_rdp" id="senha_rdp" class="form-control border-start-0 ps-0" placeholder="Senha">\s*<\/div>\s*<\/div>/';

$c = preg_replace($pattern3, $replace3, $c);

// Remove observacoes
$pattern4 = '/<div class="col-12">\s*<div class="dashboard-section p-4">\s*<h6 class="text-main fw-bold mb-3 d-flex align-items-center">\s*<i class="bi bi-chat-right-text me-2"><\/i> Observações Internas\s*<\/h6>\s*<textarea name="observacao" id="observacao" class="form-control" rows="3" placeholder="Informações relevantes para a equipe técnica\.\.\."><\/textarea>\s*<\/div>\s*<\/div>/';
$c = preg_replace($pattern4, '', $c);

// JS function update
$pattern5 = '/document\.getElementById\(\'observacao\'\)\.value = d\.obs \|\| \'\';\s*document\.getElementById\(\'emitir_nf\'\)\.value = d\.nf \|\| \'Não\';\s*document\.getElementById\(\'configurado\'\)\.value = d\.cfg \|\| \'Não\';\s*document\.getElementById\(\'num_licencas\'\)\.value = d\.licencas \|\| 0;\s*document\.getElementById\(\'anexo\'\)\.value = d\.anexo \|\| \'\';\s*document\.getElementById\(\'chamados\'\)\.value = d\.chamados \|\| \'\';\s*\/\/\s*Campos RDP\s*document\.getElementById\(\'computador_rdp\'\)\.value = d\.computadorRdp \|\| \'\';\s*document\.getElementById\(\'usuario_rdp\'\)\.value = d\.usuarioRdp \|\| \'\';\s*document\.getElementById\(\'senha_rdp\'\)\.value = d\.senhaRdp \|\| \'\';/';

$replace5 = "document.getElementById('id_cliente_api').value = d.api || '';\n        document.getElementById('emitir_nf').value = d.nf || 'Não';\n        document.getElementById('configurado').value = d.cfg || 'Não';\n        document.getElementById('num_licencas').value = d.licencas || 0;\n        document.getElementById('anexo').value = d.anexo || '';\n        document.getElementById('chamados').value = d.chamados || '';";

$c = preg_replace($pattern5, $replace5, $c);

file_put_contents($f, $c);
echo "Replaced Frontend\n";
