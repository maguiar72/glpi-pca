<?php
/**
 * Aplica do SEI, em lote, só os campos informados nas contratações JÁ vinculadas (mesma regra do botão
 * "Aplicar do SEI" da reconciliação; o histórico do GLPI registra). Uso (contêiner do GLPI, www-data):
 *   php aplicar-campos-sei.php --campos=planejamento_estrategico,vinculos_programacoes [--aplicar] [exercicio]
 * Campos válidos: as chaves de GlpiPlugin\Pca\Sei::CAMPOS. Sem --aplicar apenas simula.
 */

use GlpiPlugin\Pca\Contratacao;
use GlpiPlugin\Pca\Sei;

if (PHP_SAPI !== 'cli') {
    exit("Somente linha de comando.\n");
}
chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';
(new \Glpi\Kernel\Kernel())->boot();
$_SESSION['glpiactiveentities']        = [0];
$_SESSION['glpiactive_entity']         = 0;
$_SESSION['glpiactiveentities_string'] = "'0'";
$_SESSION['glpiname']                  = 'aplicar-campos-sei';
$_SESSION['glpiactiveprofile']         = ['id' => 4, 'plugin_pca_contratacao' => ALLSTANDARDRIGHT, 'plugin_pca_config' => READ | UPDATE];

$campos = [];
$aplicar = false;
$exercicio = 2027;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--campos=') === 0) {
        $campos = array_filter(explode(',', substr($a, 9)));
    } elseif ($a === '--aplicar') {
        $aplicar = true;
    } elseif (ctype_digit($a)) {
        $exercicio = (int) $a;
    }
}
$validos = array_keys(Sei::CAMPOS);
if (!$campos || array_diff($campos, $validos)) {
    exit("Informe --campos= com chaves válidas: " . implode(', ', $validos) . "\n");
}

$n = $alt = 0;
foreach (Sei::itens($exercicio) as $i) {
    $c = new Contratacao();
    if (!(int) $i['contratacao_id'] || !$c->getFromDB((int) $i['contratacao_id'])) {
        continue;
    }
    $n++;
    $linhas = array_filter(Sei::comparar($i, $c->fields),
        static fn ($l) => in_array($l['campo'], $campos, true) && !$l['igual'] && $l['aplicavel']);
    if (!$linhas) {
        continue;
    }
    $alt++;
    foreach ($linhas as $l) {
        printf("%s %-9s %-26.26s %s: %s -> %s\n", $aplicar ? 'APLICA' : 'SIMULA', $i['identificador'], $c->fields['name'],
            $l['rotulo'], mb_substr(str_replace("\n", ' / ', $l['pca']), 0, 20) ?: '∅', mb_substr(str_replace("\n", ' / ', $l['sei']), 0, 70));
    }
    if ($aplicar) {
        Sei::aplicar((int) $i['id'], $campos);
    }
}
echo "\nVinculadas: {$n}; com alteração: {$alt}\n";
