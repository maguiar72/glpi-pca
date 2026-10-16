<?php
/**
 * Grava o "Código no PNCP" das contratações a partir de um mapa {"PC61/27": "90026-110/2027", ...}
 * (chave = identificador do SEI). Só preenche contratações SEM código e exige que o código exista nos itens
 * do PNCP do exercício; nunca sobrescreve. Uso (contêiner do GLPI, www-data):
 *   php relacionar-pncp.php /tmp/relacao-pncp-2027.json [--aplicar] [exercicio]
 */

use GlpiPlugin\Pca\Contratacao;
use GlpiPlugin\Pca\Pncp;
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
$_SESSION['glpiname']                  = 'relacionar-pncp';
$_SESSION['glpiactiveprofile']         = ['id' => 4, 'plugin_pca_contratacao' => ALLSTANDARDRIGHT, 'plugin_pca_config' => READ | UPDATE];

$mapa = json_decode((string) @file_get_contents($argv[1] ?? ''), true);
if (!is_array($mapa)) {
    exit("Mapa inválido.\n");
}
$aplicar   = in_array('--aplicar', $argv, true);
$exercicio = (int) (array_values(array_filter(array_slice($argv, 2), 'ctype_digit'))[0] ?? 2027);

$existentes = [];
foreach (Pncp::fonte($exercicio)['itens'] as $r) {
    $existentes[$r['grupo_codigo']] = ($existentes[$r['grupo_codigo']] ?? 0) + (float) $r['valor_total'];
}
$ok = $pulados = 0;
foreach (Sei::itens($exercicio) as $i) {
    $cod = $mapa[$i['identificador']] ?? null;
    if ($cod === null || !(int) $i['contratacao_id']) {
        continue;
    }
    $c = new Contratacao();
    if (!$c->getFromDB((int) $i['contratacao_id'])) {
        continue;
    }
    $f = $c->fields;
    if (trim((string) $f['pncp_grupo_codigo']) !== '') {
        echo "PULA   {$i['identificador']} {$f['name']}: já tem código {$f['pncp_grupo_codigo']}\n";
        $pulados++;
        continue;
    }
    if (!isset($existentes[$cod])) {
        echo "PULA   {$i['identificador']} {$f['name']}: código $cod não existe no PNCP lido\n";
        $pulados++;
        continue;
    }
    printf("%s %-9s %-26.26s -> %-15s PNCP R\$ %s\n", $aplicar ? 'APLICA' : 'SIMULA', $i['identificador'], $f['name'], $cod,
        number_format($existentes[$cod], 2, ',', '.'));
    if ($aplicar) {
        $c->update(['id' => (int) $f['id'], 'pncp_grupo_codigo' => $cod]);
    }
    $ok++;
}
echo "\n", $aplicar ? 'Gravados' : 'A gravar', ": {$ok}; pulados: {$pulados}\n";
