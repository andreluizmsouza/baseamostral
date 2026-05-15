<?php
/**
 * process.php - Processamento da Base Amostral
 * Conecta ao SQL Server e executa todas as fases de extracao
 */

set_time_limit(0);
ini_set('memory_limit', '512M');
// Garantir que o processo continua mesmo se o navegador desconectar a request SSE
// (sem isso, fechar a aba durante o processamento mata o PHP no meio).
ignore_user_abort(true);

// Anti-buffer (compatibilidade com IIS/Apache)
@ini_set('zlib.output_compression', 'Off');
@ini_set('output_buffering', 'Off');
@ini_set('implicit_flush', 'On');
@ini_set('output_handler', '');
// Se for Apache, desliga gzip apenas dessa request (function_exists evita fatal em IIS)
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Encerra QUALQUER nivel de output buffer ativo
while (ob_get_level() > 0) { @ob_end_flush(); }
ob_implicit_flush(true);

// Padding inicial: forca o webserver a parar de bufferizar e comecar a entregar
echo ":" . str_repeat(" ", 16384) . "\n\n";
@flush();

// Primeiro evento ja sai imediatamente
echo "data: " . json_encode(['type' => 'log', 'level' => 'info', 'message' => 'Servidor recebeu requisicao. Iniciando...']) . "\n\n";
@flush();

require_once __DIR__ . '/sql_scripts.php';

// ===================== FUNCOES DE SAIDA =====================
function sendEvent(string $type, array $data): void
{
    echo "data: " . json_encode(array_merge(['type' => $type], $data), JSON_UNESCAPED_UNICODE) . "\n\n";
    // Padding "comentario SSE" (linha iniciada com `:` e ignorada pelo cliente)
    // forca o IIS/Apache a entregar o evento imediatamente em vez de bufferizar
    // ate acumular 4KB+. ~2KB ja e suficiente em 99% dos casos.
    echo ":" . str_repeat(" ", 2048) . "\n\n";
    @flush();
}

function logInfo(string $msg): void    { sendEvent('log', ['level' => 'info', 'message' => $msg]); }
function logSuccess(string $msg): void { sendEvent('log', ['level' => 'success', 'message' => $msg]); }
function logError(string $msg): void   { sendEvent('log', ['level' => 'error', 'message' => $msg]); }
function logWarn(string $msg): void    { sendEvent('log', ['level' => 'warn', 'message' => $msg]); }

function sendProgress(int $current, int $total, string $phase): void
{
    sendEvent('progress', [
        'current' => $current,
        'total'   => $total,
        'phase'   => $phase,
        'percent' => $total > 0 ? round(($current / $total) * 100, 1) : 0
    ]);
}

// ===================== DIAGNOSTICO PHP =====================
logInfo("PHP versao: " . PHP_VERSION);
logInfo("file_uploads: " . ini_get('file_uploads'));
logInfo("upload_max_filesize: " . ini_get('upload_max_filesize'));
logInfo("post_max_size: " . ini_get('post_max_size'));
logInfo("upload_tmp_dir: " . (ini_get('upload_tmp_dir') ?: '(padrao do sistema)'));
logInfo("Content-Type recebido: " . ($_SERVER['CONTENT_TYPE'] ?? '(nao definido)'));
logInfo("Content-Length recebido: " . ($_SERVER['CONTENT_LENGTH'] ?? '(nao definido)'));
logInfo("REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? '(nao definido)'));
logInfo("Chaves em \$_FILES: " . (empty($_FILES) ? '(vazio)' : implode(', ', array_keys($_FILES))));
logInfo("Chaves em \$_POST: " . (empty($_POST) ? '(vazio)' : implode(', ', array_keys($_POST))));

// ===================== VALIDACAO =====================
$servidor      = trim($_POST['servidor'] ?? '');
$login         = trim($_POST['login'] ?? '');
$senha         = $_POST['senha'] ?? '';
$codempInput   = trim($_POST['codemp'] ?? '');
$bdOrigem      = trim($_POST['bd_origem'] ?? '');
$bdDestino     = trim($_POST['bd_destino'] ?? '');
$dropDestino   = ($_POST['drop_destino'] ?? '0') === '1';
$skipBlobs     = ($_POST['skip_blobs']   ?? '0') === '1';
$onlyFinalizers = ($_POST['only_finalizers'] ?? '0') === '1';
$onlyExceptions = ($_POST['only_exceptions'] ?? '0') === '1';
$tipo          = trim($_POST['tipo'] ?? 'cliente');

// Parse do(s) codigo(s) de empresa: aceita "5" ou "2,5,11,12,21,22,25"
// Pode estar vazio se o TXT tiver 4 colunas (CODEMP por linha)
$codempsForm = [];
if ($codempInput !== '') {
    foreach (explode(',', $codempInput) as $part) {
        $v = intval(trim($part));
        if ($v > 0) $codempsForm[] = $v;
    }
    $codempsForm = array_values(array_unique($codempsForm));
}

logInfo("Modo de processamento: " . strtoupper($tipo));
if (!empty($codempsForm)) {
    logInfo("Empresa(s) informada(s) no formulario: " . implode(', ', $codempsForm));
}

if ($onlyFinalizers && $tipo !== 'sgh') {
    logError("Modo RETOMADA so esta disponivel no SGH Elogica.");
    sendEvent('done', ['success' => false]);
    exit;
}

if ($onlyExceptions && $tipo !== 'sgh') {
    logError("Modo REPROCESSAR EXCECOES so esta disponivel no SGH Elogica.");
    sendEvent('done', ['success' => false]);
    exit;
}

if ($onlyExceptions && $onlyFinalizers) {
    logError("Nao e possivel combinar REPROCESSAR EXCECOES com Modo RETOMADA.");
    sendEvent('done', ['success' => false]);
    exit;
}

if ($onlyExceptions && $dropDestino) {
    logError("REPROCESSAR EXCECOES nao pode ser combinado com APAGAR E RECRIAR banco destino (o banco precisa existir).");
    sendEvent('done', ['success' => false]);
    exit;
}

if (!$servidor || !$login || !$bdOrigem || !$bdDestino) {
    logError('Parametros obrigatorios nao informados (servidor/login/bancos).');
    sendEvent('done', ['success' => false]);
    exit;
}

// ===================== ARQUIVO TXT (so necessario fora do modo retomada) =====================
$contratos = [];
$codemps = [];
$codempList = '';
$codempPrimeiro = 0;

if ($onlyFinalizers) {
    logWarn("Modo RETOMADA: pulando leitura/validacao do arquivo TXT.");
    // codempList sera reconstruido a partir da CON_FIDC ja existente apos conectar
} elseif ($onlyExceptions) {
    logWarn("Modo REPROCESSAR EXCECOES: pulando leitura/validacao do arquivo TXT.");
    // codempList sera reconstruido a partir da CON_FIDC ja existente apos conectar
} else {

if (!isset($_FILES['arquivo_txt'])) {
    logError('Arquivo TXT de contratos nao foi recebido pelo servidor.');
    
    if (!ini_get('file_uploads')) {
        logError('>>> CAUSA: file_uploads esta DESABILITADO no php.ini! Habilite com: file_uploads = On');
    } elseif (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0 && empty($_POST)) {
        logError('>>> CAUSA: post_max_size (' . ini_get('post_max_size') . ') pode ter sido excedido, descartando todo o POST.');
    } else {
        logError('>>> Verifique: file_uploads=On, upload_tmp_dir com permissao de escrita, e enctype do formulario.');
        logError('>>> tmp_dir atual: ' . (sys_get_temp_dir()));
        logError('>>> tmp_dir gravavel: ' . (is_writable(sys_get_temp_dir()) ? 'SIM' : 'NAO'));
    }
    
    sendEvent('done', ['success' => false]);
    exit;
}

if ($_FILES['arquivo_txt']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'Arquivo excede upload_max_filesize do php.ini',
        UPLOAD_ERR_FORM_SIZE  => 'Arquivo excede MAX_FILE_SIZE do formulario',
        UPLOAD_ERR_PARTIAL    => 'Upload incompleto',
        UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo enviado',
        UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporaria nao encontrada',
        UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar arquivo em disco',
        UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensao PHP',
    ];
    $errCode = $_FILES['arquivo_txt']['error'];
    $errMsg = $uploadErrors[$errCode] ?? "Erro desconhecido (codigo $errCode)";
    logError("Erro no upload do arquivo: $errMsg");
    sendEvent('done', ['success' => false]);
    exit;
}

// Validar nome do banco destino (sem caracteres especiais perigosos)
if (!preg_match('/^[a-zA-Z0-9_]+$/', $bdDestino)) {
    logError('Nome do banco destino deve conter apenas letras, numeros e underscore.');
    sendEvent('done', ['success' => false]);
    exit;
}

// ===================== LER ARQUIVO TXT =====================
logInfo("Lendo arquivo de contratos...");

$conteudo = file_get_contents($_FILES['arquivo_txt']['tmp_name']);

// Remover BOM (UTF-8, UTF-16 LE/BE)
$conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);       // UTF-8 BOM
$conteudo = preg_replace('/^\xFF\xFE/', '', $conteudo);             // UTF-16 LE BOM
$conteudo = preg_replace('/^\xFE\xFF/', '', $conteudo);             // UTF-16 BE BOM

$linhas = preg_split('/\r\n|\r|\n/', $conteudo);
$linhas = array_filter($linhas, fn($l) => trim($l) !== '');

if (empty($linhas)) {
    logError('Arquivo TXT esta vazio.');
    sendEvent('done', ['success' => false]);
    exit;
}

logInfo("BOM removido (se existia). Linhas encontradas: " . count($linhas));

// Detectar formato pela primeira linha de dados (ignora cabecalho)
$formatoTxt = null; // 3 ou 4
$linhaAmostra = null;
foreach ($linhas as $i => $l) {
    $l = trim($l);
    if (empty($l)) continue;
    if ($i === 0 && (stripos($l, 'REGIAO') !== false || stripos($l, 'CODEMP') !== false)) continue;
    $linhaAmostra = $l;
    break;
}
if ($linhaAmostra !== null) {
    $partesAmostra = explode(';', $linhaAmostra);
    $formatoTxt = (count($partesAmostra) >= 4) ? 4 : 3;
    logInfo("Formato do TXT detectado: " . $formatoTxt . " colunas " .
            ($formatoTxt === 4 ? "(CODEMP;REGIAO;NUCLEO;CONTRATO)" : "(REGIAO;NUCLEO;CONTRATO)"));
}

// Validar fonte do CODEMP de acordo com o formato
if ($formatoTxt === 3 && empty($codempsForm)) {
    logError('TXT em 3 colunas exige preencher o campo "Codigo da Empresa" no formulario (um valor ou lista separada por virgula).');
    sendEvent('done', ['success' => false]);
    exit;
}

$contratos = [];
$cabecalhoPulado = false;
foreach ($linhas as $idx => $linha) {
    $linha = trim($linha);
    if (empty($linha)) continue;

    // Se a primeira linha parece ser cabecalho, pular
    if (!$cabecalhoPulado && (stripos($linha, 'REGIAO') !== false || stripos($linha, 'CODEMP') !== false)) {
        $cabecalhoPulado = true;
        continue;
    }

    $partes = explode(';', $linha);
    if (count($partes) < 3) {
        logWarn("Linha " . ($idx + 1) . " ignorada (formato invalido): $linha");
        continue;
    }

    // Limpar caracteres invisiveis (BOM residual, zero-width spaces, etc.)
    if ($formatoTxt === 4) {
        $codempLinha = intval(preg_replace('/[^\x20-\x7E]/', '', trim($partes[0])));
        $regiao      = preg_replace('/[^\x20-\x7E]/', '', trim($partes[1]));
        $nucleo      = preg_replace('/[^\x20-\x7E]/', '', trim($partes[2]));
        $contrato    = preg_replace('/[^\x20-\x7E]/', '', trim($partes[3]));
        if ($codempLinha <= 0) {
            logWarn("Linha " . ($idx + 1) . " ignorada (CODEMP invalido)");
            continue;
        }
    } else {
        $codempLinha = null; // sera atribuido a partir do form
        $regiao      = preg_replace('/[^\x20-\x7E]/', '', trim($partes[0]));
        $nucleo      = preg_replace('/[^\x20-\x7E]/', '', trim($partes[1]));
        $contrato    = preg_replace('/[^\x20-\x7E]/', '', trim($partes[2]));
    }

    if (empty($regiao) || empty($nucleo) || empty($contrato)) {
        logWarn("Linha " . ($idx + 1) . " ignorada (valores vazios apos limpeza)");
        continue;
    }

    if ($formatoTxt === 4) {
        // Uma linha por contrato (CODEMP ja vem do TXT)
        $contratos[] = [
            'codemp'   => $codempLinha,
            'regiao'   => $regiao,
            'nucleo'   => $nucleo,
            'contrato' => $contrato,
        ];
    } else {
        // Formato antigo: replicar a linha para cada CODEMP do form
        foreach ($codempsForm as $cf) {
            $contratos[] = [
                'codemp'   => $cf,
                'regiao'   => $regiao,
                'nucleo'   => $nucleo,
                'contrato' => $contrato,
            ];
        }
    }
}

if (empty($contratos)) {
    logError('Nenhum contrato valido encontrado no arquivo.');
    sendEvent('done', ['success' => false]);
    exit;
}

// Lista distinta de empresas envolvidas (descoberta automaticamente)
$codempsDescobertos = [];
foreach ($contratos as $c) {
    $codempsDescobertos[$c['codemp']] = true;
}
$codemps     = array_keys($codempsDescobertos);
sort($codemps);
$codempList  = implode(',', $codemps);
$codempPrimeiro = $codemps[0]; // para placeholders e logs que ainda usam valor unico

logSuccess("Arquivo lido: " . count($contratos) . " linhas; empresas envolvidas: " . $codempList);
if (!empty($contratos)) {
    $c = $contratos[0];
    logInfo("Primeira linha: CODEMP=[{$c['codemp']}] REGIAO=[{$c['regiao']}] NUCLEO=[{$c['nucleo']}] CONTRATO=[{$c['contrato']}]");
}

} // fim if (!$onlyFinalizers && !$onlyExceptions) - leitura do TXT

// ===================== CONEXAO SQL SERVER =====================
logInfo("Conectando ao SQL Server: $servidor ...");

$connInfo = [
    "Database"             => "master",
    "UID"                  => $login,
    "PWD"                  => $senha,
    "TrustServerCertificate" => true,
    "LoginTimeout"         => 60,
    "ConnectionPooling"    => false,
];

$conn = sqlsrv_connect($servidor, $connInfo);
if (!$conn) {
    $errors = sqlsrv_errors();
    $msg = $errors ? $errors[0]['message'] : 'Erro desconhecido';
    logError("Falha na conexao: $msg");
    sendEvent('done', ['success' => false]);
    exit;
}

// QueryTimeout=0 -> sem limite de tempo por query (importante para CREATE INDEX
// em tabelas com milhoes de linhas, que podem levar varios minutos cada)
sqlsrv_configure('WarningsReturnAsErrors', 0);

logSuccess("Conectado ao SQL Server.");

// ===================== FUNCOES SQL =====================
function executarSQL($conn, string $sql, string $descricao): bool
{
    $t0 = microtime(true);
    // QueryTimeout=0 garante que CREATE INDEX em tabelas grandes nao caia
    // por tempo (default do sqlsrv pode aplicar limite em alguns ambientes)
    $stmt = sqlsrv_query($conn, $sql, [], ['QueryTimeout' => 0]);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        $msg = $errors ? $errors[0]['message'] : 'Erro desconhecido';
        logError("ERRO em [$descricao]: $msg");
        return false;
    }
    // Consumir resultados para liberar o statement
    while (sqlsrv_next_result($stmt)) {}
    sqlsrv_free_stmt($stmt);
    $dt = microtime(true) - $t0;
    if ($dt >= 5.0) {
        // Sinaliza operacoes lentas para o usuario nao achar que travou
        logInfo("  ... [$descricao] levou " . number_format($dt, 1) . "s");
    }
    return true;
}

function dropIfExists($conn, string $fullTableName): void
{
    $sql = "IF OBJECT_ID('$fullTableName', 'U') IS NOT NULL DROP TABLE $fullTableName";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt) { while (sqlsrv_next_result($stmt)) {} sqlsrv_free_stmt($stmt); }
}

function tabelaExiste($conn, string $banco, string $tabela): bool
{
    $sql = "SELECT 1 FROM [$banco].INFORMATION_SCHEMA.TABLES
            WHERE TABLE_NAME = '$tabela' AND TABLE_TYPE = 'BASE TABLE'";
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return !empty($row);
}

/**
 * Executa um SELECT ... INTO de forma segura para multi-empresa.
 *  - Se a tabela destino NAO existe: roda o SELECT INTO original (cria a tabela).
 *  - Se EXISTE e tem coluna CODEMP: cria uma temp com o SELECT INTO,
 *    apaga so as empresas da lista atual no destino (preserva as anteriores),
 *    e faz INSERT da temp para o destino. Dropa a temp ao final.
 *  - Se EXISTE e NAO tem CODEMP: pula (foi copiada antes, integral).
 */
function executarSelectInto($conn, string $sql, string $descricao, string $bdDestino, string $tabelaDestino, string $codempList = ''): bool
{
    if (!tabelaExiste($conn, $bdDestino, $tabelaDestino)) {
        // primeira vez: SELECT INTO direto
        return executarSQL($conn, $sql, $descricao);
    }

    // Tabela ja existe -> verificar se tem coluna CODEMP
    $cols = getTableColumns($conn, $bdDestino, $tabelaDestino);
    $temCodemp = in_array('CODEMP', $cols);

    if (!$temCodemp || $codempList === '') {
        logInfo("  -> $tabelaDestino: ja existe (sem CODEMP/integral); preservando dados.");
        return true;
    }

    // Estrategia de merge: SELECT INTO temp + DELETE empresas atuais + INSERT da temp + DROP temp
    $tempName = '_tmp_merge_' . substr(md5($tabelaDestino . microtime()), 0, 12);
    $sqlTemp = preg_replace(
        '/\bINTO\s+\S+\s+FROM\b/i',
        "INTO [$bdDestino].DBO.[$tempName] FROM",
        $sql,
        1,
        $count
    );
    if ($count === 0) {
        logError("Falha ao preparar merge multi-empresa em: $descricao");
        return false;
    }

    // Garantir que a temp nao existe de execucao anterior interrompida
    dropIfExists($conn, "[$bdDestino].DBO.[$tempName]");

    if (!executarSQL($conn, $sqlTemp, "$descricao (temp)")) return false;

    if (!executarSQL($conn, "DELETE FROM [$bdDestino].DBO.[$tabelaDestino] WHERE CODEMP IN ($codempList)",
                    "DELETE CODEMP IN ($codempList) de $tabelaDestino")) {
        dropIfExists($conn, "[$bdDestino].DBO.[$tempName]");
        return false;
    }

    if (!executarSQL($conn, "INSERT INTO [$bdDestino].DBO.[$tabelaDestino] SELECT * FROM [$bdDestino].DBO.[$tempName]",
                    "INSERT $tabelaDestino (merge multi-empresa)")) {
        dropIfExists($conn, "[$bdDestino].DBO.[$tempName]");
        return false;
    }

    dropIfExists($conn, "[$bdDestino].DBO.[$tempName]");
    return true;
}

function substituirPlaceholders(string $sql, string $orig, string $dest, string $codempList): string
{
    $sql = str_replace('{ORIG}', $orig, $sql);
    $sql = str_replace('{DEST}', $dest, $sql);
    $sql = str_replace('{CODEMP}', $codempList, $sql);
    return $sql;
}

function contarRegistros($conn, string $tabela): int
{
    $sql = "SELECT COUNT(*) AS total FROM $tabela";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) return -1;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ? (int)$row['total'] : -1;
}

// ===================== CALCULAR TOTAL DE STEPS =====================
$totalSteps = 2; // criar banco + criar CON_FIDC

if ($tipo === 'cliente') {
    $totalSteps += count(getPhase1_ReferenceTables());
    $totalSteps += count(getPhase1b_NoFilterTables());
    $totalSteps += count(getPhase2_ContractFilteredTables());
    $totalSteps += count(getPhase3_PostProcessContratos());
    $totalSteps += count(getPhase4_ImovelUnicoTables());
    $totalSteps += count(getPhase5_SE1_FichaSocioEconomica());
    $totalSteps += count(getPhase6_DEP_SE2());
} else {
    // SGH: sera calculado dinamicamente apos descobrir tabelas
    $totalSteps += 1; // placeholder para descoberta
}
$currentStep = 0;

// ===================== FASE 0: CRIAR BANCO E CON_FIDC =====================
if ($onlyFinalizers || $onlyExceptions) {
    // Em ambos os modos, banco e CON_FIDC ja existem. So validamos e lemos os codemps.
    $rotuloModo = $onlyExceptions ? 'REPROCESSAR EXCECOES' : 'RETOMADA';
    logInfo("========================================");
    logInfo("FASE 0: Modo $rotuloModo - validando ambiente existente");
    logInfo("========================================");

    $checkDB = sqlsrv_query($conn, "SELECT DB_ID('$bdDestino') AS dbid");
    $rowDB = sqlsrv_fetch_array($checkDB, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($checkDB);
    if (!$rowDB || $rowDB['dbid'] === null) {
        logError("Banco [$bdDestino] nao existe. Modo $rotuloModo exige um banco ja processado.");
        sendEvent('done', ['success' => false]);
        exit;
    }

    if ($onlyExceptions && !tabelaExiste($conn, $bdDestino, 'CON_FIDC')) {
        logError("Tabela CON_FIDC nao existe em [$bdDestino]. Modo REPROCESSAR EXCECOES exige uma extracao Cliente previa completa.");
        sendEvent('done', ['success' => false]);
        exit;
    }

    // Lista bruta de codemps presentes na CON_FIDC
    $codempsCon = [];
    $stmtCe = sqlsrv_query($conn, "SELECT DISTINCT CODEMP FROM [$bdDestino].DBO.CON_FIDC ORDER BY CODEMP");
    if ($stmtCe) {
        while ($r = sqlsrv_fetch_array($stmtCe, SQLSRV_FETCH_ASSOC)) {
            $codempsCon[] = (int)$r['CODEMP'];
        }
        sqlsrv_free_stmt($stmtCe);
    }

    if (empty($codempsCon)) {
        if ($onlyExceptions) {
            logError("CON_FIDC vazia em [$bdDestino]. Sem contratos, nao ha como reprocessar excecoes.");
            sendEvent('done', ['success' => false]);
            exit;
        }
        logWarn("CON_FIDC vazia ou inexistente; finalizadores nao usam codemp diretamente, mas alguns helpers podem precisar.");
        $codemps = [];
    } else {
        // Se o usuario informou CODEMP no formulario, restringe ao(s) informado(s)
        if ($onlyExceptions && !empty($codempsForm)) {
            $codemps = array_values(array_intersect($codempsForm, $codempsCon));
            $ignorados = array_values(array_diff($codempsForm, $codempsCon));
            if (empty($codemps)) {
                logError("Nenhuma das empresas informadas (" . implode(',', $codempsForm) . ") existe em CON_FIDC. Codemps presentes: " . implode(',', $codempsCon) . ".");
                sendEvent('done', ['success' => false]);
                exit;
            }
            if (!empty($ignorados)) {
                logWarn("Empresas informadas mas ausentes em CON_FIDC (ignoradas): " . implode(',', $ignorados));
            }
            logSuccess("Modo $rotuloModo: restringindo a CODEMP(s) do formulario: " . implode(',', $codemps));
        } else {
            $codemps = $codempsCon;
            logSuccess("Modo $rotuloModo: $bdDestino existe, codemps em CON_FIDC: " . implode(',', $codemps));
        }
        $codempList = implode(',', $codemps);
        $codempPrimeiro = $codemps[0];
    }
    $currentStep += 2; // pular os steps de "Criando CON_FIDC" e "CON_FIDC populada"
    sendProgress($currentStep, $totalSteps, "Modo $rotuloModo: ambiente validado");
} else {

logInfo("========================================");
logInfo("FASE 0: Criando banco de dados [$bdDestino]");
logInfo("========================================");

// Verificar se o banco ja existe
$checkDB = sqlsrv_query($conn, "SELECT DB_ID('$bdDestino') AS dbid");
$rowDB = sqlsrv_fetch_array($checkDB, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($checkDB);

if ($rowDB && $rowDB['dbid'] !== null) {
    if ($dropDestino) {
        logWarn("Banco [$bdDestino] ja existe. Opcao APAGAR E RECRIAR ativada.");
        logWarn("Excluindo banco [$bdDestino]... (pode levar varios minutos em bancos grandes)");
        $tDrop = microtime(true);

        // Fechar conexoes ativas e dropar o banco
        $sqlDrop = "
            ALTER DATABASE [$bdDestino] SET SINGLE_USER WITH ROLLBACK IMMEDIATE;
            DROP DATABASE [$bdDestino];
        ";
        if (!executarSQL($conn, $sqlDrop, "Excluir banco $bdDestino")) {
            logError("Falha ao excluir banco [$bdDestino]. Verifique se nao ha conexoes ativas.");
            sendEvent('done', ['success' => false]);
            exit;
        }
        $dt = microtime(true) - $tDrop;
        logSuccess("Banco [$bdDestino] excluido com sucesso (em " . number_format($dt, 1) . "s).");

        // Criar banco novo
        logInfo("Criando banco [$bdDestino]...");
        $tCreate = microtime(true);
        if (!executarSQL($conn, "CREATE DATABASE [$bdDestino]", "Criar banco $bdDestino")) {
            sendEvent('done', ['success' => false]);
            exit;
        }
        $dt = microtime(true) - $tCreate;
        logSuccess("Banco [$bdDestino] recriado (em " . number_format($dt, 1) . "s).");
    } else {
        logWarn("Banco [$bdDestino] ja existe. Sera reutilizado (tabelas serao sobrescritas).");
    }
} else {
    logInfo("Criando banco [$bdDestino]...");
    if (!executarSQL($conn, "CREATE DATABASE [$bdDestino]", "Criar banco $bdDestino")) {
        sendEvent('done', ['success' => false]);
        exit;
    }
    logSuccess("Banco [$bdDestino] criado.");
}

$currentStep++;
sendProgress($currentStep, $totalSteps, 'Criando CON_FIDC');

// Criar tabela CON_FIDC
logInfo("Criando tabela CON_FIDC e importando contratos...");

// Descobrir os tipos reais das colunas em MTTBCON da origem para criar CON_FIDC
// com tipos compativeis (evita conversao implicita VARCHAR vs SMALLINT/CHAR
// que pode quebrar EXISTS em tabelas como FCVSOCOR).
$sqlTipos = "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
             FROM [$bdOrigem].INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_NAME = 'MTTBCON'
               AND COLUMN_NAME IN ('CODEMP','REGIAO','NUCLEO','CONTRATO')";
$tiposCol = ['CODEMP' => 'INT', 'REGIAO' => 'SMALLINT', 'NUCLEO' => 'SMALLINT', 'CONTRATO' => 'CHAR(15)'];
$stmtT = sqlsrv_query($conn, $sqlTipos);
if ($stmtT) {
    while ($r = sqlsrv_fetch_array($stmtT, SQLSRV_FETCH_ASSOC)) {
        $name = strtoupper($r['COLUMN_NAME']);
        $dt   = strtoupper($r['DATA_TYPE']);
        $len  = $r['CHARACTER_MAXIMUM_LENGTH'];
        if (in_array($dt, ['CHAR','VARCHAR','NCHAR','NVARCHAR'])) {
            $tiposCol[$name] = "$dt(" . ($len > 0 ? $len : 'MAX') . ")";
        } else {
            $tiposCol[$name] = $dt;
        }
    }
    sqlsrv_free_stmt($stmtT);
}
logInfo("Tipos detectados em MTTBCON (origem): "
        . "CODEMP={$tiposCol['CODEMP']}, REGIAO={$tiposCol['REGIAO']}, "
        . "NUCLEO={$tiposCol['NUCLEO']}, CONTRATO={$tiposCol['CONTRATO']}");

$sqlCreateConFidc = "
    IF OBJECT_ID('$bdDestino.DBO.CON_FIDC', 'U') IS NOT NULL
        DROP TABLE [$bdDestino].DBO.CON_FIDC;
    CREATE TABLE [$bdDestino].DBO.CON_FIDC (
        CODEMP   {$tiposCol['CODEMP']},
        REGIAO   {$tiposCol['REGIAO']},
        NUCLEO   {$tiposCol['NUCLEO']},
        CONTRATO {$tiposCol['CONTRATO']},
        IMOVEL_UNICO INT NULL
    )
";
if (!executarSQL($conn, $sqlCreateConFidc, "Criar CON_FIDC")) {
    sendEvent('done', ['success' => false]);
    exit;
}

// Indice composto na CON_FIDC para acelerar EXISTS em tabelas grandes (FCVSOCOR etc.)
executarSQL($conn,
    "CREATE INDEX IX_CON_FIDC_KEY ON [$bdDestino].DBO.CON_FIDC (CODEMP, REGIAO, NUCLEO, CONTRATO)",
    "Criar indice em CON_FIDC");

// Inserir contratos em lotes
$batchSize = 500;
$batches = array_chunk($contratos, $batchSize);
$totalInseridos = 0;

// Saber se as colunas chave sao numericas (sem aspas) ou string (com aspas)
$contratoEhString = (stripos($tiposCol['CONTRATO'], 'CHAR') !== false);
$regiaoEhString   = (stripos($tiposCol['REGIAO'], 'CHAR')   !== false);
$nucleoEhString   = (stripos($tiposCol['NUCLEO'], 'CHAR')   !== false);

foreach ($batches as $batch) {
    $values = [];
    foreach ($batch as $c) {
        $codempLn = (int)$c['codemp'];
        $regiao   = str_replace("'", "''", $c['regiao']);
        $nucleo   = str_replace("'", "''", $c['nucleo']);
        $contrato = str_replace("'", "''", $c['contrato']);

        // REGIAO/NUCLEO: numericos na maioria dos SGH -> sem aspas
        $regSql = $regiaoEhString ? "'$regiao'" : "CAST('$regiao' AS {$tiposCol['REGIAO']})";
        $nucSql = $nucleoEhString ? "'$nucleo'" : "CAST('$nucleo' AS {$tiposCol['NUCLEO']})";
        // CONTRATO: deixa o SQL Server padar conforme tipo da coluna
        $ctrSql = "'$contrato'";

        $values[] = "($codempLn, $regSql, $nucSql, $ctrSql, NULL)";
    }
    $sqlInsert = "INSERT INTO [$bdDestino].DBO.CON_FIDC (CODEMP, REGIAO, NUCLEO, CONTRATO, IMOVEL_UNICO) VALUES " . implode(',', $values);
    if (!executarSQL($conn, $sqlInsert, "Inserir lote CON_FIDC")) {
        sendEvent('done', ['success' => false]);
        exit;
    }
    $totalInseridos += count($batch);
}

// Atualizar IMOVEL_UNICO a partir da tabela de contratos na origem
$sqlUpdImov = "UPDATE [$bdDestino].DBO.CON_FIDC 
               SET IMOVEL_UNICO = C.IMOVEL_UNICO 
               FROM [$bdOrigem].DBO.MTTBCON C 
               WHERE [$bdDestino].DBO.CON_FIDC.CODEMP = C.CODEMP 
                 AND [$bdDestino].DBO.CON_FIDC.REGIAO = C.REGIAO 
                 AND [$bdDestino].DBO.CON_FIDC.NUCLEO = C.NUCLEO 
                 AND [$bdDestino].DBO.CON_FIDC.CONTRATO = C.CONTRATO";
executarSQL($conn, $sqlUpdImov, "Atualizar IMOVEL_UNICO");

logSuccess("CON_FIDC criada com $totalInseridos contratos.");
$currentStep++;
sendProgress($currentStep, $totalSteps, 'CON_FIDC populada');

} // fim if (!$onlyFinalizers) - fase 0 (criar banco + CON_FIDC)

// ===================== BRANCHING: CLIENTE vs SGH =====================
if ($tipo === 'cliente') {

// ===================== FASE 1: TABELAS DE REFERENCIA =====================
logInfo("");
logInfo("========================================");
logInfo("FASE 1: Tabelas de Referencia (por CODEMP)");
logInfo("========================================");

$tabelasRef = getPhase1_ReferenceTables();
foreach ($tabelasRef as $tab) {
    $sql = substituirPlaceholders($tab['sql'], $bdOrigem, $bdDestino, $codempList);
    $ok = executarSelectInto($conn, $sql, $tab['nome'], $bdDestino, $tab['destino'], $codempList);
    if ($ok) {
        $count = contarRegistros($conn, "[$bdDestino].DBO.[{$tab['destino']}]");
        logSuccess("[OK] {$tab['nome']} - $count registros");
    }
    $currentStep++;
    sendProgress($currentStep, $totalSteps, 'Referencia: ' . $tab['nome']);
}

// Tabelas sem filtro nenhum
logInfo("");
logInfo("Tabelas sem filtro (copia integral):");

$tabelasNoFilter = getPhase1b_NoFilterTables();
foreach ($tabelasNoFilter as $tab) {
    $sql = substituirPlaceholders($tab['sql'], $bdOrigem, $bdDestino, $codempList);
    $ok = executarSelectInto($conn, $sql, $tab['nome'], $bdDestino, $tab['destino'], $codempList);
    if ($ok) {
        $count = contarRegistros($conn, "[$bdDestino].DBO.[{$tab['destino']}]");
        logSuccess("[OK] {$tab['nome']} - $count registros");
    }
    $currentStep++;
    sendProgress($currentStep, $totalSteps, 'Integral: ' . $tab['nome']);
}

// ===================== FASE 2: TABELAS FILTRADAS POR CONTRATO =====================
logInfo("");
logInfo("========================================");
logInfo("FASE 2: Tabelas Filtradas por Contrato (EXISTS)");
logInfo("========================================");

$tabelasContrato = getPhase2_ContractFilteredTables();
foreach ($tabelasContrato as $tab) {
    $sql = substituirPlaceholders($tab['sql'], $bdOrigem, $bdDestino, $codempList);
    $ok = executarSelectInto($conn, $sql, $tab['nome'], $bdDestino, $tab['destino'], $codempList);
    if ($ok) {
        $count = contarRegistros($conn, "[$bdDestino].DBO.[{$tab['destino']}]");
        logSuccess("[OK] {$tab['nome']} - $count registros");
    }
    $currentStep++;
    sendProgress($currentStep, $totalSteps, 'Contrato: ' . $tab['nome']);
}

// ===================== FASE 3: POS-PROCESSAMENTO CONTRATOS =====================
logInfo("");
logInfo("========================================");
logInfo("FASE 3: Pos-Processamento da Tabela Contratos");
logInfo("========================================");

$postProc = getPhase3_PostProcessContratos();
foreach ($postProc as $tab) {
    $sql = substituirPlaceholders($tab['sql'], $bdOrigem, $bdDestino, $codempList);
    $ok = executarSQL($conn, $sql, $tab['nome']);
    if ($ok) {
        logSuccess("[OK] {$tab['nome']}");
    }
    $currentStep++;
    sendProgress($currentStep, $totalSteps, 'Pos-Proc: ' . $tab['nome']);
}

// ===================== FASE 4: TABELAS POR IMOVEL_UNICO =====================
logInfo("");
logInfo("========================================");
logInfo("FASE 4: Tabelas por IMOVEL_UNICO");
logInfo("========================================");

$tabelasImovel = getPhase4_ImovelUnicoTables();
foreach ($tabelasImovel as $tab) {
    $sql = substituirPlaceholders($tab['sql'], $bdOrigem, $bdDestino, $codempList);
    $ok = executarSelectInto($conn, $sql, $tab['nome'], $bdDestino, $tab['destino'], $codempList);
    if ($ok) {
        $count = contarRegistros($conn, "[$bdDestino].DBO.[{$tab['destino']}]");
        logSuccess("[OK] {$tab['nome']} - $count registros");
    }
    $currentStep++;
    sendProgress($currentStep, $totalSteps, 'Imovel: ' . $tab['nome']);
}

// ===================== FASE 5: SE1 - FICHA SOCIOECONOMICA =====================
logInfo("");
logInfo("========================================");
logInfo("FASE 5: Ficha Socioeconomica (SE1) - Coleta de CPFs");
logInfo("========================================");

// Garantir temps limpas (evita falha em CREATE TABLE caso execucao anterior tenha sido interrompida)
dropIfExists($conn, "[$bdDestino].DBO._temp_cpf");
dropIfExists($conn, "[$bdDestino].DBO._temp_cpf_final");

$se1Steps = getPhase5_SE1_FichaSocioEconomica();
foreach ($se1Steps as $tab) {
    $sql = substituirPlaceholders($tab['sql'], $bdOrigem, $bdDestino, $codempList);
    // Se for SELECT ... INTO destino real (nao temp), usar wrapper inteligente
    if (preg_match('/\bSELECT\b.*?\bINTO\s+(\S+)\s+FROM\b/is', $sql, $m) && stripos($m[1], '_temp') === false) {
        // Extrair nome da tabela destino
        $destFull = trim($m[1], "[]");
        // Remove prefixos: pode ser bd.DBO.tabela ou [bd].DBO.[tabela]
        $partes = explode('.', str_replace(['[', ']'], '', $destFull));
        $destinoSoNome = end($partes);
        $ok = executarSelectInto($conn, $sql, $tab['nome'], $bdDestino, $destinoSoNome, $codempList);
    } else {
        $ok = executarSQL($conn, $sql, $tab['nome']);
    }
    if ($ok) {
        logSuccess("[OK] {$tab['nome']}");
    }
    $currentStep++;
    sendProgress($currentStep, $totalSteps, 'SE1: ' . $tab['nome']);
}

// Contar SE1
$countSE1 = contarRegistros($conn, "[$bdDestino].DBO.ficha_socio_economica");
if ($countSE1 >= 0) {
    logSuccess("Ficha Socioeconomica: $countSE1 registros.");
}

// ===================== FASE 6: DEP e SE2 =====================
logInfo("");
logInfo("========================================");
logInfo("FASE 6: Dependentes (DEP) e Inscricoes (SE2)");
logInfo("========================================");

dropIfExists($conn, "[$bdDestino].DBO._temp_cod_adq");
dropIfExists($conn, "[$bdDestino].DBO._temp_cod_adq_final");

$depSe2Steps = getPhase6_DEP_SE2();
foreach ($depSe2Steps as $tab) {
    $sql = substituirPlaceholders($tab['sql'], $bdOrigem, $bdDestino, $codempList);
    if (preg_match('/\bSELECT\b.*?\bINTO\s+(\S+)\s+FROM\b/is', $sql, $m) && stripos($m[1], '_temp') === false) {
        $destFull = trim($m[1], "[]");
        $partes = explode('.', str_replace(['[', ']'], '', $destFull));
        $destinoSoNome = end($partes);
        $ok = executarSelectInto($conn, $sql, $tab['nome'], $bdDestino, $destinoSoNome, $codempList);
    } else {
        $ok = executarSQL($conn, $sql, $tab['nome']);
    }
    if ($ok) {
        logSuccess("[OK] {$tab['nome']}");
    }
    $currentStep++;
    sendProgress($currentStep, $totalSteps, 'DEP/SE2: ' . $tab['nome']);
}

// Contar DEP e SE2
$countDEP = contarRegistros($conn, "[$bdDestino].DBO.DEPENDENTES_CLIENTE");
$countSE2 = contarRegistros($conn, "[$bdDestino].DBO.CADASTRO_INSCRICOES");
if ($countDEP >= 0) logSuccess("Dependentes: $countDEP registros.");
if ($countSE2 >= 0) logSuccess("Inscricoes: $countSE2 registros.");

} else {
// ===================== MODO SGH ELOGICA =====================
logInfo("");
logInfo("========================================");
logInfo("SGH ELOGICA: Processamento Dinamico");
logInfo("========================================");

// Funcao auxiliar: listar todas as tabelas de usuario do banco
function getAllUserTables($conn, string $banco): array
{
    $sql = "SELECT TABLE_NAME FROM [$banco].INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME";
    $stmt = sqlsrv_query($conn, $sql);
    $tabelas = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $tabelas[] = $row['TABLE_NAME'];
        }
        sqlsrv_free_stmt($stmt);
    }
    return $tabelas;
}

// Funcao auxiliar: listar colunas de uma tabela
function getTableColumns($conn, string $banco, string $tabela): array
{
    $sql = "SELECT COLUMN_NAME FROM [$banco].INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '$tabela'";
    $stmt = sqlsrv_query($conn, $sql);
    $cols = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cols[] = strtoupper($row['COLUMN_NAME']);
        }
        sqlsrv_free_stmt($stmt);
    }
    return $cols;
}

/**
 * Pre-computa temp de CPFs para filtrar mttbse1 no modo SGH.
 * Coleta CPFs de mttbcon (ADQ1-4, DATU_CGC_CPF, DATU_AD2-4) e mttbhis (AD1-4)
 * restritos aos contratos da CON_FIDC, depois normaliza para CHAR(14).
 */
function sghCriarTempCPF($conn, string $bdOrigem, string $bdDestino): bool
{
    // Limpar se sobrou de execucao anterior
    dropIfExists($conn, "[$bdDestino].DBO._sgh_temp_cpf");
    dropIfExists($conn, "[$bdDestino].DBO._sgh_temp_cpf_final");

    if (!executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_temp_cpf (cpf CHAR(14))", "SGH: criar _sgh_temp_cpf")) {
        return false;
    }

    $camposCon = ['ADQ1_CPFCGC', 'ADQ2_CPF', 'ADQ3_CPF', 'ADQ4_CPF', 'DATU_CGC_CPF', 'DATU_AD2_CPF', 'DATU_AD3_CPF', 'DATU_AD4_CPF'];
    $colsCon = getTableColumns($conn, $bdOrigem, 'mttbcon');
    $totalCon = count(array_intersect($camposCon, $colsCon));
    $i = 0;
    foreach ($camposCon as $campo) {
        if (!in_array($campo, $colsCon)) continue;
        $i++;
        logInfo("  CPF $i/$totalCon: coletando $campo de mttbcon...");
        $sql = "INSERT INTO [$bdDestino].DBO._sgh_temp_cpf
                SELECT DISTINCT c.[$campo] FROM [$bdOrigem].DBO.mttbcon c
                WHERE c.[$campo] IS NOT NULL
                  AND EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X
                              WHERE X.CODEMP = c.CODEMP AND X.REGIAO = c.REGIAO
                                AND X.NUCLEO = c.NUCLEO AND X.CONTRATO = c.CONTRATO)";
        executarSQL($conn, $sql, "SGH: coletar CPF $campo (mttbcon)");
    }

    $camposHis = ['AD1_CGCCPF', 'AD2_CPF', 'AD3_CPF', 'AD4_CPF'];
    $colsHis = getTableColumns($conn, $bdOrigem, 'mttbhis');
    if (!empty($colsHis)) {
        $totalHis = count(array_intersect($camposHis, $colsHis));
        $j = 0;
        foreach ($camposHis as $campo) {
            if (!in_array($campo, $colsHis)) continue;
            $j++;
            logInfo("  CPF $j/$totalHis: coletando $campo de mttbhis...");
            $sql = "INSERT INTO [$bdDestino].DBO._sgh_temp_cpf
                    SELECT DISTINCT h.[$campo] FROM [$bdOrigem].DBO.mttbhis h
                    WHERE h.[$campo] IS NOT NULL
                      AND EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X
                                  WHERE X.CODEMP = h.CODEMP AND X.REGIAO = h.REGIAO
                                    AND X.NUCLEO = h.NUCLEO AND X.CONTRATO = h.CONTRATO)";
            executarSQL($conn, $sql, "SGH: coletar CPF $campo (mttbhis)");
        }
    }

    // Normalizar para CHAR(14) com zeros a esquerda
    logInfo("  CPF: normalizando para CHAR(14)...");
    executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_temp_cpf_final (cpf CHAR(14))", "SGH: criar _sgh_temp_cpf_final");
    $sqlNorm = "INSERT INTO [$bdDestino].DBO._sgh_temp_cpf_final
                SELECT DISTINCT RIGHT('00000000000000' + LTRIM(RTRIM(t.cpf)), 14)
                FROM [$bdDestino].DBO._sgh_temp_cpf t
                WHERE t.cpf IS NOT NULL AND LTRIM(RTRIM(t.cpf)) <> ''";
    return executarSQL($conn, $sqlNorm, "SGH: normalizar CPFs");
}

/**
 * Pre-computa temp de COD_ADQ para filtrar mttbdep e mttbse2 no modo SGH.
 * No Cliente vem da CADASTRO_FINANCIAMENTO; aqui usamos mttbcon da origem
 * (COD_ADQ_PRIN, COD_COADQ1/2/3) filtrada via CON_FIDC.
 */
function sghCriarTempCodAdq($conn, string $bdOrigem, string $bdDestino): bool
{
    dropIfExists($conn, "[$bdDestino].DBO._sgh_temp_codadq");
    dropIfExists($conn, "[$bdDestino].DBO._sgh_temp_codadq_final");

    if (!executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_temp_codadq (codadq INT)", "SGH: criar _sgh_temp_codadq")) {
        return false;
    }

    $colsCon = getTableColumns($conn, $bdOrigem, 'mttbcon');
    $campos = ['COD_ADQ_PRIN', 'COD_COADQ1', 'COD_COADQ2', 'COD_COADQ3'];
    $total = count(array_intersect($campos, $colsCon));
    $i = 0;
    foreach ($campos as $campo) {
        if (!in_array($campo, $colsCon)) continue;
        $i++;
        logInfo("  COD_ADQ $i/$total: coletando $campo de mttbcon...");
        $sql = "INSERT INTO [$bdDestino].DBO._sgh_temp_codadq
                SELECT DISTINCT c.[$campo] FROM [$bdOrigem].DBO.mttbcon c
                WHERE c.[$campo] IS NOT NULL AND c.[$campo] <> 0
                  AND EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X
                              WHERE X.CODEMP = c.CODEMP AND X.REGIAO = c.REGIAO
                                AND X.NUCLEO = c.NUCLEO AND X.CONTRATO = c.CONTRATO)";
        executarSQL($conn, $sql, "SGH: coletar $campo (mttbcon)");
    }

    logInfo("  COD_ADQ: consolidando codigos distintos...");
    executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_temp_codadq_final (codadq INT)", "SGH: criar _sgh_temp_codadq_final");
    return executarSQL($conn, "INSERT INTO [$bdDestino].DBO._sgh_temp_codadq_final
                                SELECT DISTINCT t.codadq FROM [$bdDestino].DBO._sgh_temp_codadq t
                                WHERE t.codadq IS NOT NULL AND t.codadq <> 0", "SGH: distinct COD_ADQ");
}

/**
 * Variante de sghCriarTempCPF que restringe a coleta aos contratos
 * das empresas indicadas em $codempList. Usada pelo reprocessamento
 * de excecoes para evitar puxar CPFs de empresas que nao serao recriadas.
 */
function sghCriarTempCPFFiltradoPorCodemp($conn, string $bdOrigem, string $bdDestino, string $codempList): bool
{
    dropIfExists($conn, "[$bdDestino].DBO._sgh_temp_cpf");
    dropIfExists($conn, "[$bdDestino].DBO._sgh_temp_cpf_final");

    if (!executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_temp_cpf (cpf CHAR(14))", "SGH: criar _sgh_temp_cpf")) {
        return false;
    }

    $camposCon = ['ADQ1_CPFCGC', 'ADQ2_CPF', 'ADQ3_CPF', 'ADQ4_CPF', 'DATU_CGC_CPF', 'DATU_AD2_CPF', 'DATU_AD3_CPF', 'DATU_AD4_CPF'];
    $colsCon = getTableColumns($conn, $bdOrigem, 'mttbcon');
    $totalCon = count(array_intersect($camposCon, $colsCon));
    $i = 0;
    foreach ($camposCon as $campo) {
        if (!in_array($campo, $colsCon)) continue;
        $i++;
        logInfo("  CPF $i/$totalCon: coletando $campo de mttbcon (CODEMP IN ($codempList))...");
        $sql = "INSERT INTO [$bdDestino].DBO._sgh_temp_cpf
                SELECT DISTINCT c.[$campo] FROM [$bdOrigem].DBO.mttbcon c
                WHERE c.[$campo] IS NOT NULL
                  AND c.CODEMP IN ($codempList)
                  AND EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X
                              WHERE X.CODEMP IN ($codempList)
                                AND X.CODEMP = c.CODEMP AND X.REGIAO = c.REGIAO
                                AND X.NUCLEO = c.NUCLEO AND X.CONTRATO = c.CONTRATO)";
        executarSQL($conn, $sql, "SGH: coletar CPF $campo (mttbcon)");
    }

    $camposHis = ['AD1_CGCCPF', 'AD2_CPF', 'AD3_CPF', 'AD4_CPF'];
    $colsHis = getTableColumns($conn, $bdOrigem, 'mttbhis');
    if (!empty($colsHis)) {
        $totalHis = count(array_intersect($camposHis, $colsHis));
        $j = 0;
        foreach ($camposHis as $campo) {
            if (!in_array($campo, $colsHis)) continue;
            $j++;
            logInfo("  CPF $j/$totalHis: coletando $campo de mttbhis (CODEMP IN ($codempList))...");
            $sql = "INSERT INTO [$bdDestino].DBO._sgh_temp_cpf
                    SELECT DISTINCT h.[$campo] FROM [$bdOrigem].DBO.mttbhis h
                    WHERE h.[$campo] IS NOT NULL
                      AND h.CODEMP IN ($codempList)
                      AND EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X
                                  WHERE X.CODEMP IN ($codempList)
                                    AND X.CODEMP = h.CODEMP AND X.REGIAO = h.REGIAO
                                    AND X.NUCLEO = h.NUCLEO AND X.CONTRATO = h.CONTRATO)";
            executarSQL($conn, $sql, "SGH: coletar CPF $campo (mttbhis)");
        }
    }

    logInfo("  CPF: normalizando para CHAR(14)...");
    executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_temp_cpf_final (cpf CHAR(14))", "SGH: criar _sgh_temp_cpf_final");
    $sqlNorm = "INSERT INTO [$bdDestino].DBO._sgh_temp_cpf_final
                SELECT DISTINCT RIGHT('00000000000000' + LTRIM(RTRIM(t.cpf)), 14)
                FROM [$bdDestino].DBO._sgh_temp_cpf t
                WHERE t.cpf IS NOT NULL AND LTRIM(RTRIM(t.cpf)) <> ''";
    return executarSQL($conn, $sqlNorm, "SGH: normalizar CPFs");
}

/**
 * Reprocessa apenas as 3 tabelas de excecao (mttbse1, mttbdep, mttbse2)
 * no modo SGH, apagando e recriando-as no banco destino.
 * Resolve CLIENTE_UNIC via mttbse2.CGC_CPF -> CLIENTE_UNIC (sem depender
 * de COD_ADQ_PRIN/COD_COADQ* em mttbcon, que nem todo schema possui).
 * Pressupoe que [DEST].DBO.CON_FIDC ja existe e esta populada.
 */
function sghReprocessarExcecoes($conn, string $bdOrigem, string $bdDestino, string $codempList): bool
{
    logInfo("");
    logInfo("========================================");
    logInfo("SGH: REPROCESSAR EXCECOES - mttbse1 / mttbdep / mttbse2");
    logInfo("========================================");

    if ($codempList === '') {
        logError("codempList vazio: nao e possivel filtrar excecoes por empresa.");
        return false;
    }

    // 1) Apagar as 3 tabelas e quaisquer temps remanescentes
    logInfo("Apagando tabelas de excecao existentes (se houver)...");
    dropIfExists($conn, "[$bdDestino].DBO.mttbse1");
    dropIfExists($conn, "[$bdDestino].DBO.mttbdep");
    dropIfExists($conn, "[$bdDestino].DBO.mttbse2");
    dropIfExists($conn, "[$bdDestino].DBO._sgh_temp_cpf");
    dropIfExists($conn, "[$bdDestino].DBO._sgh_temp_cpf_final");
    dropIfExists($conn, "[$bdDestino].DBO._sgh_temp_cliente_unic");

    // 2) Coletar CPFs filtrados pelos contratos das empresas-alvo
    if (!sghCriarTempCPFFiltradoPorCodemp($conn, $bdOrigem, $bdDestino, $codempList)) {
        return false;
    }
    $cntCpf = contarRegistros($conn, "[$bdDestino].DBO._sgh_temp_cpf_final");
    logSuccess("CPFs distintos coletados (apenas CODEMP IN ($codempList)): $cntCpf");

    // 3) Resolver CLIENTE_UNIC via mttbse2 (CGC_CPF + CLIENTE_UNIC), restrito a CODEMP alvo
    if (!executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_temp_cliente_unic (cliente_unic BIGINT)",
                     "SGH: criar _sgh_temp_cliente_unic")) {
        return false;
    }
    $sqlResolve = "INSERT INTO [$bdDestino].DBO._sgh_temp_cliente_unic
                   SELECT DISTINCT s.CLIENTE_UNIC
                     FROM [$bdOrigem].DBO.mttbse2 s
                    INNER JOIN [$bdDestino].DBO._sgh_temp_cpf_final t ON t.cpf = s.CGC_CPF
                    WHERE s.CODEMP IN ($codempList)
                      AND s.CLIENTE_UNIC IS NOT NULL AND s.CLIENTE_UNIC <> 0";
    if (!executarSQL($conn, $sqlResolve, "SGH: resolver CLIENTE_UNIC via mttbse2")) return false;
    $cntCli = contarRegistros($conn, "[$bdDestino].DBO._sgh_temp_cliente_unic");
    logSuccess("CLIENTE_UNIC resolvidos: $cntCli");

    // 4) mttbse1 (link por CPF)
    $sqlSE1 = "SELECT s.* INTO [$bdDestino].DBO.mttbse1
                 FROM [$bdOrigem].DBO.mttbse1 s
                INNER JOIN [$bdDestino].DBO._sgh_temp_cpf_final t ON t.cpf = s.CGCCPF
                WHERE s.CODEMP IN ($codempList)";
    if (!executarSQL($conn, $sqlSE1, "SGH: SELECT INTO mttbse1 (por CPF)")) return false;
    $cSE1 = contarRegistros($conn, "[$bdDestino].DBO.mttbse1");
    logSuccess("[OK] mttbse1 - $cSE1 registros");

    // 5) mttbdep (link por CLIENTE_UNIC)
    $sqlDEP = "SELECT s.* INTO [$bdDestino].DBO.mttbdep
                 FROM [$bdOrigem].DBO.mttbdep s
                INNER JOIN [$bdDestino].DBO._sgh_temp_cliente_unic t ON t.cliente_unic = s.CLIENTE_UNIC
                WHERE s.CODEMP IN ($codempList)";
    if (!executarSQL($conn, $sqlDEP, "SGH: SELECT INTO mttbdep (por CLIENTE_UNIC)")) return false;
    $cDEP = contarRegistros($conn, "[$bdDestino].DBO.mttbdep");
    logSuccess("[OK] mttbdep - $cDEP registros");

    // 6) mttbse2 (link por CLIENTE_UNIC)
    $sqlSE2 = "SELECT s.* INTO [$bdDestino].DBO.mttbse2
                 FROM [$bdOrigem].DBO.mttbse2 s
                INNER JOIN [$bdDestino].DBO._sgh_temp_cliente_unic t ON t.cliente_unic = s.CLIENTE_UNIC
                WHERE s.CODEMP IN ($codempList)";
    if (!executarSQL($conn, $sqlSE2, "SGH: SELECT INTO mttbse2 (por CLIENTE_UNIC)")) return false;
    $cSE2 = contarRegistros($conn, "[$bdDestino].DBO.mttbse2");
    logSuccess("[OK] mttbse2 - $cSE2 registros");

    // 7) Recriar indices nas 3 tabelas (SELECT INTO nao copia indices)
    logInfo("Recriando indices das 3 tabelas...");
    sghRecriarIndicesDeTabelas($conn, $bdOrigem, $bdDestino, ['mttbse1', 'mttbdep', 'mttbse2']);

    // 8) DIAGNOSTICO: manter as temps no banco destino para inspecao
    //    (em vez de dropar). Permite consultar:
    //      SELECT * FROM [DEST].DBO._sgh_temp_cpf;          -- antes da normalizacao
    //      SELECT * FROM [DEST].DBO._sgh_temp_cpf_final;    -- apos normalizacao
    //      SELECT * FROM [DEST].DBO._sgh_temp_cliente_unic; -- resolvidos via mttbse2
    $cntCpfBruto = contarRegistros($conn, "[$bdDestino].DBO._sgh_temp_cpf");
    $cntCpfDistBruto = contarRegistros($conn, "(SELECT DISTINCT cpf FROM [$bdDestino].DBO._sgh_temp_cpf) X");
    logInfo("Diagnostico _sgh_temp_cpf: $cntCpfBruto linhas totais, $cntCpfDistBruto CPFs distintos (pre-normalizacao).");
    logWarn("Temps _sgh_temp_cpf, _sgh_temp_cpf_final e _sgh_temp_cliente_unic mantidas no destino para inspecao.");

    return true;
}

/**
 * Recria os indices nao-clusterizados (e nao-PK/UQ) de uma lista de tabelas,
 * lendo a definicao da sys.indexes do banco origem. Usado pelo reprocessamento
 * de excecoes apos SELECT INTO (que nao herda indices).
 */
function sghRecriarIndicesDeTabelas($conn, string $bdOrigem, string $bdDestino, array $tabelas): void
{
    if (empty($tabelas)) return;
    $inList = "'" . implode("','", array_map(fn($t) => strtolower($t), $tabelas)) . "'";

    $sqlIdx = "
    SELECT
        t.name AS tabela,
        i.name AS idx_nome,
        i.is_unique,
        i.type_desc,
        i.filter_definition,
        STUFF((
            SELECT ',[' + c.name + ']' + CASE WHEN ic.is_descending_key = 1 THEN ' DESC' ELSE '' END
            FROM [$bdOrigem].sys.index_columns ic
            INNER JOIN [$bdOrigem].sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
            WHERE ic.object_id = i.object_id AND ic.index_id = i.index_id AND ic.is_included_column = 0
            ORDER BY ic.key_ordinal
            FOR XML PATH('')
        ), 1, 1, '') AS colunas_chave,
        STUFF((
            SELECT ',[' + c.name + ']'
            FROM [$bdOrigem].sys.index_columns ic
            INNER JOIN [$bdOrigem].sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
            WHERE ic.object_id = i.object_id AND ic.index_id = i.index_id AND ic.is_included_column = 1
            ORDER BY ic.index_column_id
            FOR XML PATH('')
        ), 1, 1, '') AS colunas_include
    FROM [$bdOrigem].sys.indexes i
    INNER JOIN [$bdOrigem].sys.tables t ON i.object_id = t.object_id
    WHERE i.type IN (1,2)
      AND i.is_primary_key = 0
      AND i.is_unique_constraint = 0
      AND i.name IS NOT NULL
      AND LOWER(t.name) IN ($inList)
    ORDER BY t.name, i.name
    ";
    $stmt = sqlsrv_query($conn, $sqlIdx);
    if (!$stmt) {
        logWarn("Nao foi possivel listar indices da origem para as tabelas alvo.");
        return;
    }
    $idxOk = 0;
    $idxErro = 0;
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $tab = $row['tabela'];
        $nomeIdx = $row['idx_nome'];
        if (empty($row['colunas_chave'])) continue;
        $unique = $row['is_unique'] ? 'UNIQUE ' : '';
        $clustered = (strpos($row['type_desc'], 'CLUSTERED') !== false && strpos($row['type_desc'], 'NONCLUSTERED') === false) ? 'CLUSTERED ' : 'NONCLUSTERED ';
        $colsKey = $row['colunas_chave'];
        $colsInc = $row['colunas_include'] ?? '';
        $filterDef = $row['filter_definition'] ?? '';
        $includeClause = !empty($colsInc) ? " INCLUDE ($colsInc)" : '';
        $whereClause   = !empty($filterDef) ? " WHERE $filterDef" : '';

        $sqlCreate = "IF NOT EXISTS (SELECT 1 FROM [$bdDestino].sys.indexes
                                     WHERE name = '$nomeIdx'
                                       AND object_id = OBJECT_ID('[$bdDestino].DBO.[$tab]'))
                      CREATE {$unique}{$clustered}INDEX [$nomeIdx] ON [$bdDestino].DBO.[$tab] ($colsKey){$includeClause}{$whereClause}";

        $stmtCreate = sqlsrv_query($conn, $sqlCreate, [], ['QueryTimeout' => 0]);
        if ($stmtCreate === false) {
            $errs = sqlsrv_errors();
            $msg = $errs ? trim($errs[0]['message']) : 'erro desconhecido';
            $msg = preg_replace('/^\[[^\]]+\]\s*\[[^\]]+\]\s*\[[^\]]+\]\s*/', '', $msg);
            logWarn("  [FALHA] Indice $nomeIdx em $tab: $msg");
            $idxErro++;
        } else {
            while (sqlsrv_next_result($stmtCreate)) {}
            sqlsrv_free_stmt($stmtCreate);
            logInfo("  [OK] Indice $nomeIdx em $tab");
            $idxOk++;
        }
    }
    sqlsrv_free_stmt($stmt);
    logSuccess("Indices recriados: $idxOk OK / $idxErro falhas");
}

/**
 * Processa mttbse1 no modo SGH: filtra por CGCCPF relacionado aos contratos.
 */
function sghProcessarSE1($conn, string $bdOrigem, string $bdDestino, string $codempList, bool $tabelaExiste): bool
{
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.mttbse1 WHERE CODEMP IN ($codempList)", "SGH: DELETE mttbse1 CODEMP IN ($codempList)");
        $sql = "INSERT INTO [$bdDestino].DBO.mttbse1
                SELECT s.* FROM [$bdOrigem].DBO.mttbse1 s
                INNER JOIN [$bdDestino].DBO._sgh_temp_cpf_final t ON t.cpf = s.CGCCPF
                WHERE s.CODEMP IN ($codempList)";
        return executarSQL($conn, $sql, "SGH: INSERT mttbse1 (por CPF)");
    } else {
        $sql = "SELECT s.* INTO [$bdDestino].DBO.mttbse1
                FROM [$bdOrigem].DBO.mttbse1 s
                INNER JOIN [$bdDestino].DBO._sgh_temp_cpf_final t ON t.cpf = s.CGCCPF
                WHERE s.CODEMP IN ($codempList)";
        return executarSQL($conn, $sql, "SGH: SELECT INTO mttbse1 (por CPF)");
    }
}

/**
 * Processa mttbdep no modo SGH: filtra por CLIENTE_UNIC relacionado aos codigos adquirentes.
 */
function sghProcessarDEP($conn, string $bdOrigem, string $bdDestino, string $codempList, bool $tabelaExiste): bool
{
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.mttbdep WHERE CODEMP IN ($codempList)", "SGH: DELETE mttbdep CODEMP IN ($codempList)");
        $sql = "INSERT INTO [$bdDestino].DBO.mttbdep
                SELECT s.* FROM [$bdOrigem].DBO.mttbdep s
                INNER JOIN [$bdDestino].DBO._sgh_temp_codadq_final t ON t.codadq = s.cliente_unic
                WHERE s.CODEMP IN ($codempList) AND t.codadq <> 0";
        return executarSQL($conn, $sql, "SGH: INSERT mttbdep (por COD_ADQ)");
    } else {
        $sql = "SELECT s.* INTO [$bdDestino].DBO.mttbdep
                FROM [$bdOrigem].DBO.mttbdep s
                INNER JOIN [$bdDestino].DBO._sgh_temp_codadq_final t ON t.codadq = s.cliente_unic
                WHERE s.CODEMP IN ($codempList) AND t.codadq <> 0";
        return executarSQL($conn, $sql, "SGH: SELECT INTO mttbdep (por COD_ADQ)");
    }
}

/**
 * Processa mttbse2 no modo SGH: filtra por CLIENTE_UNIC relacionado aos codigos adquirentes.
 */
function sghProcessarSE2($conn, string $bdOrigem, string $bdDestino, string $codempList, bool $tabelaExiste): bool
{
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.mttbse2 WHERE CODEMP IN ($codempList)", "SGH: DELETE mttbse2 CODEMP IN ($codempList)");
        $sql = "INSERT INTO [$bdDestino].DBO.mttbse2
                SELECT s.* FROM [$bdOrigem].DBO.mttbse2 s
                INNER JOIN [$bdDestino].DBO._sgh_temp_codadq_final t ON t.codadq = s.cliente_unic
                WHERE s.CODEMP IN ($codempList)";
        return executarSQL($conn, $sql, "SGH: INSERT mttbse2 (por COD_ADQ)");
    } else {
        $sql = "SELECT s.* INTO [$bdDestino].DBO.mttbse2
                FROM [$bdOrigem].DBO.mttbse2 s
                INNER JOIN [$bdDestino].DBO._sgh_temp_codadq_final t ON t.codadq = s.cliente_unic
                WHERE s.CODEMP IN ($codempList)";
        return executarSQL($conn, $sql, "SGH: SELECT INTO mttbse2 (por COD_ADQ)");
    }
}

/**
 * Processa mttbjur (Processos Juridicos) no modo SGH.
 * No Cliente, o filtro usa as colunas REGIAO_PRI/NUCLEO_PRI/CONTRATO_PRI
 * (sufixo _PRI = contrato principal do processo). A regra generica do
 * SGH faz match com REGIAO/NUCLEO/CONTRATO sem sufixo, levando dados errados.
 */
function sghProcessarJUR($conn, string $bdOrigem, string $bdDestino, string $codempList, bool $tabelaExiste): bool
{
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.mttbjur WHERE CODEMP IN ($codempList)", "SGH: DELETE mttbjur CODEMP IN ($codempList)");
        $sql = "INSERT INTO [$bdDestino].DBO.mttbjur
                SELECT Y.* FROM [$bdOrigem].DBO.mttbjur Y
                WHERE Y.CODEMP IN ($codempList)
                  AND EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X
                              WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO_PRI
                                AND X.NUCLEO = Y.NUCLEO_PRI AND X.CONTRATO = Y.CONTRATO_PRI)";
        return executarSQL($conn, $sql, "SGH: INSERT mttbjur (por _PRI)");
    } else {
        $sql = "SELECT Y.* INTO [$bdDestino].DBO.mttbjur
                FROM [$bdOrigem].DBO.mttbjur Y
                WHERE Y.CODEMP IN ($codempList)
                  AND EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X
                              WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO_PRI
                                AND X.NUCLEO = Y.NUCLEO_PRI AND X.CONTRATO = Y.CONTRATO_PRI)";
        return executarSQL($conn, $sql, "SGH: SELECT INTO mttbjur (por _PRI)");
    }
}

/**
 * Processa mttbimc/mttbimv (Caracteristicas Imoveis / Cadastro Imoveis 2) no modo SGH.
 * No Cliente, o filtro usa IMOVEL_UNICO. CON_FIDC tem essa coluna populada na
 * fase inicial (UPDATE FROM MTTBCON), entao basta um EXISTS.
 */
function sghProcessarPorImovel($conn, string $bdOrigem, string $bdDestino, string $codempList, string $tabela, bool $tabelaExiste): bool
{
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.[$tabela] WHERE CODEMP IN ($codempList)", "SGH: DELETE $tabela CODEMP IN ($codempList)");
        $sql = "INSERT INTO [$bdDestino].DBO.[$tabela]
                SELECT Y.* FROM [$bdOrigem].DBO.[$tabela] Y
                WHERE Y.CODEMP IN ($codempList)
                  AND EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X
                              WHERE X.CODEMP = Y.CODEMP AND X.IMOVEL_UNICO = Y.IMOVEL_UNICO)";
        return executarSQL($conn, $sql, "SGH: INSERT $tabela (por IMOVEL_UNICO)");
    } else {
        $sql = "SELECT Y.* INTO [$bdDestino].DBO.[$tabela]
                FROM [$bdOrigem].DBO.[$tabela] Y
                WHERE Y.CODEMP IN ($codempList)
                  AND EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X
                              WHERE X.CODEMP = Y.CODEMP AND X.IMOVEL_UNICO = Y.IMOVEL_UNICO)";
        return executarSQL($conn, $sql, "SGH: SELECT INTO $tabela (por IMOVEL_UNICO)");
    }
}

// =====================================================================
// ============= MODULO DOSSIE/RECURSO (SGH apenas) ====================
// =====================================================================
// Conjunto de tabelas MTTBxxx que se relacionam por chaves artificiais
// (codigo INT IDENTITY) e nao por REGIAO/NUCLEO/CONTRATO. Filtragem feita
// em ondas via temps populadas a partir da CON_FIDC e MTTBCON.cadmut.

/**
 * Cria a temp _sgh_dr_cadmut com os cadmuts (TRIM aplicado) dos contratos
 * presentes em CON_FIDC. Esta e a base de varios filtros do modulo.
 * Retorna true se sucesso, false se falhou.
 */
function drCriarTempCadmut($conn, string $bdOrigem, string $bdDestino): bool
{
    dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_cadmut");

    // Detectar o nome real da coluna cadmut em MTTBCON (variantes possiveis)
    $colsCon = getTableColumns($conn, $bdOrigem, 'MTTBCON');
    $colCadmut = null;
    foreach (['CADMUT', 'CAD_MUT', 'NUMCADMUT', 'NUM_CADMUT', 'CADASTRO_MUT', 'NUM_CAD_MUT', 'NUMERO_FIF'] as $cand) {
        if (in_array($cand, $colsCon)) {
            $colCadmut = $cand;
            break;
        }
    }
    if ($colCadmut === null) {
        $similares = array_filter($colsCon, function($c) {
            return stripos($c, 'CAD') !== false || stripos($c, 'MUT') !== false;
        });
        logError("DR: nenhuma coluna conhecida (CADMUT/CAD_MUT/...) encontrada em MTTBCON.");
        if (!empty($similares)) {
            logError("  Colunas similares em MTTBCON: " . implode(', ', $similares));
        } else {
            logError("  Total de colunas em MTTBCON: " . count($colsCon));
        }
        return false;
    }
    if ($colCadmut !== 'CADMUT') {
        logInfo("  DR: usando coluna [$colCadmut] de MTTBCON (em vez do CADMUT padrao).");
    }

    if (!executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_dr_cadmut (cadmut VARCHAR(13))", "DR: criar _sgh_dr_cadmut")) {
        return false;
    }
    $sql = "INSERT INTO [$bdDestino].DBO._sgh_dr_cadmut
            SELECT DISTINCT LTRIM(RTRIM(c.[$colCadmut]))
            FROM [$bdOrigem].DBO.MTTBCON c
            WHERE c.[$colCadmut] IS NOT NULL AND LTRIM(RTRIM(c.[$colCadmut])) <> ''
              AND EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X
                          WHERE X.CODEMP = c.CODEMP AND X.REGIAO = c.REGIAO
                            AND X.NUCLEO = c.NUCLEO AND X.CONTRATO = c.CONTRATO)";
    if (!executarSQL($conn, $sql, "DR: popular cadmuts (de MTTBCON via CON_FIDC)")) return false;
    executarSQL($conn, "CREATE INDEX IX_dr_cadmut ON [$bdDestino].DBO._sgh_dr_cadmut (cadmut)", "DR: indice em _sgh_dr_cadmut");
    return true;
}

/**
 * Cria a temp _sgh_dr_idict com os Id_ict relevantes da MTTBICT
 * (a ICT tem CODEMP/REGIAO/NUCLEO/CONTRATO).
 */
function drCriarTempIdict($conn, string $bdOrigem, string $bdDestino): bool
{
    dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_idict");
    if (!executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_dr_idict (Id_ict INT)", "DR: criar _sgh_dr_idict")) {
        return false;
    }
    $sql = "INSERT INTO [$bdDestino].DBO._sgh_dr_idict
            SELECT DISTINCT i.Id_ict
            FROM [$bdOrigem].DBO.MTTBICT i
            WHERE EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X
                          WHERE X.CODEMP = i.CODEMP AND X.REGIAO = i.REGIAO
                            AND X.NUCLEO = i.NUCLEO AND X.CONTRATO = i.CONTRATO)";
    if (!executarSQL($conn, $sql, "DR: popular Id_ict (de MTTBICT via CON_FIDC)")) return false;
    executarSQL($conn, "CREATE INDEX IX_dr_idict ON [$bdDestino].DBO._sgh_dr_idict (Id_ict)", "DR: indice em _sgh_dr_idict");
    return true;
}

/**
 * Cria temp _sgh_dr_codcdec (codigos de MTTBCDEC filtrados por cadmut).
 * Depende de _sgh_dr_cadmut.
 */
function drCriarTempCodCdec($conn, string $bdOrigem, string $bdDestino): bool
{
    dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_codcdec");
    if (!executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_dr_codcdec (codigo INT)", "DR: criar _sgh_dr_codcdec")) {
        return false;
    }
    $sql = "INSERT INTO [$bdDestino].DBO._sgh_dr_codcdec
            SELECT DISTINCT s.codigo
            FROM [$bdOrigem].DBO.MTTBCDEC s
            INNER JOIN [$bdDestino].DBO._sgh_dr_cadmut t ON t.cadmut = LTRIM(RTRIM(s.cadmut))";
    if (!executarSQL($conn, $sql, "DR: popular codigos de CDEC (por cadmut)")) return false;
    executarSQL($conn, "CREATE INDEX IX_dr_codcdec ON [$bdDestino].DBO._sgh_dr_codcdec (codigo)", "DR: indice em _sgh_dr_codcdec");
    return true;
}

/**
 * Cria temp _sgh_dr_codcoic (codigos de MTTBCOIC filtrados por cadmut).
 * Depende de _sgh_dr_cadmut.
 */
function drCriarTempCodCoic($conn, string $bdOrigem, string $bdDestino): bool
{
    dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_codcoic");
    if (!executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_dr_codcoic (codigo INT)", "DR: criar _sgh_dr_codcoic")) {
        return false;
    }
    $sql = "INSERT INTO [$bdDestino].DBO._sgh_dr_codcoic
            SELECT DISTINCT s.codigo
            FROM [$bdOrigem].DBO.MTTBCOIC s
            INNER JOIN [$bdDestino].DBO._sgh_dr_cadmut t ON t.cadmut = LTRIM(RTRIM(s.cadmut))";
    if (!executarSQL($conn, $sql, "DR: popular codigos de COIC (por cadmut)")) return false;
    executarSQL($conn, "CREATE INDEX IX_dr_codcoic ON [$bdDestino].DBO._sgh_dr_codcoic (codigo)", "DR: indice em _sgh_dr_codcoic");
    return true;
}

/**
 * Cria temp _sgh_dr_mrrrelev (codigos MRR alcancaveis via LMRN -> COIC filtrada).
 * MTTBCMRR continua indo integral (parametro), mas L* que dependem dela usam essa lista.
 */
function drCriarTempMrrRelev($conn, string $bdOrigem, string $bdDestino): bool
{
    dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_mrrrelev");
    if (!executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_dr_mrrrelev (codigo INT)", "DR: criar _sgh_dr_mrrrelev")) {
        return false;
    }
    $sql = "INSERT INTO [$bdDestino].DBO._sgh_dr_mrrrelev
            SELECT DISTINCT lm.fk_codigo_mrr
            FROM [$bdOrigem].DBO.MTTBLMRN lm
            INNER JOIN [$bdDestino].DBO._sgh_dr_codcoic c ON c.codigo = lm.fk_codigo_oic";
    if (!executarSQL($conn, $sql, "DR: popular MRR relevantes (via LMRN -> COIC)")) return false;
    executarSQL($conn, "CREATE INDEX IX_dr_mrrrelev ON [$bdDestino].DBO._sgh_dr_mrrrelev (codigo)", "DR: indice em _sgh_dr_mrrrelev");
    return true;
}

/**
 * Cria temp _sgh_dr_codcdoc (uniao dos doc_ids referenciados em LDMR e LDIG filtrados).
 * Depende de _sgh_dr_mrrrelev e _sgh_dr_idict.
 */
function drCriarTempCodCdoc($conn, string $bdOrigem, string $bdDestino): bool
{
    dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_codcdoc_raw");
    dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_codcdoc");
    if (!executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_dr_codcdoc_raw (codigo INT)", "DR: criar _sgh_dr_codcdoc_raw")) {
        return false;
    }
    // Via LDMR (cadeia MRR -> COIC -> cadmut)
    executarSQL($conn,
        "INSERT INTO [$bdDestino].DBO._sgh_dr_codcdoc_raw
         SELECT DISTINCT ldmr.fk_codigo_doc
         FROM [$bdOrigem].DBO.MTTBLDMR ldmr
         INNER JOIN [$bdDestino].DBO._sgh_dr_mrrrelev m ON m.codigo = ldmr.fk_codigo_mrr
         WHERE ldmr.fk_codigo_doc IS NOT NULL",
        "DR: popular codigos de DOC via LDMR");
    // Via LDIG (cadeia ICT -> contrato)
    executarSQL($conn,
        "INSERT INTO [$bdDestino].DBO._sgh_dr_codcdoc_raw
         SELECT DISTINCT ldig.fk_codigo_doc
         FROM [$bdOrigem].DBO.MTTBLDIG ldig
         INNER JOIN [$bdDestino].DBO._sgh_dr_idict i ON i.Id_ict = ldig.fk_id_ict
         WHERE ldig.fk_codigo_doc IS NOT NULL",
        "DR: popular codigos de DOC via LDIG");
    // Consolidar distinct em tabela final
    executarSQL($conn,
        "SELECT DISTINCT codigo INTO [$bdDestino].DBO._sgh_dr_codcdoc
         FROM [$bdDestino].DBO._sgh_dr_codcdoc_raw",
        "DR: consolidar dedup de DOC");
    dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_codcdoc_raw");
    executarSQL($conn, "CREATE INDEX IX_dr_codcdoc ON [$bdDestino].DBO._sgh_dr_codcdoc (codigo)", "DR: indice em _sgh_dr_codcdoc");
    return true;
}

/**
 * Cria temp _sgh_dr_seg (CODEMP, CodUsr de MTTBSEG filtrada por codempList).
 */
function drCriarTempSeg($conn, string $bdOrigem, string $bdDestino, string $codempList): bool
{
    dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_seg");
    if (!executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_dr_seg (codemp SMALLINT, CodUsr CHAR(8))", "DR: criar _sgh_dr_seg")) {
        return false;
    }
    $sql = "INSERT INTO [$bdDestino].DBO._sgh_dr_seg
            SELECT DISTINCT s.codemp, s.CodUsr
            FROM [$bdOrigem].DBO.MTTBSEG s
            WHERE s.codemp IN ($codempList)";
    if (!executarSQL($conn, $sql, "DR: popular SEG por codemp")) return false;
    executarSQL($conn, "CREATE INDEX IX_dr_seg ON [$bdDestino].DBO._sgh_dr_seg (codemp, CodUsr)", "DR: indice em _sgh_dr_seg");
    return true;
}

/**
 * Cria temp _sgh_dr_codura (codigo de MTTBLURA filtrada via SEG).
 * Depende de _sgh_dr_seg.
 */
function drCriarTempCodUra($conn, string $bdOrigem, string $bdDestino): bool
{
    dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_codura");
    if (!executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_dr_codura (codigo INT)", "DR: criar _sgh_dr_codura")) {
        return false;
    }
    $sql = "INSERT INTO [$bdDestino].DBO._sgh_dr_codura
            SELECT DISTINCT u.codigo
            FROM [$bdOrigem].DBO.MTTBLURA u
            INNER JOIN [$bdDestino].DBO._sgh_dr_seg s ON s.codemp = u.fk_codemp_seg AND s.CodUsr = u.fk_codusr_seg";
    if (!executarSQL($conn, $sql, "DR: popular codigos de LURA via SEG")) return false;
    executarSQL($conn, "CREATE INDEX IX_dr_codura ON [$bdDestino].DBO._sgh_dr_codura (codigo)", "DR: indice em _sgh_dr_codura");
    return true;
}

/**
 * Cria temp _sgh_dr_emplig02 com os codigos de empresa "parceira FCVS"
 * obtidos via MTTBEMP.EMPLIG02 das empresas em codempList.
 * Usada para tabelas tipo MFCVS que registram movimento na empresa parceira.
 */
function drCriarTempEmplig02($conn, string $bdOrigem, string $bdDestino, string $codempList): bool
{
    dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_emplig02");
    if (!executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_dr_emplig02 (codemp_fcvs SMALLINT)", "DR: criar _sgh_dr_emplig02")) {
        return false;
    }
    $sql = "INSERT INTO [$bdDestino].DBO._sgh_dr_emplig02
            SELECT DISTINCT e.EMPLIG02
            FROM [$bdOrigem].DBO.MTTBEMP e
            WHERE e.codemp IN ($codempList)
              AND e.EMPLIG02 IS NOT NULL
              AND e.EMPLIG02 <> 0";
    if (!executarSQL($conn, $sql, "DR: popular EMPLIG02 (empresas parceiras FCVS)")) return false;
    executarSQL($conn, "CREATE INDEX IX_dr_emplig02 ON [$bdDestino].DBO._sgh_dr_emplig02 (codemp_fcvs)", "DR: indice em _sgh_dr_emplig02");
    return true;
}

/**
 * MTTBMFCVS: filtro por (CODEMP em EMPLIG02 das empresas) AND (cadmut em _sgh_dr_cadmut).
 */
function drProcessarMFCVS($conn, string $bdOrigem, string $bdDestino, bool $tabelaExiste): bool
{
    if ($tabelaExiste) {
        // Apaga somente registros das empresas-parceiras que vamos reinserir
        executarSQL($conn,
            "DELETE FROM [$bdDestino].DBO.MTTBMFCVS
             WHERE codemp IN (SELECT codemp_fcvs FROM [$bdDestino].DBO._sgh_dr_emplig02)",
            "DR: DELETE MTTBMFCVS (empresas EMPLIG02)");
        $sql = "INSERT INTO [$bdDestino].DBO.MTTBMFCVS
                SELECT s.* FROM [$bdOrigem].DBO.MTTBMFCVS s
                INNER JOIN [$bdDestino].DBO._sgh_dr_emplig02 e ON e.codemp_fcvs = s.codemp
                INNER JOIN [$bdDestino].DBO._sgh_dr_cadmut c ON c.cadmut = LTRIM(RTRIM(s.cadmut))";
        return executarSQL($conn, $sql, "DR: INSERT MTTBMFCVS (EMPLIG02 + cadmut)");
    } else {
        $sql = "SELECT s.* INTO [$bdDestino].DBO.MTTBMFCVS
                FROM [$bdOrigem].DBO.MTTBMFCVS s
                INNER JOIN [$bdDestino].DBO._sgh_dr_emplig02 e ON e.codemp_fcvs = s.codemp
                INNER JOIN [$bdDestino].DBO._sgh_dr_cadmut c ON c.cadmut = LTRIM(RTRIM(s.cadmut))";
        return executarSQL($conn, $sql, "DR: SELECT INTO MTTBMFCVS (EMPLIG02 + cadmut)");
    }
}

/**
 * Helper generico para tabelas filtradas por uma unica FK contra uma temp.
 * Faz DELETE+INSERT (se ja existe) ou SELECT INTO.
 * Nao filtra por CODEMP (essas tabelas nao tem CODEMP).
 */
function drProcessarPorTemp(
    $conn, string $bdOrigem, string $bdDestino,
    string $tabela, string $colFkOrigem,
    string $tempName, string $colTemp,
    bool $tabelaExiste,
    string $descricaoFiltro
): bool {
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.[$tabela]", "DR: TRUNCATE-like $tabela (DELETE)");
        $sql = "INSERT INTO [$bdDestino].DBO.[$tabela]
                SELECT s.* FROM [$bdOrigem].DBO.[$tabela] s
                INNER JOIN [$bdDestino].DBO.[$tempName] t ON t.[$colTemp] = s.[$colFkOrigem]";
        return executarSQL($conn, $sql, "DR: INSERT $tabela ($descricaoFiltro)");
    } else {
        $sql = "SELECT s.* INTO [$bdDestino].DBO.[$tabela]
                FROM [$bdOrigem].DBO.[$tabela] s
                INNER JOIN [$bdDestino].DBO.[$tempName] t ON t.[$colTemp] = s.[$colFkOrigem]";
        return executarSQL($conn, $sql, "DR: SELECT INTO $tabela ($descricaoFiltro)");
    }
}

/**
 * Helper para tabelas que filtram por CADMUT direto (CDEC, COIC).
 */
function drProcessarPorCadmut(
    $conn, string $bdOrigem, string $bdDestino,
    string $tabela, bool $tabelaExiste
): bool {
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.[$tabela]", "DR: limpar $tabela");
        $sql = "INSERT INTO [$bdDestino].DBO.[$tabela]
                SELECT s.* FROM [$bdOrigem].DBO.[$tabela] s
                INNER JOIN [$bdDestino].DBO._sgh_dr_cadmut t ON t.cadmut = LTRIM(RTRIM(s.cadmut))";
        return executarSQL($conn, $sql, "DR: INSERT $tabela (por cadmut)");
    } else {
        $sql = "SELECT s.* INTO [$bdDestino].DBO.[$tabela]
                FROM [$bdOrigem].DBO.[$tabela] s
                INNER JOIN [$bdDestino].DBO._sgh_dr_cadmut t ON t.cadmut = LTRIM(RTRIM(s.cadmut))";
        return executarSQL($conn, $sql, "DR: SELECT INTO $tabela (por cadmut)");
    }
}

/**
 * MTTBSEG filtrada por CODEMP IN lista.
 */
function drProcessarSEG($conn, string $bdOrigem, string $bdDestino, string $codempList, bool $tabelaExiste): bool
{
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.MTTBSEG WHERE codemp IN ($codempList)", "DR: DELETE MTTBSEG");
        $sql = "INSERT INTO [$bdDestino].DBO.MTTBSEG SELECT * FROM [$bdOrigem].DBO.MTTBSEG WHERE codemp IN ($codempList)";
        return executarSQL($conn, $sql, "DR: INSERT MTTBSEG (codemp)");
    } else {
        $sql = "SELECT * INTO [$bdDestino].DBO.MTTBSEG FROM [$bdOrigem].DBO.MTTBSEG WHERE codemp IN ($codempList)";
        return executarSQL($conn, $sql, "DR: SELECT INTO MTTBSEG (codemp)");
    }
}

/**
 * MTTBLURA filtrada via _sgh_dr_seg.
 */
function drProcessarLURA($conn, string $bdOrigem, string $bdDestino, bool $tabelaExiste): bool
{
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.MTTBLURA", "DR: limpar MTTBLURA");
        $sql = "INSERT INTO [$bdDestino].DBO.MTTBLURA
                SELECT u.* FROM [$bdOrigem].DBO.MTTBLURA u
                INNER JOIN [$bdDestino].DBO._sgh_dr_seg s ON s.codemp = u.fk_codemp_seg AND s.CodUsr = u.fk_codusr_seg";
        return executarSQL($conn, $sql, "DR: INSERT MTTBLURA (via SEG)");
    } else {
        $sql = "SELECT u.* INTO [$bdDestino].DBO.MTTBLURA
                FROM [$bdOrigem].DBO.MTTBLURA u
                INNER JOIN [$bdDestino].DBO._sgh_dr_seg s ON s.codemp = u.fk_codemp_seg AND s.CodUsr = u.fk_codusr_seg";
        return executarSQL($conn, $sql, "DR: SELECT INTO MTTBLURA (via SEG)");
    }
}

/**
 * MTTBLADD: dupla restricao (DEC + URA).
 */
function drProcessarLADD($conn, string $bdOrigem, string $bdDestino, bool $tabelaExiste): bool
{
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.MTTBLADD", "DR: limpar MTTBLADD");
        $sql = "INSERT INTO [$bdDestino].DBO.MTTBLADD
                SELECT a.* FROM [$bdOrigem].DBO.MTTBLADD a
                INNER JOIN [$bdDestino].DBO._sgh_dr_codcdec d ON d.codigo = a.fk_codigo_dec
                INNER JOIN [$bdDestino].DBO._sgh_dr_codura u ON u.codigo = a.fk_codigo_ura";
        return executarSQL($conn, $sql, "DR: INSERT MTTBLADD (DEC + URA)");
    } else {
        $sql = "SELECT a.* INTO [$bdDestino].DBO.MTTBLADD
                FROM [$bdOrigem].DBO.MTTBLADD a
                INNER JOIN [$bdDestino].DBO._sgh_dr_codcdec d ON d.codigo = a.fk_codigo_dec
                INNER JOIN [$bdDestino].DBO._sgh_dr_codura u ON u.codigo = a.fk_codigo_ura";
        return executarSQL($conn, $sql, "DR: SELECT INTO MTTBLADD (DEC + URA)");
    }
}

/**
 * MTTBLADOD: dupla restricao (COIC + URA).
 */
function drProcessarLADOD($conn, string $bdOrigem, string $bdDestino, bool $tabelaExiste): bool
{
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.MTTBLADOD", "DR: limpar MTTBLADOD");
        $sql = "INSERT INTO [$bdDestino].DBO.MTTBLADOD
                SELECT a.* FROM [$bdOrigem].DBO.MTTBLADOD a
                INNER JOIN [$bdDestino].DBO._sgh_dr_codcoic c ON c.codigo = a.fk_codigo_oic
                INNER JOIN [$bdDestino].DBO._sgh_dr_codura u ON u.codigo = a.fk_codigo_ura";
        return executarSQL($conn, $sql, "DR: INSERT MTTBLADOD (COIC + URA)");
    } else {
        $sql = "SELECT a.* INTO [$bdDestino].DBO.MTTBLADOD
                FROM [$bdOrigem].DBO.MTTBLADOD a
                INNER JOIN [$bdDestino].DBO._sgh_dr_codcoic c ON c.codigo = a.fk_codigo_oic
                INNER JOIN [$bdDestino].DBO._sgh_dr_codura u ON u.codigo = a.fk_codigo_ura";
        return executarSQL($conn, $sql, "DR: SELECT INTO MTTBLADOD (COIC + URA)");
    }
}

/**
 * MTTBLDDT: filtra por fk_codigo_ddo presente em LDDO ja filtrada por idict.
 * Faz JOIN duplo: LDDT -> LDDO -> idict.
 */
function drProcessarLDDT($conn, string $bdOrigem, string $bdDestino, bool $tabelaExiste): bool
{
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.MTTBLDDT", "DR: limpar MTTBLDDT");
        $sql = "INSERT INTO [$bdDestino].DBO.MTTBLDDT
                SELECT dt.* FROM [$bdOrigem].DBO.MTTBLDDT dt
                INNER JOIN [$bdOrigem].DBO.MTTBLDDO d ON d.codigo = dt.fk_codigo_ddo
                INNER JOIN [$bdDestino].DBO._sgh_dr_idict i ON i.Id_ict = d.fk_id_ict";
        return executarSQL($conn, $sql, "DR: INSERT MTTBLDDT (via LDDO/idict)");
    } else {
        $sql = "SELECT dt.* INTO [$bdDestino].DBO.MTTBLDDT
                FROM [$bdOrigem].DBO.MTTBLDDT dt
                INNER JOIN [$bdOrigem].DBO.MTTBLDDO d ON d.codigo = dt.fk_codigo_ddo
                INNER JOIN [$bdDestino].DBO._sgh_dr_idict i ON i.Id_ict = d.fk_id_ict";
        return executarSQL($conn, $sql, "DR: SELECT INTO MTTBLDDT (via LDDO/idict)");
    }
}

/**
 * MTTBPEMP filtrada por fk_codemp_emp IN codempList.
 */
function drProcessarPEMP($conn, string $bdOrigem, string $bdDestino, string $codempList, bool $tabelaExiste): bool
{
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.MTTBPEMP WHERE fk_codemp_emp IN ($codempList)", "DR: DELETE MTTBPEMP");
        $sql = "INSERT INTO [$bdDestino].DBO.MTTBPEMP SELECT * FROM [$bdOrigem].DBO.MTTBPEMP WHERE fk_codemp_emp IN ($codempList)";
        return executarSQL($conn, $sql, "DR: INSERT MTTBPEMP (codemp)");
    } else {
        $sql = "SELECT * INTO [$bdDestino].DBO.MTTBPEMP FROM [$bdOrigem].DBO.MTTBPEMP WHERE fk_codemp_emp IN ($codempList)";
        return executarSQL($conn, $sql, "DR: SELECT INTO MTTBPEMP (codemp)");
    }
}

// =====================================================================
// ============= FIM MODULO DOSSIE/RECURSO =============================
// =====================================================================

// Descobrir tabelas na origem
logInfo("Descobrindo tabelas no banco [$bdOrigem]...");
$tabelasOrigem = getAllUserTables($conn, $bdOrigem);
logSuccess("Encontradas " . count($tabelasOrigem) . " tabelas na origem.");

// Recalcular total de steps (tabelas + indices)
$totalSteps = $currentStep + count($tabelasOrigem) + 1; // +1 para indices

// Tabelas ja existentes no destino
$tabelasDestinoExistentes = getAllUserTables($conn, $bdDestino);
$tabelasDestinoMap = array_flip(array_map('strtolower', $tabelasDestinoExistentes));

$countContrato = 0;
$countCodemp = 0;
$countIntegral = 0;
$countSelectInto = 0;
$countInsert = 0;
$countPulou = 0;
$countEspecial = 0;

if ($onlyExceptions) {
    $okExc = sghReprocessarExcecoes($conn, $bdOrigem, $bdDestino, $codempList);
    if ($okExc) {
        sendProgress($totalSteps, $totalSteps, 'Excecoes reprocessadas');
        logSuccess("Reprocessamento de excecoes concluido com sucesso.");
        sendEvent('done', [
            'success'      => true,
            'totalTabelas' => 3,
            'banco'        => $bdDestino,
            'hasReport'    => false,
        ]);
    } else {
        logError("Falha ao reprocessar excecoes. Verifique o log acima.");
        sendEvent('done', ['success' => false]);
    }
    sqlsrv_close($conn);
    exit;
}

if ($onlyFinalizers) {
    logInfo("");
    logWarn("MODO RETOMADA: pulando pre-processamento, criacao de tabelas e loop principal.");
    logWarn("Sera executado apenas: IDENTITY -> Constraints -> Indices -> Views/Procedures.");
    // Avancar o currentStep ate o ponto onde os finalizadores comecam,
    // mantendo a barra de progresso coerente
    $currentStep += count($tabelasOrigem);
    sendProgress($currentStep, $totalSteps, "Modo retomada: pulou processamento de tabelas");
} else {
// ---------- Pre-processamento das tabelas especiais (mttbse1, mttbdep, mttbse2) ----------
// Essas 3 tabelas nao tem o quarteto CODEMP+REGIAO+NUCLEO+CONTRATO,
// entao precisam de filtragem relacional (por CPF ou COD_ADQ) igual ao modo Cliente.
$tabelasOrigemLower = array_map('strtolower', $tabelasOrigem);
$temSE1 = in_array('mttbse1', $tabelasOrigemLower);
$temDEP = in_array('mttbdep', $tabelasOrigemLower);
$temSE2 = in_array('mttbse2', $tabelasOrigemLower);
$tempCPFok = false;
$tempCodAdqOk = false;

if ($temSE1) {
    logInfo("");
    logInfo("SGH: pre-computando CPFs relacionados aos contratos (para mttbse1)...");
    $tempCPFok = sghCriarTempCPF($conn, $bdOrigem, $bdDestino);
    if ($tempCPFok) {
        $n = contarRegistros($conn, "[$bdDestino].DBO._sgh_temp_cpf_final");
        logSuccess("SGH: $n CPFs distintos coletados para filtrar mttbse1.");
    } else {
        logWarn("SGH: falha ao pre-computar CPFs; mttbse1 sera pulada.");
    }
}

if ($temDEP || $temSE2) {
    logInfo("");
    logInfo("SGH: pre-computando codigos adquirentes (para mttbdep/mttbse2)...");
    $tempCodAdqOk = sghCriarTempCodAdq($conn, $bdOrigem, $bdDestino);
    if ($tempCodAdqOk) {
        $n = contarRegistros($conn, "[$bdDestino].DBO._sgh_temp_codadq_final");
        logSuccess("SGH: $n codigos adquirentes distintos coletados para filtrar mttbdep/mttbse2.");
    } else {
        logWarn("SGH: falha ao pre-computar codigos adquirentes; mttbdep/mttbse2 serao puladas.");
    }
}

// ---------- Pre-processamento do MODULO DOSSIE/RECURSO ----------
// Cadeia: cadmut (de MTTBCON via CON_FIDC) -> CDEC, COIC -> ... -> DOC, URA
$temDR = in_array('mttbcdec', $tabelasOrigemLower) || in_array('mttbcoic', $tabelasOrigemLower)
      || in_array('mttbict', $tabelasOrigemLower);
$drCadmutOk = false;
$drIdictOk  = false;
$drDecOk    = false;
$drCoicOk   = false;
$drMrrOk    = false;
$drDocOk    = false;
$drSegOk    = false;
$drUraOk    = false;
$drEmplig02Ok = false;

if ($temDR) {
    logInfo("");
    logInfo("SGH: pre-computando cadeia DOSSIE/RECURSO...");

    logInfo("  DR 1/8: coletando cadmuts dos contratos da CON_FIDC...");
    $drCadmutOk = drCriarTempCadmut($conn, $bdOrigem, $bdDestino);
    if ($drCadmutOk) {
        $n = contarRegistros($conn, "[$bdDestino].DBO._sgh_dr_cadmut");
        logSuccess("  DR: $n cadmuts distintos.");
    } else {
        logWarn("  DR: falha ao coletar cadmuts; CDEC/COIC e dependentes serao puladas.");
    }

    logInfo("  DR 2/8: coletando Id_ict da MTTBICT via CON_FIDC...");
    $drIdictOk = drCriarTempIdict($conn, $bdOrigem, $bdDestino);
    if ($drIdictOk) {
        $n = contarRegistros($conn, "[$bdDestino].DBO._sgh_dr_idict");
        logSuccess("  DR: $n Id_ict distintos.");
    } else {
        logWarn("  DR: falha ao coletar Id_ict; LDIG/LDDO/LDDT serao puladas.");
    }

    if ($drCadmutOk) {
        logInfo("  DR 3/8: coletando codigos de CDEC...");
        $drDecOk = drCriarTempCodCdec($conn, $bdOrigem, $bdDestino);
        if ($drDecOk) {
            $n = contarRegistros($conn, "[$bdDestino].DBO._sgh_dr_codcdec");
            logSuccess("  DR: $n CDECs.");
        }

        logInfo("  DR 4/8: coletando codigos de COIC...");
        $drCoicOk = drCriarTempCodCoic($conn, $bdOrigem, $bdDestino);
        if ($drCoicOk) {
            $n = contarRegistros($conn, "[$bdDestino].DBO._sgh_dr_codcoic");
            logSuccess("  DR: $n COICs.");
        }
    }

    if ($drCoicOk) {
        logInfo("  DR 5/8: coletando MRRs relevantes (via LMRN -> COIC)...");
        $drMrrOk = drCriarTempMrrRelev($conn, $bdOrigem, $bdDestino);
        if ($drMrrOk) {
            $n = contarRegistros($conn, "[$bdDestino].DBO._sgh_dr_mrrrelev");
            logSuccess("  DR: $n MRRs relevantes.");
        }
    }

    if ($drMrrOk || $drIdictOk) {
        logInfo("  DR 6/8: coletando codigos de DOC (via LDMR e LDIG)...");
        $drDocOk = drCriarTempCodCdoc($conn, $bdOrigem, $bdDestino);
        if ($drDocOk) {
            $n = contarRegistros($conn, "[$bdDestino].DBO._sgh_dr_codcdoc");
            logSuccess("  DR: $n DOCs.");
        }
    }

    logInfo("  DR 7/8: coletando SEG por CODEMP IN ($codempList)...");
    $drSegOk = drCriarTempSeg($conn, $bdOrigem, $bdDestino, $codempList);
    if ($drSegOk) {
        $n = contarRegistros($conn, "[$bdDestino].DBO._sgh_dr_seg");
        logSuccess("  DR: $n linhas em SEG.");
    }

    if ($drSegOk) {
        logInfo("  DR 8/8: coletando codigos de LURA via SEG...");
        $drUraOk = drCriarTempCodUra($conn, $bdOrigem, $bdDestino);
        if ($drUraOk) {
            $n = contarRegistros($conn, "[$bdDestino].DBO._sgh_dr_codura");
            logSuccess("  DR: $n LURAs.");
        }
    }

    // Empresas-parceiras FCVS (EMPLIG02) — usadas para MTTBMFCVS e similares
    logInfo("  DR 9/9: coletando empresas parceiras FCVS (MTTBEMP.EMPLIG02)...");
    $drEmplig02Ok = drCriarTempEmplig02($conn, $bdOrigem, $bdDestino, $codempList);
    if ($drEmplig02Ok) {
        $n = contarRegistros($conn, "[$bdDestino].DBO._sgh_dr_emplig02");
        logSuccess("  DR: $n empresas EMPLIG02.");
    }
}

// Lista de tabelas a IGNORAR no SGH (gigantes, irrelevantes ou problematicas).
// Adicione aqui em lowercase para pular sem processar nem listar como erro.
$sghPularTabelas = ['mttbptc'];

// ---------- Tabelas com VARBINARY(MAX) ----------
// Se a flag skip_blobs estiver ligada, criamos a estrutura mas nao copiamos dados.
$tabelasComBlob = [];
if ($skipBlobs) {
    $sqlBlobs = "SELECT DISTINCT t.name AS tabela
                 FROM [$bdOrigem].sys.columns c
                 INNER JOIN [$bdOrigem].sys.tables t ON t.object_id = c.object_id
                 INNER JOIN [$bdOrigem].sys.types tp ON tp.user_type_id = c.user_type_id
                 WHERE t.is_ms_shipped = 0
                   AND tp.name = 'varbinary'
                   AND c.max_length = -1";
    $stmtBlobs = sqlsrv_query($conn, $sqlBlobs);
    if ($stmtBlobs) {
        while ($r = sqlsrv_fetch_array($stmtBlobs, SQLSRV_FETCH_ASSOC)) {
            $tabelasComBlob[strtolower($r['tabela'])] = true;
        }
        sqlsrv_free_stmt($stmtBlobs);
    }
    if (!empty($tabelasComBlob)) {
        logWarn("Skip BLOBs: " . count($tabelasComBlob) . " tabelas com VARBINARY(MAX) terao apenas a estrutura criada (sem dados): "
                . implode(', ', array_keys($tabelasComBlob)));
    }
}

foreach ($tabelasOrigem as $tabela) {
    // Pular CON_FIDC (ja criada)
    if (strtoupper($tabela) === 'CON_FIDC') {
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "Pulando: $tabela (controle)");
        continue;
    }

    // Pular temps internas (se aparecerem por algum motivo)
    if (stripos($tabela, '_sgh_temp') === 0 || stripos($tabela, '_sgh_dr_') === 0) {
        $currentStep++;
        continue;
    }

    $tabelaLower = strtolower($tabela);
    $tabelaExiste = isset($tabelasDestinoMap[$tabelaLower]);

    // Pular tabelas listadas para ignorar (ex: mttbptc gigante)
    if (in_array($tabelaLower, $sghPularTabelas)) {
        logInfo("[SKIP] $tabela (configurada para nao ser processada)");
        $countPulou++;
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "Pulando: $tabela");
        continue;
    }

    // Skip BLOBs: cria so a estrutura (sem dados) e segue
    if (isset($tabelasComBlob[$tabelaLower])) {
        if ($tabelaExiste) {
            logInfo("[BLOB] $tabela: tabela ja existe no destino, mantida intacta.");
        } else {
            $sql = "SELECT TOP 0 * INTO [$bdDestino].DBO.[$tabela] FROM [$bdOrigem].DBO.[$tabela]";
            if (executarSQL($conn, $sql, "Estrutura $tabela (sem dados - VARBINARY MAX)")) {
                logSuccess("[BLOB] $tabela: estrutura criada (0 registros - dados pulados)");
                $countSelectInto++;
            }
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "BLOB skip: $tabela");
        continue;
    }

    // Log de inicio (importante para tabelas grandes que demoram minutos -
    // sem isso o front fica em silencio e parece travado)
    sendProgress($currentStep, $totalSteps, "Processando: $tabela");
    logInfo("Processando $tabela...");

    // ---------- CASO ESPECIAL 1: mttbse1 (filtro por CPF) ----------
    if ($tabelaLower === 'mttbse1') {
        if ($tempCPFok) {
            $ok = sghProcessarSE1($conn, $bdOrigem, $bdDestino, $codempList, $tabelaExiste);
            if ($ok) {
                $count = contarRegistros($conn, "[$bdDestino].DBO.mttbse1");
                logSuccess("[OK] mttbse1 (especial: por CPF) - $count registros");
                $countEspecial++;
                if ($tabelaExiste) $countInsert++; else $countSelectInto++;
            }
        } else {
            logWarn("[SKIP] mttbse1: temp de CPFs indisponivel.");
            $countPulou++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "SGH especial: mttbse1");
        continue;
    }

    // ---------- CASO ESPECIAL 2: mttbdep (filtro por COD_ADQ) ----------
    if ($tabelaLower === 'mttbdep') {
        if ($tempCodAdqOk) {
            $ok = sghProcessarDEP($conn, $bdOrigem, $bdDestino, $codempList, $tabelaExiste);
            if ($ok) {
                $count = contarRegistros($conn, "[$bdDestino].DBO.mttbdep");
                logSuccess("[OK] mttbdep (especial: por COD_ADQ) - $count registros");
                $countEspecial++;
                if ($tabelaExiste) $countInsert++; else $countSelectInto++;
            }
        } else {
            logWarn("[SKIP] mttbdep: temp de COD_ADQ indisponivel.");
            $countPulou++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "SGH especial: mttbdep");
        continue;
    }

    // ---------- CASO ESPECIAL 3: mttbse2 (filtro por COD_ADQ) ----------
    if ($tabelaLower === 'mttbse2') {
        if ($tempCodAdqOk) {
            $ok = sghProcessarSE2($conn, $bdOrigem, $bdDestino, $codempList, $tabelaExiste);
            if ($ok) {
                $count = contarRegistros($conn, "[$bdDestino].DBO.mttbse2");
                logSuccess("[OK] mttbse2 (especial: por COD_ADQ) - $count registros");
                $countEspecial++;
                if ($tabelaExiste) $countInsert++; else $countSelectInto++;
            }
        } else {
            logWarn("[SKIP] mttbse2: temp de COD_ADQ indisponivel.");
            $countPulou++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "SGH especial: mttbse2");
        continue;
    }

    // ---------- CASO ESPECIAL 4: mttbjur (filtro por REGIAO_PRI/NUCLEO_PRI/CONTRATO_PRI) ----------
    if ($tabelaLower === 'mttbjur') {
        $ok = sghProcessarJUR($conn, $bdOrigem, $bdDestino, $codempList, $tabelaExiste);
        if ($ok) {
            $count = contarRegistros($conn, "[$bdDestino].DBO.mttbjur");
            logSuccess("[OK] mttbjur (especial: por _PRI) - $count registros");
            $countEspecial++;
            if ($tabelaExiste) $countInsert++; else $countSelectInto++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "SGH especial: mttbjur");
        continue;
    }

    // ---------- CASOS ESPECIAIS 5/6/7/8: mttbimc / mttbimv / mttbepoc / mttbemut (filtro por IMOVEL_UNICO) ----------
    if (in_array($tabelaLower, ['mttbimc', 'mttbimv', 'mttbepoc', 'mttbemut'])) {
        $ok = sghProcessarPorImovel($conn, $bdOrigem, $bdDestino, $codempList, $tabelaLower, $tabelaExiste);
        if ($ok) {
            $count = contarRegistros($conn, "[$bdDestino].DBO.[$tabelaLower]");
            logSuccess("[OK] $tabelaLower (especial: por IMOVEL_UNICO) - $count registros");
            $countEspecial++;
            if ($tabelaExiste) $countInsert++; else $countSelectInto++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "SGH especial: $tabelaLower");
        continue;
    }

    // ============================================================
    // ============= MODULO DOSSIE/RECURSO ========================
    // ============================================================

    // CDEC e COIC: filtro por cadmut
    if (in_array($tabelaLower, ['mttbcdec', 'mttbcoic'])) {
        if ($drCadmutOk) {
            $ok = drProcessarPorCadmut($conn, $bdOrigem, $bdDestino, $tabelaLower, $tabelaExiste);
            if ($ok) {
                $count = contarRegistros($conn, "[$bdDestino].DBO.[$tabelaLower]");
                logSuccess("[OK] $tabelaLower (DR: por cadmut) - $count registros");
                $countEspecial++;
                if ($tabelaExiste) $countInsert++; else $countSelectInto++;
            }
        } else {
            logWarn("[SKIP] $tabelaLower: temp _sgh_dr_cadmut indisponivel.");
            $countPulou++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "DR: $tabelaLower");
        continue;
    }

    // LDIG, LDDO: filtro por Id_ict
    if (in_array($tabelaLower, ['mttbldig', 'mttblddo'])) {
        if ($drIdictOk) {
            $colFk = ($tabelaLower === 'mttbldig') ? 'fk_id_ict' : 'fk_id_ict';
            $ok = drProcessarPorTemp($conn, $bdOrigem, $bdDestino,
                $tabelaLower, $colFk, '_sgh_dr_idict', 'Id_ict', $tabelaExiste, 'por Id_ict');
            if ($ok) {
                $count = contarRegistros($conn, "[$bdDestino].DBO.[$tabelaLower]");
                logSuccess("[OK] $tabelaLower (DR: por Id_ict) - $count registros");
                $countEspecial++;
                if ($tabelaExiste) $countInsert++; else $countSelectInto++;
            }
        } else {
            logWarn("[SKIP] $tabelaLower: temp _sgh_dr_idict indisponivel.");
            $countPulou++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "DR: $tabelaLower");
        continue;
    }

    // CDPD, LDSS, LDTD: filtro por fk_codigo_dec
    if (in_array($tabelaLower, ['mttbcdpd', 'mttbldss', 'mttbldtd'])) {
        if ($drDecOk) {
            $ok = drProcessarPorTemp($conn, $bdOrigem, $bdDestino,
                $tabelaLower, 'fk_codigo_dec', '_sgh_dr_codcdec', 'codigo', $tabelaExiste, 'por CDEC');
            if ($ok) {
                $count = contarRegistros($conn, "[$bdDestino].DBO.[$tabelaLower]");
                logSuccess("[OK] $tabelaLower (DR: por CDEC) - $count registros");
                $countEspecial++;
                if ($tabelaExiste) $countInsert++; else $countSelectInto++;
            }
        } else {
            logWarn("[SKIP] $tabelaLower: temp _sgh_dr_codcdec indisponivel.");
            $countPulou++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "DR: $tabelaLower");
        continue;
    }

    // LSCN, LMRN: filtro por fk_codigo_oic
    if (in_array($tabelaLower, ['mttblscn', 'mttblmrn'])) {
        if ($drCoicOk) {
            $ok = drProcessarPorTemp($conn, $bdOrigem, $bdDestino,
                $tabelaLower, 'fk_codigo_oic', '_sgh_dr_codcoic', 'codigo', $tabelaExiste, 'por COIC');
            if ($ok) {
                $count = contarRegistros($conn, "[$bdDestino].DBO.[$tabelaLower]");
                logSuccess("[OK] $tabelaLower (DR: por COIC) - $count registros");
                $countEspecial++;
                if ($tabelaExiste) $countInsert++; else $countSelectInto++;
            }
        } else {
            logWarn("[SKIP] $tabelaLower: temp _sgh_dr_codcoic indisponivel.");
            $countPulou++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "DR: $tabelaLower");
        continue;
    }

    // LHAN, LSNG, LDMR: filtro por fk_codigo_mrr (universo MRR alcancavel via COIC)
    if (in_array($tabelaLower, ['mttblhan', 'mttblsng', 'mttbldmr'])) {
        if ($drMrrOk) {
            $ok = drProcessarPorTemp($conn, $bdOrigem, $bdDestino,
                $tabelaLower, 'fk_codigo_mrr', '_sgh_dr_mrrrelev', 'codigo', $tabelaExiste, 'por MRR-relevante');
            if ($ok) {
                $count = contarRegistros($conn, "[$bdDestino].DBO.[$tabelaLower]");
                logSuccess("[OK] $tabelaLower (DR: por MRR) - $count registros");
                $countEspecial++;
                if ($tabelaExiste) $countInsert++; else $countSelectInto++;
            }
        } else {
            logWarn("[SKIP] $tabelaLower: temp _sgh_dr_mrrrelev indisponivel.");
            $countPulou++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "DR: $tabelaLower");
        continue;
    }

    // CDOC: filtro por codigo (na lista de DOCs referenciados)
    if ($tabelaLower === 'mttbcdoc') {
        if ($drDocOk) {
            $ok = drProcessarPorTemp($conn, $bdOrigem, $bdDestino,
                'mttbcdoc', 'codigo', '_sgh_dr_codcdoc', 'codigo', $tabelaExiste, 'por DOC referenciado');
            if ($ok) {
                $count = contarRegistros($conn, "[$bdDestino].DBO.mttbcdoc");
                logSuccess("[OK] mttbcdoc (DR: por DOC) - $count registros");
                $countEspecial++;
                if ($tabelaExiste) $countInsert++; else $countSelectInto++;
            }
        } else {
            logWarn("[SKIP] mttbcdoc: temp _sgh_dr_codcdoc indisponivel.");
            $countPulou++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "DR: mttbcdoc");
        continue;
    }

    // LTDC: filtro por fk_codigo_doc
    if ($tabelaLower === 'mttbltdc') {
        if ($drDocOk) {
            $ok = drProcessarPorTemp($conn, $bdOrigem, $bdDestino,
                'mttbltdc', 'fk_codigo_doc', '_sgh_dr_codcdoc', 'codigo', $tabelaExiste, 'por DOC');
            if ($ok) {
                $count = contarRegistros($conn, "[$bdDestino].DBO.mttbltdc");
                logSuccess("[OK] mttbltdc (DR: por DOC) - $count registros");
                $countEspecial++;
                if ($tabelaExiste) $countInsert++; else $countSelectInto++;
            }
        } else {
            logWarn("[SKIP] mttbltdc: temp _sgh_dr_codcdoc indisponivel.");
            $countPulou++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "DR: mttbltdc");
        continue;
    }

    // SEG: filtro por CODEMP IN lista
    if ($tabelaLower === 'mttbseg') {
        $ok = drProcessarSEG($conn, $bdOrigem, $bdDestino, $codempList, $tabelaExiste);
        if ($ok) {
            $count = contarRegistros($conn, "[$bdDestino].DBO.mttbseg");
            logSuccess("[OK] mttbseg (DR: por CODEMP) - $count registros");
            $countEspecial++;
            if ($tabelaExiste) $countInsert++; else $countSelectInto++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "DR: mttbseg");
        continue;
    }

    // LURA: filtro via SEG
    if ($tabelaLower === 'mttblura') {
        if ($drSegOk) {
            $ok = drProcessarLURA($conn, $bdOrigem, $bdDestino, $tabelaExiste);
            if ($ok) {
                $count = contarRegistros($conn, "[$bdDestino].DBO.mttblura");
                logSuccess("[OK] mttblura (DR: via SEG) - $count registros");
                $countEspecial++;
                if ($tabelaExiste) $countInsert++; else $countSelectInto++;
            }
        } else {
            logWarn("[SKIP] mttblura: temp _sgh_dr_seg indisponivel.");
            $countPulou++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "DR: mttblura");
        continue;
    }

    // LADD: dupla restricao DEC + URA
    if ($tabelaLower === 'mttbladd') {
        if ($drDecOk && $drUraOk) {
            $ok = drProcessarLADD($conn, $bdOrigem, $bdDestino, $tabelaExiste);
            if ($ok) {
                $count = contarRegistros($conn, "[$bdDestino].DBO.mttbladd");
                logSuccess("[OK] mttbladd (DR: DEC + URA) - $count registros");
                $countEspecial++;
                if ($tabelaExiste) $countInsert++; else $countSelectInto++;
            }
        } else {
            logWarn("[SKIP] mttbladd: dependencias DEC/URA indisponiveis.");
            $countPulou++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "DR: mttbladd");
        continue;
    }

    // LADOD: dupla restricao COIC + URA
    if ($tabelaLower === 'mttbladod') {
        if ($drCoicOk && $drUraOk) {
            $ok = drProcessarLADOD($conn, $bdOrigem, $bdDestino, $tabelaExiste);
            if ($ok) {
                $count = contarRegistros($conn, "[$bdDestino].DBO.mttbladod");
                logSuccess("[OK] mttbladod (DR: COIC + URA) - $count registros");
                $countEspecial++;
                if ($tabelaExiste) $countInsert++; else $countSelectInto++;
            }
        } else {
            logWarn("[SKIP] mttbladod: dependencias COIC/URA indisponiveis.");
            $countPulou++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "DR: mttbladod");
        continue;
    }

    // LDDT: via LDDO -> idict (joins encadeados)
    if ($tabelaLower === 'mttblddt') {
        if ($drIdictOk) {
            $ok = drProcessarLDDT($conn, $bdOrigem, $bdDestino, $tabelaExiste);
            if ($ok) {
                $count = contarRegistros($conn, "[$bdDestino].DBO.mttblddt");
                logSuccess("[OK] mttblddt (DR: via LDDO/idict) - $count registros");
                $countEspecial++;
                if ($tabelaExiste) $countInsert++; else $countSelectInto++;
            }
        } else {
            logWarn("[SKIP] mttblddt: temp _sgh_dr_idict indisponivel.");
            $countPulou++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "DR: mttblddt");
        continue;
    }

    // PEMP: filtro por fk_codemp_emp IN lista
    if ($tabelaLower === 'mttbpemp') {
        $ok = drProcessarPEMP($conn, $bdOrigem, $bdDestino, $codempList, $tabelaExiste);
        if ($ok) {
            $count = contarRegistros($conn, "[$bdDestino].DBO.mttbpemp");
            logSuccess("[OK] mttbpemp (DR: por CODEMP) - $count registros");
            $countEspecial++;
            if ($tabelaExiste) $countInsert++; else $countSelectInto++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "DR: mttbpemp");
        continue;
    }

    // MFCVS: filtro por (CODEMP em EMPLIG02 das empresas) AND (cadmut em CON_FIDC)
    if ($tabelaLower === 'mttbmfcvs') {
        if ($drEmplig02Ok && $drCadmutOk) {
            $ok = drProcessarMFCVS($conn, $bdOrigem, $bdDestino, $tabelaExiste);
            if ($ok) {
                $count = contarRegistros($conn, "[$bdDestino].DBO.mttbmfcvs");
                logSuccess("[OK] mttbmfcvs (DR: EMPLIG02 + cadmut) - $count registros");
                $countEspecial++;
                if ($tabelaExiste) $countInsert++; else $countSelectInto++;
            }
        } else {
            logWarn("[SKIP] mttbmfcvs: dependencias EMPLIG02/cadmut indisponiveis.");
            $countPulou++;
        }
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "DR: mttbmfcvs");
        continue;
    }

    // ============= FIM MODULO DOSSIE/RECURSO ====================

    $cols = getTableColumns($conn, $bdOrigem, $tabela);
    $hasCODEMP   = in_array('CODEMP', $cols);
    $hasREGIAO   = in_array('REGIAO', $cols);
    $hasNUCLEO   = in_array('NUCLEO', $cols);
    $hasCONTRATO = in_array('CONTRATO', $cols);

    $hasFullKey = $hasCODEMP && $hasREGIAO && $hasNUCLEO && $hasCONTRATO;

    if ($hasFullKey) {
        // CASO 1: Tem CODEMP+REGIAO+NUCLEO+CONTRATO => filtra por contrato
        $countContrato++;

        if ($tabelaExiste) {
            // Tabela ja existe: DELETE das empresas + INSERT
            $sqlDel = "DELETE FROM [$bdDestino].DBO.[$tabela] WHERE CODEMP IN ($codempList)";
            executarSQL($conn, $sqlDel, "DELETE CODEMP IN ($codempList) de $tabela");

            $sqlIns = "INSERT INTO [$bdDestino].DBO.[$tabela]
                       SELECT Y.* FROM [$bdOrigem].DBO.[$tabela] Y
                       WHERE Y.CODEMP IN ($codempList)
                         AND EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X
                                     WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO
                                       AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)";
            $ok = executarSQL($conn, $sqlIns, "INSERT $tabela (contrato)");
            $countInsert++;
        } else {
            // Tabela nao existe: SELECT INTO
            $sql = "SELECT Y.* INTO [$bdDestino].DBO.[$tabela]
                    FROM [$bdOrigem].DBO.[$tabela] Y
                    WHERE Y.CODEMP IN ($codempList)
                      AND EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X
                                  WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO
                                    AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)";
            $ok = executarSQL($conn, $sql, "SELECT INTO $tabela (contrato)");
            $countSelectInto++;
        }

    } elseif ($hasCODEMP) {
        // CASO 2: Tem CODEMP mas nao tem chave completa => leva tudo das empresas
        $countCodemp++;

        if ($tabelaExiste) {
            // Tabela ja existe: DELETE das empresas + INSERT
            $sqlDel = "DELETE FROM [$bdDestino].DBO.[$tabela] WHERE CODEMP IN ($codempList)";
            executarSQL($conn, $sqlDel, "DELETE CODEMP IN ($codempList) de $tabela");

            $sqlIns = "INSERT INTO [$bdDestino].DBO.[$tabela]
                       SELECT * FROM [$bdOrigem].DBO.[$tabela] WHERE CODEMP IN ($codempList)";
            $ok = executarSQL($conn, $sqlIns, "INSERT $tabela (codemp)");
            $countInsert++;
        } else {
            $sql = "SELECT * INTO [$bdDestino].DBO.[$tabela]
                    FROM [$bdOrigem].DBO.[$tabela] WHERE CODEMP IN ($codempList)";
            $ok = executarSQL($conn, $sql, "SELECT INTO $tabela (codemp)");
            $countSelectInto++;
        }
        
    } else {
        // CASO 3: Nao tem CODEMP => copia integral (so na primeira vez)
        $countIntegral++;
        
        if ($tabelaExiste) {
            // Ja copiada anteriormente, pular
            $countPulou++;
            $currentStep++;
            sendProgress($currentStep, $totalSteps, "Pulando (integral): $tabela");
            logInfo("  -> $tabela: ja existe (integral), pulando.");
            continue;
        } else {
            $sql = "SELECT * INTO [$bdDestino].DBO.[$tabela] FROM [$bdOrigem].DBO.[$tabela]";
            $ok = executarSQL($conn, $sql, "SELECT INTO $tabela (integral)");
            $countSelectInto++;
        }
    }
    
    if (isset($ok) && $ok) {
        $count = contarRegistros($conn, "[$bdDestino].DBO.[$tabela]");
        $tipo_filtro = $hasFullKey ? 'contrato' : ($hasCODEMP ? 'codemp' : 'integral');
        logSuccess("[OK] $tabela ($tipo_filtro) - $count registros");
    }
    
    $currentStep++;
    sendProgress($currentStep, $totalSteps, "SGH: $tabela");
}

logInfo("");
logInfo("Resumo SGH: $countContrato por contrato | $countCodemp por empresa | $countIntegral integral | $countEspecial especiais (SE1/DEP/SE2)");
if ($countInsert > 0) logInfo("Multi-empresa: $countInsert INSERT + $countSelectInto SELECT INTO");
if ($countPulou > 0) logInfo("Tabelas puladas: $countPulou");

// Limpar temps do pre-processamento especial
dropIfExists($conn, "[$bdDestino].DBO._sgh_temp_cpf");
dropIfExists($conn, "[$bdDestino].DBO._sgh_temp_cpf_final");
dropIfExists($conn, "[$bdDestino].DBO._sgh_temp_codadq");
dropIfExists($conn, "[$bdDestino].DBO._sgh_temp_codadq_final");
// Temps do modulo DOSSIE/RECURSO
dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_cadmut");
dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_idict");
dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_codcdec");
dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_codcoic");
dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_mrrrelev");
dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_codcdoc");
dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_codcdoc_raw");
dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_seg");
dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_codura");
dropIfExists($conn, "[$bdDestino].DBO._sgh_dr_emplig02");

} // fim if (!$onlyFinalizers) - executou pre-processamento + loop de tabelas

// ===================== RESTAURAR IDENTITY =====================
// SELECT INTO copia tipos das colunas mas NAO preserva IDENTITY.
// Esta fase identifica colunas que eram IDENTITY na origem e recria
// a tabela no destino com IDENTITY preservada (e dados intactos).
logInfo("");
logInfo("========================================");
logInfo("SGH: Restaurando colunas IDENTITY");
logInfo("========================================");

/**
 * Monta a definicao DDL completa de cada coluna de uma tabela do destino.
 * Retorna array de strings tipo "[col] TYPE NULL/NOT NULL"
 * (sem o IDENTITY - este e adicionado depois pela coluna correta).
 */
function sghMontarColunasDDL($conn, string $banco, string $tabela): array
{
    $sql = "SELECT
                c.name AS col_name,
                tp.name AS type_name,
                c.max_length, c.precision, c.scale, c.is_nullable, c.column_id
            FROM [$banco].sys.columns c
            INNER JOIN [$banco].sys.types tp ON tp.user_type_id = c.user_type_id
            WHERE c.object_id = OBJECT_ID('[$banco].DBO.[$tabela]')
            ORDER BY c.column_id";
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) return [];

    $defs = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $type = strtolower($r['type_name']);
        $maxLen = (int)$r['max_length'];
        $prec   = (int)$r['precision'];
        $scale  = (int)$r['scale'];

        if (in_array($type, ['char', 'varchar', 'binary', 'varbinary'])) {
            $len = ($maxLen === -1) ? 'MAX' : $maxLen;
            $typeStr = strtoupper($type) . "($len)";
        } elseif (in_array($type, ['nchar', 'nvarchar'])) {
            $len = ($maxLen === -1) ? 'MAX' : ($maxLen / 2);
            $typeStr = strtoupper($type) . "($len)";
        } elseif (in_array($type, ['decimal', 'numeric'])) {
            $typeStr = strtoupper($type) . "($prec,$scale)";
        } elseif (in_array($type, ['datetime2', 'time', 'datetimeoffset'])) {
            $typeStr = strtoupper($type) . "($scale)";
        } else {
            $typeStr = strtoupper($type);
        }

        $nullStr = $r['is_nullable'] ? 'NULL' : 'NOT NULL';
        $defs[$r['col_name']] = "[{$r['col_name']}] $typeStr $nullStr";
    }
    sqlsrv_free_stmt($stmt);
    return $defs;
}

/**
 * Recria a tabela com IDENTITY na coluna especificada, preservando dados.
 * Retorna true se sucesso.
 */
function sghRecriarComIdentity($conn, string $bdDestino, string $tabela, string $idCol, int $seed, int $increment): bool
{
    // 1. Listar colunas atuais do destino com tipos
    $colsDef = sghMontarColunasDDL($conn, $bdDestino, $tabela);
    if (empty($colsDef)) {
        logWarn("  IDENTITY [$tabela]: nao foi possivel listar colunas, pulando.");
        return false;
    }
    if (!isset($colsDef[$idCol])) {
        logWarn("  IDENTITY [$tabela]: coluna [$idCol] nao encontrada no destino, pulando.");
        return false;
    }

    // 2. Substituir a definicao da coluna identity por uma com IDENTITY
    //    Pega o NOT NULL do tipo (IDENTITY em SQL Server obriga NOT NULL)
    $colsList = [];
    foreach ($colsDef as $cn => $def) {
        if ($cn === $idCol) {
            // Extrai o tipo (pega o que vem depois de "[colname] " e antes de " NULL/NOT NULL")
            if (preg_match('/^\[.+?\]\s+(\S+)/', $def, $m)) {
                $colsList[] = "[$cn] {$m[1]} IDENTITY($seed,$increment) NOT NULL";
            } else {
                logWarn("  IDENTITY [$tabela]: nao consegui parsear tipo de [$idCol].");
                return false;
            }
        } else {
            $colsList[] = $def;
        }
    }
    $colsCreate  = implode(', ', $colsList);
    $colsListCsv = '[' . implode('],[', array_keys($colsDef)) . ']';

    $tmpName = "_idx_tmp_" . substr(md5($tabela . microtime()), 0, 12);

    // 3. Pegar valor maximo da coluna pra reseed depois (antes de deletar a tabela)
    $sqlMax = "SELECT MAX([$idCol]) AS m FROM [$bdDestino].DBO.[$tabela]";
    $stmtMax = sqlsrv_query($conn, $sqlMax);
    $maxVal = null;
    if ($stmtMax) {
        $r = sqlsrv_fetch_array($stmtMax, SQLSRV_FETCH_ASSOC);
        $maxVal = $r['m'] ?? null;
        sqlsrv_free_stmt($stmtMax);
    }

    // 4. Trocar contexto pro destino (IDENTITY_INSERT exige isso)
    sqlsrv_query($conn, "USE [$bdDestino]");

    // 5. Criar a tabela temporaria com IDENTITY
    if (!executarSQL($conn, "CREATE TABLE [$bdDestino].DBO.[$tmpName] ($colsCreate)", "IDENTITY: criar [$tmpName]")) {
        return false;
    }

    // 6. Copiar dados com IDENTITY_INSERT ON
    $sqlCopy = "
        SET IDENTITY_INSERT [$bdDestino].DBO.[$tmpName] ON;
        INSERT INTO [$bdDestino].DBO.[$tmpName] ($colsListCsv)
        SELECT $colsListCsv FROM [$bdDestino].DBO.[$tabela];
        SET IDENTITY_INSERT [$bdDestino].DBO.[$tmpName] OFF;
    ";
    if (!executarSQL($conn, $sqlCopy, "IDENTITY: copiar dados [$tabela]")) {
        dropIfExists($conn, "[$bdDestino].DBO.[$tmpName]");
        return false;
    }

    // 7. Dropar original e renomear temp
    if (!executarSQL($conn, "DROP TABLE [$bdDestino].DBO.[$tabela]", "IDENTITY: drop original [$tabela]")) {
        dropIfExists($conn, "[$bdDestino].DBO.[$tmpName]");
        return false;
    }
    if (!executarSQL($conn, "EXEC sp_rename '[$bdDestino].DBO.[$tmpName]', '$tabela'", "IDENTITY: rename [$tmpName] -> [$tabela]")) {
        return false;
    }

    // 8. Reseed pra o proximo INSERT comecar do max+1
    if ($maxVal !== null) {
        executarSQL($conn, "DBCC CHECKIDENT('[$bdDestino].DBO.[$tabela]', RESEED, $maxVal)", "IDENTITY: reseed [$tabela]=$maxVal");
    }

    return true;
}

// ---- Coletar colunas IDENTITY da origem ----
$sqlIdent = "SELECT t.name AS tabela, c.name AS coluna,
                    CAST(ic.seed_value AS BIGINT) AS seed,
                    CAST(ic.increment_value AS BIGINT) AS incr
             FROM [$bdOrigem].sys.identity_columns ic
             INNER JOIN [$bdOrigem].sys.tables t ON t.object_id = ic.object_id
             INNER JOIN [$bdOrigem].sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
             WHERE t.is_ms_shipped = 0
             ORDER BY t.name";
$stmtId = sqlsrv_query($conn, $sqlIdent);
$idCandidatas = [];
if ($stmtId) {
    while ($r = sqlsrv_fetch_array($stmtId, SQLSRV_FETCH_ASSOC)) {
        $idCandidatas[] = $r;
    }
    sqlsrv_free_stmt($stmtId);
}
logInfo("Tabelas com IDENTITY na origem: " . count($idCandidatas));

$idOk = 0; $idSkip = 0; $idErr = 0;
foreach ($idCandidatas as $row) {
    $tab    = $row['tabela'];
    $col    = $row['coluna'];
    $seed   = (int)$row['seed'];
    $incr   = (int)$row['incr'];

    // Tabela existe no destino?
    if (!tabelaExiste($conn, $bdDestino, $tab)) {
        $idSkip++;
        continue;
    }

    // Ja tem IDENTITY? (raro, mas seguro checar)
    $sqlChk = "SELECT is_identity FROM [$bdDestino].sys.columns
               WHERE object_id = OBJECT_ID('[$bdDestino].DBO.[$tab]') AND name = '$col'";
    $stmtChk = sqlsrv_query($conn, $sqlChk);
    $jaTem = false;
    if ($stmtChk) {
        $r = sqlsrv_fetch_array($stmtChk, SQLSRV_FETCH_ASSOC);
        $jaTem = !empty($r) && (int)$r['is_identity'] === 1;
        sqlsrv_free_stmt($stmtChk);
    }
    if ($jaTem) {
        $idSkip++;
        continue;
    }

    if (sghRecriarComIdentity($conn, $bdDestino, $tab, $col, $seed, $incr)) {
        logSuccess("[OK] IDENTITY restaurada: $tab.$col");
        $idOk++;
    } else {
        $idErr++;
    }
}

// Voltar pra master por precaucao
sqlsrv_query($conn, "USE [master]");

logSuccess("IDENTITY: $idOk restauradas, $idSkip puladas, $idErr erros (de " . count($idCandidatas) . " encontradas)");

$currentStep++;
sendProgress($currentStep, $totalSteps, 'IDENTITY restaurada');

// ===================== RESTAURAR CONSTRAINTS =====================
// SELECT INTO copia tipos mas NAO preserva: DEFAULT, CHECK, PRIMARY KEY, UNIQUE.
// Esta fase coleta tudo da origem e aplica no destino via ALTER TABLE.
// Foreign keys ficam de fora por enquanto (podem falhar em base amostral).
logInfo("");
logInfo("========================================");
logInfo("SGH: Restaurando CONSTRAINTS");
logInfo("========================================");

/**
 * Lista PRIMARY KEYS / UNIQUE CONSTRAINTS da origem com colunas concatenadas.
 * $tipo: 'PK' (primary_key) ou 'UQ' (unique_constraint).
 */
function sghListarPKouUQ($conn, string $bdOrigem, string $tipo): array
{
    $cond = ($tipo === 'PK') ? "i.is_primary_key = 1" : "i.is_unique_constraint = 1";
    $sql = "SELECT
                t.name AS tabela,
                i.name AS constraint_name,
                CASE WHEN i.type_desc = 'CLUSTERED' THEN 'CLUSTERED' ELSE 'NONCLUSTERED' END AS tipo_idx,
                STUFF((
                    SELECT ',[' + c.name + ']' + CASE WHEN ic.is_descending_key = 1 THEN ' DESC' ELSE '' END
                    FROM [$bdOrigem].sys.index_columns ic
                    INNER JOIN [$bdOrigem].sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
                    WHERE ic.object_id = i.object_id AND ic.index_id = i.index_id
                    ORDER BY ic.key_ordinal
                    FOR XML PATH('')
                ), 1, 1, '') AS cols
            FROM [$bdOrigem].sys.indexes i
            INNER JOIN [$bdOrigem].sys.tables t ON t.object_id = i.object_id
            WHERE $cond AND t.is_ms_shipped = 0
            ORDER BY t.name, i.name";
    $out = [];
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (!empty($r['cols'])) $out[] = $r;
        }
        sqlsrv_free_stmt($stmt);
    }
    return $out;
}

/**
 * Lista DEFAULT constraints da origem.
 */
function sghListarDefaults($conn, string $bdOrigem): array
{
    $sql = "SELECT
                t.name AS tabela,
                c.name AS coluna,
                dc.name AS constraint_name,
                dc.definition
            FROM [$bdOrigem].sys.default_constraints dc
            INNER JOIN [$bdOrigem].sys.tables t ON t.object_id = dc.parent_object_id
            INNER JOIN [$bdOrigem].sys.columns c ON c.object_id = dc.parent_object_id AND c.column_id = dc.parent_column_id
            WHERE t.is_ms_shipped = 0
            ORDER BY t.name, c.name";
    $out = [];
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $out[] = $r;
        sqlsrv_free_stmt($stmt);
    }
    return $out;
}

/**
 * Lista CHECK constraints da origem.
 */
function sghListarChecks($conn, string $bdOrigem): array
{
    $sql = "SELECT
                t.name AS tabela,
                cc.name AS constraint_name,
                cc.definition,
                cc.is_disabled
            FROM [$bdOrigem].sys.check_constraints cc
            INNER JOIN [$bdOrigem].sys.tables t ON t.object_id = cc.parent_object_id
            WHERE t.is_ms_shipped = 0
            ORDER BY t.name, cc.name";
    $out = [];
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $out[] = $r;
        sqlsrv_free_stmt($stmt);
    }
    return $out;
}

/**
 * Verifica se uma constraint com determinado nome ja existe no destino.
 */
function sghConstraintExiste($conn, string $bdDestino, string $nome): bool
{
    $sql = "SELECT 1 FROM [$bdDestino].sys.objects WHERE name = '$nome' AND type IN ('PK','UQ','D','C','F')";
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) return false;
    $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return !empty($r);
}

// ---- 1. PRIMARY KEYS ----
$pks = sghListarPKouUQ($conn, $bdOrigem, 'PK');
logInfo("Encontradas " . count($pks) . " PRIMARY KEYs na origem.");
$pkOk = 0; $pkErr = 0;
foreach ($pks as $r) {
    $tab = $r['tabela'];
    $name = $r['constraint_name'];
    if (!tabelaExiste($conn, $bdDestino, $tab)) continue;
    if (sghConstraintExiste($conn, $bdDestino, $name)) { $pkOk++; continue; }
    $sql = "ALTER TABLE [$bdDestino].DBO.[$tab] ADD CONSTRAINT [$name] PRIMARY KEY {$r['tipo_idx']} ({$r['cols']})";
    if (executarSQL($conn, $sql, "PK [$tab].[$name]")) $pkOk++; else $pkErr++;
}

// ---- 2. UNIQUE CONSTRAINTS ----
$uqs = sghListarPKouUQ($conn, $bdOrigem, 'UQ');
logInfo("Encontradas " . count($uqs) . " UNIQUE constraints na origem.");
$uqOk = 0; $uqErr = 0;
foreach ($uqs as $r) {
    $tab = $r['tabela'];
    $name = $r['constraint_name'];
    if (!tabelaExiste($conn, $bdDestino, $tab)) continue;
    if (sghConstraintExiste($conn, $bdDestino, $name)) { $uqOk++; continue; }
    $sql = "ALTER TABLE [$bdDestino].DBO.[$tab] ADD CONSTRAINT [$name] UNIQUE {$r['tipo_idx']} ({$r['cols']})";
    if (executarSQL($conn, $sql, "UQ [$tab].[$name]")) $uqOk++; else $uqErr++;
}

// ---- 3. DEFAULTS ----
$dfs = sghListarDefaults($conn, $bdOrigem);
logInfo("Encontrados " . count($dfs) . " DEFAULTs na origem.");
$dfOk = 0; $dfErr = 0;
foreach ($dfs as $r) {
    $tab = $r['tabela'];
    $col = $r['coluna'];
    $name = $r['constraint_name'];
    if (!tabelaExiste($conn, $bdDestino, $tab)) continue;
    if (sghConstraintExiste($conn, $bdDestino, $name)) { $dfOk++; continue; }
    // dc.definition ja vem com parenteses externos
    $sql = "ALTER TABLE [$bdDestino].DBO.[$tab] ADD CONSTRAINT [$name] DEFAULT {$r['definition']} FOR [$col]";
    if (executarSQL($conn, $sql, "DEFAULT [$tab].[$name]")) $dfOk++; else $dfErr++;
}

// ---- 4. CHECKS ----
$cks = sghListarChecks($conn, $bdOrigem);
logInfo("Encontrados " . count($cks) . " CHECKs na origem.");
$ckOk = 0; $ckErr = 0;
foreach ($cks as $r) {
    $tab = $r['tabela'];
    $name = $r['constraint_name'];
    $isDisabled = (int)$r['is_disabled'] === 1;
    if (!tabelaExiste($conn, $bdDestino, $tab)) continue;
    if (sghConstraintExiste($conn, $bdDestino, $name)) { $ckOk++; continue; }
    // WITH NOCHECK pra nao validar registros amostrais que possam violar a regra
    $sql = "ALTER TABLE [$bdDestino].DBO.[$tab] WITH NOCHECK ADD CONSTRAINT [$name] CHECK {$r['definition']}";
    if (executarSQL($conn, $sql, "CHECK [$tab].[$name]")) {
        $ckOk++;
        // Se estava desabilitada na origem, desabilita no destino tambem
        if ($isDisabled) {
            executarSQL($conn, "ALTER TABLE [$bdDestino].DBO.[$tab] NOCHECK CONSTRAINT [$name]", "CHECK desabilitar [$name]");
        }
    } else {
        $ckErr++;
    }
}

logSuccess("Constraints: PK $pkOk/$pkErr | UQ $uqOk/$uqErr | DEFAULT $dfOk/$dfErr | CHECK $ckOk/$ckErr (ok/erro)");

$currentStep++;
sendProgress($currentStep, $totalSteps, 'Constraints restauradas');

// ===================== INDICES SGH =====================
logInfo("");
logInfo("========================================");
logInfo("SGH: Recriando Indices da Origem");
logInfo("========================================");

// Lista os indices nao-PK da origem com informacoes completas:
// - Colunas chave (is_included_column = 0)
// - Colunas INCLUDE (is_included_column = 1)
// - filter_definition (filtered indexes)
$sqlIdx = "
SELECT
    t.name AS tabela,
    i.name AS idx_nome,
    i.is_unique,
    i.type_desc,
    i.filter_definition,
    STUFF((
        SELECT ',[' + c.name + ']' + CASE WHEN ic.is_descending_key = 1 THEN ' DESC' ELSE '' END
        FROM [$bdOrigem].sys.index_columns ic
        INNER JOIN [$bdOrigem].sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
        WHERE ic.object_id = i.object_id AND ic.index_id = i.index_id AND ic.is_included_column = 0
        ORDER BY ic.key_ordinal
        FOR XML PATH('')
    ), 1, 1, '') AS colunas_chave,
    STUFF((
        SELECT ',[' + c.name + ']'
        FROM [$bdOrigem].sys.index_columns ic
        INNER JOIN [$bdOrigem].sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
        WHERE ic.object_id = i.object_id AND ic.index_id = i.index_id AND ic.is_included_column = 1
        ORDER BY ic.index_column_id
        FOR XML PATH('')
    ), 1, 1, '') AS colunas_include
FROM [$bdOrigem].sys.indexes i
INNER JOIN [$bdOrigem].sys.tables t ON i.object_id = t.object_id
WHERE i.type IN (1,2) AND i.is_primary_key = 0 AND i.is_unique_constraint = 0 AND i.name IS NOT NULL
ORDER BY t.name, i.name
";
$stmtIdx = sqlsrv_query($conn, $sqlIdx);
$idxTotal = 0;
$idxOk = 0;
$idxErro = 0;

// Tabelas existentes no destino (atualizar)
$tabelasDestinoFinal = getAllUserTables($conn, $bdDestino);
$tabelasDestinoFinalMap = array_flip(array_map('strtolower', $tabelasDestinoFinal));

// Coletar todos primeiro pra saber o total real e dar feedback de progresso
$indicesAplicar = [];
if ($stmtIdx) {
    while ($row = sqlsrv_fetch_array($stmtIdx, SQLSRV_FETCH_ASSOC)) {
        if (!isset($tabelasDestinoFinalMap[strtolower($row['tabela'])])) continue;
        if (empty($row['colunas_chave'])) continue; // sem colunas chave nao da pra recriar
        $indicesAplicar[] = $row;
    }
    sqlsrv_free_stmt($stmtIdx);
}
$idxTotal = count($indicesAplicar);
logInfo("Indices a recriar: $idxTotal (filtrados pelas tabelas presentes no destino)");

// Coletar erros de cada indice falhado para listar de forma agrupada no final
$idxErrosDetalhados = [];

$tIdxStart = microtime(true);
$idxNum = 0;
foreach ($indicesAplicar as $row) {
    $idxNum++;
    $tab = $row['tabela'];
    $nomeIdx = $row['idx_nome'];
    $unique = $row['is_unique'] ? 'UNIQUE ' : '';
    $clustered = (strpos($row['type_desc'], 'CLUSTERED') !== false && strpos($row['type_desc'], 'NONCLUSTERED') === false) ? 'CLUSTERED ' : 'NONCLUSTERED ';
    $colsKey = $row['colunas_chave'];                             // ja vem "[col1] ASC,[col2] DESC"
    $colsInc = $row['colunas_include'] ?? '';
    $filterDef = $row['filter_definition'] ?? '';

    $includeClause = !empty($colsInc) ? " INCLUDE ($colsInc)" : '';
    $whereClause   = !empty($filterDef) ? " WHERE $filterDef" : '';

    $sqlCreate = "IF NOT EXISTS (SELECT 1 FROM [$bdDestino].sys.indexes
                                 WHERE name = '$nomeIdx'
                                   AND object_id = OBJECT_ID('[$bdDestino].DBO.[$tab]'))
                  CREATE {$unique}{$clustered}INDEX [$nomeIdx] ON [$bdDestino].DBO.[$tab] ($colsKey){$includeClause}{$whereClause}";

    // Executar capturando erro real (sem usar executarSQL que loga e sobrecarrega o log)
    $tQuery = microtime(true);
    $stmtCreate = sqlsrv_query($conn, $sqlCreate, [], ['QueryTimeout' => 0]);
    if ($stmtCreate === false) {
        $errs = sqlsrv_errors();
        $msg = $errs ? trim($errs[0]['message']) : 'erro desconhecido';
        // Limpar prefixo padrao do driver "[Microsoft][SQL Server]..."
        $msg = preg_replace('/^\[[^\]]+\]\s*\[[^\]]+\]\s*\[[^\]]+\]\s*/', '', $msg);
        $idxErrosDetalhados[] = ['tabela' => $tab, 'indice' => $nomeIdx, 'unique' => !empty($unique), 'msg' => $msg];
        $idxErro++;
    } else {
        while (sqlsrv_next_result($stmtCreate)) {}
        sqlsrv_free_stmt($stmtCreate);
        $dt = microtime(true) - $tQuery;
        if ($dt >= 5.0) {
            logInfo("  ... [Indice $nomeIdx em $tab] levou " . number_format($dt, 1) . "s");
        }
        $idxOk++;
    }

    // Heartbeat a cada 25 indices para o usuario nao achar que travou
    if ($idxNum % 25 === 0 || $idxNum === $idxTotal) {
        $dt = microtime(true) - $tIdxStart;
        sendProgress($currentStep, $totalSteps, "Indices: $idxNum/$idxTotal (" . number_format($dt, 0) . "s)");
        logInfo("  ... indices: $idxNum/$idxTotal processados (ok=$idxOk, erro=$idxErro, tempo=" . number_format($dt, 0) . "s)");
    }
}

logSuccess("Indices: $idxOk criados, $idxErro erros (de $idxTotal encontrados na origem)");

// ---- Listagem detalhada dos erros de indice ----
if (!empty($idxErrosDetalhados)) {
    logWarn("");
    logWarn("=== INDICES QUE FALHARAM (" . count($idxErrosDetalhados) . ") ===");
    foreach ($idxErrosDetalhados as $e) {
        $tag = $e['unique'] ? '[UNIQUE]' : '[       ]';
        logWarn("  $tag {$e['tabela']}.{$e['indice']}");
        logWarn("    > " . substr($e['msg'], 0, 250));
    }
    // Detectar quantos sao por duplicata
    $dupCount = 0;
    foreach ($idxErrosDetalhados as $e) {
        if (stripos($e['msg'], 'duplicate') !== false || stripos($e['msg'], 'duplica') !== false) $dupCount++;
    }
    if ($dupCount > 0) {
        logWarn("");
        logWarn("$dupCount indice(s) falharam por duplicate key. Sao indices UNIQUE da origem cujos dados na amostra contem duplicatas.");
        logWarn("Solucoes: (a) ajustar a amostra para nao incluir duplicatas, (b) recriar manualmente como nao-unique se aceitavel, ou (c) ignorar.");
    }
}

$currentStep++;
sendProgress($currentStep, $totalSteps, 'Indices concluidos');

// ===================== VIEWS E PROCEDURES =====================
logInfo("");
logInfo("========================================");
logInfo("SGH: Views e Procedures da origem");
logInfo("========================================");

/**
 * Lista views OU procedures da origem com sua definicao.
 * $tipoObj = 'V' (view) ou 'P' (procedure).
 */
function sghListarObjetos($conn, string $bdOrigem, string $tipoObj): array
{
    $catSys = ($tipoObj === 'V') ? 'views' : 'procedures';
    $sql = "SELECT s.name AS schemaName, o.name AS objName, m.definition
            FROM [$bdOrigem].sys.$catSys o
            INNER JOIN [$bdOrigem].sys.schemas s ON s.schema_id = o.schema_id
            INNER JOIN [$bdOrigem].sys.sql_modules m ON m.object_id = o.object_id
            WHERE o.is_ms_shipped = 0
            ORDER BY o.name";
    $out = [];
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // definition pode vir como stream em sqlsrv (nvarchar(max))
            $def = $row['definition'];
            if (is_resource($def)) {
                $def = stream_get_contents($def);
            }
            if ($def !== null && trim($def) !== '') {
                $row['definition'] = $def;
                $out[] = $row;
            }
        }
        sqlsrv_free_stmt($stmt);
    }
    return $out;
}

/**
 * Tenta criar uma lista de objetos (views/procs) no destino.
 * Faz multiplas passadas para resolver dependencias entre eles.
 * Retorna [okCount, errCount, listaErros].
 */
function sghCriarObjetos($conn, string $bdOrigem, string $bdDestino, array $objetos, string $rotulo): array
{
    if (empty($objetos)) return [0, 0, []];

    $tipoObj = (stripos($rotulo, 'view') !== false) ? 'V' : 'P';

    // Trocar contexto para o banco destino (necessario porque CREATE VIEW/PROCEDURE
    // tem que ser o primeiro statement do batch)
    sqlsrv_query($conn, "USE [$bdDestino]");

    $pendentes = $objetos;
    $okCount = 0;
    $errosFinais = [];

    for ($passada = 1; $passada <= 3 && !empty($pendentes); $passada++) {
        $novosPendentes = [];
        $okNestaPassada = 0;

        foreach ($pendentes as $o) {
            $schema = $o['schemaName'];
            $nome   = $o['objName'];

            // Substituir referencias literais ao banco origem -> destino na definicao
            // (cobre [origem]., [origem]..tabela, ORIGEM. sem colchetes)
            $def = $o['definition'];
            $def = str_ireplace(["[$bdOrigem].", "[$bdOrigem]..", "$bdOrigem."], ["[$bdDestino].", "[$bdDestino]..", "$bdDestino."], $def);

            // DROP se existe
            $tipoChar = ($tipoObj === 'V') ? 'V' : 'P';
            sqlsrv_query($conn, "USE [$bdDestino]");
            $sqlDrop = ($tipoObj === 'V')
                ? "IF OBJECT_ID('[$schema].[$nome]', 'V') IS NOT NULL DROP VIEW [$schema].[$nome]"
                : "IF OBJECT_ID('[$schema].[$nome]', 'P') IS NOT NULL DROP PROCEDURE [$schema].[$nome]";
            sqlsrv_query($conn, $sqlDrop);

            // Criar (a definicao ja comeca com CREATE VIEW/PROCEDURE)
            sqlsrv_query($conn, "USE [$bdDestino]");
            $stmtCreate = sqlsrv_query($conn, $def);
            if ($stmtCreate === false) {
                $errs = sqlsrv_errors();
                $msg = $errs ? $errs[0]['message'] : 'erro desconhecido';
                if ($passada < 3) {
                    // Pode ser dependencia nao resolvida -> tenta na proxima passada
                    $novosPendentes[] = $o;
                } else {
                    // Ultima passada: registra erro
                    $errosFinais[] = ['nome' => "[$schema].[$nome]", 'msg' => $msg];
                }
            } else {
                sqlsrv_free_stmt($stmtCreate);
                $okCount++;
                $okNestaPassada++;
            }
        }
        if ($passada > 1) {
            logInfo("  $rotulo: passada $passada finalizou $okNestaPassada com sucesso, " . count($novosPendentes) . " ainda pendentes.");
        }
        $pendentes = $novosPendentes;
    }

    return [$okCount, count($errosFinais), $errosFinais];
}

// === Views ===
$views = sghListarObjetos($conn, $bdOrigem, 'V');
logInfo("Encontradas " . count($views) . " views na origem.");

list($vOk, $vErr, $errosV) = sghCriarObjetos($conn, $bdOrigem, $bdDestino, $views, 'View');
logSuccess("Views: $vOk criadas, $vErr erros (de " . count($views) . " encontradas)");
foreach ($errosV as $e) {
    logWarn("  [VIEW falhou] {$e['nome']}: " . substr($e['msg'], 0, 150));
}

// === Procedures ===
$procs = sghListarObjetos($conn, $bdOrigem, 'P');
logInfo("Encontradas " . count($procs) . " procedures na origem.");

list($pOk, $pErr, $errosP) = sghCriarObjetos($conn, $bdOrigem, $bdDestino, $procs, 'Procedure');
logSuccess("Procedures: $pOk criadas, $pErr erros (de " . count($procs) . " encontradas)");
foreach ($errosP as $e) {
    logWarn("  [PROC falhou] {$e['nome']}: " . substr($e['msg'], 0, 150));
}

// Voltar contexto pra master (precaucao para operacoes seguintes)
sqlsrv_query($conn, "USE [master]");

$currentStep++;
sendProgress($currentStep, $totalSteps, 'Views e Procedures concluidos');

} // fim if tipo === 'cliente' / else SGH

// ===================== RESUMO FINAL =====================
logInfo("");
logInfo("========================================");
logInfo("PROCESSAMENTO CONCLUIDO");
logInfo("========================================");

// Listar todas as tabelas criadas no destino
$sqlTables = "SELECT TABLE_NAME FROM [$bdDestino].INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME";
$stmtTables = sqlsrv_query($conn, $sqlTables);
$tabelas = [];
if ($stmtTables) {
    while ($row = sqlsrv_fetch_array($stmtTables, SQLSRV_FETCH_ASSOC)) {
        $tabelas[] = $row['TABLE_NAME'];
    }
    sqlsrv_free_stmt($stmtTables);
}

logSuccess("Total de tabelas em [$bdDestino]: " . count($tabelas));

// Em modo RETOMADA, pular contagens detalhadas e relatorio (dados nao mudaram).
if ($onlyFinalizers) {
    logInfo("Modo retomada: pulando contagem por tabela e geracao de relatorio (dados nao foram alterados).");
    sqlsrv_close($conn);
    sendEvent('done', [
        'success'      => true,
        'totalTabelas' => count($tabelas),
        'banco'        => $bdDestino,
        'hasReport'    => false,
        'modo'         => 'retomada',
    ]);
    exit;
}

// ===================== COLETA DE DADOS PARA O RELATORIO =====================
// Filtrar tabelas para o relatorio:
// - Modo SGH: apenas tabelas cujo nome comece com "MTTB" (case-insensitive)
// - Modo Cliente: todas as tabelas
$tabelasRelatorio = [];
foreach ($tabelas as $t) {
    if ($tipo === 'sgh' && stripos($t, 'MTTB') !== 0) {
        // No modo SGH, s puramente tabelas MTTB entram no relatorio (CON_FIDC e outras de controle ficam de fora)
        continue;
    }
    if (strtoupper($t) === 'CON_FIDC') continue; // tabela de controle
    $tabelasRelatorio[] = $t;
}

// Contar registros de cada tabela
$reportTabelas = [];
foreach ($tabelasRelatorio as $t) {
    $count = contarRegistros($conn, "[$bdDestino].DBO.[$t]");
    $reportTabelas[$t] = [
        'nome'      => $t,
        'registros' => $count,
    ];
    logInfo("  -> $t: $count registros");
}

// Contratos por REGIAO (a partir de CON_FIDC do destino)
$reportContratosRegiao = [];
$sqlRegioes = "SELECT REGIAO, COUNT(*) AS total FROM [$bdDestino].DBO.CON_FIDC GROUP BY REGIAO ORDER BY REGIAO";
$stmtReg = sqlsrv_query($conn, $sqlRegioes);
if ($stmtReg) {
    while ($row = sqlsrv_fetch_array($stmtReg, SQLSRV_FETCH_ASSOC)) {
        $reportContratosRegiao[] = [
            'regiao' => (string)$row['REGIAO'],
            'total'  => (int)$row['total'],
        ];
    }
    sqlsrv_free_stmt($stmtReg);
}

// Contratos por CTR_FLG_ATIV (situacao) - sempre lido de MTTBCON da origem com EXISTS em CON_FIDC.
// No modo Cliente a coluna CTR_FLG_ATIV nao vai para o destino; no SGH vai mas ler da origem funciona em ambos.
$reportSituacoes = [];
$sqlSit = "SELECT C.CTR_FLG_ATIV AS flg, COUNT(*) AS total
           FROM [$bdOrigem].DBO.MTTBCON C
           WHERE EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X
                         WHERE X.CODEMP = C.CODEMP AND X.REGIAO = C.REGIAO
                           AND X.NUCLEO = C.NUCLEO AND X.CONTRATO = C.CONTRATO)
           GROUP BY C.CTR_FLG_ATIV
           ORDER BY C.CTR_FLG_ATIV";
$stmtSit = sqlsrv_query($conn, $sqlSit);
if ($stmtSit) {
    while ($row = sqlsrv_fetch_array($stmtSit, SQLSRV_FETCH_ASSOC)) {
        $reportSituacoes[] = [
            'flg'   => $row['flg'] === null ? null : (int)$row['flg'],
            'total' => (int)$row['total'],
        ];
    }
    sqlsrv_free_stmt($stmtSit);
} else {
    $errors = sqlsrv_errors();
    $msg = $errors ? $errors[0]['message'] : 'erro desconhecido';
    logWarn("Nao foi possivel calcular distribuicao por CTR_FLG_ATIV: $msg");
}

// Montar JSON do relatorio
$reportData = [
    'data_execucao'      => date('d/m/Y H:i:s'),
    'servidor'           => $servidor,
    'bd_origem'          => $bdOrigem,
    'bd_destino'         => $bdDestino,
    'codemp'             => $codempList,
    'codemps'            => $codemps,
    'tipo'               => $tipo,
    'modo'               => 'completo',
    'total_tabelas'      => count($reportTabelas),
    'tabelas'            => $reportTabelas,
    'contratos_regiao'   => $reportContratosRegiao,
    'contratos_situacao' => $reportSituacoes,
    'erros'              => [],
];

$reportFile = sys_get_temp_dir() . '/report_' . $bdDestino . '.json';
if (@file_put_contents($reportFile, json_encode($reportData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
    logWarn("Nao foi possivel gravar arquivo de relatorio em: $reportFile");
} else {
    logSuccess("Relatorio gerado: $reportFile");
}

sqlsrv_close($conn);
sendEvent('done', [
    'success'      => true,
    'totalTabelas' => count($tabelas),
    'banco'        => $bdDestino,
    'hasReport'    => file_exists($reportFile),
]);