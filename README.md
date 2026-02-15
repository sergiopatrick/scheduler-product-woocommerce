# Sanar WC Product Scheduler

Plugin interno para agendar atualizacoes completas em produtos WooCommerce usando revisoes versionadas e runner WP-CLI.

## Instalacao

1. Envie o ZIP pelo painel em **Plugins > Adicionar novo > Enviar plugin**.
2. Ative o plugin.
3. Garanta que WooCommerce esteja ativo.
4. Configure cron de servidor para rodar o runner:
   - `* * * * * wp --path=/var/www/site sanar-wcps run --due-now >/dev/null 2>&1`

## Como usar

1. Abra um produto simples no admin.
2. Nao e necessario salvar o produto.
3. No box **Agendar atualizacao**, escolha a data/hora e clique **Agendar atualizacao**.
4. Uma revisao sera criada com status `scheduled`.
5. A publicacao acontece quando o runner for executado no servidor.

### Revisoes

Acesse **WooCommerce > Revisoes de Produto** para listar revisoes, ver o produto pai e executar acoes:

- Publicar agora
- Cancelar
- Editar revisao

### Home de Agendamentos

Acesse **WooCommerce > Agendamentos** para gerenciar todos os agendamentos em uma tela unica:

- Filtros por status, intervalo e busca por produto/ID
- Acoes por item: Ver, Cancelar, Reagendar e Executar agora
- Tela de detalhes com log completo e erro completo
- Compatibilidade com revisoes legadas: ao abrir a tela, o plugin migra em lote para o CPT canonico
- Revisoes orfas aparecem com alerta **ORPHAN** e execucao bloqueada ate corrigir o vinculo

## Runner WP-CLI

Comandos:

- `wp sanar-wcps run --due-now`
  - Processa revisoes `scheduled` vencidas (`scheduled_datetime <= agora UTC`) em lotes.
- `wp sanar-wcps list --scheduled`
  - Lista revisoes com status `scheduled`.
- `wp sanar-wcps retry <revision_id>`
  - Reagenda uma revisao para execucao imediata no proximo runner.

Este plugin **nao usa WP-Cron nem Action Scheduler** para publicar revisoes.

## Logs

Eventos principais sao registrados no postmeta da revisao (`_sanar_wcps_log`) e falhas em `_sanar_wcps_error_message`.

## Modo Diagnostico

Para habilitar o box de diagnostico na edicao de produto:

1. No `wp-config.php`, defina: `define('SANAR_WCPS_DIAG', true);`
2. Abra um produto no admin e veja o metabox **Sanar WCPS Status**.

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
  - Verificar: produto pai nao mudou, revisao criada com status `scheduled`
- Execucao:
  - Aguardar horario e executar `wp sanar-wcps run --due-now`
  - Verificar: produto pai atualizado, revisao marcada como `published`, sem atualizacao parcial
- Erro controlado:
  - Simular excecao durante aplicacao da revisao
  - Verificar: admin_notice com mensagem real e log registrado
