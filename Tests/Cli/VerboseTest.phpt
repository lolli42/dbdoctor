--TEST--
dbdoctor:health -v shows title, actions and description of checks without affected records, too
--ARGS--
dbdoctor:health -v
--FILE--
<?php declare(strict_types=1);
require_once __DIR__ . '/../../.Build/bin/typo3';
--EXPECTF--
Find and fix database inconsistencies
=====================================

Scan for workspace records when ext:workspaces is not loaded
------------------------------------------------------------

 Class: WorkspacesNotLoadedRecordsDangling
 Actions: remove
 When extension "workspaces" is not loaded, there should be no workspace overlay
 records (t3ver_wsid != 0). This check removes all workspace related records if
 the extension is not loaded. Think about this twice: If workspaces is a thing
 in your instance, the extension must be loaded, otherwise this check will
 remove all existing workspace overlay records!

 [OK] No affected records found%w
%A
Scan for translated records with values not in sync with default language
%A
