# Runner WP-CLI e Cron de Servidor

Este plugin processa revisoes agendadas apenas via runner WP-CLI.

## Passos

1. Garanta que `wp` (WP-CLI) esteja instalado no servidor.
2. Configure um cron no servidor para chamar o runner a cada 1 minuto.

## Exemplo de cron (Linux)

```
* * * * * wp --path=/var/www/site sanar-wcps run --due-now >/dev/null 2>&1
```

Atencao: ajuste o caminho do `wp` e o `--path` conforme o seu servidor.
