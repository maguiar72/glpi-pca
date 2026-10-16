<?php
/** Somente leitura: lista contratações sem código/código inexistente e os itens TIC do PNCP fora do cadastro. */
use GlpiPlugin\Pca\Pncp;

if (PHP_SAPI !== 'cli') {
    exit("Somente linha de comando.\n");
}
$raiz = getenv('GLPI_ROOT') ?: '/var/www/glpi';
chdir($raiz);
require $raiz . '/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();
$_SESSION['glpiactiveentities'] = [0];
$_SESSION['glpiactive_entity'] = 0;
$_SESSION['glpiactiveentities_string'] = "'0'";
$ex = (int) ($argv[1] ?? 2027);
$out = ['pendentes' => [], 'livres' => []];
global $DB;
foreach (Pncp::conciliacao($ex) as $l) {
    if ($l['situacao'] === 'No PNCP como TIC, fora do cadastro') {
        $out['livres'][] = ['codigo' => $l['codigo'], 'nome' => $l['objeto_pncp'], 'valor' => $l['valor_pncp'], 'data' => $l['data_pncp']];
    } elseif (in_array($l['situacao'], ['Sem código do PNCP', 'Código não encontrado no PNCP'], true)) {
        $c = $DB->request(['SELECT' => ['objeto', 'valor_global', 'valor_anual', 'necessidade'], 'FROM' => 'glpi_plugin_pca_contratacaos', 'WHERE' => ['id' => $l['id']]])->current();
        $out['pendentes'][] = ['apelido' => $l['apelido'], 'pca' => $l['numero_pca'], 'situacao' => $l['situacao'], 'codigo' => $l['codigo'],
            'objeto' => mb_substr((string) $c['objeto'], 0, 110), 'anual' => $c['valor_anual'], 'global' => $c['valor_global'], 'data' => $l['data_cadastro'], 'nec' => $c['necessidade']];
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
