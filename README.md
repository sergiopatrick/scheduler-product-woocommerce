# Sanar WC Product Scheduler

Plugin interno para agendar atualizacoes completas em produtos WooCommerce usando revisoes versionadas e WP-Cron.

## Instalacao

1. Envie o ZIP pelo painel em **Plugins > Adicionar novo > Enviar plugin**.
2. Ative o plugin.
3. Garanta que WooCommerce esteja ativo.
4. Configure cron de servidor (recomendado):
   - Defina `DISABLE_WP_CRON=true` no `wp-config.php`.
   - Agende chamada de `wp-cron.php` a cada 1 minuto.

## Como usar

1. Abra um produto simples no admin.
2. Nao e necessario salvar o produto.
3. No box **Agendar atualizacao**, escolha a data/hora e clique **Agendar atualizacao**.
4. Uma revisao sera criada e agendada.
5. Na data/hora, o WP-Cron publicara a revisao automaticamente.

### Revisoes

Acesse **WooCommerce > Revisoes de Produto** para listar revisoes, ver o produto pai e executar acoes:

- Publicar agora
- Cancelar
- Editar revisao

## Scheduler

O agendamento usa WP-Cron com eventos one-shot. Para maior confiabilidade, use cron de servidor e desative o WP-Cron interno.
Veja `docs/README.md`.

## Logs

Eventos principais sao registrados no postmeta da revisao (`_sanar_wcps_log`) e falhas em `_sanar_wcps_error_message`.

## Limitacoes (v1)

- Produtos variaveis sao bloqueados nesta versao inicial.
- O agendamento usa o estado atual exibido na tela (payload do formulario). Para ajustar campos adicionais em revisoes, edite a revisao diretamente.

## Hooks internos

Veja `docs/HOOKS.md`.

## Estrutura

- `sanar-wc-product-scheduler.php` bootstrap
- `src/` classes principais
- `templates/` views admin
- `assets/` css/js admin
- `docs/` documentacao tecnica

## Testes manuais

Executar em ambiente WordPress com WooCommerce ativo.

- Produto publicado:
  - Alterar preco + campo ACF sem clicar em **Atualizar**
  - Clicar em **Agendar atualizacao**
  - Verificar: produto pai nao mudou, revisao criada, evento WP-Cron agendado
- Execucao:
  - Aguardar horario ou forcar execucao via `wp-cron.php`
  - Verificar: produto pai atualizado, revisao marcada como `published`, sem atualizacao parcial
- Erro controlado:
  - Simular falha de `wp_insert_post`
  - Verificar: admin_notice com mensagem real e log registrado
