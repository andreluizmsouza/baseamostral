<?php
/**
 * compare.php - Comparativo de quantidade de registros entre uma base CLIENTE e uma base SGH ELOGICA
 * Mostra apenas tabelas que entram no modo Cliente (subconjunto do SGH).
 * Mapeamento MTTBxxx <-> nome renomeado e extraido automaticamente do sql_scripts.php.
 */

header('Content-Type: text/html; charset=UTF-8');

// =================== Helpers ===================
function extrairMapeamentoMTTB(): array
{
    $src = @file_get_contents(__DIR__ . '/sql_scripts.php');
    if ($src === false) return [];
    preg_match_all(
        '/INTO\s+\{DEST\}\.DBO\.([A-Za-z_][A-Za-z0-9_]*)[^F]*?FROM\s+\{ORIG\}\.DBO\.([A-Za-z_][A-Za-z0-9_]*)/is',
        $src, $m
    );
    $pairs = []; // dest_uppercase => orig_uppercase
    for ($i = 0; $i < count($m[1]); $i++) {
        $dest = strtoupper($m[1][$i]);
        $orig = strtoupper($m[2][$i]);
        if (stripos($dest, '_TEMP') === 0 || stripos($dest, '_SGH_TEMP') === 0) continue;
        if (stripos($orig, '_TEMP') === 0 || stripos($orig, '_SGH_TEMP') === 0) continue;
        if (stripos($orig, 'MTTB') !== 0) continue;
        if (!isset($pairs[$dest])) $pairs[$dest] = $orig;
    }
    return $pairs;
}

function contarTabela($conn, string $banco, string $tabela): int
{
    $sql = "SELECT COUNT(*) AS total FROM [$banco].DBO.[$tabela]";
    $stmt = @sqlsrv_query($conn, $sql);
    if (!$stmt) return -1;
    $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return (int)($r['total'] ?? -1);
}

function tabelaExisteEm($conn, string $banco, string $tabela): bool
{
    $sql = "SELECT 1 FROM [$banco].INFORMATION_SCHEMA.TABLES
            WHERE TABLE_NAME = '$tabela' AND TABLE_TYPE = 'BASE TABLE'";
    $stmt = @sqlsrv_query($conn, $sql);
    if (!$stmt) return false;
    $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return !empty($r);
}

// =================== Form / processamento ===================
$servidor   = trim($_POST['servidor'] ?? $_GET['servidor'] ?? '');
$login      = trim($_POST['login'] ?? '');
$senha      = $_POST['senha'] ?? '';
$bdCliente  = trim($_POST['bd_cliente'] ?? '');
$bdSgh      = trim($_POST['bd_sgh'] ?? '');
$ja         = !empty($_POST['executar']);

$mapeamento = extrairMapeamentoMTTB(); // [DEST_CLIENTE => ORIG_MTTB]

$linhas    = [];
$totalCli  = 0;
$totalSgh  = 0;
$divergentes = 0;
$erro      = '';

if ($ja) {
    if (!$servidor || !$login || !$bdCliente || !$bdSgh) {
        $erro = 'Preencha todos os campos.';
    } else {
        $connInfo = [
            "Database"               => "master",
            "UID"                    => $login,
            "PWD"                    => $senha,
            "TrustServerCertificate" => true,
        ];
        $conn = @sqlsrv_connect($servidor, $connInfo);
        if (!$conn) {
            $errs = sqlsrv_errors();
            $erro = 'Falha na conexao: ' . ($errs ? $errs[0]['message'] : 'desconhecido');
        } else {
            foreach ($mapeamento as $destCli => $origMttb) {
                $existeCli = tabelaExisteEm($conn, $bdCliente, $destCli);
                $existeSgh = tabelaExisteEm($conn, $bdSgh, $origMttb);
                $qCli = $existeCli ? contarTabela($conn, $bdCliente, $destCli) : -1;
                $qSgh = $existeSgh ? contarTabela($conn, $bdSgh,     $origMttb) : -1;
                if (!$existeCli && !$existeSgh) continue; // ignora linhas sem dado em ambos os lados

                if ($qCli >= 0) $totalCli += $qCli;
                if ($qSgh >= 0) $totalSgh += $qSgh;

                $bate = ($qCli === $qSgh && $qCli >= 0);
                if (!$bate && $existeCli && $existeSgh) $divergentes++;

                $linhas[] = [
                    'destCli'   => $destCli,
                    'origMttb'  => $origMttb,
                    'qCli'      => $qCli,
                    'qSgh'      => $qSgh,
                    'existeCli' => $existeCli,
                    'existeSgh' => $existeSgh,
                    'bate'      => $bate,
                ];
            }
            sqlsrv_close($conn);
            // Ordenar: divergentes primeiro, depois por nome
            usort($linhas, function($a, $b) {
                $ka = ($a['existeCli'] && $a['existeSgh'] && !$a['bate']) ? 0 : 1;
                $kb = ($b['existeCli'] && $b['existeSgh'] && !$b['bate']) ? 0 : 1;
                if ($ka !== $kb) return $ka - $kb;
                return strcmp($a['destCli'], $b['destCli']);
            });
        }
    }
}

function fmt($n) {
    if ($n < 0) return '<span style="color:#9ca3af">-</span>';
    return number_format($n, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Comparativo Cliente vs SGH Elogica</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); min-height: 100vh; color: #e2e8f0; padding: 24px; }
        .container { max-width: 1100px; margin: 0 auto; }
        h1 { font-size: 22pt; margin-bottom: 6px; color: #fff; }
        .subtitle { font-size: 11pt; color: #94a3b8; margin-bottom: 20px; }
        .card { background: rgba(30, 41, 59, 0.7); border-radius: 12px; padding: 20px; margin-bottom: 18px; border: 1px solid rgba(148, 163, 184, 0.15); }
        h2 { font-size: 14pt; color: #60a5fa; margin-bottom: 12px; border-left: 3px solid #60a5fa; padding-left: 10px; }
        .row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin-bottom: 14px; }
        .row-3 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        label { display: block; font-size: 10pt; color: #94a3b8; margin-bottom: 6px; }
        input { width: 100%; background: #1e293b; color: #e2e8f0; border: 1px solid #475569; padding: 10px 12px; border-radius: 6px; font-size: 11pt; }
        input:focus { outline: none; border-color: #60a5fa; }
        button { background: linear-gradient(90deg, #3b5998, #1a3a5c); color: #fff; border: none; padding: 12px 28px; border-radius: 6px; font-size: 12pt; cursor: pointer; font-weight: 600; }
        button:hover { opacity: 0.9; }
        .erro { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
        .stat { background: rgba(15, 23, 42, 0.7); border-radius: 8px; padding: 14px; text-align: center; border: 1px solid rgba(148, 163, 184, 0.1); }
        .stat .num { font-size: 18pt; font-weight: 700; color: #60a5fa; }
        .stat .lbl { font-size: 9pt; color: #94a3b8; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat.warn .num { color: #fbbf24; }
        .stat.ok .num { color: #34d399; }
        table { width: 100%; border-collapse: collapse; font-size: 10pt; }
        th { background: #1a3a5c; color: #fff; padding: 10px 12px; text-align: left; font-size: 9.5pt; }
        td { padding: 8px 12px; border-bottom: 1px solid rgba(148, 163, 184, 0.1); }
        tr:hover { background: rgba(30, 41, 59, 0.5); }
        .num { text-align: right; font-family: 'Consolas', monospace; }
        .ok-row { color: #cbd5e1; }
        .warn-row { background: rgba(251, 191, 36, 0.05); }
        .warn-row td { color: #fde68a; }
        .err-row td { color: #fca5a5; }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 8pt; font-weight: 600; }
        .pill.ok   { background: rgba(52, 211, 153, 0.2); color: #6ee7b7; }
        .pill.warn { background: rgba(251, 191, 36, 0.25); color: #fcd34d; }
        .pill.err  { background: rgba(239, 68, 68, 0.25); color: #fca5a5; }
        .back-link { display: inline-block; color: #94a3b8; text-decoration: none; margin-bottom: 14px; }
        .back-link:hover { color: #60a5fa; }
    </style>
</head>
<body>
    <div class="container">
        <a class="back-link" href="index.php">&larr; Voltar para Base Amostral</a>
        <h1>Comparativo Cliente vs SGH Elogica</h1>
        <div class="subtitle">Confere se as quantidades por tabela batem entre as duas bases geradas para o mesmo arquivo.</div>

        <?php if ($erro): ?>
        <div class="erro">&#10008; <?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Conexao</h2>
            <form method="post">
                <input type="hidden" name="executar" value="1">
                <div class="row-3">
                    <div>
                        <label>Servidor SQL</label>
                        <input type="text" name="servidor" value="<?= htmlspecialchars($servidor) ?>" placeholder="localhost\SQLEXPRESS" required>
                    </div>
                    <div>
                        <label>Login</label>
                        <input type="text" name="login" value="<?= htmlspecialchars($login) ?>" placeholder="sa" required>
                    </div>
                    <div>
                        <label>Senha</label>
                        <input type="password" name="senha" required>
                    </div>
                </div>
                <div class="row">
                    <div>
                        <label>Banco CLIENTE (gerado em modo Cliente)</label>
                        <input type="text" name="bd_cliente" value="<?= htmlspecialchars($bdCliente) ?>" placeholder="BASE_AMOSTRAL_CLIENTE" required>
                    </div>
                    <div>
                        <label>Banco SGH ELOGICA (gerado em modo SGH)</label>
                        <input type="text" name="bd_sgh" value="<?= htmlspecialchars($bdSgh) ?>" placeholder="BASE_AMOSTRAL_SGH" required>
                    </div>
                </div>
                <button type="submit">&#128270; Comparar</button>
            </form>
        </div>

        <?php if ($ja && !$erro && !empty($linhas)):
            $totalLinhas = count($linhas);
            $batem = 0;
            foreach ($linhas as $l) if ($l['existeCli'] && $l['existeSgh'] && $l['bate']) $batem++;
        ?>
        <div class="card">
            <h2>Resumo</h2>
            <div class="stats">
                <div class="stat"><div class="num"><?= $totalLinhas ?></div><div class="lbl">Tabelas analisadas</div></div>
                <div class="stat ok"><div class="num"><?= $batem ?></div><div class="lbl">Quantidades OK</div></div>
                <div class="stat warn"><div class="num"><?= $divergentes ?></div><div class="lbl">Divergentes</div></div>
                <div class="stat"><div class="num"><?= number_format(max($totalCli, $totalSgh), 0, ',', '.') ?></div><div class="lbl">Total registros (max)</div></div>
            </div>
        </div>

        <div class="card">
            <h2>Detalhes por tabela</h2>
            <table>
                <thead>
                    <tr>
                        <th>Tabela CLIENTE</th>
                        <th>Tabela SGH (origem)</th>
                        <th class="num">Cliente</th>
                        <th class="num">SGH</th>
                        <th class="num">Diferenca</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($linhas as $l):
                        $rowClass = '';
                        $statusHtml = '';
                        if (!$l['existeCli'] && !$l['existeSgh']) continue;
                        if (!$l['existeCli']) {
                            $rowClass = 'err-row';
                            $statusHtml = '<span class="pill err">Cliente: nao existe</span>';
                        } elseif (!$l['existeSgh']) {
                            $rowClass = 'err-row';
                            $statusHtml = '<span class="pill err">SGH: nao existe</span>';
                        } elseif ($l['bate']) {
                            $rowClass = 'ok-row';
                            $statusHtml = '<span class="pill ok">Bate</span>';
                        } else {
                            $rowClass = 'warn-row';
                            $statusHtml = '<span class="pill warn">Divergente</span>';
                        }
                        $diff = ($l['qCli'] >= 0 && $l['qSgh'] >= 0) ? ($l['qSgh'] - $l['qCli']) : null;
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td><strong><?= htmlspecialchars($l['destCli']) ?></strong></td>
                        <td style="color:#94a3b8"><?= htmlspecialchars($l['origMttb']) ?></td>
                        <td class="num"><?= fmt($l['qCli']) ?></td>
                        <td class="num"><?= fmt($l['qSgh']) ?></td>
                        <td class="num">
                            <?= $diff === null ? '<span style="color:#9ca3af">-</span>'
                                : ($diff === 0 ? '0' : ($diff > 0 ? '+' . number_format($diff, 0, ',', '.') : number_format($diff, 0, ',', '.'))) ?>
                        </td>
                        <td><?= $statusHtml ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: rgba(26, 58, 92, 0.4); font-weight: 700;">
                        <td colspan="2">TOTAL</td>
                        <td class="num"><?= number_format($totalCli, 0, ',', '.') ?></td>
                        <td class="num"><?= number_format($totalSgh, 0, ',', '.') ?></td>
                        <td class="num"><?= number_format($totalSgh - $totalCli, 0, ',', '.') ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php elseif ($ja && !$erro): ?>
        <div class="card">Nenhuma tabela mapeada encontrada nos bancos informados.</div>
        <?php endif; ?>
    </div>
</body>
</html>
