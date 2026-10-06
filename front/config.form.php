<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Pca\Contratacao;
use GlpiPlugin\Pca\Parametros;

Session::checkRight('plugin_pca_config', UPDATE);

if (isset($_POST['update'])) {
    $novos = [];
    foreach (array_keys(Parametros::padroes()) as $k) {
        if (isset($_POST[$k])) {
            $novos[$k] = is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k];
        }
    }
    Config::setConfigurationValues('plugin:pca', $novos);

    if (!empty($_POST['recalcular'])) {
        $item = new Contratacao();
        $n = 0;
        foreach ($item->find(['is_deleted' => 0]) as $linha) {
            $item->update(['id' => $linha['id'], '_recalculo' => 1]);
            $n++;
        }
        Session::addMessageAfterRedirect("Contratações recalculadas: {$n}", false, INFO);
    }
    Session::addMessageAfterRedirect('Parâmetros gravados', false, INFO);
    Html::back();
}

Html::header('PCA - Parâmetros', '', 'management', Contratacao::class);
TemplateRenderer::getInstance()->display('@pca/config.html.twig', ['p' => Parametros::todos()]);
Html::footer();
