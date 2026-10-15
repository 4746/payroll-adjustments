# Назва контейнера для доступу до bash
CONTAINER_NAME = payroll_adjustments_cloud

# Команди для Docker Compose
up:
	docker-compose up -d

down:
	docker-compose down

restart: down up

bash:
	docker compose exec $(CONTAINER_NAME) /bin/bash

doc:
	docker compose exec $(CONTAINER_NAME) php artisan scribe:generate

lint:
	docker compose exec $(CONTAINER_NAME) ./vendor/bin/pint

bash-root:
	docker compose exec -u root $(CONTAINER_NAME) /bin/bash

cache-clear:
	docker compose exec $(CONTAINER_NAME) php artisan cache:clear

route:
	docker compose exec $(CONTAINER_NAME) php artisan route:list --sort

route-v:
	docker compose exec $(CONTAINER_NAME) php artisan route:list -v --sort

route-vvv:
	docker compose exec $(CONTAINER_NAME) php artisan route:list -vvv --sort

key-generate:
	docker compose exec $(CONTAINER_NAME) php artisan key:generate

.PHONY: up stop restart bash cache-clear log-clear route route-v route-vvv doc lint
