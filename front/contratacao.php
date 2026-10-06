<?php

use GlpiPlugin\Pca\Contratacao;

Session::checkRight(Contratacao::$rightname, READ);

// Exercício filtrado agora (critério "Exercício é igual a ..."), para destacar o botão
$ativo = null;
try {
    $params = Search::manageParams(Contratacao::class, $_GET);
} catch (\Throwable $e) {
    $params = $_GET;
}
foreach ((array) ($params['criteria'] ?? []) as $c) {
    if (is_array($c) && (string) ($c['field'] ?? '') === '3' && ($c['searchtype'] ?? '') === 'equals' && ctype_digit((string) ($c['value'] ?? ''))) {
        $ativo = (int) $c['value'];
    }
}
if ($ativo !== null) {
    $_SESSION['pca_exercicio'] = $ativo;
}

Html::header(Contratacao::getTypeName(2), '', 'management', Contratacao::class);

$base = Contratacao::getSearchURL();
echo "<div class='container-fluid mb-2 d-flex align-items-center'>";
echo "<label class='me-2 mb-0 fw-bold' for='pca-ano-lista'>Exercício</label>";
echo "<select id='pca-ano-lista' class='form-select form-select-sm w-auto pca-ano' aria-label='Exercício'>";
echo "<option value='" . htmlescape($base . '?reset=reset') . "'" . ($ativo === null ? ' selected' : '') . ">Todos</option>";
foreach (Contratacao::anos() as $ano) {
    $url = $base . '?' . http_build_query(['reset' => 'reset', 'criteria' => [['field' => 3, 'searchtype' => 'equals', 'value' => $ano]]]);
    echo "<option value='" . htmlescape($url) . "'" . ($ativo === $ano ? ' selected' : '') . ">" . $ano . "</option>";
}
echo "</select></div>";
echo Html::scriptBlock("document.querySelectorAll('select.pca-ano').forEach(function (s) { s.addEventListener('change', function () { window.location.href = s.value; }); });");

Search::show(Contratacao::class);
Html::footer();
