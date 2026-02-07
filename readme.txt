=== Sanar WC Product Scheduler ===
Contributors: sanar
Tags: woocommerce, scheduler, products
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: Proprietary

== Description ==

Agende atualizacoes completas em produtos WooCommerce usando revisoes versionadas e WP-Cron.

== Installation ==

1. Envie o arquivo ZIP pelo painel em Plugins > Adicionar novo > Enviar plugin.
2. Ative o plugin.
3. Garanta que WooCommerce esteja ativo.
4. Recomenda-se configurar cron de servidor:
   - Defina DISABLE_WP_CRON=true no wp-config.php.
   - Agende chamada de wp-cron.php a cada 1 minuto.

== Usage ==

1. Abra um produto simples no admin.
2. Nao e necessario salvar o produto.
3. No box "Agendar atualizacao", selecione data/hora e confirme.
4. A revisao sera publicada automaticamente no horario via WP-Cron.

== Limitations ==

- Produtos variaveis nao sao suportados nesta versao inicial.

== Changelog ==

= 0.1.0 =
- Versao inicial.
