<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Pca\Calculo;
use GlpiPlugin\Pca\Contratacao;
use GlpiPlugin\Pca\Parametros;
use GlpiPlugin\Pca\Sei;

Session::checkRight(Contratacao::$rightname, READ);

global $DB, $CFG_GLPI;

$admin = Session::haveRight('plugin_pca_config', UPDATE);
$exercicio = (int) ($_REQUEST['exercicio'] ?? Contratacao::exercicioEscolhido());
$_SESSION['pca_exercicio'] = $exercicio;
$self = $CFG_GLPI['root_doc'] . '/plugins/pca/front/sei.php';
$volta = $self . '?exercicio=' . $exercicio;

if (isset($_POST['carregar'])) {
    Session::checkRight('plugin_pca_config', UPDATE);
    $arq = $_FILES['arquivo'] ?? null;
    if (!$arq || $arq['error'] !== UPLOAD_ERR_OK) {
        Session::addMessageAfterRedirect('Selecione o arquivo exportado do módulo Contratações do SEI.', false, ERROR);
        Html::redirect($volta);
    }
    $setor = trim((string) ($_POST['setor'] ?? ''));
    try {
        $r = Sei::importar($arq['tmp_name'], pathinfo($arq['name'], PATHINFO_EXTENSION), $setor, basename($arq['name']));
        if ($setor !== '') {
            \Config::setConfigurationValues('plugin:pca', ['sei_setor' => $setor]);
        }
        $outras = $r['outras'] ? ' Ignoradas de outras unidades: ' . implode(', ', array_map(
            static fn ($k, $v) => "$k ($v)", array_keys($r['outras']), $r['outras'])) . '.' : '';
        Session::addMessageAfterRedirect(sprintf(
            'Arquivo lido: %d linhas; da unidade %s: %d (novas %d, alteradas %d, sem mudança %d).%s',
            $r['lidas'], $setor ?: 'todas', $r['unidade'], $r['novas'], $r['alteradas'], $r['iguais'], $outras
        ), false, INFO);
    } catch (\Throwable $e) {
        Session::addMessageAfterRedirect('Falha ao ler o arquivo: ' . $e->getMessage(), false, ERROR);
    }
    Html::redirect($volta);
}

if (isset($_POST['vincular'])) {
    Session::checkRight('plugin_pca_config', UPDATE);
    $ok = Sei::vincular((int) $_POST['item'], (int) ($_POST['contratacao'] ?? 0));
    Session::addMessageAfterRedirect($ok ? 'Vínculo gravado.' : 'Não foi possível vincular (a contratação já está ligada a outro item do SEI).', false, $ok ? INFO : ERROR);
    Html::redirect($volta);
}

if (isset($_POST['criar'])) {
    Session::checkRight(Contratacao::$rightname, CREATE);
    try {
        $id = Sei::criar((int) $_POST['item']);
        Session::addMessageAfterRedirect('Contratação criada no PCA a partir do SEI.', false, INFO);
    } catch (\Throwable $e) {
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    }
    Html::redirect($volta);
}

if (isset($_POST['criar_todos'])) {
    Session::checkRight(Contratacao::$rightname, CREATE);
    $ok = 0;
    $falhas = [];
    foreach (Sei::itens($exercicio) as $i) {
        if ((int) $i['contratacao_id'] > 0) {
            continue;
        }
        try {
            Sei::criar((int) $i['id']);
            $ok++;
        } catch (\Throwable $e) {
            $falhas[] = $i['identificador'] . ': ' . $e->getMessage();
        }
    }
    Session::addMessageAfterRedirect("Contratações criadas no PCA a partir do SEI: {$ok}."
        . ($falhas ? ' Falhas: ' . implode('; ', array_slice($falhas, 0, 5)) . (count($falhas) > 5 ? '…' : '') : ''),
        false, $falhas ? WARNING : INFO);
    Html::redirect($volta);
}

if (isset($_POST['aplicar'])) {
    try {
        $feitos = Sei::aplicar((int) $_POST['item'], (array) ($_POST['campos'] ?? []));
        Session::addMessageAfterRedirect($feitos ? 'Aplicado do SEI: ' . implode(', ', $feitos) : 'Nada a aplicar: já confere com o SEI.', false, INFO);
    } catch (\Throwable $e) {
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    }
    Html::redirect($self . '?exercicio=' . $exercicio . '&item=' . (int) $_POST['item']);
}

$itens  = Sei::itens($exercicio);
$livres = Sei::livres($exercicio);
$todas  = [];
foreach ($DB->request(['FROM' => Contratacao::getTable(), 'WHERE' => ['exercicio' => $exercicio, 'is_deleted' => 0]]) as $c) {
    $todas[(int) $c['id']] = $c;
}
$resumo = [];
foreach ($itens as &$i) {
    $c = $i['contratacao_id'] ? ($todas[(int) $i['contratacao_id']] ?? null) : null;
    $i['pca']      = $c;
    $i['situacao'] = Sei::situacao($i, $c);
    $i['sugestoes'] = $c ? [] : Sei::sugestoes($i, $livres);
    $i['valor_pca'] = $c['valor_global'] ?? null;
    $i['alterado_desde'] = $i['hash_aplicado'] !== null && $i['hash_aplicado'] !== $i['hash'];
    $resumo[$i['situacao']] = ($resumo[$i['situacao']] ?? 0) + 1;
}
unset($i);

$detalhe = null;
$sel = (int) ($_GET['item'] ?? 0);
foreach ($itens as $i) {
    if ($sel && (int) $i['id'] === $sel && $i['pca']) {
        $obj = new Contratacao();
        $obj->getFromDB((int) $i['pca']['id']);
        $detalhe = ['item' => $i, 'linhas' => Sei::comparar($i, $i['pca']), 'pode_alterar' => $obj->canUpdateItem()];
    }
}

Html::header('PCA - Reconciliação com o SEI', '', 'management', Contratacao::class);
TemplateRenderer::getInstance()->display('@pca/sei.html.twig', [
    'exercicio' => $exercicio, 'anos' => Contratacao::anos(), 'url_base' => $self,
    'itens' => $itens, 'resumo' => $resumo, 'livres' => $livres, 'so_pca' => $livres,
    'detalhe' => $detalhe, 'admin' => $admin, 'setor' => Parametros::get('sei_setor'),
    'url_form' => Contratacao::getFormURL(),
    'pode_criar' => Contratacao::canCreate(),
    'sem_vinculo' => count(array_filter($itens, static fn ($i) => !(int) $i['contratacao_id'])),
]);
echo Html::scriptBlock("document.querySelectorAll('select.pca-ano').forEach(function (s) { s.addEventListener('change', function () { window.location.href = s.value; }); });");
Html::footer();
