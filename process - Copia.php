<?php
/**
 * process.php - Processamento da Base Amostral
 * Conecta ao SQL Server e executa todas as fases de extracao
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

ob_implicit_flush(true);
if (ob_get_level()) ob_end_clean();

require_once __DIR__ . '/sql_scripts.php';

// ===================== FUNCOES DE SAIDA =====================
function sendEvent(string $type, array $data): void
{
    echo "data: " . json_encode(array_merge(['type' => $type], $data), JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
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
$servidor    = trim($_POST['servidor'] ?? '');
$login       = trim($_POST['login'] ?? '');
$senha       = $_POST['senha'] ?? '';
$codemp      = intval($_POST['codemp'] ?? 0);
$bdOrigem    = trim($_POST['bd_origem'] ?? '');
$bdDestino   = trim($_POST['bd_destino'] ?? '');
$dropDestino = ($_POST['drop_destino'] ?? '0') === '1';
$tipo        = trim($_POST['tipo'] ?? 'cliente');

logInfo("Modo de processamento: " . strtoupper($tipo));

if (!$servidor || !$login || !$bdOrigem || !$bdDestino || $codemp <= 0) {
    logError('Parametros obrigatorios nao informados.');
    sendEvent('done', ['success' => false]);
    exit;
}

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

$contratos = [];
foreach ($linhas as $idx => $linha) {
    $linha = trim($linha);
    if (empty($linha)) continue;
    
    // Se a primeira linha parece ser cabecalho, pular
    if ($idx === 0 && stripos($linha, 'REGIAO') !== false) {
        continue;
    }
    
    $partes = explode(';', $linha);
    if (count($partes) < 3) {
        logWarn("Linha " . ($idx + 1) . " ignorada (formato invalido): $linha");
        continue;
    }
    
    // Limpar caracteres invisiveis (BOM residual, zero-width spaces, etc.)
    $regiao   = preg_replace('/[^\x20-\x7E]/', '', trim($partes[0]));
    $nucleo   = preg_replace('/[^\x20-\x7E]/', '', trim($partes[1]));
    $contrato = preg_replace('/[^\x20-\x7E]/', '', trim($partes[2]));
    
    if (empty($regiao) || empty($nucleo) || empty($contrato)) {
        logWarn("Linha " . ($idx + 1) . " ignorada (valores vazios apos limpeza)");
        continue;
    }
    
    $contratos[] = [
        'regiao'   => $regiao,
        'nucleo'   => $nucleo,
        'contrato' => $contrato
    ];
}

if (empty($contratos)) {
    logError('Nenhum contrato valido encontrado no arquivo.');
    sendEvent('done', ['success' => false]);
    exit;
}

logSuccess("Arquivo lido: " . count($contratos) . " contratos encontrados.");
if (!empty($contratos)) {
    $c = $contratos[0];
    logInfo("Primeiro contrato: REGIAO=[{$c['regiao']}] NUCLEO=[{$c['nucleo']}] CONTRATO=[{$c['contrato']}]");
}

// ===================== CONEXAO SQL SERVER =====================
logInfo("Conectando ao SQL Server: $servidor ...");

$connInfo = [
    "Database"             => "master",
    "UID"                  => $login,
    "PWD"                  => $senha,
    "TrustServerCertificate" => true,
    "LoginTimeout"         => 30,
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

logSuccess("Conectado ao SQL Server.");

// ===================== FUNCOES SQL =====================
function executarSQL($conn, string $sql, string $descricao): bool
{
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        $msg = $errors ? $errors[0]['message'] : 'Erro desconhecido';
        logError("ERRO em [$descricao]: $msg");
        return false;
    }
    // Consumir resultados para liberar o statement
    while (sqlsrv_next_result($stmt)) {}
    sqlsrv_free_stmt($stmt);
    return true;
}

function dropIfExists($conn, string $fullTableName): void
{
    $sql = "IF OBJECT_ID('$fullTableName', 'U') IS NOT NULL DROP TABLE $fullTableName";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt) { while (sqlsrv_next_result($stmt)) {} sqlsrv_free_stmt($stmt); }
}

function executarSelectInto($conn, string $sql, string $descricao, string $bdDestino, string $tabelaDestino): bool
{
    // Dropar tabela se ja existir (permite reexecucao)
    dropIfExists($conn, "[$bdDestino].DBO.[$tabelaDestino]");
    return executarSQL($conn, $sql, $descricao);
}

function substituirPlaceholders(string $sql, string $orig, string $dest, int $codemp): string
{
    $sql = str_replace('{ORIG}', $orig, $sql);
    $sql = str_replace('{DEST}', $dest, $sql);
    $sql = str_replace('{CODEMP}', (string)$codemp, $sql);
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
        logWarn("Excluindo banco [$bdDestino]...");
        
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
        logSuccess("Banco [$bdDestino] excluido com sucesso.");
        
        // Criar banco novo
        if (!executarSQL($conn, "CREATE DATABASE [$bdDestino]", "Criar banco $bdDestino")) {
            sendEvent('done', ['success' => false]);
            exit;
        }
        logSuccess("Banco [$bdDestino] recriado.");
    } else {
        logWarn("Banco [$bdDestino] ja existe. Sera reutilizado (tabelas serao sobrescritas).");
    }
} else {
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

$sqlCreateConFidc = "
    IF OBJECT_ID('$bdDestino.DBO.CON_FIDC', 'U') IS NOT NULL 
        DROP TABLE [$bdDestino].DBO.CON_FIDC;
    CREATE TABLE [$bdDestino].DBO.CON_FIDC (
        CODEMP   INT,
        REGIAO   VARCHAR(10),
        NUCLEO   VARCHAR(10),
        CONTRATO VARCHAR(20),
        IMOVEL_UNICO INT NULL
    )
";
if (!executarSQL($conn, $sqlCreateConFidc, "Criar CON_FIDC")) {
    sendEvent('done', ['success' => false]);
    exit;
}

// Inserir contratos em lotes
$batchSize = 500;
$batches = array_chunk($contratos, $batchSize);
$totalInseridos = 0;

foreach ($batches as $batch) {
    $values = [];
    foreach ($batch as $c) {
        $regiao   = str_replace("'", "''", $c['regiao']);
        $nucleo   = str_replace("'", "''", $c['nucleo']);
        $contrato = str_replace("'", "''", $c['contrato']);
        $values[] = "($codemp, '$regiao', '$nucleo', '$contrato', NULL)";
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

// ===================== BRANCHING: CLIENTE vs SGH =====================
if ($tipo === 'cliente') {

// ===================== FASE 1: TABELAS DE REFERENCIA =====================
logInfo("");
logInfo("========================================");
logInfo("FASE 1: Tabelas de Referencia (por CODEMP)");
logInfo("========================================");

$tabelasRef = getPhase1_ReferenceTables();
foreach ($tabelasRef as $tab) {
    $sql = substituirPlaceholders($tab['sql'], $bdOrigem, $bdDestino, $codemp);
    $ok = executarSelectInto($conn, $sql, $tab['nome'], $bdDestino, $tab['destino']);
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
    $sql = substituirPlaceholders($tab['sql'], $bdOrigem, $bdDestino, $codemp);
    $ok = executarSelectInto($conn, $sql, $tab['nome'], $bdDestino, $tab['destino']);
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
    $sql = substituirPlaceholders($tab['sql'], $bdOrigem, $bdDestino, $codemp);
    $ok = executarSelectInto($conn, $sql, $tab['nome'], $bdDestino, $tab['destino']);
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
    $sql = substituirPlaceholders($tab['sql'], $bdOrigem, $bdDestino, $codemp);
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
    $sql = substituirPlaceholders($tab['sql'], $bdOrigem, $bdDestino, $codemp);
    $ok = executarSelectInto($conn, $sql, $tab['nome'], $bdDestino, $tab['destino']);
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

$se1Steps = getPhase5_SE1_FichaSocioEconomica();
foreach ($se1Steps as $tab) {
    $sql = substituirPlaceholders($tab['sql'], $bdOrigem, $bdDestino, $codemp);
    $ok = executarSQL($conn, $sql, $tab['nome']);
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

$depSe2Steps = getPhase6_DEP_SE2();
foreach ($depSe2Steps as $tab) {
    $sql = substituirPlaceholders($tab['sql'], $bdOrigem, $bdDestino, $codemp);
    $ok = executarSQL($conn, $sql, $tab['nome']);
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
    foreach ($camposCon as $campo) {
        if (!in_array($campo, $colsCon)) continue; // defensivo: coluna pode nao existir
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
        foreach ($camposHis as $campo) {
            if (!in_array($campo, $colsHis)) continue;
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
    foreach ($campos as $campo) {
        if (!in_array($campo, $colsCon)) continue;
        $sql = "INSERT INTO [$bdDestino].DBO._sgh_temp_codadq
                SELECT DISTINCT c.[$campo] FROM [$bdOrigem].DBO.mttbcon c
                WHERE c.[$campo] IS NOT NULL AND c.[$campo] <> 0
                  AND EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X
                              WHERE X.CODEMP = c.CODEMP AND X.REGIAO = c.REGIAO
                                AND X.NUCLEO = c.NUCLEO AND X.CONTRATO = c.CONTRATO)";
        executarSQL($conn, $sql, "SGH: coletar $campo (mttbcon)");
    }

    executarSQL($conn, "CREATE TABLE [$bdDestino].DBO._sgh_temp_codadq_final (codadq INT)", "SGH: criar _sgh_temp_codadq_final");
    return executarSQL($conn, "INSERT INTO [$bdDestino].DBO._sgh_temp_codadq_final
                                SELECT DISTINCT t.codadq FROM [$bdDestino].DBO._sgh_temp_codadq t
                                WHERE t.codadq IS NOT NULL AND t.codadq <> 0", "SGH: distinct COD_ADQ");
}

/**
 * Processa mttbse1 no modo SGH: filtra por CGCCPF relacionado aos contratos.
 */
function sghProcessarSE1($conn, string $bdOrigem, string $bdDestino, int $codemp, bool $tabelaExiste): bool
{
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.mttbse1 WHERE CODEMP = $codemp", "SGH: DELETE mttbse1 CODEMP=$codemp");
        $sql = "INSERT INTO [$bdDestino].DBO.mttbse1
                SELECT s.* FROM [$bdOrigem].DBO.mttbse1 s
                INNER JOIN [$bdDestino].DBO._sgh_temp_cpf_final t ON t.cpf = s.CGCCPF
                WHERE s.CODEMP = $codemp";
        return executarSQL($conn, $sql, "SGH: INSERT mttbse1 (por CPF)");
    } else {
        $sql = "SELECT s.* INTO [$bdDestino].DBO.mttbse1
                FROM [$bdOrigem].DBO.mttbse1 s
                INNER JOIN [$bdDestino].DBO._sgh_temp_cpf_final t ON t.cpf = s.CGCCPF
                WHERE s.CODEMP = $codemp";
        return executarSQL($conn, $sql, "SGH: SELECT INTO mttbse1 (por CPF)");
    }
}

/**
 * Processa mttbdep no modo SGH: filtra por CLIENTE_UNIC relacionado aos codigos adquirentes.
 */
function sghProcessarDEP($conn, string $bdOrigem, string $bdDestino, int $codemp, bool $tabelaExiste): bool
{
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.mttbdep WHERE CODEMP = $codemp", "SGH: DELETE mttbdep CODEMP=$codemp");
        $sql = "INSERT INTO [$bdDestino].DBO.mttbdep
                SELECT s.* FROM [$bdOrigem].DBO.mttbdep s
                INNER JOIN [$bdDestino].DBO._sgh_temp_codadq_final t ON t.codadq = s.cliente_unic
                WHERE s.CODEMP = $codemp AND t.codadq <> 0";
        return executarSQL($conn, $sql, "SGH: INSERT mttbdep (por COD_ADQ)");
    } else {
        $sql = "SELECT s.* INTO [$bdDestino].DBO.mttbdep
                FROM [$bdOrigem].DBO.mttbdep s
                INNER JOIN [$bdDestino].DBO._sgh_temp_codadq_final t ON t.codadq = s.cliente_unic
                WHERE s.CODEMP = $codemp AND t.codadq <> 0";
        return executarSQL($conn, $sql, "SGH: SELECT INTO mttbdep (por COD_ADQ)");
    }
}

/**
 * Processa mttbse2 no modo SGH: filtra por CLIENTE_UNIC relacionado aos codigos adquirentes.
 */
function sghProcessarSE2($conn, string $bdOrigem, string $bdDestino, int $codemp, bool $tabelaExiste): bool
{
    if ($tabelaExiste) {
        executarSQL($conn, "DELETE FROM [$bdDestino].DBO.mttbse2 WHERE CODEMP = $codemp", "SGH: DELETE mttbse2 CODEMP=$codemp");
        $sql = "INSERT INTO [$bdDestino].DBO.mttbse2
                SELECT s.* FROM [$bdOrigem].DBO.mttbse2 s
                INNER JOIN [$bdDestino].DBO._sgh_temp_codadq_final t ON t.codadq = s.cliente_unic
                WHERE s.CODEMP = $codemp";
        return executarSQL($conn, $sql, "SGH: INSERT mttbse2 (por COD_ADQ)");
    } else {
        $sql = "SELECT s.* INTO [$bdDestino].DBO.mttbse2
                FROM [$bdOrigem].DBO.mttbse2 s
                INNER JOIN [$bdDestino].DBO._sgh_temp_codadq_final t ON t.codadq = s.cliente_unic
                WHERE s.CODEMP = $codemp";
        return executarSQL($conn, $sql, "SGH: SELECT INTO mttbse2 (por COD_ADQ)");
    }
}

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

foreach ($tabelasOrigem as $tabela) {
    // Pular CON_FIDC (ja criada)
    if (strtoupper($tabela) === 'CON_FIDC') {
        $currentStep++;
        sendProgress($currentStep, $totalSteps, "Pulando: $tabela (controle)");
        continue;
    }

    // Pular temps internas (se aparecerem por algum motivo)
    if (stripos($tabela, '_sgh_temp') === 0) {
        $currentStep++;
        continue;
    }

    $tabelaLower = strtolower($tabela);
    $tabelaExiste = isset($tabelasDestinoMap[$tabelaLower]);

    // ---------- CASO ESPECIAL 1: mttbse1 (filtro por CPF) ----------
    if ($tabelaLower === 'mttbse1') {
        if ($tempCPFok) {
            $ok = sghProcessarSE1($conn, $bdOrigem, $bdDestino, $codemp, $tabelaExiste);
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
            $ok = sghProcessarDEP($conn, $bdOrigem, $bdDestino, $codemp, $tabelaExiste);
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
            $ok = sghProcessarSE2($conn, $bdOrigem, $bdDestino, $codemp, $tabelaExiste);
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
            // Tabela ja existe: DELETE da empresa + INSERT
            $sqlDel = "DELETE FROM [$bdDestino].DBO.[$tabela] WHERE CODEMP = $codemp";
            executarSQL($conn, $sqlDel, "DELETE CODEMP=$codemp de $tabela");
            
            $sqlIns = "INSERT INTO [$bdDestino].DBO.[$tabela] 
                       SELECT Y.* FROM [$bdOrigem].DBO.[$tabela] Y 
                       WHERE Y.CODEMP = $codemp 
                         AND EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X 
                                     WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO 
                                       AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)";
            $ok = executarSQL($conn, $sqlIns, "INSERT $tabela (contrato)");
            $countInsert++;
        } else {
            // Tabela nao existe: SELECT INTO
            $sql = "SELECT Y.* INTO [$bdDestino].DBO.[$tabela] 
                    FROM [$bdOrigem].DBO.[$tabela] Y 
                    WHERE Y.CODEMP = $codemp 
                      AND EXISTS (SELECT 1 FROM [$bdDestino].DBO.CON_FIDC X 
                                  WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO 
                                    AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)";
            $ok = executarSQL($conn, $sql, "SELECT INTO $tabela (contrato)");
            $countSelectInto++;
        }
        
    } elseif ($hasCODEMP) {
        // CASO 2: Tem CODEMP mas nao tem chave completa => leva tudo da empresa
        $countCodemp++;
        
        if ($tabelaExiste) {
            // Tabela ja existe: DELETE da empresa + INSERT
            $sqlDel = "DELETE FROM [$bdDestino].DBO.[$tabela] WHERE CODEMP = $codemp";
            executarSQL($conn, $sqlDel, "DELETE CODEMP=$codemp de $tabela");
            
            $sqlIns = "INSERT INTO [$bdDestino].DBO.[$tabela] 
                       SELECT * FROM [$bdOrigem].DBO.[$tabela] WHERE CODEMP = $codemp";
            $ok = executarSQL($conn, $sqlIns, "INSERT $tabela (codemp)");
            $countInsert++;
        } else {
            $sql = "SELECT * INTO [$bdDestino].DBO.[$tabela] 
                    FROM [$bdOrigem].DBO.[$tabela] WHERE CODEMP = $codemp";
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

// ===================== INDICES SGH =====================
logInfo("");
logInfo("========================================");
logInfo("SGH: Recriando Indices da Origem");
logInfo("========================================");

$sqlIdx = "
SELECT 
    t.name AS tabela,
    i.name AS idx_nome,
    i.is_unique,
    i.type_desc,
    STRING_AGG(c.name, ',') WITHIN GROUP (ORDER BY ic.key_ordinal) AS colunas
FROM [$bdOrigem].sys.indexes i
INNER JOIN [$bdOrigem].sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
INNER JOIN [$bdOrigem].sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
INNER JOIN [$bdOrigem].sys.tables t ON i.object_id = t.object_id
WHERE i.type IN (1,2) AND i.is_primary_key = 0 AND i.name IS NOT NULL
GROUP BY t.name, i.name, i.is_unique, i.type_desc
ORDER BY t.name, i.name
";
$stmtIdx = sqlsrv_query($conn, $sqlIdx);
$idxTotal = 0;
$idxOk = 0;
$idxErro = 0;

// Tabelas existentes no destino (atualizar)
$tabelasDestinoFinal = getAllUserTables($conn, $bdDestino);
$tabelasDestinoFinalMap = array_flip(array_map('strtolower', $tabelasDestinoFinal));

if ($stmtIdx) {
    while ($row = sqlsrv_fetch_array($stmtIdx, SQLSRV_FETCH_ASSOC)) {
        $tab = $row['tabela'];
        // So criar indice se tabela existe no destino
        if (!isset($tabelasDestinoFinalMap[strtolower($tab)])) continue;
        
        $idxTotal++;
        $nomeIdx = $row['idx_nome'];
        $unique = $row['is_unique'] ? 'UNIQUE ' : '';
        $clustered = (strpos($row['type_desc'], 'CLUSTERED') !== false && strpos($row['type_desc'], 'NONCLUSTERED') === false) ? 'CLUSTERED ' : 'NONCLUSTERED ';
        $colunas = $row['colunas'];
        
        $colsList = implode('], [', explode(',', $colunas));
        $sqlCreate = "IF NOT EXISTS (SELECT 1 FROM [$bdDestino].sys.indexes WHERE name = '$nomeIdx') 
                      CREATE {$unique}{$clustered}INDEX [$nomeIdx] ON [$bdDestino].DBO.[$tab] ([$colsList])";
        
        if (executarSQL($conn, $sqlCreate, "Indice $nomeIdx em $tab")) {
            $idxOk++;
        } else {
            $idxErro++;
        }
    }
    sqlsrv_free_stmt($stmtIdx);
}

logSuccess("Indices: $idxOk criados, $idxErro erros (de $idxTotal encontrados na origem)");

$currentStep++;
sendProgress($currentStep, $totalSteps, 'Indices concluidos');

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

logSuccess("Total de tabelas criadas em [$bdDestino]: " . count($tabelas));
foreach ($tabelas as $t) {
    $count = contarRegistros($conn, "[$bdDestino].DBO.[$t]");
    logInfo("  -> $t: $count registros");
}

sqlsrv_close($conn);
sendEvent('done', ['success' => true, 'totalTabelas' => count($tabelas), 'banco' => $bdDestino]);
