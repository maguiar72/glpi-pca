# Plugin PCA para GLPI 11

Cadastro das contratações do **Plano de Contratações Anual (PCA)**, com cálculo automático de Valor Anual e Valor Global, pendências de preenchimento, painel por exercício e conciliação com o plano publicado no PNCP.

- **Versão:** 0.1.5 (protótipo)
- **GLPI:** 11.0.x · **PHP:** 8.2+ · **Idioma:** apenas português
- **Licença:** GPLv3+ (arquivo `LICENSE`)

## O que o plugin entrega

| Tela | Onde | Função |
|---|---|---|
| Contratações | Gerência > PCA | Lista com busca, filtros, colunas e exportação padrão do GLPI; combo de **Exercício** (Todos, 2025–2028 e qualquer ano cadastrado) |
| Ficha | clique no apelido | Dados da contratação, valores calculados, pendências e histórico de alterações |
| Painel | menu superior > Painel | Totais por destinação, requisitante, necessidade, prioridade, tipo, pagamento, origem e mês; combo de exercício no título |
| Conciliação PNCP | menu superior > Conciliação PNCP | Cadastro x plano publicado no PNCP, pela chave "Código no PNCP" |
| Parâmetros | menu superior > Parâmetros | Exercício, regra de meses, faixas de prioridade, PNCP e listas suspensas |
| Direitos | Administração > Perfis > aba PCA | Quem lê, cria, altera e exclui; quem administra |

## Regra de cálculo

- **Meses no exercício:** contados a partir do mês da data limite (maio = 8). Data anterior ao exercício = 12; posterior = 0; sem data = 12. A contagem do mês da data limite é parâmetro ("Contar o mês da data limite").
- **Valor Anual** = pagamento único + (valor cheio no ano / 12 × meses no exercício).
- **Valor Global** = pagamento único + (valor cheio no ano × vigência em meses / 12).
- Valor zerado é tratado como não informado.

## Quem altera o quê

- O direito "Contratações do PCA" define leitura, criação, alteração e exclusão por perfil.
- Quem não tem o direito "Parâmetros, conciliação e edição de qualquer unidade" só altera, exclui, purga e transfere contratações cuja unidade requisitante (grupo do GLPI) é um dos seus grupos. Contratações sem grupo ficam abertas a quem tem o direito correspondente.
- Na instalação, os perfis que administram a configuração do GLPI recebem todos os direitos. Em atualização, os direitos já ajustados são preservados.

## Instalação

1. Copie esta pasta para `plugins/pca` (ou `marketplace/pca`) do GLPI e ajuste o dono dos arquivos para o usuário do servidor web.
2. Instale e ative:
   ```bash
   php bin/console plugin:install pca -u <usuario-glpi>
   php bin/console plugin:activate pca
   ```
3. Abra **Parâmetros** e informe o exercício, o **CNPJ do órgão** e a **UASG** no PNCP, a estimativa anual do PCA e as faixas de prioridade. Os CNPJ/UASG ficam em branco até serem informados.
4. Dê o direito PCA aos perfis em Administração > Perfis > aba PCA.

## Carga de dados

`tools/importar.php` lê um JSON com as contratações (formato em `dados/README.md`) e grava no cadastro:

```bash
php plugins/pca/tools/importar.php --pca=/caminho/pca.json [--simular] [--forcar] [--semente --pncp=/caminho/semente.json]
```

- Idempotente: a chave é exercício + apelido.
- **Não sobrescreve** contratações já alteradas por uma pessoa (histórico com usuário) ou marcadas como conferidas; elas são listadas. `--forcar` sobrescreve também essas.
- A unidade requisitante é ligada ao grupo do GLPI cuja sigla (campo "código") coincide com o requisitante do arquivo; requisitante composto (`AREA1/AREA2`) usa o primeiro código que corresponder a um grupo.
- Ao final, informa as divergências de cálculo frente aos valores do arquivo.

## PNCP e o plugin PNCP

A conciliação lê os itens do PCA publicados no PNCP por **uma de duas fontes**, nesta ordem:

1. **Plugin PNCP (opcional)**: se o plugin "PNCP" estiver ativo e tiver a tabela `glpi_plugin_pncp_pcaitems`, a conciliação usa os itens que o monitoramento dele já coleta. Nesse caso o PCA não faz nenhuma chamada própria ao portal.
2. **Leitura própria**: sem o plugin PNCP, o PCA lê o portal diretamente (ação automática `SincronizarPncp`, criada desativada, e botão "Ler o PNCP agora") e guarda em `glpi_plugin_pca_pncpitens`.

Os dois plugins são **independentes**: o plugin PNCP não conhece o PCA, e o PCA funciona sozinho.

- Somente leitura: o plugin não grava nada no PNCP.
- Endpoints usados na leitura própria: `/orgaos/{cnpj}/pca/{ano}/consolidado/unidades` e `/orgaos/{cnpj}/pca/{ano}/{sequencial}/itens`.
- O servidor do GLPI precisa de saída HTTPS para `pncp.gov.br` (o proxy usado é o de Configurar > Geral > Proxy).
- O plugin PNCP não guarda o código da categoria, só o nome; por isso TIC é reconhecido pelo nome (parâmetro "Categoria ... TIC (nome)").

## Limites conhecidos

- Não há envio de dados ao PNCP nem ao Compras.gov.br.
- A API de alto nível do GLPI (v2) não expõe tipos de plugin; para integração, use a API legada (`apirest.php`) com o tipo `GlpiPlugin\Pca\Contratacao`.
- Os textos estão só em português.

## Remoção

```bash
php bin/console plugin:uninstall pca
```

A desinstalação apaga as tabelas do plugin e os dados.

## Histórico

### 0.1.5
- Repositório genérico: CNPJ, UASG e listas suspensas padrão neutros (configuráveis em Parâmetros); mensagem clara quando CNPJ/UASG não estão informados.

### 0.1.4
- Combo "Exercício" na lista, no Painel e na Conciliação; o ano escolhido fica na sessão e os links do menu superior abrem nele.

### 0.1.3
- Menu lateral "PCA". Painel, Conciliação, Parâmetros e Ficha mostram valores como `R$ 1.234,56`. Botões/combo de exercício na lista.

### 0.1.2
- A conciliação lê os itens do plugin PNCP quando disponível. `importar.php` não sobrescreve contratações já alteradas.

### 0.1.1
- A carga-semente do PNCP só é gravada com `--semente` e sem itens reais. Valor Anual/Global das listas em `R$`. Excluir, purgar e transferir seguem a regra de unidade. `plugin_pca_install()` idempotente em atualização.

### 0.1.0
- Primeira versão: cadastro, cálculo, pendências, painel, conciliação, parâmetros e direitos.
