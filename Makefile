.PHONY: up down test

up:
	docker compose up -d --build

down:
	docker compose down

test:
	docker compose up -d --wait db_test
	docker compose run --rm app php artisan test
