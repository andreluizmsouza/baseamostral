<?php
/**
 * Definicoes de todos os scripts SQL organizados por fase de execucao.
 * Placeholders:
 *   {DEST}    = banco destino
 *   {ORIG}    = banco origem
 *   {CODEMP}  = codigo da empresa
 */

function getPhase1_ReferenceTables(): array
{
    return [
        [
            'nome' => 'Banco e Agencia',
            'destino' => 'banco_e_agencia',
            'sql' => "SELECT CODEMP,BCOAGE,AGENCIA,N_CONVENIO,DDD,FONE
                      INTO {DEST}.DBO.banco_e_agencia
                      FROM {ORIG}.DBO.mttbage WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Categorias Monitoradas',
            'destino' => 'categorias_monitoradas',
            'sql' => "SELECT CodEmp, AnoCateg, Ind01, Ind02, Ind03, Ind04, Ind05, Ind06, Ind07, Ind08, Ind09, Ind10, Ind11, Ind12
                      INTO {DEST}.DBO.categorias_monitoradas
                      FROM {ORIG}.DBO.mttbcat WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Contas Contabeis CBP/CCC/PDD',
            'destino' => 'contas_contabeis_cbp_ccc_pdd',
            'sql' => "SELECT CODEMP,REGIAO,LIN_CRED,BCL_FX1,BCL_FX2,BCL_FX3,CCC_AA,CCC_A,CCC_B_CN,CCC_B_VE,CCC_C_CN,CCC_C_VE,CCC_D_CN,CCC_D_VE,CCC_E_CN,CCC_E_VE,CCC_F_CN,CCC_F_VE,CCC_G_CN,CCC_G_VE,CCC_H_CN,CCC_H_VE,PCL_AA,PCL_A,PCL_B,PCL_C,PCL_D,PCL_E,PCL_F,PCL_G,PCL_H,QTD_REF
                      INTO {DEST}.DBO.contas_contabeis_cbp_ccc_pdd
                      FROM {ORIG}.DBO.MTTBCBP WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Contas Contabeis',
            'destino' => 'contas_contabeis',
            'sql' => "SELECT CodEmp,Conta,Titulo,Cosif,Qtd_Ref
                      INTO {DEST}.DBO.contas_contabeis
                      FROM {ORIG}.DBO.MTTBCTA WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Parametros de Contabilizacao',
            'destino' => 'parametros_contabilizacao',
            'sql' => "SELECT CodEmp, Codigo, CodHis, CodHisExc, Juncao, SldDeb, SldCre, CorDeb, CorCre, AmoDeb, AmoCre, JurDeb, JurCre, TaxDeb, TaxCre, SegDeb, SegCre, FcvDeb, FcvCre, FgtDeb, FgtCre, SubDeb, SubCre, CcoDeb, CcoCre, DesDeb, DesCre, JrcDeb, JrcCre, AtmDeb, AtmCre, MulDeb, MulCre, MorDeb, MorCre, DifDeb, DifCre, DfmDeb, DfmCre, IofDeb, IofCre, TarDeb, TarCre, OutDeb, OutCre, Compl
                      INTO {DEST}.DBO.parametros_contabilizacao
                      FROM {ORIG}.DBO.MTTBCTB WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Contas Contabeis 2',
            'destino' => 'contas_contabeis_2',
            'sql' => "SELECT CODEMP,GRUPO,CONTA,TITULO,COSIF,NATUREZA,TIPO,NIVEL,FUNCAO,CONTA_PAI,USUA_INC,DATA_INC,HORA_INC,USUA_ALT,DATA_ALT,HORA_ALT,QTD_REF
                      INTO {DEST}.DBO.contas_contabeis_2
                      FROM {ORIG}.DBO.MTTBCTC WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Esquema Contabilizacao',
            'destino' => 'esquema_contabilizacao',
            'sql' => "SELECT CODEMP, REGIAO, ORIG_FIN, LIN_CRED, SIT_CONTR, SIT_PGTO, APROP_RENDA, FATO, PARTIDA, TIPO_LANC, COD_HIST, CONTA_DEB, CONTA_CRE, FLAG_COMPL
                      INTO {DEST}.DBO.esquema_contabilizacao
                      FROM {ORIG}.DBO.MTTBECB WHERE CODEMP IN ({CODEMP})"
        ],
        [
            'nome' => 'Empresas',
            'destino' => 'empresas',
            'sql' => "SELECT CODEMP, TIPOEMP, SIGLA, CGCCPF, RAZSOCIAL, NOME_COMPAC, ENDEMP, ENDCOM, BAIRRO, CIDADE, ESTADO, CODCEP, SUFCEP, CXAPST, DDDTEL, NUMTEL, RAMTEL, DDDFAX, NUMFAX, BNH_COD_AGEN, BNH_UFS_AGEN, BNH_MAT_AGEN, SEG_REGIAO, SEG_MATRIC, SEG_AGENCIA, DTA_BASE_PRO, DTA_EVOLUCAO, DTA_MORA, DTA_CONTABIL, DTA_FECH_CTB, LIM_CRED_CC, LIM_DEB_CC, LIM_DESC_ATU, LIM_DESC_MOR, LIM_TC_ATRAS, LIM_TC_RENDA, LIM_TC_CL, LIM_TC_CL_EX, LIM_TC_PREJU, FLG_COR01, FLG_REA01, FLG_VOUCHER, TP_CARNE, DIG_EMP, BANCO_ATUAL, EMPLIG01, EMPLIG02, DTA_NOVACAO, DTA_SERASA, CEF_CRED, CEF_COD_AGEN, CEF_COD_ARR, CEF_CGC_ADM, CEF_EMP_ADM, CEF_DTA_TRA, FLG_RES1, FLG_RES2, FLG_RES3, FLG_RES4, FLG_RES5, FLG_RES6, DTA_AT_ARREC, SEQ_LOTE, DTA_CONV, WS_URL, BANCO_FGTS, AGENCIA_FGTS, CONTA_FGTS, STR_FGTS, FLG_TPCHAVE, DTA_AUX, SEQ_DBCTA_AM, SEQ_DBCTA, DTA_EVOL_FCF, PATH_READ, PATH_WRITE, SEQ_CARTA1, SEQ_CARTA2, SEQ_CARTA3, DTA_EVOL_HAB, DTA_CTB_PROX, CTR_MODO_CTB, FLG_SARBOX, EMPLIGCER, EMPLIGSIM, EMPLIGBAK, VALI_SENH, PASTABACKUP, EXTENSBACKUP, DTAUTACC, COD_UNI_CLI, WS_URL_REL
                      INTO {DEST}.DBO.empresas
                      FROM {ORIG}.DBO.MTTBEMP WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Enderecos Contabeis',
            'destino' => 'enderecos_contabeis',
            'sql' => "SELECT CodEmp, TipoTab, REGIAO, CodSit, FinImo, CorInc, CorApr, CorEfe, JurInc, JurApr, JurEfe, TxInc, TxApr, TxEfe, MorRec, MorEfe, CcoCob, CcoDev, QtdRef
                      INTO {DEST}.DBO.enderecos_contabeis
                      FROM {ORIG}.DBO.MTTBEND WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Escritorio Regional',
            'destino' => 'escritorio_regional',
            'sql' => "SELECT CODEMP, CODESR, NOME_ESR, ENDERECO, COMPLEMENTO, BAIRRO, CIDADE, ESTADO, COD_CEP, CPL_CEP
                      INTO {DEST}.DBO.escritorio_regional
                      FROM {ORIG}.DBO.MTTBESR WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'FGTS Controles',
            'destino' => 'fgts_controles',
            'sql' => "SELECT CODEMP, SEQARQ, SEQOP, SEQFMP, SEQCANC, SEQCONTA, MAT_AGENTE, SEQARQACAT
                      INTO {DEST}.DBO.fgts_controles
                      FROM {ORIG}.DBO.MTTBFG1 WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'FGTS Header/Trailler',
            'destino' => 'fgts_header_trailler',
            'sql' => "SELECT CODEMP, ARQ_UNIC, TIPREG_H, TIPREG_T, CGCCNPJ, MATRIC_AGEN, TOTREG11, TOTREG12, TOTREG13, TOTREG14, TOTREG21, TOTREG22, TOTREG23, TOTREG24, TOTREG50, TOTREG60, TOTREG, DTARQUIVO, DTRETORNO, VERLAYOUT, SEQARQ, CODRETORNO_H, CODRETORNO_T, CODOCORR_H, CODOCORR_T, OBS_H, OBS_T, SEQ_H, SEQ_T, TOT_FGTS_UTI, TOT_FGTS_RES, DTA_INCLUSAO, HOR_INCLUSAO, USU_INCLUSAO, DTA_ALTER, HOR_ALTER, USU_ALTER, FLG_SIT_CAD
                      INTO {DEST}.DBO.fgts_header_trailler
                      FROM {ORIG}.DBO.MTTBFG2 WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Operacoes FGTS',
            'destino' => 'operacoes_fgts',
            'sql' => "SELECT CODEMP, OPER_UNIC, TIPREG, TIPREM, TIPOPER, TIPCANC, DTACANC, CODUTI, MATRICAGF, BCOAG_ID, OPER_ID, OPER_AMB, IMOVEL_ID, TPIMO, LOGRADOURO, COMPLEMENTO, BAIRRO, COD_MUN, DESC_MUN, UF, CEP, VAL_CMP_VEND, VAL_AVALIA, VAL_FINANC, VAL_RECPROP, DTAPURSAL, VAL_SALDEV, VAL_PRESANT, TPLIQUID, OPERORIG, DTDIF_LIQUID, TOT_FGTS, TOT_FGTS_FMP, DTFIMOBRA, CONTASVINC, FMPVINC, STATUS, DTA_STATUS, CODBANCO, CODAGENC, NUMCONTA, USOAGENTE, FINANC_OPER, CODMENSTR, NOMECREDTIT, CODOPER, DTDEBITOS, VAL_PRI_FGTS, ATUMONET, TOTFGTSRES, DTRESSARC, CPMPNUM, MEIORESSARC, CONTR_INDIC, ORIG_CONTIND, CODRETORNO, CODOCORR, OBSERVACOES, SEQHEADER, CHAVEUNI, FLG_TPCHAVE, NOME, SEQ_REC, SEQ, DTA_INCLUSAO, HOR_INCLUSAO, USU_INCLUSAO, DTA_ALTER, HOR_ALTER, USU_ALTER, FLG_SIT_CAD
                      INTO {DEST}.DBO.operacoes_fgts
                      FROM {ORIG}.DBO.MTTBFG3 WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Contas FGTS',
            'destino' => 'contas_fgts',
            'sql' => "SELECT CODEMP, CONTA_UNIC, TIPREG, TIPOPER, NOME, SITCONTA, CTFGTS_EMP, CTFGTS_TRA, PISPASEP, CGCCPF, DTNASC, VLR_FGTS, SALDOFGTS, DTEMISSAO, OPER_ID, CHAVEUNI, FLG_TPCHAVE, BASE_FGTS, CODRETORNO, CODOCORR, OBSERVACOES, SEQ, DTA_INCLUSAO, HOR_INCLUSAO, USU_INCLUSAO, DTA_ALTER, HOR_ALTER, USU_ALTER, FLG_SIT_CAD
                      INTO {DEST}.DBO.contas_fgts
                      FROM {ORIG}.DBO.MTTBFG4 WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'FGTS Resgate FMP',
            'destino' => 'fgts_resgate_fmp',
            'sql' => "SELECT CODEMP, FMP_UNIC, TIPREG, TIPREM, NOME, SITCONTA, MATRICAGF, CTFGTS_EMP, CTFGTS_TRA, BCOAGE_ID, TIPCONTA, PISPASEP, CGCCPF, TPSOLICIT, VAL_RESGATE, CGCADMIN, MATADM, MATFUNDO, OPER_ID, OPER_ID_VINC, CHAVEUNI, FLG_TPCHAVE, CODUTILIZA, USOAGENTE, BASEFGTS, VAL_EFETRESG, SALDOAPRESG, DTAPURSALDO, SITRESGATE, DTSOLICRESG, CODRETORNO, CODOCORR, OBSERVACOES, SEQ, DTA_INCLUSAO, HOR_INCLUSAO, USU_INCLUSAO, DTA_ALTER, HOR_ALTER, USU_ALTER, FLG_SIT_CAD
                      INTO {DEST}.DBO.fgts_resgate_fmp
                      FROM {ORIG}.DBO.MTTBFG5 WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Cta Ag Financ FGTS',
            'destino' => 'cta_ag_financ_fgts',
            'sql' => "SELECT CODEMP, TPCONTA, BANCO, AGENCIA, CONTA, DESCRICAO, TITULAR
                      INTO {DEST}.DBO.cta_ag_financ_fgts
                      FROM {ORIG}.DBO.MTTBFG6 WHERE CODEMP IN ({CODEMP})"
        ],
        [
            'nome' => 'FGTS Vinculo Operacao CPF',
            'destino' => 'fgts_vinculo_operacao_cpf',
            'sql' => "SELECT CODEMP, OPER_UNIC, CPF
                      INTO {DEST}.DBO.fgts_vinculo_operacao_cpf
                      FROM {ORIG}.DBO.MTTBFG7 WHERE CODEMP IN ({CODEMP})"
        ],
        [
            'nome' => 'Historico Conta Corrente',
            'destino' => 'historio_conta_corrente',
            'sql' => "SELECT * INTO {DEST}.DBO.historio_conta_corrente
                      FROM {ORIG}.DBO.MTTBHCC WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Nucleos Habitacionais',
            'destino' => 'nucleos_habitacionais',
            'sql' => "SELECT CodEmp, Regiao, Codigo, Nucleo, Qtd_Imovel, COD_BANCO, BANCO_CC, AGENCIA_CC, TIPO_CC, NUMERO_CC, FIS_JUR, CGC_CPF, REGIONAL, VAL_MERC_IMO
                      INTO {DEST}.DBO.nucleos_habitacionais
                      FROM {ORIG}.DBO.MTTBNUC WHERE CodEmp IN ({CODEMP})"
        ],
        [
            'nome' => 'Origem Agenda',
            'destino' => 'origem_agenda',
            'sql' => "SELECT CODEMP, CODIGO, DESCRICAO, VALIDADE, Qtd_Ref, AGD_PADRAO, FLG_ALERTA, SOL_PADRAO, AGDABERTA, SL_CRIANOVA, AGD_NOVA
                      INTO {DEST}.DBO.origem_agenda
                      FROM {ORIG}.DBO.MTTBOAG WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Parametros Planos Financiamento',
            'destino' => 'parametros_planos_financiamento',
            'sql' => "SELECT CODEMP, CODIGO_PLANO, PVP_DTA_INIC, PVP_DTA_FIN, SIGLA, DESCRICAO, TIPO_OPER, SIST_AMORTIZ, USO_DO_CES, SREAJ_PERIOD, SREAJ_MOEDA, SCSD_PERIOD, SCSD_MOEDA, VENC_PREST, VMPRES_MOEDA, VMPRES_QTDE, CAR_TIPO, CAR_NUM_MES, FLG_REPACTUA, FLG_REV_REAJ, FLG_LIM_1331, QTD_CON, CODINDREAJ, CODCORSAL
                      INTO {DEST}.DBO.parametros_planos_financiamento
                      FROM {ORIG}.DBO.MTTBPPF WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Parametros Historicos Renegociacoes',
            'destino' => 'parametros_historicos_renegociacoes',
            'sql' => "SELECT CODEMP, COD_REN_EMP, SIGLA_REN, DESC_REN_EMP, DESC_REN_SIS, DESC_LEG_EMP, INI_VALIDADE, FIM_VALIDADE, FLAG_VL_REN
                      INTO {DEST}.DBO.parametros_historicos_renegociacoes
                      FROM {ORIG}.DBO.MTTBPRE WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Planos de Seguros',
            'destino' => 'tabela_planos_seguros',
            'sql' => "SELECT CODEMP, CODSEG, PLANO_SEG, DESCRICAO, TIPO_SEG, MIP_FXA1, MIP_IND1, MIP_FXA2, MIP_IND2, MIP_FXA3, MIP_IND3, MIP_FXA4, MIP_IND4, MIP_FXA5, MIP_IND5, MIP_FXA6, MIP_IND6, DFI_IND, FLG_DFI_VAL, DTA_LIM_AVAL, VAL_LIM_AVAL, QTD_SEG
                      INTO {DEST}.DBO.tabela_planos_seguros
                      FROM {ORIG}.DBO.MTTBPSE WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Regioes',
            'destino' => 'regioes',
            'sql' => "SELECT CodEmp, Regiao, Descricao
                      INTO {DEST}.DBO.regioes
                      FROM {ORIG}.DBO.MTTBREG WHERE CodEmp IN ({CODEMP})"
        ],
        [
            'nome' => 'Seguradoras',
            'destino' => 'seguradoras',
            'sql' => "SELECT CODEMP, CODSEG, NOME, SEG_REGIAO, SEG_MATRIC, SEG_AGENCIA, QTD_PSE
                      INTO {DEST}.DBO.seguradoras
                      FROM {ORIG}.DBO.MTTBSGU WHERE CODEMP IN ({CODEMP})"
        ],
        [
            'nome' => 'Parametro Sinistro',
            'destino' => 'parametro_sinistro',
            'sql' => "SELECT CODEMP, GRUPO, SITUACAO, COD_HIST_T, COD_HIST_P, COD_HIST_CC
                      INTO {DEST}.DBO.PARAMETRO_SINISTRO
                      FROM {ORIG}.DBO.mttbsnp WHERE CODEMP IN ({CODEMP})"
        ],
        [
            'nome' => 'Contrato Controle FEH',
            'destino' => 'contrato_controle_feh',
            'sql' => "SELECT CODEMP, CONTRATO_FEH, COD_MUNIC, MUNICIPIO, QTD_UH, VAL_CONTRATO, DTA_INCLUSAO, HOR_INCLUSAO, USU_INCLUSAO, DTA_ALTER, HOR_ALTER, USU_ALTER
                      INTO {DEST}.DBO.CONTRATO_CONTROLE_FEH
                      FROM {ORIG}.DBO.MTTBFHC WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Cadastro Nucleos Controle FEH',
            'destino' => 'cadastro_nucleos_controle_feh',
            'sql' => "SELECT CODEMP, CONTRATO_FEH, REGIAO, NUCLEO, NOME_NUCLEO, QTD_UH, VALOR_UH, QTD_REF_FHS, DTA_INCLUSAO, HOR_INCLUSAO, USU_INCLUSAO, DTA_ALTER, HOR_ALTER, USU_ALTER
                      INTO {DEST}.DBO.CADASTRO_NUCLEOS_CONTROLE_FEH
                      FROM {ORIG}.DBO.MTTBFHN WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Cadastro Campanha',
            'destino' => 'cadastro_campanha',
            'sql' => "SELECT codemp, COD_CAMPANHA, DSC_CAMPANHA
                      INTO {DEST}.DBO.CADASTRO_CAMPANHA
                      FROM {ORIG}.DBO.MTTBCCA WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Cadastro Empresas Cobranca Terceirizadas',
            'destino' => 'cadastro_empresas_cobranca_terceirizadas',
            'sql' => "SELECT CODEMP, CODTRZ, NOME, ENDERECO, COMPLEMENTO, BAIRRO, CIDADE, ESTADO, CEP, SUFCEP, DDDTEL, NUMTEL, DDDFAX, NUMFAX, QTDPINICIAL, QTDPFINAL
                      INTO {DEST}.DBO.Cadastro_Empresas_Cobranca_Terceirizadas
                      FROM {ORIG}.DBO.MTTBCTZ WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Parametros Grupo Agenda',
            'destino' => 'parametros_grupo_agenda',
            'sql' => "SELECT CODEMP, GRUPO, DESCRICAO, AGD_INICIAL, SITUACAO, PART_FLUXO, PERFIS, EMAIL
                      INTO {DEST}.DBO.PARAMETROS_GRUPO_AGENDA
                      FROM {ORIG}.DBO.mttbpga WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Programas Habitacionais',
            'destino' => 'programas_habitacionais',
            'sql' => "SELECT CODEMP, COD_PROG, DESCR_PROG, ORIG_REC, RENDA_MIN, RENDA_MAX, PERC_REN_MIN, PERC_REN_MAX, VAL_IMOV_MIN, VAL_IMOV_MAX, PERC_FIN_MIN, PERC_FIN_MAX, PRAZO_MIN, PRAZO_MAX, TX_JUR_MIN, TX_JUR_MAX, AREA_IMO_MIN, AREA_IMO_MAX, IDADE_MIN, IDADE_MAX, QTD_REF, VAL_SUBSD, CODMOD, TX_DIF_JUR_MUT, TX_DIF_JUR_CEF, DT_INICIO, DT_TERMINO, ORIGEM_IMOV, TX_RISCO, LIM_RENDA_FAM_TX_ADM, TX_DESC_TX_ADM, PRZ_MAX_LIB_PARC, RED_JUR_TEMPO_FGTS, TP_FGTS_MIN, LIM_SUBS_CEF_VAL, LIM_SUBS_CEF_PERC_SALDO, VAL_TX_ADM, LIM_VAL_TX_ADM
                      INTO {DEST}.DBO.PROGRAMAS_HABITACIONAIS
                      FROM {ORIG}.DBO.MTTBPRH WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Renda Per Capita',
            'destino' => 'renda_per_capita',
            'sql' => "SELECT CODEMP, SEQUENCIA, DTA_INICIO, RENDA_PERC, TAXA_JUROS, TAXA_DESCONT, USUARIO, DTA_INCLUSAO, HOR_INCLUSAO
                      INTO {DEST}.DBO.RENDA_PER_CAPITA
                      FROM {ORIG}.DBO.MTTBRPC WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Situacoes do Contrato',
            'destino' => 'situacoes_contrato',
            'sql' => "SELECT CODEMP, CODIGO, DESCRICAO, FLGEXCOB, TIPOTAB
                      INTO {DEST}.DBO.SITUACOES_CONTRATO
                      FROM {ORIG}.DBO.MTTBSCT WHERE codemp IN ({CODEMP})"
        ],
        [
            'nome' => 'Fases HistÃ³ricas Processo',
            'destino' => 'fases_historicas_processo',
            'sql' => "SELECT CODEMP, COD_FASE, DESC_FASE, PRZ_FASE, COD_AGENDA
                      INTO {DEST}.DBO.FASES_HISTORICAS_PROCESSO
                      FROM {ORIG}.DBO.MTTBFAS WHERE CODEMP IN ({CODEMP})"
        ],
        [
            'nome' => 'Foro das AÃ§Ãµes',
            'destino' => 'foro_das_acoes',
            'sql' => "SELECT CODEMP, COD_FORO, DESC_FORO
                      INTO {DEST}.DBO.FORO_DAS_ACOES
                      FROM {ORIG}.DBO.MTTBFOR WHERE CODEMP IN ({CODEMP})"
        ],
        [
            'nome' => 'HistÃ³rico Valores Processo',
            'destino' => 'historico_valores_processo',
            'sql' => "SELECT CODEMP, COD_VALOR, TIPO_VALOR, DESC_VALOR, VALOR, FLG_REEM_MUT, FLG_CORREC, TIPO_CORREC, FLG_JUROS, TAXA_JUROS, TIPO_CAPIT, TIPO_VARIAC, FLG_MULTA, PERC_MULTA, CONTA_CONTAB
                      INTO {DEST}.DBO.HISTORICO_VALORES_PROCESSO
                      FROM {ORIG}.DBO.MTTBHVP WHERE CODEMP IN ({CODEMP})"
        ],
        [
            'nome' => 'Tipo de AÃ§Ãµes',
            'destino' => 'tipo_de_acoes',
            'sql' => "SELECT CODEMP, COD_ACAO, DESC_ACAO, FLG_EXEC
                      INTO {DEST}.DBO.TIPO_DE_ACOES
                      FROM {ORIG}.DBO.MTTBTPA WHERE CODEMP IN ({CODEMP})"
        ],
        [
            'nome' => 'Varas Juntas CartÃ³rios',
            'destino' => 'varas_juntas_cartorios',
            'sql' => "SELECT CODEMP, COD_VARA, COD_COMARCA, COD_FORO, DESC_VARA
                      INTO {DEST}.DBO.VARAS_JUNTAS_CARTORIOS
                      FROM {ORIG}.DBO.MTTBVAR WHERE CODEMP IN ({CODEMP})"
        ],
    ];
}

function getPhase1b_NoFilterTables(): array
{
    return [
        [
            'nome' => 'Esquema Contabil',
            'destino' => 'esquema_contabil',
            'sql' => "SELECT Seq, Natureza, Conta
                      INTO {DEST}.DBO.esquema_contabil
                      FROM {ORIG}.DBO.MTTBECT"
        ],
        [
            'nome' => 'Codigos Municipios BNH',
            'destino' => 'codigos_municipios_bnh',
            'sql' => "SELECT COD_IBGE, COD_BNH, MUNICIPIO, ESTADO
                      INTO {DEST}.DBO.codigos_municipios_bnh
                      FROM {ORIG}.DBO.MTTBMUN"
        ],
        [
            'nome' => 'Tabela Profissoes',
            'destino' => 'tabela_profissoes',
            'sql' => "SELECT COD_PROF, TITULO_PROF
                      INTO {DEST}.DBO.tabela_profissoes
                      FROM {ORIG}.DBO.MTTBPRO"
        ],
        [
            'nome' => 'Parametro Finalidade Uso Cliente',
            'destino' => 'parametro_finalidade_uso_cliente',
            'sql' => "SELECT CODFIN, DESCRICAO
                      INTO {DEST}.DBO.PARAMETRO_FINALIDADE_USO_CLIENTE
                      FROM {ORIG}.DBO.MTTBPFU"
        ],
        [
            'nome' => 'Padroes Unidades Habitacionais',
            'destino' => 'padroes_unidades_habitacionais',
            'sql' => "SELECT UNIDADE_HABITACIONAL, TIPO_UNIDADE, AREA_PRIVATIVA, AREA_COMUM, AREA_TERRENO, AREA_AUX1, AREA_AUX2, AREA_AUX3, DTA_INCLUSAO, HOR_INCLUSAO, USU_INCLUSAO, DTA_ALTER, HOR_ALTER, USU_ALTER
                      INTO {DEST}.DBO.PADROES_UNIDADES_HABITACIONAIS
                      FROM {ORIG}.DBO.MTTBPUH"
        ],
    ];
}

function getPhase2_ContractFilteredTables(): array
{
    return [
        [
            'nome' => 'Adquirentes',
            'destino' => 'adquirentes',
            'sql' => "SELECT Y.Codemp, Y.CgcCpf, Y.Regiao, Y.Nucleo, Y.Contrato, Y.Flag_EndCob, Y.Data_Ocor
                      INTO {DEST}.DBO.adquirentes
                      FROM {ORIG}.DBO.mttbadq Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Agenda',
            'destino' => 'agenda',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DT_AGENDA, Y.HR_AGENDA, Y.USR_AGENDA, Y.DT_SOLUCAO, Y.HR_SOLUCAO, Y.USR_SOLUCAO, Y.DT_LEMBRA, Y.ASSUNTO, Y.ORIGEM, Y.ASSUNTO_01
                      INTO {DEST}.DBO.agenda
                      FROM {ORIG}.DBO.mttbagd Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Condicoes dos Acordos',
            'destino' => 'condicoes_dos_acordos',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DATA_ACORDO, Y.DT_INIVIGEN, Y.DT_FIMVIGEN, Y.DT_CANCEL, Y.PDESC_ATMON, Y.PDESC_JURMOR, Y.SITUACAO, Y.IND_PREMES, Y.PRIM_ATRASO, Y.USR_CANCEL, Y.MOTIVO_CANC, Y.PDESC_JURREM, Y.PDESC_MORANT
                      INTO {DEST}.DBO.condicoes_dos_acordos
                      FROM {ORIG}.DBO.mttbcac Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Cadastro Conta Corrente',
            'destino' => 'cadastro_conta_corrente',
            'sql' => "SELECT Y.CodEmp, Y.Regiao, Y.Nucleo, Y.Contrato, Y.Data_Cad, Y.Seq, Y.TpLanc, Y.VlOrig, Y.TpMoeda, Y.CodHist, Y.QtdParc, Y.QtdParcRem, Y.Saldo, Y.Data_ULT, Y.DtCancel, Y.FlgBloq, Y.DtBloq, Y.DtDesBloq, Y.Seq_Rec, Y.Data_Incl, Y.Hora_Incl, Y.Flg_Res1, Y.USR_INCL, Y.USR_BLOQ, Y.HORA_BLOQ, Y.USR_DESBLOQ, Y.HORA_DESBLOQ, Y.USR_CANCEL, Y.HORA_CANCEL, Y.VAL_CORR_INI, Y.SALDO_INI, Y.VAL_COMP, Y.VAL_ENC, Y.SALDO_CANC, Y.DTA_CORR_ANT, Y.VAL_CORR_ANT, Y.VAL_COMP_ANT, Y.VAL_ENC_ANT, Y.SALDO_ANT
                      INTO {DEST}.DBO.cadastro_conta_corrente
                      FROM {ORIG}.DBO.mttbccc Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'FGTS (Cadastro)',
            'destino' => 'fgts',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DAMP, Y.DTA_AUT, Y.DTA_INI, Y.DTA_FIM, Y.QTD_PARC, Y.PERC_UTI, Y.VAL_UTIL, Y.VAL_PRES, Y.VAL_PARC, Y.TIPO_CALCULO, Y.USR_INC, Y.DATA_INC, Y.HORA_INC, Y.USR_ALT, Y.DTA_ALT, Y.HORA_ALT, Y.DAMP1, Y.CPF_TITULAR1, Y.VAL_PARC1, Y.VAL_UTIL1, Y.DAMP2, Y.CPF_TITULAR2, Y.VAL_PARC2, Y.VAL_UTIL2, Y.DAMP3, Y.CPF_TITULAR3, Y.VAL_PARC3, Y.VAL_UTIL3, Y.FLG_PAGTO, Y.DTA_PAGTO, Y.VLA_PAGTO
                      INTO {DEST}.DBO.fgts
                      FROM {ORIG}.DBO.MTTBCFG Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Contratos',
            'destino' => 'contratos',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.FISJUR, Y.ADQ1_CPFCGC, Y.ADQ1_PART, Y.ADQ2_CPF, Y.ADQ2_PART, Y.ADQ3_CPF, Y.ADQ3_PART, Y.ADQ4_CPF, Y.ADQ4_PART, Y.TIPOFIN, Y.PART_CEF, Y.ORI_REC_TIPO, Y.CODCAUCAO, Y.CODIGO_IM, Y.CODMUN_BNH, Y.CATEGORIA, Y.MODALIDADE, Y.CH_CODIGO, Y.CH_TIPO, Y.CH_NUMERO, Y.CH_SERIE, Y.CH_REGISTRO, Y.CH_INSCRICAO, Y.CH_COMARCA, Y.CH_CART, Y.CH_DATA, Y.CH_LIVRO, Y.CH_FOLHA, Y.GRAU_HIPO, Y.IMFIN_QUADRA, Y.IMFIN_LOTE, Y.IMFIN_BLOCO, Y.IMFIN_APTO, Y.IMFIN_DV, Y.IMFIN_PADRAO, Y.DATA_ASS, Y.DATACONTR, Y.VAL_IMOVEL, Y.VAL_AVAL, Y.DATA_AVAL, Y.PREST_PAGAS, Y.VAL_FIN, Y.VAL_FIN_CONT, Y.PLANO_FIN, Y.PRAZO_FIN, Y.FAIXA_FIN, Y.TAXA_JUR, Y.CES, Y.FATOR_Q, Y.RED_GRAD, Y.CAR_PRAZO, Y.CAR_TIPO, Y.ORIG_FIN, Y.ORI_LIN_CRED, Y.DTA_PRI_PRES, Y.DTA_PRI_REAJ, Y.CODIGO_SEG, Y.PLANO_SEG, Y.NUMERO_FIF, Y.ORI_SEG_MIP, Y.ORI_SEG_CRED, Y.ORI_SEG_DFI, Y.DTA_INI_SEG, Y.DTA_TAXAS, Y.TXI_ABR_CRED, Y.TXI_SEG_AVI, Y.TXI_FUNDAB, Y.TXI_FCVS_AVI, Y.TXI_OUTRAS, Y.TXA1_COB_COD, Y.TXA1_COB_ARG, Y.TXA2_COB_COD, Y.TXA2_COB_ARG, Y.TXA3_COB_COD, Y.TXA3_COB_ARG, Y.CES_COD_CP, Y.CES_MES_DISS, Y.CES_TIP_REAJ, Y.ORI_COB_FCVS, Y.JP_DT_INIC, Y.JP_PERC, Y.JP_FREQ, Y.JP_TX_FINAL, Y.TAXA_MORA, Y.TP_CALC_MORA, Y.TAXA_MULTA, Y.LCOB_TIPO, Y.LCOB_END, Y.LCOB_BANCO, Y.LCOB_AGENCIA, Y.LCOB_CONTA, Y.CONS_ORGAO, Y.CONS_MUNIC, Y.CONS_SETOR, Y.CONS_MATR, Y.RCON_COMARCA, Y.RCON_CART, Y.RCON_DATA, Y.RCON_LIVRO, Y.RCON_FOLHA, Y.LIG_CON_IDEN, Y.LIG_CON_DATA, Y.LIG_CON_CODM, Y.LPX_CON_IDEN, Y.LPX_CON_DATA, Y.LPX_CON_CODM, Y.TIPO_CALCULO, Y.ENC_MEN_DTI, Y.ENC_MEN_SDI, Y.ENC_MEN_AJ, Y.ENC_MEN_JUR, Y.ENC_MEN_MIP, Y.ENC_MEN_CRED, Y.ENC_MEN_DFI, Y.ENC_MEN_TXA1, Y.ENC_MEN_TXA2, Y.ENC_MEN_TXA3, Y.ENC_MEN_RAZ, Y.ENC_MEN_FCVS, Y.DATA_LIB_HIP, Y.LIN_CRED, Y.CTR_SIT_CONT, Y.CTR_STC_COD1, Y.CTR_STC_DTA1, Y.CTR_STC_COD2, Y.CTR_STC_DTA2, Y.CTR_STC_COD3, Y.CTR_STC_DTA3, Y.CTR_STC_COD4, Y.CTR_STC_DTA4, Y.CTR_STC_COD5, Y.CTR_STC_DTA5, Y.CC_TIP_CORR, Y.CC_TX_JUROS, Y.CC_TX_MORA, Y.CC_TX_MULTA, Y.CC_LIM_CRED, Y.CC_LIM_DEB, Y.CC_FLG_BLOQ, Y.CC_DTA_BLOQ, Y.CC_QTD_PAR, Y.CC_VLR_PAR, Y.CCA_TIP_CORR, Y.CCA_TX_JUROS, Y.CCA_TX_MORA, Y.CCA_TX_MULTA, Y.CCA_LIM_CRED, Y.CCA_LIM_DEB, Y.CCA_FLG_BLOQ, Y.CCA_DTA_BLOQ, Y.CCA_QTD_PAR, Y.CCA_VLR_PAR, Y.CC_QTD_EMI, Y.CC_DTA_LCT, Y.CC_VLR_LCT, Y.CC_DTA_SALDO, Y.CC_SALDO, Y.PSW_ADQ, Y.CTR_COD_USR, Y.DATA_INC, Y.HORA_INC, Y.USR_ALT, Y.DATA_ALT, Y.HORA_ALT, Y.CPF_GAVETA, Y.FLG_TIP_GAV, Y.DIA_VENC, Y.DATU_DIA_VEN, Y.PRAZ_PROR, Y.DATU_PZ_PROR, Y.ORI_REP_PRO, Y.DATU_REP_PRO, Y.CTR_DTU_JPRO, Y.CTR_DTU_CPRO, Y.CTR_FX_CBP, Y.LCOB_TIPO_CC, Y.COD_SEGUR, Y.CAMPO_EXTRA, Y.VAL_AQUIS, Y.VAL_CONTABIL, Y.VAL_AVAL_CEF, Y.POUP_ESPECIE, Y.POUP_FGTS, Y.POUP_OUTROS, Y.POUP_TOTAL, Y.VAL_CMP_VEND, Y.PC_CUST_VEND, Y.PC_PART_FMH, Y.APU_FINANC, Y.APU_TX_JUR, Y.APU_AMJ, Y.APU_JUROS, Y.DATU_APTXJUR, Y.DATU_APU_AMJ, Y.DATU_APU_JUR, Y.DATU_APU_SLD, Y.CTR_ETP_SEG, Y.IMOVEL_UNICO, Y.CTR_FLGRES16
                      INTO {DEST}.DBO.contratos
                      FROM {ORIG}.DBO.mttbcon Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Imp. Escritura',
            'destino' => 'imp_escritura',
            'sql' => "SELECT Y.CODEMP, Y.COMPRADOR_1, Y.COMP1_CODNAC, Y.COMP1_ESTCIV, Y.COMP1_PROF, Y.COMP1_CGCCPF, Y.COMPRADOR_2, Y.COMP2_CODNAC, Y.COMP2_ESTCIV, Y.COMP2_PROF, Y.COMP2_CPF, Y.COMPRADOR_3, Y.COMP3_CODNAC, Y.COMP3_ESTCIV, Y.COMP3_PROF, Y.COMP3_CPF, Y.COMPRADOR_4, Y.COMP4_CODNAC, Y.COMP4_ESTCIV, Y.COMP4_PROF, Y.COMP4_CPF, Y.COD_IMOVEL, Y.CGC_CPF, Y.ENDERECO, Y.QUADRA, Y.NUMERO, Y.COMPLEMENTO, Y.BAIRRO, Y.MUNICIPIO, Y.COD_MUNIC, Y.ESTADO, Y.CEP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.LOTE, Y.BLOCO, Y.AREA_PRIVADA, Y.AREA_COMUM, Y.AREA_TERRENO, Y.SALA_QTD, Y.COPA_QTD, Y.QUAR_SOC_QTD, Y.QUAR_SUI_QTD, Y.QUAR_SRV_QTD, Y.WC_SOC_QTD, Y.WC_SUI_QTD, Y.WC_SRV_QTD, Y.COZINHA_QTD, Y.AREA_SRV_QTD, Y.TERRACO_QTD, Y.GARAGEM_QTD, Y.DEPOSITO_QTD, Y.DESPENSA_QTD, Y.N_FOLHA_INSC, Y.LIVRO_INSC, Y.DTA_INSC_LIV, Y.N_INSCRICAO, Y.N_FLH_INSC_2, Y.LIVRO_INSC_2, Y.DTA_INS_LV_2, Y.N_INSCRICAO2, Y.N_FLH_INSC_3, Y.LIVRO_INSC_3, Y.DTA_INS_LV_3, Y.N_INSCRICAO3, Y.N_FLH_INSC_4, Y.LIVRO_INSC_4, Y.DTA_INS_LV_4, Y.N_INSCRICAO4, Y.N_FLH_INSC_5, Y.LIVRO_INSC_5, Y.DTA_INS_LV_5, Y.N_INSCRICAO5, Y.DIRETOR_PRES, Y.SUPER_IMOB, Y.N_FOLHA_ESCR, Y.LIVRO_ESCR, Y.DTA_ESCR_LIV, Y.N_ESCRITURA, Y.N_FLH_ESCR_2, Y.LIVRO_ESCR_2, Y.DTA_ESC_LV_2, Y.N_ESCRITUR_2, Y.N_FLH_ESCR_3, Y.LIVRO_ESCR_3, Y.DTA_ESC_LV_3, Y.N_ESCRITUR_3, Y.N_FLH_ESCR_4, Y.LIVRO_ESCR_4, Y.DTA_ESC_LV_4, Y.N_ESCRITUR_4, Y.N_FLH_ESCR_5, Y.LIVRO_ESCR_5, Y.DTA_ESC_LV_5, Y.N_ESCRITUR_5, Y.DESCR_LOC_IM, Y.CONT_DESCR, Y.DTA_ASS, Y.VAL_COMPRA, Y.DESC_FRENTE, Y.DESC_ESQUERD, Y.DESC_DIREITO, Y.DESC_FUNDOS, Y.TIPO_IMOVEL, Y.CODUSR, Y.BLOCO_FRENTE, Y.BLOCO_ESQ, Y.BLOCO_DIREIT, Y.BLOCO_FUNDOS, Y.AREA_TOTAL, Y.COTA_IDEAL, Y.TAM_FRENTE, Y.TAM_DIREITA, Y.TAM_ESQUERDA, Y.TAM_FUNDOS, Y.IMPORT_DBF, Y.OBS_INSC, Y.OBS_ESCR, Y.CONT2_DESCR, Y.CONT3_DESCR, Y.USU_EXCL, Y.HOR_EXCL, Y.DT_EXCL, Y.Seq_Unico, Y.FISJUR_COMP1, Y.FISJUR_COMP2, Y.FISJUR_COMP3, Y.FISJUR_COMP4, Y.APTO, Y.USUFRUTO, Y.NUC_ESPECIAL, Y.PAVIMENTO, Y.NUC_FORA_TER, Y.SEG_RET_FUND, Y.SEG_RET_DIRE, Y.FLG_IMPRESSA, Y.USU_IMPRESSA, Y.HOR_IMPRESSA, Y.DTA_IMPRESSA, Y.DTA_ENTREGA, Y.DTA_LOG_ENTR, Y.HOR_LOG_ENTR, Y.USU_LOG_ENTR
                      INTO {DEST}.DBO.imp_escritura
                      FROM {ORIG}.DBO.MTTBDEX Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Eventos Mensais FCVS',
            'destino' => 'eventos_mensais_fcvs',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DATA_EVM, Y.PRT_DIA_VENC, Y.PRT_NUM_PRES, Y.PRT_PRAZO, Y.PRT_VAL_AMJ, Y.PRT_VAL_RAZ, Y.PRO_VAL_JUR, Y.TEO_VAL_JUR, Y.TXA_VAL_TXA1, Y.TXA_VAL_TXA2, Y.TXA_VAL_TXA3, Y.TXA_VAL_FCVS, Y.SEG_MIP, Y.SEG_CRED, Y.SEG_DFI, Y.SALDO_PROR, Y.SALDO_TEOR, Y.VAF4_VAL_JUR, Y.VAF4_VALCORR, Y.VAF4_SALDO
                      INTO {DEST}.DBO.eventos_mensais_fcvs
                      FROM {ORIG}.DBO.MTTBEFC Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Eventos Mensais',
            'destino' => 'eventos_mensais',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DATA_EVM, Y.PRT_DIA_VENC, Y.PRT_NUM_PRES, Y.PRT_VAL_AMJ, Y.PRT_VAL_RAZ, Y.PRT_VAL_JUR, Y.TXA_VAL_TXA1, Y.TXA_VAL_TXA2, Y.TXA_VAL_TXA3, Y.TXA_VAL_FCVS, Y.EXT_VAL_CC, Y.EXT_VAL_MORA, Y.EXT_VAL_SUBS, Y.EXT_VAL_OUTR, Y.EXT_VAL_IOF, Y.FGT_COT_FGTS, Y.FGT_VAL_FGTS, Y.SEG_MIP, Y.SEG_CRED, Y.SEG_DFI, Y.SALDO_TEOR, Y.CTB_SIT_CONT, Y.CTB_REN_CMON, Y.CTB_REN_PRES, Y.CTB_SIT_PRES, Y.PAG_SIT_PGTO, Y.PAG_BCO_AGE, Y.PAG_DTA_PGTO, Y.PAG_DTA_ESTR, Y.PAG_DTA_PROR, Y.PAG_COD_BAIX, Y.PAG_VAL_PGTO, Y.PAG_DIF_PGTO, Y.PAG_ATU_MON, Y.PAG_JUR_REM, Y.PAG_MORA_DEV, Y.PAG_MULTA, Y.PAG_OUTROS, Y.PAG_DTA_MORA, Y.PAG_SEQ_REC, Y.EXT_VALCCSEM
                      INTO {DEST}.DBO.eventos_mensais
                      FROM {ORIG}.DBO.MTTBEVM Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Historico Renegociacoes',
            'destino' => 'historico_renegociacoes',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DTA_PALT, Y.SEQ, Y.DTA_ALT, Y.COD_HIST, Y.VL_RENEG, Y.VL_FGTS, Y.VL_CC, Y.AD1_CGCCPF, Y.AD1_PART, Y.AD2_CPF, Y.AD2_PART, Y.AD3_CPF, Y.AD3_PART, Y.AD4_CPF, Y.AD4_PART, Y.PCP_CDCP, Y.PCP_MDIS, Y.PCP_TPRJ, Y.DT_REAJ, Y.SIN_PERC_RJ, Y.PERC_RJ, Y.VL_PREST, Y.PLAN_FIN, Y.PRAZ_TOT, Y.TAXA_JUR, Y.CES, Y.FATOR_Q, Y.RED_GRAD, Y.CAR_PRAZO, Y.CAR_TIPO, Y.TX_COD1, Y.TX_ARG1, Y.TX_COD2, Y.TX_ARG2, Y.TX_COD3, Y.TX_ARG3, Y.COD_CTB, Y.DIA_VENC, Y.CODIGO_SEG, Y.PLANO_SEG, Y.FLG_SEG_MIP, Y.FLG_SEG_CRED, Y.FLG_SEG_DFI, Y.JP_DT_INIC, Y.JP_PERC, Y.JP_FREQ, Y.JP_TX_FINAL, Y.TAXA_MORA, Y.TP_CALC_MORA, Y.TAXA_MULTA, Y.FLG_CON_ESP, Y.SEQ_REC, Y.PRIN_MOT, Y.TP_SUBRO, Y.DATA_CONTAB, Y.USR_INC, Y.DATA_INC, Y.HORA_INC, Y.USR_ALT, Y.DATA_ALT, Y.HORA_ALT, Y.DTA_CTB_ALT, Y.VL_DEP_JUD, Y.QTD_PARC, Y.REN_VAL_AMOR, Y.SALDO_TEOR, Y.PRO_VAL_CMON, Y.PRO_IND_CMON, Y.COR_IND_BASE, Y.DD_PRO_CMON, Y.CTR_DTU_CORR, Y.PRO_VAL_JUR, Y.PRO_IND_JUR, Y.DD_PRO_JUR, Y.DES_VAL_FCVS, Y.DES_VAL_AGEN, Y.PE_LIGCRONO, Y.VL_TXA_REN, Y.VL_MON_REN, Y.VL_JTP_REN, Y.VL_TX2_REN, Y.VL_TX3_REN, Y.VL_TIPTX_REN, Y.VL_IOF_REN, Y.DTA_PG_SEG, Y.PRAZ_PROR, Y.ORI_REP_PRO, Y.VL_MON_ATU, Y.VL_JTP_ATU
                      INTO {DEST}.DBO.historico_renegociacoes
                      FROM {ORIG}.DBO.MTTBHIS Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Cadastro Imoveis',
            'destino' => 'cadastro_imoveis',
            'sql' => "SELECT Y.CodEmp, Y.Regiao, Y.Nucleo, Y.Contrato, Y.Endereco, Y.Complemento, Y.Bairro, Y.Cidade, Y.Estado, Y.CodCep, Y.SufCep, Y.DDDFone, Y.NumFone, Y.RamFone, Y.Quadra, Y.Lote, Y.Compl, Y.Apto, Y.Tipo, Y.DT_EMISS_CAR, Y.CONTROLE_CAR
                      INTO {DEST}.DBO.cadastro_imoveis
                      FROM {ORIG}.DBO.MTTBIMO Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Processos Juridicos',
            'destino' => 'cadastro_processos_juridicos',
            'sql' => "SELECT Y.CODEMP, Y.NUM_PROC, Y.TIPO_PROC, Y.COD_ACAO, Y.FLG_EXEC, Y.COD_TITULO, Y.COD_VARA, Y.COD_COMARCA, Y.COD_FORO, Y.COD_ORGAO, Y.REGIAO_PRI, Y.NUCLEO_PRI, Y.CONTRATO_PRI, Y.COD_ESCR_PRI, Y.COD_ADV_PRI, Y.DTA_ENV_ESCR, Y.COD_ESCR_C, Y.COD_ADV_C, Y.DTA_AJUIZAM, Y.DTA_REC_PRO, Y.VALOR_PRINC, Y.VAL_ATU_MORA, Y.VALOR_OUTROS, Y.VALOR_CAUSA, Y.DTA_VL_CAUSA, Y.SALDO_DEV, Y.DTA_SALD_DEV, Y.COD_FASE_AT, Y.DTA_FASE_AT, Y.PROB_EXITO, Y.DTA_ENCER, Y.VALOR_ENCER, Y.OBS_PROCESSO, Y.FLG_RES1, Y.FLG_RES2, Y.FLG_RES3, Y.FLG_RES4, Y.FLG_RES5, Y.FLG_RES6, Y.DTA_INCLUSAO, Y.HOR_INCLUSAO, Y.USU_INCLUSAO, Y.DTA_ALTER, Y.HOR_ALTER, Y.USU_ALTER
                      INTO {DEST}.DBO.cadastro_processos_juridicos
                      FROM {ORIG}.DBO.MTTBJUR Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO_PRI AND X.NUCLEO = Y.NUCLEO_PRI AND X.CONTRATO = Y.CONTRATO_PRI)"
        ],
        [
            'nome' => 'Base Mensal Ctr Hab FCVS',
            'destino' => 'base_mensal_ctr_hab_fcvs',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DATA_COMP, Y.CONTRAT_FCVS, Y.HIPOTECA, Y.MUTUARIO, Y.CPF, Y.DT_CONTRATO, Y.ORIG_RECURSO, Y.TP_EVENTO, Y.DT_EVENTO, Y.TX_JUR_CTO, Y.TX_JUR_EVENT, Y.TX_JR_MP1520, Y.VAF1_AGENTE, Y.VAF2_AGENTE, Y.VAF3_AGENTE, Y.SOMA_VAF_AGE, Y.DT_BASE_AGEN, Y.DT_HABILITAC, Y.HAB_ACE_REG, Y.SIT_DOSS_CEF, Y.DT_PROT_CEF, Y.MSG_CRITICA, Y.COD_CRITICA, Y.DESC_CRITICA, Y.DT_TERM_ANA, Y.TA_PER_FCVS, Y.TA_VAF1_FCVS, Y.TA_VAF2_FCVS, Y.TA_VAF3_FCVS, Y.TA_SOMA_VAF, Y.TA_VAF4_FCVS, Y.TA_DT_BASE, Y.TA_DIF_UPF, Y.TA_DIF_REAIS, Y.TA_DIF_PERC, Y.TA_DIF_AGEN, Y.TA_RNV_RCV, Y.TA_DT_RNVRCV, Y.NEGAT_ABERTU, Y.DT_REPROCESS, Y.RP_PART_FCVS, Y.RP_VAF1_FCVS, Y.RP_VAF2_FCVS, Y.RP_VAF3_FCVS, Y.RP_SOMA_FCVS, Y.RP_VAF4_FCVS, Y.RP_DT_BASE, Y.RP_DIF_UPF, Y.RP_DIF_REAIS, Y.RP_DIF_PERC, Y.RP_DIF_SUPOR, Y.RP_RNV_RCV, Y.RP_DT_RNVRCV, Y.NUM_OFIC_ANA, Y.DT_OFIC_ANA, Y.PLAN_EVO, Y.DT_RECURSO, Y.STATUS_REC, Y.DT_STAT_REC, Y.AUDITADO, Y.NOTA_TECNIC, Y.DT_NOTA_TECN
                      INTO {DEST}.DBO.base_mensal_ctr_hab_fcvs
                      FROM {ORIG}.DBO.MTTBMFC Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Partes Envolvidas Processo',
            'destino' => 'cadastro_partes_envolvidas_processo',
            'sql' => "SELECT Y.CODEMP, Y.NUM_PROC, Y.FLG_PARTE, Y.SEQ_PARTE, Y.CGC_CPF_PAR, Y.FISJUR, Y.CLI_UNIC_PAR, Y.NOME_PARTE, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.FISJUR_MUT, Y.CGC_CPF_MUT, Y.CLI_UNIC_MUT, Y.NOME_MUT, Y.OBS_PARTE, Y.DTA_INCLUSAO, Y.HOR_INCLUSAO, Y.USU_INCLUSAO, Y.DTA_ALTER, Y.HOR_ALTER, Y.USU_ALTER
                      INTO {DEST}.DBO.cadastro_partes_envolvidas_processo
                      FROM {ORIG}.DBO.MTTBPAR Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Recibos Emitidos',
            'destino' => 'tabela_recibos_emitidos',
            'sql' => "SELECT Y.SEQUENCIAL, Y.NUM_PRT, Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DATA_EMIS, Y.DATA_VENC, Y.DATA_PRORROG, Y.VLR_ATUAL, Y.VLR_MORA, Y.VLR_JUR_REM, Y.VLR_MULTA, Y.VLR_TARIFA, Y.VLR_TOTAL, Y.TIPO_EMISS, Y.TIPO_ARREC, Y.FLG_COB_TERC, Y.FLG_TP_MORA, Y.FLG_REC_PAGO, Y.COD_RENG, Y.COD_DAMP, Y.CGC_CPF, Y.DESC_MOR_ANT, Y.DESC_MORA, Y.DESC_ATU_MON, Y.DESC_JUR_REM, Y.DESC_MULTA, Y.DESC_QUIT, Y.DESC_PONTUAL, Y.VLR_REC_FGTS, Y.VLR_REC_OUTR
                      INTO {DEST}.DBO.tabela_recibos_emitidos
                      FROM {ORIG}.DBO.MTTBREC Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Subsidios',
            'destino' => 'subsidio',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DTA_AUT, Y.DTA_INI, Y.DOC_AUT, Y.PRAZO, Y.VLR_Limite, Y.MOEDA, Y.CT_UTILIZ, Y.CT_U_UTIL, Y.DTA_ENCER, Y.ACCSEQ, Y.CT_DIVIDA, Y.CT_PRAZO, Y.CT_CARENCIA, Y.CT_PLANO, Y.CT_REGIAO, Y.CT_LIN_CRED, Y.CT_TX_JUROS, Y.CT_CAR_TIPO, Y.CT_SEG_MIP, Y.CT_COD_SEG, Y.CT_PLANO_SEG, Y.CT_FLG_IMPL, Y.CT_DTUINCORP
                      INTO {DEST}.DBO.SUBSIDIO
                      FROM {ORIG}.DBO.mttbsub Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Cadastro Sinistro',
            'destino' => 'cadastro_sinistro',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DT_PROTOCOLO, Y.HR_PROTOCOLO, Y.TP_SINISTRO, Y.CNPJCPFTIT, Y.DT_SINISTRO, Y.DT_AVISOSIN, Y.FISJURTIT, Y.NOMTIT, Y.PERCTIT, Y.NUMERO_FIF, Y.MUNICIPIO, Y.ST_SINISTRO, Y.ST_ATIVSINISTRO, Y.GRUPO_SINISTRO, Y.ULTIMA_FASE, Y.ACCSEQ, Y.VLSOLICITADO, Y.DTVENCTO_INAD, Y.QTDPREST_INAD, Y.VLENCARG_INAD, Y.VLATUALIZ_INAD, Y.VLCTACORR_INAD, Y.SLDEVEDOR_INAD, Y.DTVENCTO_ATU, Y.QTDPREST_ATU, Y.VLENCARG_ATU, Y.VLATUALIZ_ATU, Y.VLCTACORR_ATU, Y.SLDEVEDOR_ATU, Y.USR_INC, Y.DATA_INC, Y.HORA_INC, Y.USR_ALT, Y.DATA_ALT, Y.HORA_ALT, Y.USR_EXC, Y.DATA_EXC, Y.HORA_EXC, Y.USR_CONC, Y.DATA_CONC, Y.HORA_CONC, Y.USR_RENG, Y.DATA_RENG, Y.HORA_RENG, Y.USR_REAB, Y.DATA_REAB, Y.HORA_REAB, Y.NM_SINISTRO, Y.CODSEG, Y.PLANOSEG, Y.SLDEVEDOR_CONC, Y.VLINDENIZ_CONC, Y.VLDIFERE_CONC
                      INTO {DEST}.DBO.CADASTRO_SINISTRO
                      FROM {ORIG}.DBO.MTTBSNC Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Ocorrencias Sinistro',
            'destino' => 'ocorrencias_sinistro',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DT_PROTOCOLO, Y.HR_PROTOCOLO, Y.TP_SINISTRO, Y.CNPJCPFTIT, Y.SEQOCORR, Y.FS_SINISTRO, Y.DT_AGENDA, Y.HR_AGENDA, Y.ST_OCORRENCIA, Y.EXISTE_DOC, Y.TIPODOC, Y.REFERDOC, Y.DATA_DOC, Y.HORA_DOC, Y.USR_INC, Y.DATA_INC, Y.HORA_INC, Y.USR_ALT, Y.DATA_ALT, Y.HORA_ALT, Y.USR_EXC, Y.DATA_EXC, Y.HORA_EXC, Y.USR_CONC, Y.DATA_CONC, Y.HORA_CONC
                      INTO {DEST}.DBO.OCORRENCIAS_SINISTRO
                      FROM {ORIG}.DBO.mttbsno Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Contratos Enviados SPC',
            'destino' => 'contratos_enviados_spc',
            'sql' => "SELECT Y.CODEMP, Y.SEQARQ, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.CPFCGC, Y.NOME, Y.DTNASC, Y.DTCOMUNICA, Y.DIASPENVIO, Y.DTENVIO, Y.DTEXCLMANUAL, Y.MOTEXCMANUAL, Y.DTEXCSISTEMA, Y.DTINIATRASO, Y.DTFIMATRASO, Y.DTPAGDEBITO, Y.QTPRTATRASO, Y.VLPRTATRASO, Y.DTNEGOCIACAO, Y.STATUS, Y.DTOCORRENCIA, Y.OCORRENCIA, Y.USR_INC, Y.DATA_INC, Y.HORA_INC, Y.USR_ALT, Y.DATA_ALT, Y.HORA_ALT
                      INTO {DEST}.DBO.Contratos_Enviados_SPC
                      FROM {ORIG}.DBO.MTTBCSP Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'FGTS CEF',
            'destino' => 'fgts_cef',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DAMP, Y.DATA_EVM, Y.QTD_COT_ORIG, Y.NUM_COT_MES, Y.COTA_ORIG, Y.COTA_UTILIZ, Y.RESID_UTILIZ, Y.RESID_GERADO, Y.CORR_SALDO, Y.CORR_RESID, Y.SALDO_ATUAL, Y.RESID_ATUAL
                      INTO {DEST}.DBO.FGTS_CEF
                      FROM {ORIG}.DBO.MTTBFGC Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Ocorrencias Seguro',
            'destino' => 'ocorrencias_seguro',
            'sql' => "SELECT Y.CODEMP, Y.DT_COMPET, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DT_INICIO, Y.ETAPA_ANT, Y.ETAPA_ATU, Y.PRM_ANT_MIP, Y.PRM_ANT_CRED, Y.PRM_ANT_DFI, Y.PRM_ATU_MIP, Y.PRM_ATU_CRED, Y.PRM_ATU_DFI, Y.STATUS, Y.VL_FCVS_ANT, Y.VL_FCVS_ATU, Y.DT_COBRAR
                      INTO {DEST}.DBO.OCORRENCIAS_SEGURO
                      FROM {ORIG}.DBO.MTTBOCS Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Tabela FGTS',
            'destino' => 'tabela_fgts',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DTA_AUT, Y.DAMP, Y.DTA_INI, Y.DTA_FIM, Y.QT_CT_OR, Y.PERC_UTI, Y.VL_CT_OR, Y.VL_TO_OR, Y.PRI_COTA, Y.CT_A_UTI, Y.CT_UTILZ, Y.VL_UCOT, Y.SALDO, Y.RESIDUO, Y.DT_UL_UT, Y.ACCSEQ, Y.FL_SIT, Y.FL_ANDA, Y.FLG_USE, Y.FLG_CONC, Y.FL_RES3, Y.FL_RES4
                      INTO {DEST}.DBO.TABELA_FGTS
                      FROM {ORIG}.DBO.mttbfgt Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Historico Terceirizadas',
            'destino' => 'historico_terceirizadas',
            'sql' => "SELECT Y.CODEMP, Y.CODTRZ, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DT_INC_APURA, Y.DT_FIM_APURA, Y.FICHA_CADAST
                      INTO {DEST}.DBO.HISTORICO_TERCEIRIZADAS
                      FROM {ORIG}.DBO.MTTBHTZ Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Desdobramento Acordos',
            'destino' => 'desdobramento_acordos',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DATA_ACORDO, Y.DATA_PREST, Y.DIA_VENC, Y.NUM_PREST, Y.DATA_PAGTO, Y.DATA_COBEVM
                      INTO {DEST}.DBO.DESDOBRAMENTO_ACORDOS
                      FROM {ORIG}.DBO.MTTBLAC Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Procuradores',
            'destino' => 'procuradores',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.CGCCPF, Y.FISJUR, Y.NOME, Y.ENDERECO, Y.COMPLEMENTO, Y.BAIRRO, Y.CIDADE, Y.UF, Y.NUMCEP, Y.SUFCEP, Y.DTNASC, Y.CODNAC, Y.ESTCIV, Y.NUMIDENT, Y.ORGIDENT, Y.PROCURACAO, Y.LAVRATURA, Y.LIVRO, Y.FOLHA, Y.CARTORIO, Y.OUTORGANTE, Y.CATEG, Y.SUBCATEG
                      INTO {DEST}.DBO.PROCURADORES
                      FROM {ORIG}.DBO.MTTBPRC Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Situacoes Contrato c/ Historico',
            'destino' => 'situacoes_contrato_com_historico_inclusoes',
            'sql' => "SELECT Y.CODEMP, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.CODSIT, Y.DATASIT, Y.USUCAD, Y.DATACAD, Y.HORACAD, Y.DTVALID
                      INTO {DEST}.DBO.SITUACOES_CONTRATO_COM_HISTORICO_INCLUSOES
                      FROM {ORIG}.DBO.MTTBSC2 Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Cadastro Financiamento',
            'destino' => 'cadastro_financiamento',
            'sql' => "SELECT Y.CODEMP, Y.CONTR_UNICO, Y.IMOVEL_UNICO, Y.COD_ADQ_PRIN, Y.PART_ADQ_PRI, Y.COD_COADQ1, Y.PART_COADQ1, Y.COD_COADQ2, Y.PART_COADQ2, Y.COD_COADQ3, Y.PART_COADQ3, Y.TP_COMERC, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DTA_AQUIS, Y.VAL_AQUIS, Y.DTA_CONTABIL, Y.VAL_CONTABIL, Y.DTA_AVAL, Y.VAL_AVAL, Y.DTA_AVAL_CEF, Y.VAL_AVAL_CEF, Y.DTA_MERCADO, Y.VAL_MERCADO, Y.DTA_CMP_VEND, Y.VAL_CMP_VEND, Y.POUP_ESPECIE, Y.DTA_POUP_ESP, Y.POUP_FGTS, Y.DTA_POUP_FGT, Y.POUP_OUTROS, Y.DTA_POUP_OUT, Y.POUP_TOTAL, Y.MOD_FINANC, Y.VAL_FINANC, Y.DTA_ASS, Y.VAL_FUNDHAB, Y.VAL_TAX_FCVS, Y.VAL_TAX_TIE, Y.VAL_SEG_INI, Y.VAL_TAX_CAC, Y.VAL_TAX_OUT, Y.PLANO_FIN, Y.PRAZO_MESES, Y.PRAZO_PRORRO, Y.COD_SEG, Y.PLANO_SEG, Y.FLG_SEG_MIP, Y.FLG_SEG_CRE, Y.FLG_SEG_DFI, Y.TAX_JUR_NOMI, Y.TAX_JUR_EFET, Y.CES, Y.FATOR_Q, Y.DESCR_COMP1, Y.DESCR_COMP2, Y.DESCR_COMP3, Y.DESCR_VEND1, Y.DESCR_VEND2, Y.DESCR_VEND3, Y.DESCR_AG_FIN, Y.PART_CEF, Y.CODCAUCAO, Y.CODIGO_IM, Y.TIPOFIN, Y.ORI_REC_TIPO, Y.NUM_CED_HIPO, Y.SER_CED_HIPO, Y.LIN_CRED, Y.ORIG_FIN, Y.MES_DISSIDIO, Y.MES_APUR_REP, Y.PRAZO_CARENC, Y.TIPO_CARENC, Y.DIA_VENC, Y.DTA_PRI_REAJ, Y.ENC_DTA_VENC, Y.ENC_AJ, Y.ENC_JUR, Y.ENC_SEG_MIP, Y.ENC_SEG_CRED, Y.ENC_SEG_DFI, Y.COD_TAXA1, Y.ENC_TAXA1, Y.COD_TAXA2, Y.ENC_TAXA2, Y.COD_TAXA3, Y.ENC_TAXA3, Y.ENC_FCVS, Y.ENC_RAZAO, Y.ENC_OUTROS, Y.BANCO_COB, Y.AGENCIA_COB, Y.CONS_ORGAO, Y.CONS_MUNIC, Y.CONS_SETOR, Y.CONS_MATR, Y.DTA_INCLUSAO, Y.HOR_INCLUSAO, Y.USU_INCLUSAO, Y.DTA_ALTER, Y.HOR_ALTER, Y.USU_ALTER, Y.FLG_USU, Y.FLG_SIT_CAD, Y.ENC_SUBS, Y.QTD_MEMBROS, Y.PROG_HABIT, Y.VAL_FIN_PSH, Y.VAL_SUBS, Y.VAL_CONT_PAR, Y.VAL_REN_FAM, Y.VAL_FIN_OUTR, Y.REGIAO_ANT, Y.NUCLEO_ANT, Y.CONTRATO_ANT, Y.TRF_NUM_PRT, Y.TRF_PRZ_REM, Y.TRF_COD_REN, Y.TP_SUBRO, Y.IND_MP1520, Y.DTA_SUBR_TRF, Y.SLD_SUBR_TRF, Y.PSH_PERC_DED, Y.TIPO_DESC, Y.VAR_DESC, Y.VAL_DESC_VIS, Y.LOCAL_COB, Y.OBS_MINUTA, Y.COD_MDI, Y.CONTA_COB, Y.VAL_REF01, Y.VAL_REF02, Y.VAL_REF03
                      INTO {DEST}.DBO.CADASTRO_FINANCIAMENTO
                      FROM {ORIG}.DBO.MTTBFIN Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Andamento Processo',
            'destino' => 'andamento_processo',
            'sql' => "SELECT Y.CODEMP, Y.NUM_PROC, Y.COD_FASE, Y.DTA_FASE, Y.SEQ_FASE, Y.DTA_AGENDADA, Y.TIPO_VL_VLP, Y.COD_VL_VLP, Y.DTA_VC_VLP, Y.SEQ_VL_VLP, Y.COD_AGENDA, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DTA_AGENDA, Y.HOR_AGENDA, Y.ATZ_FASE_ATL, Y.DTA_INCLUSAO, Y.HOR_INCLUSAO, Y.USU_INCLUSAO, Y.PROC_UNICO
                      INTO {DEST}.DBO.ANDAMENTO_PROCESSO
                      FROM {ORIG}.DBO.MTTBCFP Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
        [
            'nome' => 'Valor do Processo',
            'destino' => 'valor_do_processo',
            'sql' => "SELECT Y.CODEMP, Y.NUM_PROC, Y.TIPO_PROC, Y.TIPO_VALOR, Y.COD_VALOR, Y.DTA_VENC_VAL, Y.SEQ_VALOR, Y.REGIAO, Y.NUCLEO, Y.CONTRATO, Y.DTA_EFETIV, Y.FORMA_EFETIV, Y.COMP_EFETIV, Y.VAL_EFETIV, Y.OBS_VALOR, Y.DTA_ULT_ATUA, Y.VAL_ATUALIZ, Y.DTA_RESSARC, Y.DTA_PAG_TERC, Y.DTA_INCLUSAO, Y.HOR_INCLUSAO, Y.USU_INCLUSAO, Y.NUM_CI, Y.DEBITAR, Y.DT_PREV
                      INTO {DEST}.DBO.VALOR_DO_PROCESSO
                      FROM {ORIG}.DBO.MTTBVLP Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.CON_FIDC X WHERE X.CODEMP = Y.CODEMP AND X.REGIAO = Y.REGIAO AND X.NUCLEO = Y.NUCLEO AND X.CONTRATO = Y.CONTRATO)"
        ],
    ];
}

function getPhase3_PostProcessContratos(): array
{
    return [
        [
            'nome' => 'ADD FLG_SITUACAO em Contratos',
            'sql' => "IF NOT EXISTS (SELECT 1 FROM {DEST}.sys.columns
                                     WHERE Name = 'FLG_SITUACAO'
                                       AND Object_ID = Object_ID('{DEST}.DBO.CONTRATOS'))
                       ALTER TABLE {DEST}.DBO.CONTRATOS ADD FLG_SITUACAO VARCHAR(15)"
        ],
        [
            'nome' => 'UPDATE FLG_SITUACAO',
            'sql' => "UPDATE {DEST}.DBO.CONTRATOS SET FLG_SITUACAO = 
                      (CASE 
                          WHEN (X.CTR_FLG_ATIV = 1) THEN 'ATIVO' 
                          WHEN (X.CTR_FLG_ATIV = 0) THEN 'EM IMPLANTACAO' 
                          WHEN (X.CTR_FLG_ATIV = 5) THEN 'EM IMPLANTACAO' 
                          WHEN (X.CTR_FLG_ATIV = 2) THEN 'ENCERROU PRAZO' 
                          ELSE 'LIQUIDADO'
                      END)
                      FROM {ORIG}.DBO.MTTBCON X 
                      WHERE {DEST}.DBO.CONTRATOS.CODEMP = X.CODEMP 
                        AND {DEST}.DBO.CONTRATOS.REGIAO = X.REGIAO 
                        AND {DEST}.DBO.CONTRATOS.NUCLEO = X.NUCLEO 
                        AND {DEST}.DBO.CONTRATOS.CONTRATO = X.CONTRATO"
        ],
    ];
}

function getPhase4_ImovelUnicoTables(): array
{
    return [
        [
            'nome' => 'Caracteristicas Imoveis',
            'destino' => 'caracteristica_imoveis',
            'sql' => "SELECT Y.CODEMP, Y.IMOVEL_UNICO, Y.TIPO_UNIDADE, Y.CARACTERIST, Y.AREA_PRIVADA, Y.AREA_COMUM, Y.AREA_TERRENO, Y.DTA_CONSTRUC, Y.QTD_PAVIMENT, Y.SALA_QTD, Y.SALA_PIS, Y.SALA_PAR, Y.SALA_CSV, Y.COPA_QTD, Y.COPA_PIS, Y.COPA_PAR, Y.COPA_CSV, Y.QUAR_SOC_QTD, Y.QUAR_SOC_PIS, Y.QUAR_SOC_PAR, Y.QUAR_SOC_CSV, Y.QUAR_SUI_QTD, Y.QUAR_SUI_PIS, Y.QUAR_SUI_PAR, Y.QUAR_SUI_CSV, Y.QUAR_SRV_QTD, Y.QUAR_SRV_PIS, Y.QUAR_SRV_PAR, Y.QUAR_SRV_CSV, Y.WC_SOC_QTD, Y.WC_SOC_PIS, Y.WC_SOC_PAR, Y.WC_SOC_CSV, Y.WC_SUI_QTD, Y.WC_SUI_PIS, Y.WC_SUI_PAR, Y.WC_SUI_CSV, Y.WC_SRV_QTD, Y.WC_SRV_PIS, Y.WC_SRV_PAR, Y.WC_SRV_CSV, Y.COZINHA_QTD, Y.COZINHA_PIS, Y.COZINHA_PAR, Y.COZINHA_CSV, Y.AREA_SRV_QTD, Y.AREA_SRV_PIS, Y.AREA_SRV_PAR, Y.AREA_SRV_CSV, Y.TERRACO_QTD, Y.TERRACO_PIS, Y.TERRACO_PAR, Y.TERRACO_CSV, Y.GARAGEM_QTD, Y.GARAGEM_PIS, Y.GARAGEM_PAR, Y.GARAGEM_CSV, Y.DEPOSITO_QTD, Y.DEPOSITO_PIS, Y.DEPOSITO_PAR, Y.DEPOSITO_CSV, Y.DESPENSA_QTD, Y.DESPENSA_PIS, Y.DESPENSA_PAR, Y.DESPENSA_CSV, Y.DEP_VIG_QTD, Y.DEP_VIG_PIS, Y.DEP_VIG_PAR, Y.DEP_VIG_CSV, Y.SAL_FEST_QTD, Y.SAL_FEST_PIS, Y.SAL_FEST_PAR, Y.SAL_FEST_CSV, Y.SAL_JOGO_QTD, Y.SAL_JOGO_PIS, Y.SAL_JOGO_PAR, Y.SAL_JOGO_CSV, Y.PISCINA_QTD, Y.PISCINA_CSV, Y.SAUNA_QTD, Y.SAUNA_CSV, Y.CHURRAS_QTD, Y.CHURRAS_CSV, Y.AREA_LAS_QTD, Y.AREA_LAS_CSV, Y.JARD_INT_QTD, Y.JARD_INT_CSV, Y.JARD_EXT_QTD, Y.JARD_EXT_CSV, Y.POCO_ART_QTD, Y.POCO_ART_CSV, Y.CANIL_QTD, Y.CANIL_CSV, Y.ENERGIA, Y.AGUA, Y.SANEAMENTO, Y.SIT_IMOV1, Y.SIT_IMOV2, Y.SIT_IMOV3, Y.SIT_IMOV4, Y.SIT_IMOV5, Y.DESCR_IMOV1, Y.DESCR_IMOV2, Y.DESCR_IMOV3, Y.DESCR_IMOV4, Y.DESCR_IMOV5, Y.DESCR_IMOV6, Y.DTA_INCLUSAO, Y.HOR_INCLUSAO, Y.USU_INCLUSAO, Y.DTA_ALTER, Y.HOR_ALTER, Y.USU_ALTER, Y.FLG_SIT_CAD, Y.DES_FRENTE, Y.TAM_FRENTE, Y.DES_FUNDO, Y.TAM_FUNDO, Y.DES_ESQUERDA, Y.TAM_ESQUERDA, Y.DES_DIREITA, Y.TAM_DIREITA
                      INTO {DEST}.DBO.CARACTERISTICA_IMOVEIS
                      FROM {ORIG}.DBO.MTTBIMC Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.contratos X WHERE X.CODEMP = Y.CODEMP AND X.IMOVEL_UNICO = Y.IMOVEL_UNICO)"
        ],
        [
            'nome' => 'Cadastro Imoveis 2',
            'destino' => 'cadstro_imoveis_2',
            'sql' => "SELECT Y.CODEMP, Y.IMOVEL_UNICO, Y.LOGRADOURO, Y.ENDERECO, Y.NUMERO, Y.COMPLEMENTO, Y.NOME_EDFCONJ, Y.BAIRRO, Y.MUNICIPIO, Y.COD_MUNIC, Y.ESTADO, Y.CEP, Y.REGIAO, Y.NUCLEO, Y.QUADRA, Y.LOTE, Y.BLOCO, Y.UNIDADE, Y.REGIAO_MUT, Y.NUCLEO_MUT, Y.CONTRATO_MUT, Y.TIPO_IMOVEL, Y.TIPO_USO, Y.RGI_DATA, Y.RGI_CARTORIO, Y.RGI_LIVRO, Y.RGI_FOLHA, Y.RGI_MATRIC, Y.RGI_NUMERO, Y.DTA_HABITESE, Y.COD_HABITESE, Y.CGC_INCORP, Y.RAZSOC_INCOR, Y.ORIGEM_IMOV, Y.PLD_SEGURO, Y.CAD_UNI_PROP, Y.COD_IPTU, Y.COD_SIST_PAT, Y.DTA_AQUIS, Y.VAL_AQUIS, Y.DTA_CONTABIL, Y.VAL_CONTABIL, Y.DTA_AVAL, Y.VAL_AVAL, Y.DTA_AVAL_CEF, Y.VAL_AVAL_CEF, Y.DTA_MERCADO, Y.VAL_MERCADO, Y.DTA_CMP_VEND, Y.VAL_CMP_VEND, Y.DTA_INCLUSAO, Y.HOR_INCLUSAO, Y.USU_INCLUSAO, Y.DTA_ALTER, Y.HOR_ALTER, Y.USU_ALTER, Y.FLG_USO, Y.FLG_SIT_CAD, Y.FLG_RES01, Y.FLG_RES02, Y.FLG_RES03, Y.FLG_RES04, Y.FLG_RES05, Y.FLG_RES06, Y.FLG_RES07, Y.FLG_RES08, Y.FLG_RES09, Y.FLG_RES10, Y.ACCSEQ, Y.CREA, Y.FISJUR_INCOR, Y.PROG_HABIT, Y.CAD_UNI_PRO2, Y.CAD_UNI_PRO3, Y.CAD_UNI_PRO4, Y.PUBLICARWEB, Y.DATA_PUBLIC, Y.DATA_EXPIRA, Y.DTA_DESP_JUD, Y.VAL_DESP_JUD, Y.DTA_RESERVA, Y.USU_RESERVA
                      INTO {DEST}.DBO.CADSTRO_IMOVEIS_2
                      FROM {ORIG}.DBO.MTTBIMV Y
                      WHERE EXISTS (SELECT 1 FROM {DEST}.DBO.contratos X WHERE X.CODEMP = Y.CODEMP AND X.IMOVEL_UNICO = Y.IMOVEL_UNICO)"
        ],
        [
            'nome' => 'Dados Ocupantes',
            'destino' => 'dados_ocupantes',
            'sql' => "SELECT Y.* 
                      INTO {DEST}.DBO.DADOS_OCUPANTES 
                      FROM {ORIG}.DBO.MTTBEPOC Y 
                      WHERE Y.CODEMP IN ({CODEMP}) 
                        AND EXISTS (SELECT 1 FROM {DEST}.DBO.contratos X WHERE Y.ImovelUnico = X.IMOVEL_UNICO)"
        ],
        [
            'nome' => 'Dados Mutuarios',
            'destino' => 'dados_mutuarios',
            'sql' => "SELECT Y.* 
                      INTO {DEST}.DBO.DADOS_MUTUARIOS 
                      FROM {ORIG}.DBO.MTTBEMUT Y 
                      WHERE Y.CODEMP IN ({CODEMP}) 
                        AND EXISTS (SELECT 1 FROM {DEST}.DBO.contratos X WHERE Y.ImovelUnico = X.IMOVEL_UNICO)"
        ],
    ];
}
