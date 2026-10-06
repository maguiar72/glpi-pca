<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Pca\Contratacao;
use GlpiPlugin\Pca\Painel;
use GlpiPlugin\Pca\Parametros;

Session::checkRight(Contratacao::$rightname, READ);

global $CFG_GLPI;

$exercicio = (int) ($_GET['exercicio'] ?? Contratacao::exercicioEscolhido());
$_SESSION['pca_exercicio'] = $exercicio;

Html::header('PCA - Painel', '', 'management', Contratacao::class);
TemplateRenderer::getInstance()->display('@pca/painel.html.twig', Painel::dados($exercicio) + [
    'url_lista' => Contratacao::getSearchURL(),
    'anos' => Contratacao::anos(),
    'url_base' => $CFG_GLPI['root_doc'] . '/plugins/pca/front/painel.php',
]);
// Combo de exercício: ao trocar, abre a mesma tela no ano escolhido
echo Html::scriptBlock("document.querySelectorAll('select.pca-ano').forEach(function (s) { s.addEventListener('change', function () { window.location.href = s.value; }); });");
Html::footer();
