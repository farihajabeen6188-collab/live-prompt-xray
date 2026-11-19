.PHONY: up down test stan cs
up:
	docker compose up --build -d
down:
	docker compose down
test:
	docker compose run --rm app vendor/bin/phpunit
stan:
	docker compose run --rm app vendor/bin/phpstan analyse -c phpstan.neon
cs:
	docker compose run --rm app vendor/bin/php-cs-fixer fix --dry-run --diff
