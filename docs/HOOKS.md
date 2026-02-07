# Hooks Internos

Use estes hooks para integracoes (cache, notificacoes, observabilidade).

## Actions

- `sanar_wcps_publish_revision( int $revision_id )`
  - Hook do WP-Cron para publicar a revisao agendada.

- `sanar_wcps_after_publish( int $product_id, int $revision_id )`
  - Disparado apos a revisao ser aplicada com sucesso.

- `sanar_wcps_cache_purge( int $product_id, int $revision_id )`
  - Disparado para integracoes de purge de cache/CDN.

## Filters

- `sanar_wcps_protected_meta_keys( array $keys ) : array`
  - Permite adicionar chaves de metadados que nao devem ser removidas quando a revisao e aplicada.
