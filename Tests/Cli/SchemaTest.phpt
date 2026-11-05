--TEST--
dbdoctor:schema on an instance set up by the core setup command: No missing tables, columns and indexes
--ARGS--
dbdoctor:schema --level required
--FILE--
<?php declare(strict_types=1);
require_once __DIR__ . '/../../.Build/bin/typo3';
--EXPECTF--
Compare database schema with table definitions
==============================================

%A [OK] Database schema is up to date with level "required".%w
