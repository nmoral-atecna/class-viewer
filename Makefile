IMAGE := class-viewer-dev:issue-1
DOCKER := docker run --rm -u "$(shell id -u):$(shell id -g)" -e HOME=/tmp -v "$(CURDIR)":/app -w /app $(IMAGE)

.PHONY: build install test stan cs cs-fix infection check run

build:
	docker build -t $(IMAGE) -f Dockerfile .

install:
	$(DOCKER) composer install

test:
	$(DOCKER) vendor/bin/phpunit

stan:
	$(DOCKER) vendor/bin/phpstan analyse

cs:
	$(DOCKER) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	$(DOCKER) vendor/bin/php-cs-fixer fix

infection:
	$(DOCKER) vendor/bin/infection --min-msi=100 --threads=max

check: cs stan test

# make run ARGS="path/to/project"
run:
	$(DOCKER) php bin/class-viewer $(ARGS)
