<?php

namespace GlpiPlugin\Pca;

use CommonDBTM;
use Glpi\Application\View\TemplateRenderer;
use Group;
use Log;
use Session;

/**
 * Contratação prevista no Plano de Contratações Anual.
 */
class Contratacao extends CommonDBTM
{
    // Tabela: glpi_plugin_pca_contratacaos (nome derivado pelo GLPI a partir da classe)
    public static $rightname = 'plugin_pca_contratacao';
    public $dohistory        = true;

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Contratações do PCA' : 'Contratação do PCA';
    }

    public static function getIcon()
    {
        return 'ti ti-clipboard-list';
    }

    public static function getMenuName()
    {
        return 'PCA';
    }

    /** Anos oferecidos no combo: 2025-2028 mais qualquer exercício que já exista no cadastro. */
    public static function anos(): array
    {
        global $DB;
        $anos = [2025, 2026, 2027, 2028];
        foreach ($DB->request(['SELECT' => 'exercicio', 'DISTINCT' => true, 'FROM' => self::getTable()]) as $r) {
            if ((int) $r['exercicio'] > 0) {
                $anos[] = (int) $r['exercicio'];
            }
        }
        $anos = array_values(array_unique($anos));
        sort($anos);
        return $anos;
    }

    /** Exercício escolhido por último na sessão (lista, painel ou conciliação); senão o dos parâmetros. */
    public static function exercicioEscolhido(): int
    {
        return (int) ($_SESSION['pca_exercicio'] ?? Parametros::get('exercicio'));
    }

    public static function getMenuContent()
    {
        $menu = parent::getMenuContent();
        if (is_array($menu)) {
            $base = '/plugins/pca/front/';
            $ano = self::exercicioEscolhido();
            $menu['links']['<i class="ti ti-chart-bar"></i> Painel']            = $base . 'painel.php?exercicio=' . $ano;
            $menu['links']['<i class="ti ti-arrows-diff"></i> Conciliação PNCP'] = $base . 'conciliacao.php?exercicio=' . $ano;
            if (Session::haveRight('plugin_pca_config', UPDATE)) {
                $menu['links']['<i class="ti ti-settings"></i> Parâmetros'] = $base . 'config.form.php';
            }
        }
        return $menu;
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(Log::class, $tabs, $options);
        return $tabs;
    }

    /**
     * Além do direito do perfil, quem não administra o PCA só altera as linhas
     * do próprio grupo (unidade requisitante).
     */
    public function canUpdateItem(): bool
    {
        if (!parent::canUpdateItem()) {
            return false;
        }
        if (Session::haveRight('plugin_pca_config', UPDATE)) {
            return true;
        }
        $grupo = (int) ($this->fields['groups_id'] ?? 0);
        return $grupo === 0 || in_array($grupo, $_SESSION['glpigroups'] ?? []);
    }

    /** Quem não administra o PCA só mexe nas linhas da própria unidade (ou sem unidade). */
    private function daMinhaUnidade(): bool
    {
        if (Session::haveRight('plugin_pca_config', UPDATE)) {
            return true;
        }
        $grupo = (int) ($this->fields['groups_id'] ?? 0);
        return $grupo === 0 || in_array($grupo, $_SESSION['glpigroups'] ?? []);
    }

    public function canDeleteItem(): bool
    {
        return parent::canDeleteItem() && $this->daMinhaUnidade();
    }

    public function canPurgeItem(): bool
    {
        return parent::canPurgeItem() && $this->daMinhaUnidade();
    }

    public function post_getEmpty()
    {
        $this->fields['exercicio']    = (int) Parametros::get('exercicio');
        $this->fields['necessidade']  = 'Manter';
        $this->fields['is_recursive'] = 1;
    }

    public function prepareInputForAdd($input)
    {
        if (empty($input['exercicio'])) {
            $input['exercicio'] = (int) Parametros::get('exercicio');
        }
        return $this->calcular($input, []);
    }

    public function prepareInputForUpdate($input)
    {
        // Sessão interativa sem direito de administrar: não pode passar a linha para outra unidade
        if (
            Session::getLoginUserID()
            && !Session::haveRight('plugin_pca_config', UPDATE)
            && isset($input['groups_id'])
            && (int) $input['groups_id'] !== (int) ($this->fields['groups_id'] ?? 0)
            && (int) $input['groups_id'] !== 0
            && !in_array((int) $input['groups_id'], $_SESSION['glpigroups'] ?? [])
        ) {
            Session::addMessageAfterRedirect('Você não pode transferir a contratação para uma unidade da qual não participa.', false, ERROR);
            return false;
        }
        return $this->calcular($input, $this->fields);
    }

    /** Recalcula meses, valores e pendências a partir dos dados informados. */
    private function calcular(array $input, array $atual): array
    {
        foreach (['valor_unico', 'valor_cheio_ano', 'prioridade', 'vigencia_meses', 'data_limite',
            'valor_anual_original', 'valor_global_original'] as $c) {
            if (array_key_exists($c, $input) && ($input[$c] === '' || $input[$c] === 'NULL')) {
                $input[$c] = 'NULL';
            }
        }
        // A opção vazia das listas chega como "0"
        foreach (Parametros::LISTAS as $c) {
            if (array_key_exists($c, $input) && (string) $input[$c] === '0') {
                $input[$c] = '';
            }
        }
        foreach (['prioridade', 'vigencia_meses'] as $c) {
            if (array_key_exists($c, $input) && $input[$c] !== 'NULL' && (int) $input[$c] === 0) {
                $input[$c] = 'NULL';
            }
        }
        // Valor zerado equivale a não informado
        foreach (['valor_unico', 'valor_cheio_ano'] as $c) {
            if (array_key_exists($c, $input) && $input[$c] !== 'NULL' && (float) $input[$c] == 0.0) {
                $input[$c] = 'NULL';
            }
        }
        $v = static function (string $c) use ($input, $atual) {
            $x = array_key_exists($c, $input) ? $input[$c] : ($atual[$c] ?? null);
            return $x === 'NULL' ? null : $x;
        };

        if (!empty($input['groups_id'])) {
            $g = new Group();
            if ($g->getFromDB((int) $input['groups_id'])) {
                $input['requisitante'] = $g->fields['code'] ?: $g->fields['name'];
            }
        }

        $p         = Parametros::todos();
        $exercicio = (int) ($v('exercicio') ?: $p['exercicio']);
        $meses     = Calculo::mesesNoExercicio($v('data_limite'), $exercicio, (bool) $p['inclui_mes_limite']);
        $anual     = Calculo::valorAnual($v('valor_unico'), $v('valor_cheio_ano'), $meses);
        $global    = Calculo::valorGlobal($v('valor_unico'), $v('valor_cheio_ano'), $v('vigencia_meses'));

        $input['meses_exercicio'] = $meses;
        $input['valor_anual']     = $anual  ?? 'NULL';
        $input['valor_global']    = $global ?? 'NULL';
        $input['pendencias']      = implode('; ', $this->pendencias($v, $exercicio, (int) $p['apelido_max'], (int) ($atual['id'] ?? 0)));

        return $input;
    }

    private function pendencias(callable $v, int $exercicio, int $apelido_max, int $id): array
    {
        if ($v('necessidade') === 'Remover') {
            return [];
        }
        $pend = [];
        $nome = trim((string) $v('name'));
        if ($nome === '') {
            $pend[] = 'Apelido';
        } elseif (preg_match('~[/\\\\:*?"<>|#%&]~', $nome)) {
            $pend[] = 'Apelido com caractere inválido em canal do Teams';
        } elseif (mb_strlen($nome) > $apelido_max) {
            $pend[] = 'Apelido longo';
        } elseif (countElementsInTable(self::getTable(), ['name' => $nome, 'exercicio' => $exercicio,
            'is_deleted' => 0, 'NOT' => ['id' => $id]])) {
            $pend[] = 'Apelido duplicado';
        }
        if (Calculo::vazio($v('prioridade'))) {
            $pend[] = 'Prioridade';
        }
        $pca = trim((string) $v('numero_pca'));
        if ($pca === '') {
            $pend[] = 'N.PCA';
        } elseif (countElementsInTable(self::getTable(), ['numero_pca' => $pca, 'exercicio' => $exercicio,
            'is_deleted' => 0, 'NOT' => ['id' => $id]])) {
            $pend[] = 'N.PCA duplicado';
        }
        if (empty($v('destinacao'))) {
            $pend[] = 'Destinação';
        }
        if (trim((string) $v('justificativa')) === '') {
            $pend[] = 'Justificativa';
        }
        $data = $v('data_limite');
        if (empty($data)) {
            $pend[] = 'Data limite';
        } elseif ((int) date('Y', strtotime($data)) < $exercicio) {
            $pend[] = 'Data limite anterior a ' . $exercicio;
        }
        if (Calculo::vazio($v('vigencia_meses'))) {
            $pend[] = 'Vigência';
        }
        $pag = (string) $v('pagamento');
        $un  = !Calculo::vazio($v('valor_unico'));
        $ch  = !Calculo::vazio($v('valor_cheio_ano'));
        if ($pag === '') {
            $pend[] = 'Tipo de pagamento';
        }
        if (!$un && !$ch) {
            $pend[] = 'Valor';
        }
        if ($pag === 'Único' && $ch) {
            $pend[] = 'Pagamento Único com valor parcelado preenchido';
        }
        if ($pag === 'Parcelado' && $un) {
            $pend[] = 'Pagamento Parcelado com valor único preenchido';
        }
        if ($pag === 'Único e parcelado' && (!$un || !$ch)) {
            $pend[] = 'Pagamento misto incompleto';
        }
        $nec = (string) $v('necessidade');
        if ($nec === '') {
            $pend[] = 'Necessidade';
        } elseif ($nec === 'Avaliar') {
            $pend[] = 'Decidir: Manter ou Remover';
        }
        return $pend;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $p   = Parametros::todos();
        $vg  = Calculo::vazio($this->fields['valor_global'] ?? null) ? null : (float) $this->fields['valor_global'];
        TemplateRenderer::getInstance()->display('@pca/contratacao.html.twig', [
            'item'       => $this,
            'params'     => $options + ['canedit' => $this->isNewItem() ? self::canCreate() : $this->canUpdateItem()],
            'listas'     => array_combine(Parametros::LISTAS, array_map([Parametros::class, 'lista'], Parametros::LISTAS)),
            'prioridades' => [1 => '1 - Alta', 2 => '2 - Média', 3 => '3 - Baixa'],
            'prioridade_valor' => Calculo::prioridadePorValor($vg, (float) $p['faixa_alta'], (float) $p['faixa_media']),
            'exercicio_padrao' => (int) $p['exercicio'],
        ]);
        return true;
    }

    public function rawSearchOptions()
    {
        $t   = self::getTable();
        $tab = [];
        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];
        $tab[] = ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => 'Apelido', 'datatype' => 'itemlink', 'massiveaction' => false];
        $tab[] = ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false];
        $tab[] = ['id' => 3, 'table' => $t, 'field' => 'exercicio', 'name' => 'Exercício', 'datatype' => 'integer'];
        $tab[] = ['id' => 4, 'table' => $t, 'field' => 'prioridade', 'name' => 'Prioridade', 'datatype' => 'integer'];
        $tab[] = ['id' => 5, 'table' => $t, 'field' => 'destinacao', 'name' => 'Destinação', 'datatype' => 'string'];
        $tab[] = ['id' => 6, 'table' => $t, 'field' => 'numero_pca', 'name' => 'N.PCA', 'datatype' => 'string'];
        $tab[] = ['id' => 7, 'table' => $t, 'field' => 'requisitante', 'name' => 'Requisitante', 'datatype' => 'string', 'massiveaction' => false];
        $tab[] = ['id' => 8, 'table' => $t, 'field' => 'objeto', 'name' => 'Objeto', 'datatype' => 'text'];
        $tab[] = ['id' => 9, 'table' => $t, 'field' => 'tipo', 'name' => 'Tipo', 'datatype' => 'string'];
        $tab[] = ['id' => 10, 'table' => $t, 'field' => 'status', 'name' => 'Status', 'datatype' => 'string'];
        $tab[] = ['id' => 11, 'table' => $t, 'field' => 'necessidade', 'name' => 'Necessidade', 'datatype' => 'string'];
        $tab[] = ['id' => 12, 'table' => $t, 'field' => 'data_limite', 'name' => 'Data limite', 'datatype' => 'date'];
        $tab[] = ['id' => 13, 'table' => $t, 'field' => 'vigencia_meses', 'name' => 'Vigência (meses)', 'datatype' => 'integer'];
        $tab[] = ['id' => 14, 'table' => $t, 'field' => 'pagamento', 'name' => 'Pagamento', 'datatype' => 'string'];
        $tab[] = ['id' => 15, 'table' => $t, 'field' => 'valor_anual', 'name' => 'Valor Anual (R$)', 'datatype' => 'decimal', 'massiveaction' => false];
        $tab[] = ['id' => 16, 'table' => $t, 'field' => 'valor_global', 'name' => 'Valor Global (R$)', 'datatype' => 'decimal', 'massiveaction' => false];
        $tab[] = ['id' => 17, 'table' => $t, 'field' => 'pendencias', 'name' => 'Pendências', 'datatype' => 'text', 'massiveaction' => false];
        $tab[] = ['id' => 18, 'table' => $t, 'field' => 'conferido', 'name' => 'Conferido pela área', 'datatype' => 'bool'];
        $tab[] = ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false];
        $tab[] = ['id' => 20, 'table' => $t, 'field' => 'processo_sei', 'name' => 'Processo SEI', 'datatype' => 'string'];
        $tab[] = ['id' => 21, 'table' => $t, 'field' => 'origem', 'name' => 'Origem', 'datatype' => 'string'];
        $tab[] = ['id' => 22, 'table' => $t, 'field' => 'pncp_grupo_codigo', 'name' => 'Código no PNCP', 'datatype' => 'string'];
        $tab[] = ['id' => 23, 'table' => $t, 'field' => 'meses_exercicio', 'name' => 'Meses no exercício', 'datatype' => 'integer', 'massiveaction' => false];
        $tab[] = ['id' => 24, 'table' => 'glpi_groups', 'field' => 'completename', 'name' => 'Unidade requisitante (grupo)', 'datatype' => 'dropdown'];
        $tab[] = ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => \Entity::getTypeName(1), 'datatype' => 'dropdown'];
        $tab[] = ['id' => 121, 'table' => $t, 'field' => 'date_creation', 'name' => __('Creation date'), 'datatype' => 'datetime', 'massiveaction' => false];
        return $tab;
    }
}
