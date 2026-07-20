# Testing

How to run this plugin's test suite and static checks.

The plugin has no `composer.json` of its own. PHPUnit and its dependencies come
from the surrounding Moodle installation, so the tests must run inside a Moodle
development or CI site with the plugin placed at
`<moodle-root>/plagiarism/pchkorg`. Never run them against a production database
or dataroot.

The suite makes no network requests to plagiarismcheck.org and needs no API
token.

## Prerequisites

Moodle's `config.php` must point PHPUnit at its own database prefix and
dataroot, neither of which may be the production ones:

```php
$CFG->phpunit_dataroot = '/path/to/phpunit_moodledata';
$CFG->phpunit_prefix = 'phpu_';
```

See Moodle's [PHPUnit installation documentation](https://moodledev.io/general/development/tools/phpunit)
if `vendor/bin/phpunit` is not present in your Moodle checkout.

## Run the tests

This command runs the plugin's full PHPUnit suite. It prepares the Moodle test
environment, regenerates the test configuration so any newly added test files
are picked up, and then runs the suite.

Replace `/path/to/moodle` with your Moodle directory: the one containing
`config.php` and `admin/`, which in newer Moodle layouts may be `public/`.

```bash
cd /path/to/moodle && \
php -d max_input_vars=5000 admin/tool/phpunit/cli/init.php && \
php -d max_input_vars=5000 admin/tool/phpunit/cli/util.php --buildcomponentconfigs && \
vendor/bin/phpunit --testsuite plagiarism_pchkorg_testsuite
```

Every step is idempotent, so this is safe to re-run at any time.

### What each step is for

- `init.php` prepares the PHPUnit database and dataroot. It must be re-run
  after `$plugin->version` changes, otherwise the suite aborts with
  *"Moodle PHPUnit environment was initialised for different version"*.
- `util.php --buildcomponentconfigs` regenerates
  `plagiarism/pchkorg/phpunit.xml`. A newly added test file is not picked up
  until this runs. That generated file is deliberately not tracked, since it
  hardcodes paths and a PHPUnit schema version belonging to one particular
  Moodle installation.
- `phpunit --testsuite plagiarism_pchkorg_testsuite` runs only this plugin's
  tests.

`-d max_input_vars=5000` satisfies a Moodle environment check. It can be
omitted where `php.ini` already sets that value.

## Narrower runs

```bash
cd /path/to/moodle

# One file.
vendor/bin/phpunit plagiarism/pchkorg/tests/sender_test.php

# One test method.
vendor/bin/phpunit --testsuite plagiarism_pchkorg_testsuite --filter test_sent_becomes_checked

# Ignore a stale result cache.
vendor/bin/phpunit --testsuite plagiarism_pchkorg_testsuite --do-not-cache-result

# Show warnings and deprecations that a passing run otherwise hides.
vendor/bin/phpunit --testsuite plagiarism_pchkorg_testsuite \
  --display-warnings --display-phpunit-deprecations
```

The last one is worth running before a release. The suite can report `OK` while
still emitting PHP warnings, and only that flag reveals them.

## Static checks

These run from the plugin directory and need no Moodle installation. Both are
gated in CI.

```bash
cd <moodle-root>/plagiarism/pchkorg

# Official Moodle coding style, using this repository's phpcs.xml.
phpcs

# Compatibility across the whole supported PHP range.
phpcs --standard=PHPCompatibility --runtime-set testVersion 7.2-8.4 --extensions=php .
```

They require these tools on `PATH`:

```bash
composer global config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer global require squizlabs/php_codesniffer moodlehq/moodle-cs \
  phpcompatibility/php-compatibility dealerdirect/phpcodesniffer-composer-installer
```

## Continuous integration

[GitHub Actions](.github/workflows/moodle-ci.yml) runs the same tests through
`moodle-plugin-ci` against a matrix of supported Moodle releases on PostgreSQL,
plus one MariaDB job, since the two databases disagree on enough SQL to be worth
covering separately. Two further jobs enforce the PHP version range described
below without needing a Moodle installation at all.

Each CI job uses a throwaway database and dataroot which are discarded
afterwards.

## Constraints when writing tests

- Production code must parse on **PHP 7.2** and run cleanly through **PHP 8.4**,
  because Moodle 3.9 accepts PHP 7.2 while Moodle 5.x accepts 8.4. The
  compatibility check above enforces this. It rules out arrow functions, `??=`,
  typed properties and spread-in-array (7.4); `match`, `?->`, named arguments,
  constructor promotion, union types and `str_contains` (8.0); enums, `readonly`
  and first-class callables (8.1); and trailing commas in function calls (7.3)
  or parameter lists (8.0). Write `?string $x = null` rather than
  `string $x = null`, since implicit nullable parameters are deprecated in 8.4.
- Test code must work across **PHPUnit 7 to 11**, the range spanned by Moodle
  3.9 to 5.x. In practice that means static data providers, no `setMethods()`,
  no regex assertions, and hand-written stubs rather than `createMock()`.
- Tests must never make HTTP requests to plagiarismcheck.org. Use the fake
  transport in `tests/fixtures/fake_transport.php`, which records requests and
  replays canned responses.
- Configuration is cached per request in `plagiarism_pchkorg_config_model`. That
  cache outlives `resetAfterTest()`, so call
  `plagiarism_pchkorg_config_model::reset_caches()` in `setUp()`, and again
  after creating course modules: creating one runs plugin hooks that populate
  the cache before your fixture rows exist.
