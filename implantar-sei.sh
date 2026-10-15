#!/bin/bash
# implantar-sei.sh — implanta no GLPI de PRODUÇÃO o plugin PCA 0.2.0 (reconciliação com o SEI).
#  Entrada: /root/pca-sei.tgz (conteúdo de glpi-pca/, sem dados/), enviado com ./svx --put.
#  Backup: /root/backup-pca-sei-<ts>/ (plugin + dump das tabelas do PCA + rollback.sh).
#  Efeito: troca os arquivos do plugin, cria glpi_plugin_pca_seiitens e reativa o plugin (o PCA fica
#  ~1 min indisponível; o resto do GLPI não é afetado). Não altera dados das contratações.
set -euo pipefail
PKG=${1:-/root/pca-sei.tgz}
V=/srv/docker/volumes/glpi-lab_glpi-data/_data/marketplace
P=$V/pca
TS=$(date +%Y%m%d-%H%M%S)
BK=/root/backup-pca-sei-$TS

[ -f "$PKG" ] || { echo "ERRO: pacote $PKG ausente ou corrompido"; exit 1; }
LISTA=$(tar tzf "$PKG"); grep -q 'src/Sei.php$' <<<"$LISTA" || { echo "ERRO: pacote sem src/Sei.php"; exit 1; }
[ -d "$P" ] || { echo "ERRO: $P não existe"; exit 1; }
docker ps --format '{{.Names}}' | grep -qx glpi || { echo "ERRO: contêiner glpi parado"; exit 1; }

mkdir -p "$BK"
grep -m1 PLUGIN_PCA_VERSION "$P/setup.php" > "$BK/versao-anterior.txt"
tar czf "$BK/pca-plugin.tgz" -C "$V" pca && tar tzf "$BK/pca-plugin.tgz" >/dev/null
docker exec glpi-db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction glpi $(mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -N glpi -e "show tables like \"glpi_plugin_pca_%\"" | tr "\n" " ")' > "$BK/pca-tabelas.sql"
[ -s "$BK/pca-tabelas.sql" ] && grep -q 'glpi_plugin_pca_contratacaos' "$BK/pca-tabelas.sql" || { echo "ERRO: dump das tabelas do PCA vazio"; exit 1; }

cat > "$BK/rollback.sh" <<RB
#!/bin/bash
# Volta o plugin para a versão anterior. A tabela glpi_plugin_pca_seiitens (staging do SEI) permanece;
# para descartá-la: DROP TABLE glpi_plugin_pca_seiitens (somente com confirmação).
set -eu
mv $P $V/pca.sei-removido-$TS
tar xzf $BK/pca-plugin.tgz -C $V
docker exec -u www-data -w /var/www/glpi glpi php bin/console plugin:install pca -u glpi --force
docker exec -u www-data -w /var/www/glpi glpi php bin/console plugin:activate pca
docker exec -u www-data -w /var/www/glpi glpi php bin/console cache:clear
echo "plugin restaurado ($(cat $BK/versao-anterior.txt)); versão nova guardada em $V/pca.sei-removido-$TS"
RB
chmod 700 "$BK/rollback.sh"
echo "backup: $BK"

# Implantação: extrai por cima (dados/ do servidor é preservado) e ajusta o dono
REF=$(stat -c '%u:%g' "$P/setup.php")
tar xzf "$PKG" -C "$P"
chown -R "$REF" "$P"
for f in src/Sei.php src/Contratacao.php src/Parametros.php front/sei.php hook.php setup.php; do
  docker exec glpi php -l "/var/glpi/marketplace/pca/$f" >/dev/null || { echo "ERRO de sintaxe em $f — execute $BK/rollback.sh"; exit 1; }
done

docker exec -u www-data -w /var/www/glpi glpi php bin/console plugin:install pca -u glpi --force
docker exec -u www-data -w /var/www/glpi glpi php bin/console plugin:activate pca
docker exec -u www-data -w /var/www/glpi glpi php bin/console cache:clear

# Carga real das classes com o Kernel (o php -l não detecta incompatibilidade de herança com o GLPI)
cat > /root/pca-carga.php <<'PHP'
<?php
chdir('/var/www/glpi'); require '/var/www/glpi/vendor/autoload.php';
$k = new \Glpi\Kernel\Kernel(); $k->boot();
foreach (['Contratacao', 'Perfil', 'Sei', 'Pncp'] as $c) {
    if (!class_exists('GlpiPlugin\\Pca\\' . $c)) { echo "FALHA $c\n"; exit(1); }
}
echo 'carga ok; contratacoes=', countElementsInTable(GlpiPlugin\Pca\Contratacao::getTable()), "\n";
PHP
docker cp /root/pca-carga.php glpi:/tmp/pca-carga.php >/dev/null
if ! docker exec -u www-data glpi php /tmp/pca-carga.php; then
  echo "ERRO na carga das classes: revertendo automaticamente"; bash "$BK/rollback.sh"; exit 1
fi

# Validação
docker exec glpi-db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" glpi -e "select directory,version,state from glpi_plugins where directory=\"pca\"; select count(*) as contratacoes from glpi_plugin_pca_contratacaos; select count(*) as seiitens from glpi_plugin_pca_seiitens;"'
grep -m1 PLUGIN_PCA_VERSION "$P/setup.php"
echo "OK. Abra PCA > Reconciliação SEI. Reversão: bash $BK/rollback.sh"
echo "$BK" > /root/ultimo-backup-pca-sei.txt
