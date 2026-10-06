# dados/

Esta pasta é opcional e **não é versionada** (`dados/*.json` está no `.gitignore`): os arquivos de carga trazem o planejamento interno de cada órgão.

`tools/importar.php` aceita os caminhos por `--pca=` e `--pncp=`, ou lê daqui:

- `pca_<ano>.json` (ex.: `pca_2027.json`) — `{"exercicio": 2027, "contratacoes": [ {...} ]}`. Cada contratação usa os campos: `prioridade`, `destinacao`, `numero_pca`, `requisitante`, `name` (apelido), `objeto`, `tipo`, `justificativa`, `processo_sei`, `status`, `origem`, `necessidade`, `data_limite` (AAAA-MM-DD), `vigencia_meses`, `pagamento`, `valor_unico`, `valor_cheio_ano`, `observacoes`, `nota_revisao`, `valor_anual_original`, `valor_global_original`, `pncp_grupo_codigo`, `_valor_anual_planilha`, `_valor_global_planilha`.
- `pncp_tic_<ano>_semente.json` — opcional, só com `--semente`: `{"exercicio", "idPcaPncp", "sequencialPca", "itens": [ ... ]}` no formato da API do PNCP.
