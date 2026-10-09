<?php

namespace GlpiPlugin\Pca;

use Html;

/**
 * Filtro estilo Excel (funil) nos títulos da lista de contratações.
 * Cada seleção vira um grupo de critérios "OU" do próprio GLPI, então ordenação,
 * paginação, busca salva e exportação continuam funcionando.
 */
class Funil
{
    /** id da opção de busca => [coluna, rótulo]. */
    public const COLUNAS = [
        5  => ['destinacao', 'Destinação'],
        7  => ['requisitante', 'Requisitante'],
        4  => ['prioridade', 'Prioridade'],
        18 => ['conferido', 'Conferido pela área'],
        9  => ['tipo', 'Tipo'],
    ];

    private const PRIORIDADES = [1 => '1 - Alta', 2 => '2 - Média', 3 => '3 - Baixa'];

    /** Valores distintos de cada coluna (valor => rótulo), das contratações não excluídas. */
    public static function opcoes(): array
    {
        global $DB;
        $out = [];
        foreach (self::COLUNAS as $id => [$col, $nome]) {
            $itens = [];
            foreach ($DB->request(['SELECT' => $col, 'DISTINCT' => true, 'FROM' => Contratacao::getTable(),
                'WHERE' => ['is_deleted' => 0, 'NOT' => [$col => null]]]) as $r) {
                $v = trim((string) $r[$col]);
                if ($v === '') {
                    continue;
                }
                if ($col === 'conferido') {
                    $rot = $v === '1' ? 'Sim' : 'Não';
                } elseif ($col === 'prioridade') {
                    $rot = self::PRIORIDADES[(int) $v] ?? $v;
                } else {
                    $rot = $v;
                }
                $itens[] = ['v' => $v, 'r' => $rot];
            }
            usort($itens, static fn($a, $b) => strnatcasecmp($a['r'], $b['r']));
            $out[$id] = ['nome' => $nome, 'itens' => $itens];
        }
        return $out;
    }

    /** Escreve o script que desenha os funis. $criterios = critérios atuais da busca. */
    public static function imprimir(array $criterios, string $url_base): void
    {
        $dados = [
            'colunas'   => self::opcoes(),
            'criterios' => array_values($criterios),
            'url'       => $url_base,
            'ordem'     => array_intersect_key($_GET, array_flip(['sort', 'order'])),
        ];
        $json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo Html::scriptBlock('window.PCA_FUNIL = ' . $json . ';' . self::js());
        echo '<style>
.pca-funil{border:0;background:transparent;padding:0 .25rem;margin-left:.25rem;cursor:pointer;color:#8a94a6;line-height:1}
.pca-funil.ativo{color:#d6336c}
.pca-funil-box{position:absolute;z-index:1080;background:var(--tblr-bg-surface,#fff);color:var(--tblr-body-color,#212529);border:1px solid var(--tblr-border-color,#ccc);border-radius:.4rem;box-shadow:0 .5rem 1rem rgba(0,0,0,.2);padding:.6rem;width:260px;font-weight:normal;text-transform:none}
.pca-funil-box .lista{max-height:240px;overflow:auto;margin:.4rem 0;border:1px solid var(--tblr-border-color,#ddd);border-radius:.25rem;padding:.25rem .5rem}
.pca-funil-box label{display:flex;gap:.4rem;align-items:center;margin:.15rem 0;cursor:pointer}
</style>';
    }

    private static function js(): string
    {
        return <<<'JS'
(function () {
  var D = window.PCA_FUNIL;
  if (!D) { return; }

  // Valores já filtrados por coluna: grupos de critérios cujos itens são todos "campo é valor" do mesmo campo.
  function selecionados(id) {
    var out = [];
    D.criterios.forEach(function (c) {
      if (c && c.criteria && c.criteria.length && c.criteria.every(function (s) { return String(s.field) === String(id) && s.searchtype === 'equals'; })) {
        c.criteria.forEach(function (s) { out.push(String(s.value)); });
      }
    });
    return out;
  }
  function ehGrupoDe(c, id) {
    return c && c.criteria && c.criteria.length && c.criteria.every(function (s) { return String(s.field) === String(id); });
  }
  function achatar(o, p, q) {
    Object.keys(o).forEach(function (k) {
      var v = o[k], n = p ? p + '[' + k + ']' : k;
      if (v !== null && typeof v === 'object') { achatar(v, n, q); }
      else if (v !== undefined && v !== null) { q.push(encodeURIComponent(n) + '=' + encodeURIComponent(v)); }
    });
  }
  function aplicar(id, valores) {
    var crit = D.criterios.filter(function (c) { return !ehGrupoDe(c, id); }).map(function (c) {
      return c.criteria ? c : {link: c.link || 'AND', field: c.field, searchtype: c.searchtype, value: c.value};
    });
    if (valores.length) {
      crit.push({link: 'AND', criteria: valores.map(function (v, i) {
        return {link: i ? 'OR' : 'AND', field: id, searchtype: 'equals', value: v};
      })});
    }
    if (crit.length) { crit[0].link = 'AND'; }
    var q = ['reset=reset'];
    achatar({criteria: crit}, '', q);
    achatar(D.ordem || {}, '', q);
    window.location.href = D.url + '?' + q.join('&');
  }

  var aberta = null;
  function fechar() { if (aberta) { aberta.remove(); aberta = null; } }
  document.addEventListener('click', function (e) { if (aberta && !aberta.contains(e.target)) { fechar(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { fechar(); } });

  function abrir(btn, id) {
    fechar();
    var col = D.colunas[id], marcados = selecionados(id);
    var box = document.createElement('div');
    box.className = 'pca-funil-box';
    var busca = '<input type="text" class="form-control form-control-sm" placeholder="Pesquisar">';
    var itens = col.itens.map(function (it) {
      return '<label data-t="' + it.r.toLowerCase().replace(/"/g, '') + '"><input type="checkbox" value="' + it.v.replace(/"/g, '&quot;') + '"' +
        (marcados.indexOf(it.v) >= 0 ? ' checked' : '') + '> <span></span></label>';
    }).join('');
    box.innerHTML = '<div class="fw-bold mb-1">' + col.nome + '</div>' + busca +
      '<div class="mt-1 small"><a href="#" data-a="todos">Selecionar todos</a> · <a href="#" data-a="nenhum">Limpar seleção</a></div>' +
      '<div class="lista">' + (itens || '<em>Sem valores</em>') + '</div>' +
      '<div class="d-flex justify-content-between"><button type="button" class="btn btn-sm btn-outline-secondary" data-a="limpar">Remover filtro</button>' +
      '<button type="button" class="btn btn-sm btn-primary" data-a="ok">Aplicar</button></div>';
    var spans = box.querySelectorAll('.lista label span');
    col.itens.forEach(function (it, i) { if (spans[i]) { spans[i].textContent = it.r; } });
    document.body.appendChild(box);
    var r = btn.getBoundingClientRect();
    box.style.top = (window.scrollY + r.bottom + 4) + 'px';
    box.style.left = Math.max(8, Math.min(window.scrollX + r.left, window.scrollX + document.documentElement.clientWidth - 276)) + 'px';
    aberta = box;
    var checks = function () { return box.querySelectorAll('.lista input[type=checkbox]'); };
    box.querySelector('input[type=text]').addEventListener('input', function (e) {
      var t = e.target.value.toLowerCase();
      box.querySelectorAll('.lista label').forEach(function (l) { l.style.display = l.getAttribute('data-t').indexOf(t) >= 0 ? '' : 'none'; });
    });
    box.addEventListener('click', function (e) {
      var a = e.target.closest('[data-a]');
      if (!a) { return; }
      e.preventDefault();
      var acao = a.getAttribute('data-a');
      if (acao === 'todos') { checks().forEach(function (c) { if (c.parentNode.style.display !== 'none') { c.checked = true; } }); }
      if (acao === 'nenhum') { checks().forEach(function (c) { c.checked = false; }); }
      if (acao === 'limpar') { aplicar(id, []); }
      if (acao === 'ok') {
        var v = []; checks().forEach(function (c) { if (c.checked) { v.push(c.value); } });
        aplicar(id, v);
      }
    });
    box.querySelector('input[type=text]').focus();
  }

  function montar() {
    Object.keys(D.colunas).forEach(function (id) {
      var th = document.querySelector('th[data-searchopt-id="' + id + '"]');
      if (!th || th.querySelector('.pca-funil')) { return; }
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'pca-funil' + (selecionados(id).length ? ' ativo' : '');
      b.title = 'Filtrar ' + D.colunas[id].nome;
      b.innerHTML = '<i class="ti ti-filter"></i>';
      b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); abrir(b, id); });
      th.appendChild(b);
    });
  }
  montar();
})();
JS;
    }
}
