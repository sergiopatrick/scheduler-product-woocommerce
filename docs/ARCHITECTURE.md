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
- `Scheduler`
  - Agenda e publica revisoes via WP-Cron (one-shot).
- `Logger`
  - Registra eventos no postmeta da revisao.
- `Lock`
  - Lock por `product_id` para evitar corrida.

## Templates e Assets

- `templates/admin-revisions-list.php`: aviso contextual na listagem.
- `assets/css/admin.css`: estilos minimos.
- `assets/js/admin.js`: coleta payload e ajustes de UI.

## Fluxo (texto)

1. Usuario define uma data/hora futura no box "Publicar" e clica em "Atualizar".
2. `wp_insert_post_data` reverte `post_title/post_content/post_excerpt` para os valores atuais e captura um snapshot completo do produto pai.
3. `save_post_product` cria a revisao com o payload do POST, restaura o produto pai (campos + meta + taxonomias) e so entao agenda o evento.
4. A revisao recebe status `scheduled` e o horario em UTC.
5. WP-Cron executa `sanar_wcps_publish_revision` no horario.
6. `RevisionManager` valida, aplica atualizacao atomica e marca `published`.
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
  - Lock armazenado em options com TTL de 10 minutos

## Reverter pai + salvar revisao

- O agendamento acontece no mesmo fluxo de "Atualizar".
- `wp_insert_post_data` força os campos do post pai a permanecerem iguais ao banco.
- A revisao e criada a partir do payload do POST.
- Em seguida, o produto pai e restaurado integralmente (campos, metas e taxonomias).
- Somente depois disso o agendamento e criado via WP-Cron.

## Dependencias

- WordPress + WooCommerce.
- WP-Cron habilitado ou cron de servidor chamando `wp-cron.php`.

## Confiabilidade do WP-Cron

- Para maior confiabilidade, recomendamos definir `DISABLE_WP_CRON=true` no `wp-config.php`.
- Crie um cron de servidor chamando `wp-cron.php` a cada 1 minuto.

## Limitacoes (v1)

- Produtos variaveis estao bloqueados para evitar aplicacao parcial de variacoes.
- Nao ha edicao visual dedicada da revisao. A revisao pode ser ajustada via tela de edicao do CPT.
