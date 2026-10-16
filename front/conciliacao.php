<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Pca\Contratacao;
use GlpiPlugin\Pca\Parametros;
use GlpiPlugin\Pca\Pncp;

Session::checkRight(Contratacao::$rightname, READ);

global $DB, $CFG_GLPI;

if (isset($_POST['sincronizar']) || isset($_POST['reler_item'])) {
    Session::checkRight('plugin_pca_config', UPDATE);
    $ano    = (int) ($_POST['exercicio'] ?? Contratacao::exercicioEscolhido());
    $codigo = isset($_POST['reler_item']) ? trim((string) $_POST['reler_item']) : '';
    $soma = static function (int $ano, string $cod): ?float {
        $v = null;
        foreach (Pncp::fonte($ano)['itens'] as $r) {
            if ((string) $r['grupo_codigo'] === $cod) {
                $v = ($v ?? 0.0) + (float) $r['valor_total'];
            }
        }
        return $v;
    };
    try {
        $antes = $codigo !== '' ? $soma($ano, $codigo) : null;
        // O PNCP entrega o plano inteiro da UASG numa só leitura; o "item" apenas destaca o resultado dele
        $n = Pncp::sincronizar($ano);
        if ($codigo !== '') {
            $depois = $soma($ano, $codigo);
            $fmt = static fn ($v) => $v === null ? 'não encontrado' : 'R$ ' . number_format($v, 2, ',', '.');
            Session::addMessageAfterRedirect($antes === $depois
                ? "{$codigo}: sem mudança ({$fmt($depois)}). Itens lidos do PNCP: {$n}."
                : "{$codigo}: {$fmt($antes)} → {$fmt($depois)}. Itens lidos do PNCP: {$n}.", false, INFO);
        } else {
            Session::addMessageAfterRedirect("Itens lidos do PNCP: {$n}", false, INFO);
        }
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
    'pode_sincronizar' => Session::haveRight('plugin_pca_config', UPDATE),
    'fonte_tipo' => $fonte['tipo'],
    'url_form' => Contratacao::getFormURL(),
    'uasg' => Parametros::get('uasg'),
    'anos' => Contratacao::anos(),
    'url_base' => $CFG_GLPI['root_doc'] . '/plugins/pca/front/conciliacao.php',
]);
// Combo de exercício: ao trocar, abre a mesma tela no ano escolhido
echo Html::scriptBlock("document.querySelectorAll('select.pca-ano').forEach(function (s) { s.addEventListener('change', function () { window.location.href = s.value; }); });");
Html::footer();
