<?php

namespace GlpiPlugin\Pca;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Programação de contratações do módulo "Contratações" do SEI (mandatário do PCA).
 *
 * Fluxo: a exportação do módulo (XLS/XLSX/CSV) é carregada para a tabela de staging
 * glpi_plugin_pca_seiitens, só com as linhas da unidade requisitante configurada (CEGOVE).
 * Cada linha é vinculada a uma contratação do PCA e reconciliada individualmente: o gestor
 * vê o comparativo campo a campo e aplica o que vem do SEI. Nada é gravado no SEI.
 */
class Sei
{
    public const TABELA = 'glpi_plugin_pca_seiitens';

    /**
     * Rótulos conhecidos (normalizados, por prefixo, na ordem) => chave interna. Vale para o cabeçalho da
     * exportação em lista e para os rótulos da tela "Consultar Programação de Contratação" (coletor).
     */
    private const PADROES = [
        ['identificador', 'identificador'],
        ['codigo do item', 'codigo'], ['codigo', 'codigo'],
        ['descricao', 'descricao'],
        ['tipo da contratacao', 'tipo'],
        ['necessidade de mao', 'mao_de_obra'], ['requer mao', 'mao_de_obra'], ['mao de obra', 'mao_de_obra'],
        ['justificativa', 'justificativa'],
        ['valor total estimado', 'valor'], ['valor total r', 'valor'],
        ['vigencia estimada em meses', 'vigencia'], ['vigencia estimada meses', 'vigencia'],
        ['mes estimado', 'mes'], ['contratacao mm', 'mes'],
        ['fim da vigencia mm', 'fim_vigencia'],
        ['orgao', 'orgao'],
        ['setor demandante', 'setor'], ['unidade requisitante', 'setor'], ['unidade', 'setor'],
        ['pt', 'programa'],
        ['prioridade', 'prioridade'],
        ['programa de trabalho', 'programa'],
        ['planejamento estrategico', 'planejamento'], ['vinculacoes com o planejamento', 'planejamento'],
        ['vinculacoes com outras', 'vinculos'],
        ['plano de contratacoes', 'plano'],
        ['fornecedor', 'fornecedor'], ['cnpj', 'cnpj'],
        ['n processo', 'processo'], ['processo', 'processo'],
        ['observacao', 'observacao'],
        ['servico', 'servico'],
        ['curta duracao', 'curta_duracao'],
    ];

    public const CHAVES = ['identificador', 'codigo', 'descricao', 'tipo', 'mao_de_obra', 'justificativa', 'valor',
        'vigencia', 'mes', 'fim_vigencia', 'orgao', 'setor', 'prioridade', 'programa', 'planejamento', 'vinculos',
        'plano', 'fornecedor', 'cnpj', 'processo', 'observacao', 'servico', 'curta_duracao'];

    /** Chave interna de um rótulo/cabeçalho, ou null se desconhecido. */
    public static function chaveDe(string $rotulo): ?string
    {
        $n = self::normalizar($rotulo);
        if ($n === '') {
            return null;
        }
        foreach (self::PADROES as [$prefixo, $chave]) {
            if ($n === $prefixo || strpos($n, $prefixo . ' ') === 0) {
                return $chave;
            }
        }
        return null;
    }

    private static function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c', '-' => ' ']);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    /** Texto com um item por linha (vinculações): preserva as quebras e limpa cada linha. */
    private static function lista($v): string
    {
        $linhas = preg_split('/\R/u', (string) $v) ?: [];
        $linhas = array_filter(array_map(static fn ($l) => trim(preg_replace('/[ \t]+/u', ' ', $l)), $linhas), 'strlen');
        return implode("\n", $linhas);
    }

    private static function texto($v): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $v));
    }

    /**
     * Lê a planilha e devolve todas as linhas de dados (qualquer unidade), já com as chaves internas.
     *
     * @return array<int,array<string,string>>
     */
    public static function ler(string $arquivo, string $extensao): array
    {
        $ext = strtolower($extensao);
        if ($ext === 'json') {
            $dados = json_decode((string) file_get_contents($arquivo), true);
            if (isset($dados['itens']) && is_array($dados['itens'])) {
                $dados = $dados['itens'];
            }
            if (!is_array($dados)) {
                throw new \RuntimeException('JSON inválido: esperava uma lista de programações.');
            }
            $linhas = [];
            foreach ($dados as $reg) {
                $linha = [];
                foreach ((array) $reg as $k => $v) {
                    $chave = in_array($k, self::CHAVES, true) ? $k : self::chaveDe((string) $k);
                    if ($chave !== null && (!isset($linha[$chave]) || $linha[$chave] === '')) {
                        $linha[$chave] = is_bool($v) ? ($v ? '1' : '0')
                            : (in_array($chave, ['planejamento', 'vinculos'], true) ? self::lista($v) : self::texto($v));
                    }
                }
                $linhas[] = $linha;
            }
            return self::finalizar($linhas);
        }
        $tipos = ['xls' => 'Xls', 'xlsx' => 'Xlsx', 'csv' => 'Csv'];
        if (!isset($tipos[$ext])) {
            throw new \RuntimeException('Formato não suportado: envie .xls, .xlsx, .csv ou .json');
        }
        $leitor = IOFactory::createReader($tipos[$ext]);
        $leitor->setReadDataOnly(true);
        $dados = $leitor->load($arquivo)->getActiveSheet()->toArray(null, false, false, false);

        $mapa    = null;
        $linhas  = [];
        foreach ($dados as $linha) {
            if ($mapa === null) {
                $tmp = [];
                foreach ($linha as $i => $titulo) {
                    $k = self::chaveDe((string) $titulo);
                    if ($k !== null && !in_array($k, $tmp, true)) {
                        $tmp[$i] = $k;
                    }
                }
                if (in_array('identificador', $tmp, true) && in_array('descricao', $tmp, true)) {
                    $mapa = $tmp;
                }
                continue;
            }
            $r = [];
            foreach ($mapa as $i => $k) {
                $r[$k] = self::texto($linha[$i] ?? '');
            }
            $linhas[] = $r;
        }
        if ($mapa === null) {
            throw new \RuntimeException('Cabeçalho não reconhecido: a planilha precisa ter as colunas "Identificador" e "Descrição".');
        }
        return self::finalizar($linhas);
    }

    /** Completa as chaves ausentes e descarta linhas sem identificador no formato PCnn/aa (títulos, totais). */
    private static function finalizar(array $linhas): array
    {
        $out = [];
        foreach ($linhas as $l) {
            $r = array_fill_keys(self::CHAVES, '') + [];
            foreach ($l as $k => $v) {
                $r[$k] = $v;
            }
            if (!preg_match('~^PC\s*\d+\s*/\s*\d{2,4}$~i', $r['identificador'])) {
                continue;
            }
            $r['identificador'] = strtoupper(preg_replace('/\s+/', '', $r['identificador']));
            $out[] = $r;
        }
        return $out;
    }

    /** "Sim"/"1"/"true"/"x" => 1; "Não"/"0"/"false"/vazio => 0; null se vazio. */
    public static function booleano($v): ?int
    {
        $n = self::normalizar((string) $v);
        if ($n === '') {
            return null;
        }
        return in_array($n, ['1', 'sim', 's', 'true', 'x', 'on', 'checked', 'yes'], true) ? 1 : 0;
    }

    /** "09/2028" => 2028-09-30. */
    public static function fimVigencia($v): ?string
    {
        return self::dataDoMes($v);
    }

    /**
     * Interpreta a Observação "PCTIC-2027-072 GITLAB - PAGAMENTO: ÚNICO - ORÇAMENTO: CJF/JF".
     *
     * @return array{numero:?string,apelido:?string,pagamento:?string,orcamento:?string}
     */
    public static function observacao(string $o): array
    {
        $r = ['numero' => null, 'apelido' => null, 'pagamento' => null, 'orcamento' => null, 'area' => null];
        $o = trim(preg_replace('/\s+/u', ' ', $o));
        if (!preg_match('~PCTIC[-\s]*(\d{4})[-\s]*(\d+)\s*(.*)$~iu', $o, $m)) {
            return $r;
        }
        $r['numero'] = (string) ((int) $m[2]);
        $resto = trim(preg_replace('~^-\s*~u', '', trim($m[3])));
        $parte = preg_split('~\s+-\s+~u', $resto)[0] ?? '';
        if ($parte !== '' && !preg_match('~^(PAGAMENTO|OR[ÇC]AMENTO|[ÁA]REA)\b~iu', $parte)) {
            $r['apelido'] = trim($parte);
        }
        if (preg_match('~PAGAMENTO\s*:\s*(.+?)(?:\s+-\s+|$)~iu', $resto, $p)) {
            $n = self::normalizar($p[1]);
            if (strpos($n, 'parcel') !== false && strpos($n, 'unico') !== false) {
                $r['pagamento'] = 'Único e parcelado';
            } elseif (strpos($n, 'parcel') !== false) {
                $r['pagamento'] = 'Parcelado';
            } elseif (strpos($n, 'unico') !== false) {
                $r['pagamento'] = 'Único';
            }
        }
        if (preg_match('~OR[ÇC]AMENTO\s*:\s*(.+?)(?:\s+-\s+|$)~iu', $resto, $p)) {
            $r['orcamento'] = trim($p[1]);
        }
        if (preg_match('~[ÁA]REA\s+DEMANDANTE\s*:\s*(.+?)(?:\s+-\s+|$)~iu', $resto, $p)) {
            $r['area'] = mb_strtoupper(trim($p[1]));
        }
        return $r;
    }

    /** Exercício a partir do identificador "PC101/27" (ou "/2027"). */
    public static function exercicioDe(string $identificador): int
    {
        if (preg_match('~/(\d{2,4})$~', $identificador, $m)) {
            $a = (int) $m[1];
            return $a < 100 ? 2000 + $a : $a;
        }
        return 0;
    }

    /** "PC101/27" => "101" (número do PCA). */
    public static function numeroDe(string $identificador): string
    {
        return preg_match('~^PC(\d+)/~', $identificador, $m) ? $m[1] : '';
    }

    public static function valor($v): ?float
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $x = Contratacao::numeroBR($v);
        return is_numeric($x) ? round((float) $x, 2) : null;
    }

    /** Vigência numérica ("12.0" => 12); textos como "Curta duração" => null. */
    public static function vigencia($v): ?int
    {
        $v = trim((string) $v);
        return is_numeric($v) && (float) $v > 0 ? (int) round((float) $v) : null;
    }

    /** "05/2027" => 2027-05-31. */
    public static function dataDoMes($v): ?string
    {
        if (preg_match('~^(\d{1,2})/(\d{4})$~', trim((string) $v), $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
            return date('Y-m-t', mktime(0, 0, 0, (int) $m[1], 1, (int) $m[2]));
        }
        return null;
    }

    /** "3 - Alta" => 1 (o PCA usa 1 = Alta; a numeração do SEI é a inversa). */
    public static function prioridade($v): ?int
    {
        $n = self::normalizar((string) $v);
        foreach (['alta' => 1, 'media' => 2, 'baixa' => 3] as $txt => $p) {
            if (strpos($n, $txt) !== false) {
                return $p;
            }
        }
        return null;
    }

    /** Tipo do SEI => lista de tipos do PCA (null = sem equivalente). */
    public static function tipo($v): ?string
    {
        $n = self::normalizar((string) $v);
        if (strpos($n, 'nova contratacao') !== false) {
            return 'Nova Contratação';
        }
        if (strpos($n, 'renovacao') !== false || strpos($n, 'prorrogacao') !== false) {
            return 'Prorrogação';
        }
        if (strpos($n, 'manutencao') !== false) {
            return 'Manutenção';
        }
        return null;
    }

    // ------------------------------------------------------------------ carga

    /**
     * Grava no staging as linhas da unidade informada.
     *
     * @return array{lidas:int,unidade:int,novas:int,alteradas:int,iguais:int,outras:array}
     */
    public static function importar(string $arquivo, string $extensao, string $setor, string $nome_arquivo): array
    {
        global $DB;

        $setor  = mb_strtoupper(trim($setor));
        $linhas = self::ler($arquivo, $extensao);
        $res    = ['lidas' => count($linhas), 'unidade' => 0, 'novas' => 0, 'alteradas' => 0, 'iguais' => 0, 'outras' => []];
        $agora  = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        foreach ($linhas as $r) {
            if ($setor !== '' && $r['setor'] !== '' && mb_strtoupper($r['setor']) !== $setor) {
                $res['outras'][$r['setor'] ?: '(vazio)'] = ($res['outras'][$r['setor'] ?: '(vazio)'] ?? 0) + 1;
                continue;
            }
            $res['unidade']++;
            $exercicio = self::exercicioDe($r['identificador']);
            $json      = json_encode($r, JSON_UNESCAPED_UNICODE);
            $hash      = sha1($json);
            $atual     = $DB->request(['FROM' => self::TABELA,
                'WHERE' => ['exercicio' => $exercicio, 'identificador' => $r['identificador']]])->current();
            $campos = [
                'codigo' => $r['codigo'], 'descricao' => $r['descricao'], 'setor' => $r['setor'],
                'valor_total' => self::valor($r['valor']), 'raw' => $json, 'hash' => $hash,
                'arquivo' => mb_substr($nome_arquivo, 0, 200), 'date_carga' => $agora,
                'users_id_carga' => (int) \Session::getLoginUserID(),
            ];
            if (!$atual) {
                $DB->insert(self::TABELA, $campos + ['exercicio' => $exercicio, 'identificador' => $r['identificador'],
                    'contratacao_id' => 0, 'ausente' => 0]);
                $res['novas']++;
            } else {
                $res[$atual['hash'] === $hash ? 'iguais' : 'alteradas']++;
                $DB->update(self::TABELA, $campos + ['ausente' => 0], ['id' => $atual['id']]);
            }
        }
        return $res;
    }

    // ------------------------------------------------------------------ vínculo

    /** Desfaz vínculos com contratações que não existem mais (excluídas ou purgadas no PCA). */
    public static function limparOrfaos(): int
    {
        global $DB;
        $n = 0;
        foreach ($DB->request(['SELECT' => ['s.id'], 'FROM' => self::TABELA . ' AS s',
            'LEFT JOIN' => [Contratacao::getTable() . ' AS c' => ['ON' => ['s' => 'contratacao_id', 'c' => 'id']]],
            'WHERE' => ['s.contratacao_id' => ['>', 0], 'OR' => ['c.id' => null, 'c.is_deleted' => 1]]]) as $r) {
            $DB->update(self::TABELA, ['contratacao_id' => 0, 'date_conciliacao' => null, 'hash_aplicado' => null], ['id' => $r['id']]);
            $n++;
        }
        return $n;
    }

    public static function itens(int $exercicio): array
    {
        global $DB;
        self::limparOrfaos();
        $out = [];
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['exercicio' => $exercicio],
            'ORDER' => 'identificador']) as $r) {
            $r['sei'] = json_decode((string) $r['raw'], true) ?: [];
            $out[] = $r;
        }
        usort($out, static fn ($a, $b) => (int) self::numeroDe($a['identificador']) <=> (int) self::numeroDe($b['identificador']));
        return $out;
    }

    /** Contratações do exercício ainda não vinculadas a nenhum item do SEI. */
    public static function livres(int $exercicio, int $manter_id = 0): array
    {
        global $DB;
        $ocupadas = [];
        foreach ($DB->request(['SELECT' => 'contratacao_id', 'FROM' => self::TABELA,
            'WHERE' => ['exercicio' => $exercicio, 'contratacao_id' => ['>', 0]]]) as $r) {
            $ocupadas[(int) $r['contratacao_id']] = true;
        }
        $out = [];
        foreach ($DB->request(['FROM' => Contratacao::getTable(),
            'WHERE' => ['exercicio' => $exercicio, 'is_deleted' => 0], 'ORDER' => 'name']) as $c) {
            if (!isset($ocupadas[(int) $c['id']]) || (int) $c['id'] === $manter_id) {
                $out[(int) $c['id']] = $c;
            }
        }
        return $out;
    }

    private static function simples(string $s): string
    {
        return self::normalizar($s);
    }

    /**
     * Candidatas para um item do SEI, da mais provável para a menos: semelhança do texto
     * (objeto/apelido) e coincidência do valor global.
     *
     * @return array<int,array{id:int,nome:string,score:int,motivo:string}>
     */
    public static function sugestoes(array $item, array $livres, int $max = 3): array
    {
        $desc  = self::simples((string) $item['descricao']);
        $total = $item['valor_total'] !== null ? round((float) $item['valor_total'], 2) : null;
        $out   = [];
        foreach ($livres as $c) {
            $alvo = self::simples(($c['name'] ?? '') . ' ' . ($c['objeto'] ?? ''));
            similar_text($desc, $alvo, $pct);
            $score  = (int) round($pct);
            $motivo = ['texto ' . $score . '%'];
            // O apelido (curto) contido na descrição vale mais que a semelhança global
            $apelido = self::simples((string) ($c['name'] ?? ''));
            if ($apelido !== '' && mb_strlen($apelido) >= 3 && strpos($desc, $apelido) !== false) {
                $score = max($score, 80);
                $motivo[] = 'apelido na descrição';
            }
            if ($total !== null && !\GlpiPlugin\Pca\Calculo::vazio($c['valor_global'])
                && abs(round((float) $c['valor_global'], 2) - $total) < 0.01) {
                $score  = min(100, $score + 30);
                $motivo[] = 'mesmo valor';
            }
            $out[] = ['id' => (int) $c['id'], 'nome' => (string) $c['name'], 'score' => $score, 'motivo' => implode(', ', $motivo)];
        }
        usort($out, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice(array_filter($out, static fn ($x) => $x['score'] >= 40), 0, $max);
    }

    public static function vincular(int $item_id, int $contratacao_id): bool
    {
        global $DB;
        self::limparOrfaos();
        if ($contratacao_id > 0) {
            $linha = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $item_id]])->current();
            if (!$linha) {
                return false;
            }
            $outro = countElementsInTable(self::TABELA, ['exercicio' => $linha['exercicio'],
                'contratacao_id' => $contratacao_id, 'NOT' => ['id' => $item_id]]);
            if ($outro) {
                return false; // uma contratação do PCA corresponde a um único item do SEI
            }
        }
        return (bool) $DB->update(self::TABELA, ['contratacao_id' => $contratacao_id, 'date_conciliacao' => null,
            'hash_aplicado' => null], ['id' => $item_id]);
    }

    // ------------------------------------------------------------------ reconciliação

    /** Rótulo dos campos reconciliáveis. */
    public const CAMPOS = [
        'numero_pctic'   => 'N. PCTIC (da Observação)',
        'objeto'         => 'Objeto',
        'tipo'           => 'Tipo',
        'justificativa'  => 'Justificativa',
        'prioridade'     => 'Prioridade',
        'vigencia_meses' => 'Vigência (meses)',
        'data_limite'    => 'Data limite (mês estimado)',
        'valor_global'   => 'Valor global (R$)',
        'name'           => 'Apelido (da Observação)',
        'pagamento'      => 'Pagamento (da Observação)',
        'processo_sei'   => 'Processo SEI',
        'fornecedor'     => 'Fornecedor',
        'cnpj_fornecedor' => 'CNPJ do fornecedor',
        'codigo_item'    => 'Código do item',
        'programa_trabalho' => 'Programa de trabalho',
        'plano'          => 'Plano de contratações',
        'fim_vigencia'   => 'Fim da vigência',
        'servico'        => 'Serviço',
        'mao_de_obra'    => 'Requer mão de obra',
        'curta_duracao'  => 'Curta duração',
        'observacao_sei' => 'Observação (SEI)',
        'planejamento_estrategico' => 'Planejamento estratégico',
        'vinculos_programacoes' => 'Vinculações com outras programações',
        'orcamento'      => 'Orçamento (da Observação)',
        'requisitante'   => 'Requisitante (área demandante da Observação)',
        'sei_identificador' => 'Identificador no SEI',
    ];

    /**
     * Comparativo SEI x PCA de um item vinculado.
     *
     * @return array<int,array{campo:string,rotulo:string,pca:string,sei:string,igual:bool,aplicavel:bool,nota:string,grava:array}>
     */
    public static function comparar(array $item, array $c): array
    {
        $sei  = $item['sei'] ?? (json_decode((string) $item['raw'], true) ?: []);
        $rot  = self::CAMPOS;
        $out  = [];
        $add  = static function (string $campo, string $pca, string $seiv, bool $igual, bool $aplicavel, string $nota, array $grava) use (&$out, $rot) {
            $out[] = ['campo' => $campo, 'rotulo' => $rot[$campo], 'pca' => $pca, 'sei' => $seiv,
                'igual' => $igual, 'aplicavel' => $aplicavel, 'nota' => $nota, 'grava' => $grava];
        };
        $cmp = static fn (string $a, string $b) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $a))) === mb_strtolower(trim(preg_replace('/\s+/u', ' ', $b)));

        // N.PCA é da planilha de planejamento; o SEI traz o número do PCTIC (Observação "PCTIC-aaaa-nnn")
        $obs = self::observacao((string) ($sei['observacao'] ?? ''));
        $num = (string) ($obs['numero'] ?? '');
        $add('numero_pctic', (string) ($c['numero_pctic'] ?? ''), $num, (string) ($c['numero_pctic'] ?? '') === $num,
            $num !== '', $num === '' ? 'Observação sem PCTIC-aaaa-nnn: nada a aplicar' : '', ['numero_pctic' => $num]);

        $add('objeto', (string) $c['objeto'], (string) $sei['descricao'], $cmp((string) $c['objeto'], (string) $sei['descricao']),
            $sei['descricao'] !== '', '', ['objeto' => $sei['descricao']]);

        $tipo = self::tipo($sei['tipo']);
        $add('tipo', (string) $c['tipo'], $tipo ?? (string) $sei['tipo'], $tipo !== null && $cmp((string) $c['tipo'], $tipo),
            $tipo !== null, $tipo === null ? 'Sem equivalente na lista de tipos do PCA (SEI: ' . $sei['tipo'] . ')' : 'SEI: ' . $sei['tipo'],
            ['tipo' => $tipo]);

        $add('justificativa', (string) $c['justificativa'], (string) $sei['justificativa'],
            $cmp((string) $c['justificativa'], (string) $sei['justificativa']), $sei['justificativa'] !== '',
            $sei['justificativa'] === '' ? 'Vazia no SEI: nada a aplicar' : '', ['justificativa' => $sei['justificativa']]);

        $p = self::prioridade($sei['prioridade']);
        $add('prioridade', (string) $c['prioridade'], $p === null ? '' : (string) $p, $p !== null && (int) $c['prioridade'] === $p,
            $p !== null, 'SEI: ' . $sei['prioridade'] . ' (no PCA 1 = Alta, 3 = Baixa)', ['prioridade' => $p]);

        $vig = self::vigencia($sei['vigencia']);
        $add('vigencia_meses', (string) $c['vigencia_meses'], $vig === null ? '' : (string) $vig,
            $vig !== null && (int) $c['vigencia_meses'] === $vig, $vig !== null,
            $vig === null ? 'SEI: "' . $sei['vigencia'] . '" (não numérica; mantém o PCA)' : '', ['vigencia_meses' => $vig]);

        $data = self::dataDoMes($sei['mes']);
        $add('data_limite', (string) $c['data_limite'], (string) $data, $data !== null && (string) $c['data_limite'] === $data,
            $data !== null, $data === null ? 'Mês estimado ausente no SEI' : 'SEI: ' . $sei['mes'] . ' (último dia do mês)',
            ['data_limite' => $data]);

        // Valor: o total do SEI é o valor global; a forma de gravar depende do tipo de pagamento
        $total = self::valor($sei['valor']);
        $vg    = Calculo::vazio($c['valor_global']) ? null : round((float) $c['valor_global'], 2);
        $grava = [];
        $nota  = '';
        $apl   = $total !== null;
        $pag   = (string) $c['pagamento'];
        $vigef = $vig ?? (Calculo::vazio($c['vigencia_meses']) ? null : (int) $c['vigencia_meses']);
        if ($total === null) {
            $nota = 'Valor ausente no SEI';
        } elseif ($pag === 'Parcelado') {
            if ($vigef) {
                $grava = ['valor_cheio_ano' => round($total * 12 / $vigef, 2), 'valor_unico' => 'NULL'];
                $nota  = 'Pagamento parcelado: valor cheio no ano = total × 12 / ' . $vigef . ' meses';
            } else {
                $apl  = false;
                $nota = 'Parcelado sem vigência numérica: informe a vigência';
            }
        } elseif ($pag === 'Único e parcelado') {
            $apl  = false;
            $nota = 'Pagamento misto: o total do SEI não separa a parte única da parcelada; ajuste manualmente';
        } else {
            $grava = ['valor_unico' => $total, 'valor_cheio_ano' => 'NULL'];
            $nota  = $pag === '' ? 'Pagamento não definido: o total entra como valor único' : 'Pagamento único: total = valor único';
        }
        // Parcelado: valor cheio no ano tem 2 casas e é multiplicado por vigência/12; o total pode diferir em centavos
        $tolerancia = $pag === 'Parcelado' && $vigef ? max(0.01, round($vigef / 12 * 0.005 + 0.005, 3)) : 0.009;
        // O total pode bater e a composição estar errada (ex.: "Parcelado" com o total em valor único)
        $un_ok = !Calculo::vazio($c['valor_unico']);
        $ch_ok = !Calculo::vazio($c['valor_cheio_ano']);
        $composicao = $pag === 'Parcelado' ? (!$un_ok && $ch_ok) : ($pag === 'Único' || $pag === '' ? ($un_ok && !$ch_ok) : true);
        if (!$composicao) {
            $nota = trim($nota . ' (composição atual: ' . ($un_ok ? 'valor único' : '') . ($un_ok && $ch_ok ? ' + ' : '')
                . ($ch_ok ? 'valor cheio no ano' : '') . ')');
        }
        $add('valor_global', $vg === null ? '' : (string) $vg, $total === null ? '' : (string) $total,
            $total !== null && $vg !== null && abs($vg - $total) <= $tolerancia && $composicao, $apl, $nota, $grava);

        // Campos que só vêm da tela de detalhe / Observação; ausentes no SEI não geram divergência
        $txt = static function (string $campo, string $coluna, $valor, string $nota = '') use ($add, $c, $cmp) {
            $valor = (string) $valor;
            $add($campo, (string) ($c[$coluna] ?? ''), $valor, $cmp((string) ($c[$coluna] ?? ''), $valor),
                $valor !== '', $valor === '' ? 'Ausente no SEI: nada a aplicar' : $nota, [$coluna => $valor]);
        };
        $ap_sei = (string) ($obs['apelido'] ?? '');
        $ap_pca = (string) ($c['name'] ?? '');
        // o apelido da planilha (prioritário) pode detalhar o do SEI: não é divergência
        $detalha = $ap_sei !== '' && mb_stripos($ap_pca, $ap_sei) === 0;
        $add('name', $ap_pca, $ap_sei, $detalha || $cmp($ap_pca, $ap_sei), $ap_sei !== '',
            $ap_sei === '' ? 'Ausente no SEI: nada a aplicar' : 'Texto após PCTIC-aaaa-nnn na Observação', ['name' => $ap_sei]);
        $txt('pagamento', 'pagamento', $obs['pagamento'] ?? '');
        $txt('orcamento', 'orcamento', $obs['orcamento'] ?? '');
        $txt('requisitante', 'requisitante', $obs['area'] ?? '', 'ÁREA DEMANDANTE na Observação');
        $txt('processo_sei', 'processo_sei', $sei['processo'] ?? '');
        $txt('fornecedor', 'fornecedor', $sei['fornecedor'] ?? '');
        $txt('cnpj_fornecedor', 'cnpj_fornecedor', $sei['cnpj'] ?? '');
        $txt('codigo_item', 'codigo_item', $sei['codigo'] ?? '');
        $txt('programa_trabalho', 'programa_trabalho', $sei['programa'] ?? '');
        $txt('plano', 'plano', $sei['plano'] ?? '');
        $txt('observacao_sei', 'observacao_sei', $sei['observacao'] ?? '');
        $txt('planejamento_estrategico', 'planejamento_estrategico', $sei['planejamento'] ?? '');
        $txt('vinculos_programacoes', 'vinculos_programacoes', $sei['vinculos'] ?? '');
        $txt('sei_identificador', 'sei_identificador', $item['identificador']);
        $fim = self::fimVigencia($sei['fim_vigencia'] ?? '');
        $add('fim_vigencia', (string) ($c['fim_vigencia'] ?? ''), (string) $fim, $fim !== null && (string) ($c['fim_vigencia'] ?? '') === $fim,
            $fim !== null, $fim === null ? 'Ausente no SEI: nada a aplicar' : 'SEI: ' . $sei['fim_vigencia'] . ' (último dia do mês)',
            ['fim_vigencia' => $fim]);
        foreach (['servico' => 'servico', 'mao_de_obra' => 'mao_de_obra', 'curta_duracao' => 'curta_duracao'] as $campo => $chave) {
            $b = self::booleano($sei[$chave] ?? '');
            $atual = (int) ($c[$campo] ?? 0);
            $add($campo, $atual ? 'Sim' : 'Não', $b === null ? '' : ($b ? 'Sim' : 'Não'), $b !== null && $atual === $b,
                $b !== null, $b === null ? 'Ausente no SEI: nada a aplicar' : '', [$campo => $b]);
        }

        return $out;
    }

    /** Situação resumida de um item vinculado (para a lista). */
    public static function situacao(array $item, ?array $c): string
    {
        if (!$c) {
            return $item['contratacao_id'] ? 'Contratação do PCA não encontrada' : 'Sem vínculo';
        }
        foreach (self::comparar($item, $c) as $l) {
            if (!$l['igual'] && $l['aplicavel']) {
                return 'Divergente';
            }
        }
        return 'Confere';
    }

    /**
     * Aplica ao PCA os campos escolhidos (o SEI é o mandatário). O histórico do GLPI registra a alteração.
     *
     * @param string[] $campos chaves de self::CAMPOS
     */
    public static function aplicar(int $item_id, array $campos): array
    {
        global $DB;
        $item = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $item_id]])->current();
        if (!$item || !(int) $item['contratacao_id']) {
            throw new \RuntimeException('Item sem vínculo com contratação do PCA.');
        }
        $c = new Contratacao();
        if (!$c->getFromDB((int) $item['contratacao_id'])) {
            throw new \RuntimeException('Contratação do PCA não encontrada.');
        }
        if (!$c->canUpdateItem()) {
            throw new \RuntimeException('Sem permissão para alterar esta contratação.');
        }
        $item['sei'] = json_decode((string) $item['raw'], true) ?: [];
        $input = ['id' => $c->getID()];
        $feitos = [];
        foreach (self::comparar($item, $c->fields) as $l) {
            if (!in_array($l['campo'], $campos, true) || !$l['aplicavel'] || $l['igual']) {
                continue;
            }
            foreach ($l['grava'] as $k => $v) {
                $input[$k] = $v;
            }
            $feitos[] = $l['rotulo'];
        }
        if (!empty($input['tipo'])) {
            Parametros::garantirNaLista('tipo', $input['tipo']);
        }
        if ($feitos) {
            if (!$c->update($input)) {
                throw new \RuntimeException('O GLPI recusou a atualização da contratação.');
            }
        }
        $DB->update(self::TABELA, ['date_conciliacao' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'users_id_conciliacao' => (int) \Session::getLoginUserID(), 'hash_aplicado' => $item['hash']],
            ['id' => $item_id]);
        return $feitos;
    }

    /** Cria no PCA uma contratação nova a partir da linha do SEI e já a vincula. */
    public static function criar(int $item_id): int
    {
        global $DB;
        self::limparOrfaos();
        $item = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $item_id]])->current();
        if (!$item || (int) $item['contratacao_id']) {
            throw new \RuntimeException('Item inexistente ou já vinculado.');
        }
        $sei = json_decode((string) $item['raw'], true) ?: [];
        $vig = self::vigencia($sei['vigencia']);
        $obs = self::observacao((string) ($sei['observacao'] ?? ''));
        $in  = [
            'name'         => mb_substr($obs['apelido'] ?: str_replace('/', '-', (string) $item['identificador']), 0, 25),
            'exercicio'    => (int) $item['exercicio'],
            'numero_pctic' => $obs['numero'] ?? '',
            'objeto'       => $sei['descricao'],
            'tipo'         => self::tipo($sei['tipo']) ?? '',
            'justificativa' => $sei['justificativa'],
            'prioridade'   => self::prioridade($sei['prioridade']),
            'vigencia_meses' => $vig,
            'data_limite'  => self::dataDoMes($sei['mes']),
            'valor_unico'  => self::valor($sei['valor']),
            'pagamento'    => $obs['pagamento'] ?? 'Único',
            'orcamento'    => $obs['orcamento'] ?? '',
            'processo_sei' => $sei['processo'] ?? '',
            'fornecedor'   => $sei['fornecedor'] ?? '',
            'cnpj_fornecedor' => $sei['cnpj'] ?? '',
            'codigo_item'  => $sei['codigo'] ?? '',
            'programa_trabalho' => $sei['programa'] ?? '',
            'plano'        => $sei['plano'] ?? '',
            'observacao_sei' => $sei['observacao'] ?? '',
            'planejamento_estrategico' => $sei['planejamento'] ?? '',
            'vinculos_programacoes' => $sei['vinculos'] ?? '',
            'sei_identificador' => $item['identificador'],
            'fim_vigencia' => self::fimVigencia($sei['fim_vigencia'] ?? ''),
            'servico'      => self::booleano($sei['servico'] ?? ''),
            'mao_de_obra'  => self::booleano($sei['mao_de_obra'] ?? ''),
            'curta_duracao' => self::booleano($sei['curta_duracao'] ?? ''),
            'origem'       => 'SEI (módulo Contratações)',
            'necessidade'  => 'Manter',
            'requisitante' => $obs['area'] ?? '',
            'entities_id'  => (int) ($_SESSION['glpiactive_entity'] ?? 0),
        ];
        foreach (['prioridade', 'vigencia_meses', 'data_limite', 'valor_unico', 'fim_vigencia', 'servico', 'mao_de_obra', 'curta_duracao'] as $k) {
            if ($in[$k] === null) {
                unset($in[$k]);
            }
        }
        if (!empty($in['tipo'])) {
            Parametros::garantirNaLista('tipo', $in['tipo']);
        }
        $c = new Contratacao();
        $id = $c->add($in);
        if (!$id) {
            throw new \RuntimeException('Não foi possível criar a contratação.');
        }
        self::vincular($item_id, (int) $id);
        $DB->update(self::TABELA, ['date_conciliacao' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'users_id_conciliacao' => (int) \Session::getLoginUserID(), 'hash_aplicado' => $item['hash']],
            ['id' => $item_id]);
        return (int) $id;
    }

    /** Contratações do PCA do exercício sem item do SEI vinculado. */
    public static function soNoPca(int $exercicio): array
    {
        return self::livres($exercicio);
    }

    /** Item do SEI vinculado a uma contratação (para o link na ficha). */
    public static function itemDaContratacao(int $contratacao_id): ?array
    {
        global $DB;
        $r = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['contratacao_id' => $contratacao_id]])->current();
        return $r ?: null;
    }
}
