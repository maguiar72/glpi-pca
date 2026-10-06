<?php
/**
 * Plugin PCA - Plano de Contratações Anual
 * Cadastro das contratações do PCA, cálculo de Valor Anual e Valor Global,
 * pendências automáticas, painel e conciliação com o PNCP.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Pca\Contratacao;
use GlpiPlugin\Pca\Perfil;

define('PLUGIN_PCA_VERSION', '0.1.5');
define('PLUGIN_PCA_MIN_GLPI', '11.0.0');
define('PLUGIN_PCA_MAX_GLPI', '11.0.99');

function plugin_init_pca(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['pca'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('pca')) {
        return;
    }

    Plugin::registerClass(Perfil::class, ['addtabon' => ['Profile']]);
    Plugin::registerClass(Contratacao::class);

    if (Session::getLoginUserID()) {
        if (Session::haveRight(Contratacao::$rightname, READ)) {
            $PLUGIN_HOOKS[Hooks::MENU_TOADD]['pca'] = ['management' => Contratacao::class];
        }
        if (Session::haveRight('plugin_pca_config', UPDATE)) {
            $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['pca'] = 'front/config.form.php';
        }
    }
}

function plugin_version_pca(): array
{
    return [
        'name'         => 'PCA - Plano de Contratações Anual',
        'version'      => PLUGIN_PCA_VERSION,
        'author'       => 'Marcos Aguiar',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_PCA_MIN_GLPI, 'max' => PLUGIN_PCA_MAX_GLPI],
            'php'  => ['min' => '8.2'],
        ],
    ];
}

function plugin_pca_check_prerequisites(): bool
{
    return true;
}

function plugin_pca_check_config($verbose = false): bool
{
    return true;
}

/**
 * Exibição das listas (Hooks::AUTO_GIVE_ITEM): Valor Anual e Valor Global como "R$ 1.234,56".
 * As colunas continuam decimais, então filtros (">1000000") e ordenação seguem funcionando.
 */
function plugin_pca_giveItem($type, $id, $data, $num)
{
    if ($type !== Contratacao::class) {
        return '';
    }
    $opcao = Glpi\Search\SearchOption::getOptionsForItemtype($type)[$id] ?? [];
    if (!in_array($opcao['field'] ?? '', ['valor_anual', 'valor_global'], true)) {
        return '';
    }
    $valor = $data[$num][0]['name'] ?? null;
    if ($valor === null || $valor === '') {
        return '';
    }

    return "<span class='text-nowrap'>" . htmlescape('R$ ' . number_format((float) $valor, 2, ',', '.')) . '</span>';
}
