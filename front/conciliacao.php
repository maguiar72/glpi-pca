<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Pca\Contratacao;
use GlpiPlugin\Pca\Parametros;
use GlpiPlugin\Pca\Pncp;

Session::checkRight(Contratacao::$rightname, READ);

global $DB, $CFG_GLPI;

if (isset($_POST['sincronizar'])) {
    Session::checkRight('plugin_pca_config', UPDATE);
    try {
        $n = Pncp::sincronizar();
        Session::addMessageAfterRedirect("Itens lidos do PNCP: {$n}", false, INFO);
    } catch (\Throwable $e) {
        Session::addMessageAfterRedirect('Falha na leitura do PNCP: ' . $e->getMessage(), false, ERROR);
    }
    Html::back();
}

$exercicio = (int) ($_GET['exercicio'] ?? Contratacao::exercicioEscolhido());
$_SESSION['pca_exercicio'] = $exercicio;
$linhas    = Pncp::conciliacao($exercicio);
$resumo    = [];
foreach ($linhas as $l) {
    $resumo[$l['situacao']] = ($resumo[$l['situacao']] ?? 0) + 1;
}
arsort($resumo);
$fonte = Pncp::fonte($exercicio);
$sync  = ['qtd' => $fonte['qtd'], 'ultima' => $fonte['ultima']];

Html::header('PCA - Conciliação com o PNCP', '', 'management', Contratacao::class);
TemplateRenderer::getInstance()->display('@pca/conciliacao.html.twig', [
    'exercicio' => $exercicio, 'linhas' => $linhas, 'resumo' => $resumo, 'sync' => $sync,
    'base_valor' => Parametros::get('pncp_valor_total') === 'global' ? 'Valor Global' : 'Valor Anual',
    'pode_sincronizar' => Session::haveRight('plugin_pca_config', UPDATE) && $fonte['tipo'] !== 'plugin',
    'fonte_tipo' => $fonte['tipo'],
    'url_form' => Contratacao::getFormURL(),
    'uasg' => Parametros::get('uasg'),
    'anos' => Contratacao::anos(),
    'url_base' => $CFG_GLPI['root_doc'] . '/plugins/pca/front/conciliacao.php',
]);
// Combo de exercício: ao trocar, abre a mesma tela no ano escolhido
echo Html::scriptBlock("document.querySelectorAll('select.pca-ano').forEach(function (s) { s.addEventListener('change', function () { window.location.href = s.value; }); });");
Html::footer();
