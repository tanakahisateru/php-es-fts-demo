Setup data. MUST use `--no-debug`.

```
symfony console app:generate-fake-data --no-debug 100000
symfony console app:init-search-index --no-debug
```

Serve the app:

```
symfony serve --no-tls
```
