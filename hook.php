<?php

use GlpiPlugin\Pca\Contratacao;
use GlpiPlugin\Pca\Parametros;
use GlpiPlugin\Pca\Pncp;

function plugin_pca_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists('glpi_plugin_pca_contratacaos')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_pca_contratacaos` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id` int {$sign} NOT NULL DEFAULT '0',
            `is_recursive` tinyint NOT NULL DEFAULT '1',
            `name` varchar(255) DEFAULT NULL,
            `exercicio` int NOT NULL DEFAULT '0',
            `prioridade` int DEFAULT NULL,
            `destinacao` varchar(100) DEFAULT NULL,
            `numero_pca` varchar(50) DEFAULT NULL,
            `groups_id` int {$sign} NOT NULL DEFAULT '0',
            `requisitante` varchar(100) DEFAULT NULL,
            `objeto` text,
            `tipo` varchar(100) DEFAULT NULL,
            `justificativa` text,
            `processo_sei` varchar(100) DEFAULT NULL,
            `status` varchar(150) DEFAULT NULL,
            `origem` varchar(100) DEFAULT NULL,
            `necessidade` varchar(50) DEFAULT NULL,
            `data_limite` date DEFAULT NULL,
            `vigencia_meses` int DEFAULT NULL,
            `pagamento` varchar(50) DEFAULT NULL,
            `valor_unico` decimal(20,2) DEFAULT NULL,
            `valor_cheio_ano` decimal(20,2) DEFAULT NULL,
            `meses_exercicio` int DEFAULT NULL,
            `valor_anual` decimal(20,2) DEFAULT NULL,
            `valor_global` decimal(20,2) DEFAULT NULL,
            `observacoes` text,
            `conferido` tinyint NOT NULL DEFAULT '0',
            `pendencias` text,
            `nota_revisao` text,
            `pncp_grupo_codigo` varchar(50) DEFAULT NULL,
            `valor_anual_original` decimal(20,2) DEFAULT NULL,
            `valor_global_original` decimal(20,2) DEFAULT NULL,
            `projects_id` int {$sign} NOT NULL DEFAULT '0',
            `contracts_id` int {$sign} NOT NULL DEFAULT '0',
            `is_deleted` tinyint NOT NULL DEFAULT '0',
            `date_mod` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`),
            KEY `exercicio` (`exercicio`),
            KEY `groups_id` (`groups_id`),
            KEY `pncp_grupo_codigo` (`pncp_grupo_codigo`),
            KEY `is_deleted` (`is_deleted`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    if (!$DB->tableExists('glpi_plugin_pca_pncpitens')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_pca_pncpitens` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `exercicio` int NOT NULL DEFAULT '0',
            `pncp_id_plano` varchar(60) DEFAULT NULL,
            `sequencial` int DEFAULT NULL,
            `numero_item` int DEFAULT NULL,
            `grupo_codigo` varchar(50) DEFAULT NULL,
            `grupo_nome` varchar(255) DEFAULT NULL,
            `categoria_cod` int DEFAULT NULL,
            `categoria_nome` varchar(100) DEFAULT NULL,
            `classificacao_codigo` varchar(20) DEFAULT NULL,
            `classificacao_nome` varchar(255) DEFAULT NULL,
            `valor_total` decimal(20,4) DEFAULT NULL,
            `valor_orcamento_exercicio` decimal(20,4) DEFAULT NULL,
            `data_desejada` date DEFAULT NULL,
            `data_atualizacao` varchar(30) DEFAULT NULL,
            `origem_dado` varchar(30) DEFAULT NULL,
            `raw` longtext,
            `date_sync` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicidade` (`exercicio`,`grupo_codigo`,`numero_item`),
            KEY `grupo_codigo` (`grupo_codigo`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // Parâmetros padrão (não sobrescreve o que já existe)
    $atuais = Config::getConfigurationValues('plugin:pca');
    $novos  = array_diff_key(Parametros::padroes(), $atuais);
    if ($novos) {
        Config::setConfigurationValues('plugin:pca', $novos);
    }

    // Direitos: perfis que administram a configuração do GLPI recebem tudo, mas só quando o direito é
    // criado agora. Em atualização os direitos já ajustados por perfil são preservados.
    $novos_direitos = array_values(array_filter(
        ['plugin_pca_contratacao', 'plugin_pca_config'],
        static fn ($d) => !countElementsInTable('glpi_profilerights', ['name' => $d])
    ));
    if ($novos_direitos) {
        ProfileRight::addProfileRights($novos_direitos);
        foreach ($DB->request(['SELECT' => 'profiles_id', 'FROM' => 'glpi_profilerights',
            'WHERE' => ['name' => 'config', 'rights' => ['&', UPDATE]]]) as $row) {
            $conceder = [];
            if (in_array('plugin_pca_contratacao', $novos_direitos, true)) {
                $conceder['plugin_pca_contratacao'] = ALLSTANDARDRIGHT;
            }
            if (in_array('plugin_pca_config', $novos_direitos, true)) {
                $conceder['plugin_pca_config'] = READ | UPDATE;
            }
            ProfileRight::updateProfileRights($row['profiles_id'], $conceder);
        }
    }

    // Colunas padrão da listagem
    $colunas = [5, 4, 7, 11, 12, 15, 16, 10, 17];
    if (!countElementsInTable('glpi_displaypreferences', ['itemtype' => Contratacao::class, 'users_id' => 0])) {
        foreach ($colunas as $rank => $num) {
            $DB->insert('glpi_displaypreferences', [
                'itemtype' => Contratacao::class, 'num' => $num, 'rank' => $rank + 1, 'users_id' => 0,
            ]);
        }
    }

    CronTask::register(Pncp::class, 'SincronizarPncp', DAY_TIMESTAMP, [
        'comment' => 'Lê o PCA publicado no PNCP para a UASG configurada',
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_DISABLE,
    ]);

    return true;
}

function plugin_pca_uninstall(): bool
{
    global $DB;

    foreach (['glpi_plugin_pca_contratacaos', 'glpi_plugin_pca_pncpitens'] as $t) {
        $DB->doQuery("DROP TABLE IF EXISTS `{$t}`");
    }
    $DB->delete('glpi_displaypreferences', ['itemtype' => Contratacao::class]);
    $DB->delete('glpi_logs', ['itemtype' => Contratacao::class]);
    ProfileRight::deleteProfileRights(['plugin_pca_contratacao', 'plugin_pca_config']);
    $cfg = new Config();
    $cfg->deleteByCriteria(['context' => 'plugin:pca']);
    CronTask::unregister('pca');

    return true;
}
