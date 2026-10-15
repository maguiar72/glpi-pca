<?php
/**
 * Aplica do SEI o valor das contratações com pagamento "Parcelado" que ainda têm o total em "valor único"
 * (total do SEI × 12 / vigência = valor cheio no ano). Uso (no contêiner do GLPI, como www-data):
 *   php plugins/pca/tools/aplicar-valor-parcelado.php [--aplicar]
 * Sem --aplicar apenas simula. Mexe só no campo "Valor global"; o histórico do GLPI registra a mudança.
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
$_SESSION['glpiname']                  = 'aplicar-valor-parcelado';
$_SESSION['glpiactiveprofile']         = ['id' => 4, 'plugin_pca_contratacao' => ALLSTANDARDRIGHT, 'plugin_pca_config' => READ | UPDATE];
$aplicar = in_array('--aplicar', $argv, true);

$n = $ok = $pulados = 0;
$soma_antes = $soma_depois = 0.0;
foreach (Sei::itens(2027) as $i) {
    $c = new Contratacao();
    if (!(int) $i['contratacao_id'] || !$c->getFromDB((int) $i['contratacao_id'])) {
        continue;
    }
    $f = $c->fields;
    if ($f['pagamento'] !== 'Parcelado' || (float) $f['valor_unico'] <= 0 || (float) $f['valor_cheio_ano'] > 0) {
        continue;
    }
    $n++;
    $linha = null;
    foreach (Sei::comparar($i, $f) as $l) {
        if ($l['campo'] === 'valor_global') {
            $linha = $l;
        }
    }
    if (!$linha || !$linha['aplicavel']) {
        $pulados++;
        printf("PULA  %-9s %-26.26s %s\n", $i['identificador'], $f['name'], $linha['nota'] ?? 'sem linha de valor');
        continue;
    }
    $cheio = $linha['grava']['valor_cheio_ano'] ?? null;
    printf("%s %-9s %-26.26s vig=%-3s total SEI=%14s -> cheio/ano=%14s (global antes=%s)\n", $aplicar ? 'APLICA' : 'SIMULA',
        $i['identificador'], $f['name'], $f['vigencia_meses'], number_format((float) $linha['sei'], 2, ',', '.'),
        number_format((float) $cheio, 2, ',', '.'), number_format((float) $f['valor_global'], 2, ',', '.'));
    $soma_antes += (float) $f['valor_global'];
    if ($aplicar) {
        Sei::aplicar((int) $i['id'], ['valor_global']);
        $c->getFromDB((int) $i['contratacao_id']);
        $soma_depois += (float) $c->fields['valor_global'];
    }
    $ok++;
}
echo "\nParceladas: {$n}; ", $aplicar ? 'aplicadas' : 'a aplicar', ": {$ok}; puladas: {$pulados}\n";
echo 'Soma do valor global antes: ', number_format($soma_antes, 2, ',', '.'), $aplicar ? ' | depois: ' . number_format($soma_depois, 2, ',', '.') : '', "\n";
