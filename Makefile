.PHONY: up down test stan cs shell console composer logs
# Windows without make: ./dev.ps1 <target> mirrors every target below.
up:
	docker compose up --build -d
down:
	docker compose down
test:
	docker compose exec app sh -c 'cd /srv/app && vendor/bin/phpunit'
stan:
	docker compose exec app sh -c 'cd /srv/app && vendor/bin/phpstan analyse -c /srv/phpstan.neon'
cs:
	docker compose exec app sh -c 'cd /srv/app && vendor/bin/php-cs-fixer fix --dry-run --diff --config=/srv/.php-cs-fixer.dist.php'
shell:
	docker compose exec app sh
console:
	docker compose exec app php /srv/app/bin/console $(c)
composer:
	docker compose exec -w /srv/app app composer $(c)
logs:
	docker compose logs -f app
