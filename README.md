# payroll-adjustments
A PHP domain model for payroll adjustments with immutable audit history and PHPUnit tests.

```shell
docker compose up -d
docker compose exec payroll_adjustments_cloud bash

supervisorctl status
supervisorctl restart nginx
supervisorctl restart php-fpm
```

# Initial
```shell
# Windows
docker compose pull
# Mac OS Silicon
DOCKER_DEFAULT_PLATFORM=linux/amd64 docker compose pull

# Start container
docker compose up -d
# bash
docker compose exec payroll_adjustments_cloud bash
```

# build
```shell
docker buildx bake -f compose.yml --set '*.platform=linux/amd64' --builder desktop-linux --push
# OR
docker buildx bake -f compose.yml --set '*.platform=linux/amd64' --builder default --push
```
