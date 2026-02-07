# Architecture

## Modulos

- `Plugin`
  - Bootstrap e constantes centrais.
  - Carrega classes e assets do admin.
- `RevisionPostType`
  - Registra o CPT `sanar_product_revision` e seus metadados.
- `ProductMetaBox`
  - UI de agendamento na tela de produto.
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

- `templates/metabox-schedule.php`: UI do metabox de agendamento.
- `templates/admin-revisions-list.php`: aviso contextual na listagem.
- `assets/css/admin.css`: estilos minimos.
- `assets/js/admin.js`: placeholder para melhorias futuras.

## Fluxo (texto)

1. Usuario abre um produto e agenda atualizacao.
2. O plugin captura os dados da tela atual (mesmo sem salvar) e monta uma revisao.
3. Caso algum dado nao esteja no POST, o fallback e o valor atual do produto pai no banco.
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

## Agendamento sem salvar o produto

- O metabox envia o formulario atual completo via `admin-post.php`.
- Os campos do POST sao priorizados para montar a revisao.
- O produto pai nunca e salvo nesse fluxo, garantindo que as alteracoes nao vazem antes do horario.

## Dependencias

- WordPress + WooCommerce.
- WP-Cron habilitado ou cron de servidor chamando `wp-cron.php`.

## Confiabilidade do WP-Cron

- Para maior confiabilidade, recomendamos definir `DISABLE_WP_CRON=true` no `wp-config.php`.
- Crie um cron de servidor chamando `wp-cron.php` a cada 1 minuto.

## Limitacoes (v1)

- Produtos variaveis estao bloqueados para evitar aplicacao parcial de variacoes.
- Nao ha edicao visual dedicada da revisao. A revisao pode ser ajustada via tela de edicao do CPT.
