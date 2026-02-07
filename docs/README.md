# WP-Cron e Cron de Servidor

Para evitar atrasos no agendamento, recomendamos usar cron de servidor em vez do WP-Cron interno.

## Passos

1. No `wp-config.php`, defina:
   - `define('DISABLE_WP_CRON', true);`
2. Configure um cron no servidor para chamar `wp-cron.php` a cada 1 minuto.

## Exemplo de cron (Linux)

```
* * * * * /usr/bin/php /caminho/para/site/wp-cron.php > /dev/null 2>&1
```

Atenção: ajuste o caminho do PHP e do `wp-cron.php` conforme o seu servidor.
