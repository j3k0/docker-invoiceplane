all: build

build:
	@docker build --tag=jeko/invoiceplane:latest .

release: build
	@docker build --tag=jeko/invoiceplane:$(shell cat VERSION) .
