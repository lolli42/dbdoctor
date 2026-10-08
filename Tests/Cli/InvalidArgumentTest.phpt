--TEST--
dbdoctor:health, first part of the health check chain
--ARGS--
dbdoctor:health
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

Scan for workspace records of deleted sys_workspace's
-----------------------------------------------------

 Class: WorkspacesRecordsOfDeletedWorkspaces
 Actions: remove
 When a workspace (table "sys_workspace") is deleted, all existing workspace
 overlays in all tables of this workspace are removed. When this goes wrong,
 or if the workspace extension is removed, the system ends up with "dangling"
 workspace records in tables. This health check finds those records and removes them.

 [OK] No affected records found%w

Scan for rows with delete field not "0" or "1"
----------------------------------------------

 Class: TcaTablesDeleteFlagZeroOrOne
 Actions: soft-delete
 Values of the "deleted" column of TCA tables with enabled soft-delete
 (["ctrl"]["delete"] set to a column name) must be either zero (0) or one (1).
 The default core database DeletedRestriction tests for equality with zero.
 This scan finds records having a different value than zero or one and sets them to one.

 [OK] No affected records found%w

Scan for soft-deleted workspaces records
----------------------------------------

 Class: WorkspacesSoftDeletedRecords
 Actions: workspace-remove
 Records in workspaces (t3ver_wsid != 0) are not soft-delete aware since TYPO3 v11:
 When "discarding" workspace changes, affected records are fully removed from the database.
 This check looks for workspace overlays being soft-deleted and removes them.

 [OK] No affected records found%w

Scan for records with negative pid
----------------------------------

 Class: WorkspacesPidNegative
 Actions: remove
 Records must have a pid equal or greater than zero (0).
 Until TYPO3 v10, workspace records where placed on pid=-1. This check removes leftovers.
 If this check finds records, it may indicate the upgrade wizard "WorkspaceVersionRecordsMigration"
 has not been run. ABORT NOW and run the wizard, it is included in TYPO3 core v10 and v11.

 [OK] No affected records found%w

Scan for records with t3ver_wsid=0 and t3ver_state!=0
-----------------------------------------------------

 Class: WorkspacesT3verStateNotZeroInLive
 Actions: remove, soft-delete, update-fields
 There should be no t3ver_state non-zero (0) records in live.
 If this check finds records, ABORT NOW and run these upgrades wizards:
 WorkspaceVersionRecordsMigration (TYPO3 v10 & v11),
 WorkspaceNewPlaceholderRemovalMigration (TYPO3 v11 & v12),
 WorkspaceMovePlaceholderRemovalMigration (TYPO3 v11 & v12).
 If there are still affected records, this check will remove, soft-delete or update them,
 depending on their specific t3ver_state value: Records typically shown in FE are kept,
 others are deleted or soft-deleted.

 [OK] No affected records found%w

Scan for records with t3ver_state=-1
------------------------------------

 Class: WorkspacesT3verStateMinusOne
 Actions: workspace-remove
 The workspace related field state t3ver_state=-1 has been removed with TYPO3 v11.
 Until TYPO3 v11, they were paired with a t3ver_state=-1 record. A core upgrade
 wizard migrates affected records. This check removes left over records having t3ver_state=-1.
 If this check finds records, it may indicate the upgrade wizard "WorkspaceNewPlaceholderRemovalMigration"
 has not been run. ABORT NOW and run the wizard if it is still available in your TYPO3 version.

 [OK] No affected records found%w

Scan for records with t3ver_state=3
-----------------------------------

 Class: WorkspacesT3verStateThree
 Actions: workspace-remove
 The workspace related field state t3ver_state=3 has been removed with TYPO3 v11.
 Until TYPO3 v11, they were paired with a t3ver_state=4 record. A core upgrade
 wizard migrates affected records. This check removes left over records having t3ver_state=3.
 If this check finds records, it may indicate the upgrade wizard "WorkspaceMovePlaceholderRemovalMigration"
 has not been run. ABORT NOW and run the wizard if it is still available in your TYPO3 version.

 [OK] No affected records found%w

Scan for sys_redirect records on wrong pid
------------------------------------------

 Class: SysRedirectInvalidPid
 Actions: update-fields
 Redirect records should be located on pages having a site config, or pid 0.
 A TYPO3 core upgrade wizard introduced in v12 deals with this. This check takes
 care of affected records as well: Records on pages that have no site config
 are moved to the first page up in rootline that has a site config, or to pid 0.

 [OK] No affected records found%w

Scan for records in default language not having language parent zero
--------------------------------------------------------------------

 Class: TcaTablesLanguageLessThanOneHasZeroLanguageParent
 Actions: update-fields
 TCA records in default or "all" language (typically sys_language_uid field having 0 or -1)
 must have their "transOrigPointerField" (typically l10n_parent or l18n_parent) field
 set to zero (0). This checks finds and updates violating records.

 [OK] No affected records found%w

Scan for records in default language not having language source zero
--------------------------------------------------------------------

 Class: TcaTablesLanguageLessThanOneHasZeroLanguageSource
 Actions: update-fields
 TCA records in default or "all" language (typically sys_language_uid field having 0 or -1)
 must have their "translationSource" (typically l10n_source) field set to zero (0).
 This checks finds and updates violating records.

 [OK] No affected records found%w

Check pages with negative language
----------------------------------

 Class: PagesLanguageNegative
 Actions: soft-delete, workspace-remove
 This health check finds not deleted "pages" records with sys_language_uid < 0. The
 backend does not allow language "-1" (all languages) for pages. Such pages are not shown
 in the page tree, menus and routing, but their sub pages and records may still be in use.
 They are soft-deleted in live and removed if they are workspace records. Later checks
 handle sub pages, translations and records of these pages.

 [OK] No affected records found%w

Check page tree integrity
-------------------------

 Class: PagesBrokenTree
 Actions: remove
 This health check finds "pages" records with their "pid" set to pages that do
 not exist in the database. Pages without proper connection to the tree root are never
 shown in the backend. They are removed.

 [OK] No affected records found%w

Check pages within deleted pages
--------------------------------

 Class: PagesPidDeleted
 Actions: soft-delete, workspace-remove
 This health check finds not deleted "pages" records with their "pid" set to a soft-deleted
 page, including whole sub trees below a deleted page. The core deletes sub pages when a
 page is deleted, those pages are not reachable in backend and frontend anymore. They are
 soft-deleted in live and removed if they are workspace records.

 [OK] No affected records found%w

Check localized pages having language parent set to self
--------------------------------------------------------

 Class: PagesTranslatedLanguageParentSelf
 Actions: soft-delete, workspace-remove
 This health check finds not deleted but localized (sys_language_uid > 0) "pages" records
 having their own uid set as their localization parent (l10n_parent = uid).
 This is invalid, such page records are not listed in the BE list module and the Frontend
 will most likely not render such pages.
 They are soft-deleted in live and removed if they are workspace overlay records.

 [OK] No affected records found%w

Check pages with missing language parent
----------------------------------------

 Class: PagesTranslatedLanguageParentMissing
 Actions: remove
 This health check finds translated "pages" records (sys_language_uid > 0) with
 their default language record (l10n_parent field) not existing in the database.
 Those translated pages are never shown in backend and frontend and removed.

 [OK] No affected records found%w

Check pages with deleted language parent
----------------------------------------

 Class: PagesTranslatedLanguageParentDeleted
 Actions: soft-delete, workspace-remove
 This health check finds not deleted but translated (sys_language_uid > 0) "pages" records,
 with their default language record (l10n_parent field) being soft-deleted.
 Those translated pages are never shown in backend and frontend. They are soft-deleted in
 live and removed if they are workspace overlay records.

 [OK] No affected records found%w

Check pages with different pid than their language parent
---------------------------------------------------------

 Class: PagesTranslatedLanguageParentDifferentPid
 Actions: remove
 This health check finds translated "pages" records (sys_language_uid > 0) with
 their default language record (l10n_parent field) on a different pid.
 Those translated pages are shown in backend at a wrong place. They are removed.

 [OK] No affected records found%w

Scan for duplicate page translations
------------------------------------

 Class: PagesTranslatedLanguageParentDuplicates
 Actions: soft-delete
 There must be only one translated "pages" record (sys_language_uid > 0) per
 default language page (l10n_parent) and language. This check finds duplicates,
 keeps the one with the lowest uid and soft-deletes others.

 [OK] No affected records found%w

Scan for sys_file records without sys_file_metadata record
----------------------------------------------------------

 Class: SysFileMetadataMissing
 Actions: insert
 Each "sys_file" record needs a default language "sys_file_metadata" record. The core creates
 it when a file is indexed, but does not re-create a missing one: Image dimensions are then
 unknown and images can not be cropped in backend. This check creates missing records. As
 with core indexing, width and height of images in local storages are read from the file.

 [OK] No affected records found%w

Scan for translated sys_file_metadata records with invalid language parent
--------------------------------------------------------------------------

 Class: SysFileMetadataTranslatedParentInvalid
 Actions: update-fields
 Translated "sys_file_metadata" records must point to the default language record of their
 file in "l10n_parent". This check finds live translations pointing to a not existing record,
 to no record, to themselves, or to the record of a different file, and sets "l10n_parent" to
 the default language record of their file. The translation is kept this way, instead of being
 deleted by later generic checks.

 [OK] No affected records found%w

Scan for record translations pointing to self
---------------------------------------------

 Class: TcaTablesTranslatedParentSelf
 Actions: soft-delete, remove, workspace-remove, risky
 Record translations ("translate" / "connected" mode, as opposed to "free" mode) use the
 database field "transOrigPointerField" (field name usually "l10n_parent" or "l18n_parent").
 This field should point to the default language record. This health check scans for not
 soft-deleted and localized records that point to their own uid in "transOrigPointerField".
 They are soft-deleted in live and removed if they are workspace overlay records.
 This change is considered risky since depending on configuration, such records may still be
 shown in the Frontend and will disappear when deleted.

 [OK] No affected records found%w

Scan for record translations pointing to non default language parent
--------------------------------------------------------------------

 Class: TcaTablesTranslatedParentInvalidPointer
 Actions: update-fields, soft-delete, remove, workspace-remove
 Record translations ("translate" / "connected" mode, as opposed to "free" mode) use the
 database field "transOrigPointerField" (field name usually "l10n_parent" or "l18n_parent").
 This field points to the default language record. This health check verifies that target
 actually has sys_language_uid = 0. Violating localizations are set to the transOrigPointerField
 of the current target record. Localizations of a sys_language_uid = -1 record are soft deleted
 if possible, or removed: The "all languages" record is shown in their language already.

 [OK] No affected records found%w

Scan for tt_content on not existing pages
-----------------------------------------

 Class: TtContentPidMissing
 Actions: remove
 tt_content must have a "pid" page record that exists. Otherwise, they are most likely not editable
 and can be removed. There are potential exceptions for tt_content records that are inline children
 for example using "news" extension that may create such scenarios, but even then, those records
 are most likely not shown in FE. You may want to look at some cases manually if this instance
 has some weird scenarios where tt_content is used as inline child. Otherwise, it is usually ok
 to let dbdoctor just REMOVE tt_content records that are located at a page that does not exist.

 [OK] No affected records found%w

Scan for tt_content on soft-deleted pages
-----------------------------------------

 Class: TtContentPidDeleted
 Actions: soft-delete, workspace-remove
 tt_content not soft-delete must have a "pid" page record that is not soft-deleted. Otherwise, they are
 most likely not editable. This is similar to the previous check, affected records will be soft-deleted
 if in live, and removed if in workspaces.

 [OK] No affected records found%w

Scan for soft-deleted localized tt_content records without parent
-----------------------------------------------------------------

 Class: TtContentDeletedLocalizedParentExists
 Actions: remove
 Soft deleted localized records in "tt_content" (sys_language_uid > 0) having
 l18n_parent > 0 must point to a sys_language_uid = 0 existing language parent record.
 Records violating this are removed.

 [OK] No affected records found%w

Localized tt_content records without parent
-------------------------------------------

 Class: TtContentLocalizedParentExists
 Actions: remove
 Localized records in "tt_content" (sys_language_uid > 0) having
 l18n_parent > 0 must point to a sys_language_uid = 0 existing language parent record.
 Violating records are removed since they are typically never rendered in FE,
 even though the BE renders them in page module.

 [OK] No affected records found%w

Localized tt_content records with soft-deleted parent
-----------------------------------------------------

 Class: TtContentLocalizedParentSoftDeleted
 Actions: soft-delete, workspace-remove
 Not soft-deleted localized records in "tt_content" (sys_language_uid > 0) having
 l18n_parent > 0 must point to a sys_language_uid = 0 language parent record that
 is not soft-deleted as well. Violating records are set to soft-deleted as well (or
 removed if in workspaces), since they are typically never rendered in FE, even
 though the BE renders them in page module.

 [OK] No affected records found%w

Scan for soft-deleted localized tt_content records with parent on different pid
-------------------------------------------------------------------------------

 Class: TtContentDeletedLocalizedParentDifferentPid
 Actions: remove
 Soft deleted localized records in "tt_content" (sys_language_uid > 0) having
 l18n_parent > 0 must point to a sys_language_uid = 0 language parent record
 on the same pid. Records violating this are removed.

 [OK] No affected records found%w

Scan for localized tt_content records with parent on different pid
------------------------------------------------------------------

 Class: TtContentLocalizedParentDifferentPid
 Actions: update-fields, workspace-remove
 Localized records in "tt_content" (sys_language_uid > 0) having
 l18n_parent > 0 must point to a sys_language_uid = 0 language parent record
 on the same pid. Records violating this are typically still shown in FE at
 the correct page the l18n_parent lives on, but are shown in the BE at the
 wrong page. Affected records are moved to the pid of the l18n_parent record
 when possible, or removed in some workspace scenarios.

 [OK] No affected records found%w

Duplicate localized tt_content records
--------------------------------------

 Class: TtContentLocalizedDuplicates
 Actions: soft-delete
 There must be only one localized record in "tt_content" per target language.
 Having more than one leads to various issues in FE and BE. This check finds
 duplicates, keeps the one with the lowest uid and soft-deletes others.

 [OK] No affected records found%w

Scan for localized tt_content records without page translation
--------------------------------------------------------------

 Class: TtContentLocalizedPageTranslationMissing
 Actions: soft-delete, workspace-remove
 Localized tt_content records (sys_language_uid > 0) need a not deleted page translation
 in their language on their page, otherwise they are never rendered in frontend. This check
 finds such records and soft-deletes them, workspace records are removed. tt_content records
 in sys folders are not checked: They are typically rendered by "Insert records" elements on
 other pages, which works without a page translation of the sys folder.

 [OK] No affected records found%w

Scan for translated records with not existing translation source
----------------------------------------------------------------

 Class: TcaTablesTranslationSourceExists
 Actions: update-fields
 When the "translationSource" field (typically l10n_source) of a translated record is not zero,
 the target record must exist. A broken translation source especially confuses the "Translate"
 button in page module. The translation source of affected records is set to the value of the
 "transOrigPointerField" (typically l10n_parent) if set, to zero otherwise.

 [OK] No affected records found%w

Scan for translated records with parent but without translation source
----------------------------------------------------------------------

 Class: TcaTablesTranslationSourceSetWithParent
 Actions: update-fields
 When the "transOrigPointerField" (typically l10n_parent) of a translated record is not zero
 ("Connected mode"), the "translationSource" field (typically l10n_source) must not be zero.
 A broken translation source especially confuses the "Translate" button in page module.
 The translation source of affected records is set to the value of the "transOrigPointerField".

 [OK] No affected records found%w

Scan for translated records with logically wrong translation source
-------------------------------------------------------------------

 Class: TcaTablesTranslationSourceLogicWithParent
 Actions: update-fields
 When "transOrigPointerField" (typically l10n_parent) and "translationSource" (typically
 l10n_source) of a translated record are not zero but point to different uids, it indicates
 the record has been derived from a different language record and not from the default language
 record. That different language record should have the same "transOrigPointerField" value. If
 this is not the case, set the translation source to the value of "transOrigPointerField" to fix
 the inheritance chain.

 [OK] No affected records found%w

%A
