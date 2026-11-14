# Contributing to maatify/php-product

`maatify/php-product` owns Product, Variant, Option, Content, and Product image-assignment contracts backed by MySQL. Pricing, inventory, category mapping, and media storage belong to the Host or other packages. See the [Package Reference](PRODUCT_PACKAGE_REFERENCE.md) and [active decisions](docs/decisions/DECISIONS_INDEX.md).

Contribute reproducible bug reports, documentation corrections, test coverage, or changes within the package's approved scope. Discuss changes to architecture, public contracts, schema guarantees, or package boundaries before implementation and follow the decision process in [AGENTS.md](AGENTS.md).

## Local verification

```bash
composer update --no-interaction --prefer-dist --no-progress
composer validate --strict
composer analyse
composer format:check
composer test:unit
composer test:integration
composer verify:consumer
```

The Integration and Consumer gates use disposable MySQL services through Docker Compose. See [CI gates and prerequisites](docs/verification/CI_GATES.md) for the supported PHP and MySQL matrix and the full command list. Cover behavior changes through the relevant public entry point. Use real MySQL for changes to SQL, constraints, locking, or transactions. Capture a failing test before correcting a reproducible defect and a passing result after it.

Do not commit `composer.lock`. This library validates both current and lowest compatible dependency resolutions in CI.

## Pull requests

Explain the change, its scope, and the verification you ran. Include relevant tests and update any public contract or guide affected by the change. Follow the repository workflow and wait for applicable checks and review before requesting integration.

Send vulnerabilities through the private route in [SECURITY.md](SECURITY.md). Follow the [Code of Conduct](CODE_OF_CONDUCT.md) in project discussions.
