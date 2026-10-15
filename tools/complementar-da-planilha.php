<?php
/**
 * Complementa as contratações do PCA com a planilha de planejamento (JSON de planilha-para-json.py).
 * A planilha tem prioridade em: apelido, N.PCA e destinação (pelo Orçamento: CJF/JF=Ambos, CJF=CJF, JF=NUJUFE).
 * Nos demais campos só PREENCHE o que está vazio (requisitante, grupo, status, observações). O SEI continua mandatário de valores, datas,
 * tipo, prioridade e justificativa: estes não são tocados. O número que o SEI traz na Observação
 * (PCTIC-aaaa-nnn) vai para "N. PCTIC".
 *
 *   php complementar-da-planilha.php /tmp/planilha-pca-2027.json [--aplicar] [exercicio]
 * Sem --aplicar apenas simula.
 */

use GlpiPlugin\Pca\Contratacao;
use GlpiPlugin\Pca\Parametros;

if (PHP_SAPI !== 'cli') {
    exit("Somente linha de comando.\n");
}
chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';
(new \Glpi\Kernel\Kernel())->boot();
$_SESSION['glpiactiveentities']        = [0];
$_SESSION['glpiactive_entity']         = 0;
$_SESSION['glpiactiveentities_string'] = "'0'";
$_SESSION['glpiname']                  = 'complementar-planilha';
$_SESSION['glpiactiveprofile']         = ['id' => 4, 'plugin_pca_contratacao' => ALLSTANDARDRIGHT, 'plugin_pca_config' => READ | UPDATE];

$arq      = $argv[1] ?? '';
$aplicar  = in_array('--aplicar', $argv, true);
$exercicio = (int) (array_values(array_filter(array_slice($argv, 2), 'ctype_digit'))[0] ?? 2027);
$linhas   = json_decode((string) @file_get_contents($arq), true);
if (!is_array($linhas)) {
    exit("JSON inválido: $arq\n");
}
$por_pctic = [];
foreach ($linhas as $l) {
    $por_pctic[(string) $l['pctic']] = $l;
}

global $DB;
$grupos = [];
foreach ($DB->request(['SELECT' => ['id', 'code'], 'FROM' => 'glpi_groups']) as $g) {
    if (!empty($g['code'])) {
        $grupos[mb_strtoupper($g['code'])] = (int) $g['id'];
    }
}
$status_lista = Parametros::lista('status');
$norm = static fn (string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
$status_idx = [];
foreach ($status_lista as $v) {
    $status_idx[$norm($v)] = $v;
}
$dest_idx = [];
foreach (Parametros::lista('destinacao') as $v) {
    $dest_idx[$norm($v)] = $v;
}

$cont = ['linhas' => 0, 'sem_planilha' => [], 'campos' => []];
$usadas = [];
foreach ($DB->request(['FROM' => Contratacao::getTable(), 'WHERE' => ['exercicio' => $exercicio, 'is_deleted' => 0], 'ORDER' => 'id']) as $f) {
    $cont['linhas']++;
    // importações anteriores gravaram o PCTIC em numero_pca; a coluna numero_pctic passa a guardá-lo
    $pctic = (string) (($f['numero_pctic'] ?? '') !== '' ? $f['numero_pctic'] : $f['numero_pca']);
    $p = $por_pctic[$pctic] ?? null;
    if (!$p) {
        $cont['sem_planilha'][] = ($f['sei_identificador'] ?: $f['id']) . ' ' . $f['name'];
        // sem planilha: o número que veio do SEI não é N.PCA; passa para N. PCTIC
        if (($f['numero_pctic'] ?? '') === '' && (string) $f['numero_pca'] !== '') {
            $cont['campos']['numero_pctic'] = ($cont['campos']['numero_pctic'] ?? 0) + 1;
            printf("%s #%d %-26.26s  numero_pctic: ∅ → %s | numero_pca: %s → ∅\n", $aplicar ? 'APLICA' : 'SIMULA',
                $f['id'], $f['name'], $f['numero_pca'], $f['numero_pca']);
            if ($aplicar) {
                $c = new Contratacao();
                $c->getFromDB((int) $f['id']);
                $c->update(['id' => (int) $f['id'], 'numero_pctic' => (string) $f['numero_pca'], 'numero_pca' => '']);
            }
        }
        continue;
    }
    $usadas[$pctic] = true;
    $novo = [];
    $vazio = static fn ($v) => $v === null || trim((string) $v) === '' || $v === 'NULL';

    if (($f['numero_pctic'] ?? '') !== $pctic) {
        $novo['numero_pctic'] = $pctic;
    }
    if ((string) $f['numero_pca'] !== (string) $p['n_pca']) {
        $novo['numero_pca'] = (string) $p['n_pca'];
    }
    if ($p['apelido'] !== '' && $p['apelido'] !== $f['name']) {
        $novo['name'] = $p['apelido'];
    }
    $req = (string) $f['requisitante'];
    if ($vazio($req) && $p['requisitante'] !== '') {
        $req = mb_strtoupper($p['requisitante']);
        $novo['requisitante'] = $req;
    }
    if ((int) $f['groups_id'] === 0 && $req !== '') {
        foreach (preg_split('~[/\s]+~', mb_strtoupper($req)) as $cod) {
            if (isset($grupos[$cod])) {
                $novo['groups_id'] = $grupos[$cod];
                break;
            }
        }
    }
    // Destinação pelo orçamento da planilha: CJF/JF => Ambos; CJF => CJF; JF => NUJUFE / Sistemas Nacionais.
    // O orçamento da planilha é regra explícita e prevalece sobre a destinação atual; sem orçamento na planilha,
    // vale a destinação do pca_2027.json (por N.PCA), só se vazia.
    $mapa_orc = ['CJF/JF' => 'Ambos', 'JF/CJF' => 'Ambos', 'CJF' => 'CJF', 'JF' => 'NUJUFE / Sistemas Nacionais'];
    $orc = strtoupper(preg_replace('~\s+~', '', (string) ($p['orcamento'] ?? '')));
    if (isset($mapa_orc[$orc])) {
        $dest = $dest_idx[$norm($mapa_orc[$orc])] ?? $mapa_orc[$orc];
        if ((string) $f['destinacao'] !== $dest) {
            $novo['destinacao'] = $dest;
        }
    } elseif ($vazio($f['destinacao']) && $p['destinacao'] !== '') {
        $novo['destinacao'] = $dest_idx[$norm($p['destinacao'])] ?? $p['destinacao'];
    }
    if ($vazio($f['status']) && $p['status'] !== '') {
        $s = $norm($p['status']);
        if (isset($status_idx[$s])) {
            $novo['status'] = $status_idx[$s];
        } else {
            $cont['campos']['status_sem_equivalente'][] = $f['name'] . ' = "' . $p['status'] . '"';
        }
    }
    if ($vazio($f['observacoes']) && trim($p['observacoes']) !== '') {
        $novo['observacoes'] = trim($p['observacoes']);
    }
    if (!$novo) {
        continue;
    }
    foreach ($novo as $k => $v) {
        $cont['campos'][$k] = ($cont['campos'][$k] ?? 0) + 1;
    }
    $resumo = [];
    foreach ($novo as $k => $v) {
        $ant = (string) $f[$k];
        $resumo[] = $k . ': ' . ($ant === '' || $ant === 'NULL' ? '∅' : mb_substr($ant, 0, 22)) . ' → ' . mb_substr((string) $v, 0, 30);
    }
    printf("%s #%d %-26.26s  %s\n", $aplicar ? 'APLICA' : 'SIMULA', $f['id'], $f['name'], implode(' | ', $resumo));
    if ($aplicar) {
        $c = new Contratacao();
        $c->getFromDB((int) $f['id']);
        $in = $novo + ['id' => (int) $f['id']];
        if (!$c->update($in)) {
            echo "  FALHOU #{$f['id']}\n";
        }
    }
}
echo "\nContratações do exercício: {$cont['linhas']}\n";
echo 'Campos ', $aplicar ? 'alterados' : 'a alterar', ': ', json_encode(array_filter($cont['campos'], 'is_int'), JSON_UNESCAPED_UNICODE), "\n";
if (!empty($cont['campos']['status_sem_equivalente'])) {
    echo 'Status sem equivalente na lista (não aplicados): ', implode('; ', $cont['campos']['status_sem_equivalente']), "\n";
}
echo 'Sem linha na planilha (', count($cont['sem_planilha']), '): ', implode('; ', $cont['sem_planilha']), "\n";
$fora = array_diff_key($por_pctic, $usadas);
echo 'Na planilha e fora do GLPI (', count($fora), '): ', implode('; ', array_map(static fn ($l) => $l['apelido'] . ' (PCTIC ' . $l['pctic'] . ', ' . $l['aba'] . ')', $fora)), "\n";
