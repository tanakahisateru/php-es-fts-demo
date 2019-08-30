# Simple full text search demo

Pre-requirements
- Docker
- PHP ^7.3
- Composer
- Symfony CLI frontend

```
curl -sS https://get.symfony.com/cli/installer | bash
```

How to run:

```
composer install

docker-compose up
symfony console doctrine:migrations:migrate
symfony console app:generate-fake-data
symfony console app:init-search-index

symfony serve --no-tls
```

Then open `http://127.0.0.1:8000/book/es?q=` with any search word.
You can append `&page=2` or `3` parameter to see more results.

To add / delete an entity:

```
symfony console app:add-book --title="ももたろう" --contents="むかしむかしあるところに..."
symfony console app:delete-book 101
```

If you want to test with huge data, MUST use `--no-debug` .

```
symfony console --no-debug app:generate-fake-data 300000
symfony console --no-debug app:init-search-index
```
