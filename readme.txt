=== Sanar WC Product Scheduler ===
Contributors: sanar
Tags: woocommerce, scheduler, products
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: Proprietary

== Description ==

Agende atualizacoes completas em produtos WooCommerce usando revisoes versionadas e runner WP-CLI.

== Installation ==

1. Envie o arquivo ZIP pelo painel em Plugins > Adicionar novo > Enviar plugin.
2. Ative o plugin.
3. Garanta que WooCommerce esteja ativo.
4. Configure cron de servidor para executar o runner:
   - Agende: wp --path=/var/www/site sanar-wcps run --due-now >/dev/null 2>&1

== Usage ==

1. Abra um produto simples no admin.
2. Nao e necessario salvar o produto.
3. No box "Agendar atualizacao", selecione data/hora e confirme.
4. A revisao sera publicada quando o runner WP-CLI processar os itens vencidos.
5. Use WooCommerce > Agendamentos para listar, filtrar e executar acoes (Cancelar, Reagendar, Executar agora).

== Limitations ==

- Produtos variaveis nao sao suportados nesta versao inicial.

== Changelog ==

= 0.1.0 =
- Versao inicial.
