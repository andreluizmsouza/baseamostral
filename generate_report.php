<?php
/**
 * generate_report.php - Gera relatorio da extracao em formato pronto para PDF
 * Le os dados do JSON gerado pelo process.php
 */

header('Content-Type: text/html; charset=UTF-8');

$bdDestino = trim($_GET['banco'] ?? '');
if (!$bdDestino || !preg_match('/^[a-zA-Z0-9_]+$/', $bdDestino)) {
    die('Parametro banco invalido.');
}

$reportFile = sys_get_temp_dir() . '/report_' . $bdDestino . '.json';
if (!file_exists($reportFile)) {
    die('Relatorio nao encontrado. Execute o processamento primeiro.');
}

$report = json_decode(file_get_contents($reportFile), true);
if (!$report) {
    die('Erro ao ler dados do relatorio.');
}

// ===================== DICIONARIO CTR_FLG_ATIV =====================
// Documentacao: mttbcon.ctr_flg_ativ
$dictSituacao = [
    0  => 'Contrato em implantacao S/erro',
    1  => 'Contrato Ativo',
    2  => 'Quitado pelo Prazo',
    3  => 'Quitado por Nulidade do Saldo',
    4  => 'Quitado Antecipadamente por Sinistro',
    5  => 'Contrato em implantacao C/erro',
    6  => 'Quitado Antecipadamente por Transferencia',
    7  => 'Quitado Antecipadamente por PxN',
    8  => 'Quitado Antecipadamente C/desconto',
    9  => 'Quitado Antecipadamente (S/desconto)',
    10 => 'Contrato Cancelado (Aluguel/Txa.Ocupacao)',
    11 => 'Dacao de Pagamento',
    12 => 'Adjudicacao',
    13 => 'Habilitado ao FCVS',
    14 => 'Reabilitacao sem erro',
    15 => 'Quitacao especial CEF pelo numero de prestacoes',
    16 => 'Desconto de 30% com perda do FCVS (apenas para empresa de habilitacao)',
    17 => 'Reabilitacao com erro',
    18 => 'Quitado - Pagamento a Vista',
    19 => 'Quitado - Estado da Divida',
    20 => 'Quitado - Concessao de Uso',
    21 => 'Quitado - por Usucapiao',
];

$dataExec  = $report['data_execucao'] ?? '-';
$bdOrig    = $report['bd_origem'] ?? '-';
$bdDest    = $report['bd_destino'] ?? '-';
$codemp    = $report['codemp'] ?? '-';
$codemps   = $report['codemps'] ?? null;
$tabelas   = $report['tabelas'] ?? [];
$contRegs  = $report['contratos_regiao'] ?? [];
$situacoes = $report['contratos_situacao'] ?? [];
$totalTabs = $report['total_tabelas'] ?? count($tabelas);
$erros     = $report['erros'] ?? [];
$modo      = $report['modo'] ?? 'completo';
$tipo      = $report['tipo'] ?? 'cliente';

// Ordenar tabelas por nome
ksort($tabelas);

// Remover tabelas sem registros do relatorio (nao poluir com vazias)
$tabelas = array_filter($tabelas, function($t) {
    return (int)($t['registros'] ?? 0) > 0;
});

$totalTabs = count($tabelas);

$totalRegistros = 0;
foreach ($tabelas as $t) { $totalRegistros += (int)($t['registros'] ?? 0); }

$totalContratos = 0;
foreach ($contRegs as $n) { $totalContratos += (int)$n['total']; }

$totalContratosSit = 0;
foreach ($situacoes as $s) { $totalContratosSit += (int)$s['total']; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatorio Base Amostral &mdash; <?= htmlspecialchars($bdDest) ?></title>
    <style>
        @page { margin: 15mm 20mm; size: A4; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; color: #1a1a2e; font-size: 11pt; line-height: 1.5; background: #f0f2f5; }
        .page { max-width: 210mm; margin: 20px auto; background: #fff; padding: 30px 40px; box-shadow: 0 2px 20px rgba(0,0,0,0.08); }

        .header { border-bottom: 3px solid #1a3a5c; padding-bottom: 15px; margin-bottom: 25px; }
        .header h1 { font-size: 20pt; color: #1a3a5c; font-weight: 700; }
        .header .subtitle { font-size: 10pt; color: #6b7280; margin-top: 4px; }

        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 30px; margin-bottom: 25px; padding: 15px; background: #f8fafc; border-radius: 6px; border-left: 4px solid #1a3a5c; }
        .info-item { font-size: 10pt; }
        .info-item .label { font-weight: 600; color: #4b5563; }
        .info-item .value { color: #1a1a2e; font-weight: 500; }

        .section { margin-bottom: 22px; }
        .section h2 { font-size: 13pt; color: #1a3a5c; border-bottom: 1px solid #e5e7eb; padding-bottom: 6px; margin-bottom: 12px; }

        table { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
        th { background: #1a3a5c; color: #fff; padding: 8px 10px; text-align: left; font-weight: 600; }
        td { padding: 6px 10px; border-bottom: 1px solid #e5e7eb; }
        tr:nth-child(even) { background: #f9fafb; }
        tr:hover { background: #f0f4ff; }
        .num { text-align: right; font-family: 'Consolas', monospace; }
        .num-flg { text-align: center; font-family: 'Consolas', monospace; font-weight: 700; color: #1a3a5c; width: 50px; }
        .pct { text-align: right; font-family: 'Consolas', monospace; color: #6b7280; font-size: 9pt; width: 70px; }

        .summary-boxes { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 25px; }
        .summary-box { text-align: center; padding: 12px; border-radius: 6px; background: #f0f4ff; border: 1px solid #dbeafe; }
        .summary-box .number { font-size: 22pt; font-weight: 700; color: #1a3a5c; }
        .summary-box .desc { font-size: 9pt; color: #6b7280; margin-top: 2px; }

        .regiao-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 8px; }
        .regiao-item { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 4px; padding: 8px; text-align: center; }
        .regiao-item .nuc-id { font-weight: 700; color: #1a3a5c; font-size: 12pt; }
        .regiao-item .nuc-count { font-size: 9pt; color: #6b7280; }

        .errors { background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 12px; }
        .errors li { color: #991b1b; font-size: 9.5pt; margin-left: 16px; }

        .footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #e5e7eb; font-size: 8pt; color: #9ca3af; text-align: center; }

        .btn-print { display: inline-block; background: #1a3a5c; color: #fff; border: none; padding: 10px 24px; border-radius: 6px; font-size: 11pt; cursor: pointer; margin-bottom: 20px; }
        .btn-print:hover { background: #0f2a42; }

        /* Barra inline de percentual */
        .bar-cell { position: relative; padding: 0; }
        .bar-wrap { height: 18px; width: 100%; background: #eef2f7; position: relative; border-radius: 3px; overflow: hidden; }
        .bar-fill { position: absolute; top: 0; left: 0; height: 100%; background: linear-gradient(90deg, #3b5998, #1a3a5c); }

        @media print {
            body { background: #fff; }
            .page { box-shadow: none; margin: 0; padding: 0; max-width: none; }
            .btn-print, .no-print { display: none !important; }
            tr:hover { background: transparent; }
            .section { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="no-print" style="text-align:right; margin-bottom: 10px;">
            <button class="btn-print" onclick="window.print()">&#128196; Imprimir / Salvar como PDF</button>
        </div>

        <div class="header">
            <h1>Relatorio de Extracao &mdash; Base Amostral</h1>
            <div class="subtitle">Gerado em <?= htmlspecialchars($dataExec) ?> &middot; Modo: <?= $tipo === 'sgh' ? 'SGH Elogica (tabelas MTTB)' : 'Cliente' ?></div>
        </div>

        <div class="info-grid">
            <div class="info-item"><span class="label">Banco Origem:</span> <span class="value"><?= htmlspecialchars($bdOrig) ?></span></div>
            <div class="info-item"><span class="label">Banco Destino:</span> <span class="value"><?= htmlspecialchars($bdDest) ?></span></div>
            <div class="info-item">
                <span class="label"><?= (is_array($codemps) && count($codemps) > 1) ? 'Empresas:' : 'Codigo Empresa:' ?></span>
                <span class="value"><?= htmlspecialchars(is_array($codemps) ? implode(', ', $codemps) : (string)$codemp) ?></span>
            </div>
            <div class="info-item"><span class="label">Servidor:</span> <span class="value"><?= htmlspecialchars($report['servidor'] ?? '-') ?></span></div>
            <div class="info-item"><span class="label">Tipo:</span> <span class="value"><?= $tipo === 'sgh' ? 'SGH Elogica' : 'Cliente' ?></span></div>
        </div>

        <div class="summary-boxes">
            <div class="summary-box">
                <div class="number"><?= $totalTabs ?></div>
                <div class="desc"><?= $tipo === 'sgh' ? 'Tabelas MTTB' : 'Tabelas Criadas' ?></div>
            </div>
            <div class="summary-box">
                <div class="number"><?= number_format($totalRegistros, 0, ',', '.') ?></div>
                <div class="desc">Total de Registros</div>
            </div>
            <div class="summary-box">
                <div class="number"><?= number_format($totalContratos, 0, ',', '.') ?></div>
                <div class="desc">Contratos Extraidos</div>
            </div>
        </div>

        <?php if (!empty($contRegs)): ?>
        <div class="section">
            <h2>Contratos por Regiao</h2>
            <div class="regiao-grid">
                <?php foreach ($contRegs as $n): ?>
                <div class="regiao-item">
                    <div class="nuc-id">Regiao <?= htmlspecialchars($n['regiao']) ?></div>
                    <div class="nuc-count"><?= number_format((int)$n['total'], 0, ',', '.') ?> contratos</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($situacoes) && $totalContratosSit > 0): ?>
        <div class="section">
            <h2>Distribuicao por Situacao (CTR_FLG_ATIV em MTTBCON)</h2>
            <table>
                <thead>
                    <tr>
                        <th style="width:50px; text-align:center;">FLG</th>
                        <th>Descricao</th>
                        <th class="num" style="width:100px">Quantidade</th>
                        <th style="width:200px">Participacao</th>
                        <th class="pct">%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($situacoes as $s):
                        $flg = $s['flg'];
                        $qtd = (int)$s['total'];
                        $pct = $totalContratosSit > 0 ? ($qtd / $totalContratosSit) * 100 : 0;
                        $desc = ($flg !== null && isset($dictSituacao[$flg]))
                                ? $dictSituacao[$flg]
                                : '(desconhecido)';
                        $flgShow = $flg === null ? 'NULL' : $flg;
                    ?>
                    <tr>
                        <td class="num-flg"><?= htmlspecialchars((string)$flgShow) ?></td>
                        <td><?= htmlspecialchars($desc) ?></td>
                        <td class="num"><?= number_format($qtd, 0, ',', '.') ?></td>
                        <td class="bar-cell">
                            <div class="bar-wrap">
                                <div class="bar-fill" style="width: <?= number_format($pct, 2, '.', '') ?>%;"></div>
                            </div>
                        </td>
                        <td class="pct"><?= number_format($pct, 2, ',', '.') ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f0f4ff; font-weight:700;">
                        <td colspan="2">TOTAL</td>
                        <td class="num"><?= number_format($totalContratosSit, 0, ',', '.') ?></td>
                        <td></td>
                        <td class="pct">100,00%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>

        <div class="section">
            <h2><?= $tipo === 'sgh' ? 'Tabelas MTTB Extraidas' : 'Tabelas Extraidas' ?></h2>
            <table>
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th>Tabela</th>
                        <th style="width:100px" class="num">Registros</th>
                        <th class="pct">%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 0; foreach ($tabelas as $key => $tab): $i++;
                        $regs = (int)($tab['registros'] ?? 0);
                        $pct = $totalRegistros > 0 ? ($regs / $totalRegistros) * 100 : 0;
                    ?>
                    <tr>
                        <td><?= $i ?></td>
                        <td><strong><?= htmlspecialchars($key) ?></strong></td>
                        <td class="num"><?= number_format($regs, 0, ',', '.') ?></td>
                        <td class="pct"><?= number_format($pct, 2, ',', '.') ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f0f4ff; font-weight:700;">
                        <td colspan="2">TOTAL</td>
                        <td class="num"><?= number_format($totalRegistros, 0, ',', '.') ?></td>
                        <td class="pct">100,00%</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if (!empty($erros)): ?>
        <div class="section">
            <h2>Erros Encontrados (<?= count($erros) ?>)</h2>
            <div class="errors">
                <?php foreach ($erros as $e):
                    $nome = is_array($e) ? ($e['t'] ?? '-') : $e;
                    $msg  = is_array($e) ? ($e['m'] ?? '') : '';
                ?>
                <div style="margin-bottom:8px;">
                    <strong style="color:#991b1b;">&#10008; <?= htmlspecialchars($nome) ?></strong>
                    <?php if ($msg): ?>
                    <div style="font-size:8.5pt; color:#7f1d1d; font-family:Consolas,monospace; margin-top:2px; padding-left:16px; word-break:break-all;">&rarr; <?= htmlspecialchars($msg) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="footer">
            Sistema de Geracao de Base Amostral &mdash; <?= date('d/m/Y H:i') ?>
        </div>
    </div>
</body>
</html>
