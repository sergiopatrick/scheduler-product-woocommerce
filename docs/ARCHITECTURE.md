# Architecture

## Modulos

- `Plugin`
  - Bootstrap e constantes centrais.
  - Carrega classes e assets do admin.
- `RevisionPostType`
  - Registra o CPT `sanar_product_revision` e seus metadados.
- `ProductMetaBox`
  - UI de agendamento dentro do box "Publicar/Atualizar".
  - Intercepta o salvamento para impedir vazamento no produto pai.
- `RevisionAdmin`
  - Lista de revisoes, colunas, filtros e acoes.
- `RevisionManager`
  - Clonagem, snapshot, aplicacao e validacoes.
- `Runner`
  - Processa revisoes vencidas via comando WP-CLI.
- `Command` (WP-CLI)
  - Subcomandos `run --due-now`, `list --scheduled`, `retry <revision_id>`.
- `Logger`
  - Registra eventos no postmeta da revisao.

## Templates e Assets

- `templates/admin-revisions-list.php`: aviso contextual na listagem.
- `assets/css/admin.css`: estilos minimos.
- `assets/js/admin.js`: coleta payload e ajustes de UI.

## Fluxo (texto)

1. Usuario define uma data/hora futura no box "Publicar" e clica em "Atualizar".
2. `wp_insert_post_data` reverte `post_title/post_content/post_excerpt` para os valores atuais e captura um snapshot completo do produto pai.
3. `save_post_product` cria a revisao com o payload do POST, restaura o produto pai (campos + meta + taxonomias) e marca a revisao como `scheduled`.
4. A revisao recebe status `scheduled` e o horario em UTC.
5. Cron de servidor executa `wp sanar-wcps run --due-now`.
6. O runner seleciona revisoes vencidas, aplica lock por `product_id`, chama `RevisionManager::apply_revision()` e marca `published`.
7. Hooks internos disparam para cache e integracoes.

## Dados

- CPT: `sanar_product_revision`
- Metas principais:
  - `_sanar_wcps_parent_product_id`
  - `_sanar_wcps_scheduled_datetime` (UTC)
  - `_sanar_wcps_revision_status`
  - `_sanar_wcps_created_by`
  - `_sanar_wcps_timezone`
  - `_sanar_wcps_log`
  - `_sanar_wcps_error_message`
  - `_sanar_wcps_taxonomies`

## Atomicidade

- Snapshot do produto antes da aplicacao
- Reversao em caso de erro
- Lock por `product_id` para evitar corrida
  - Lock armazenado em options com TTL de 120 segundos

## Reverter pai + salvar revisao

- O agendamento acontece no mesmo fluxo de "Atualizar".
- `wp_insert_post_data` força os campos do post pai a permanecerem iguais ao banco.
- A revisao e criada a partir do payload do POST.
- Em seguida, o produto pai e restaurado integralmente (campos, metas e taxonomias).
- Somente depois disso a revisao fica com status `scheduled` para o runner externo.

## Dependencias

- WordPress + WooCommerce.
- WP-CLI disponivel no servidor.
- Cron de servidor executando `wp sanar-wcps run --due-now`.

## Execucao em Producao

- Exemplo:
  - `* * * * * wp --path=/var/www/site sanar-wcps run --due-now >/dev/null 2>&1`

## Limitacoes (v1)

- Produtos variaveis estao bloqueados para evitar aplicacao parcial de variacoes.
- Nao ha edicao visual dedicada da revisao. A revisao pode ser ajustada via tela de edicao do CPT.
