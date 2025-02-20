--TEST--
dbdoctor:health
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
 WorkspaceNewPlaceholderRemovalMigration (TYPO3 11 & v12),
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
 has not been run. ABORT NOW and run the wizard, it is included in TYPO3 core v11 and v12.

 [OK] No affected records found%w

Scan for records with t3ver_state=3
-----------------------------------

 Class: WorkspacesT3verStateThree
 Actions: workspace-remove
 The workspace related field state t3ver_state=3 has been removed with TYPO3 v11.
 Until TYPO3 v11, they were paired with a t3ver_state=4 record. A core upgrade
 wizard migrates affected records. This check removes left over records having t3ver_state=3.
 If this check finds records, it may indicate the upgrade wizard "WorkspaceMovePlaceholderRemovalMigration"
 has not been run. ABORT NOW and run the wizard, it is included in TYPO3 core v11 and v12.

 [OK] No affected records found%w

Scan for sys_redirect records on wrong pid
------------------------------------------

 Class: SysRedirectInvalidPid
 Actions: update-fields
 Redirect records should be located on pages having a site config, or pid 0.
 There is a TYPO3 core v12 upgrade wizard to deal with this. This check takes
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

Check page tree integrity
-------------------------

 Class: PagesBrokenTree
 Actions: remove
 This health check finds "pages" records with their "pid" set to pages that do
 not exist in the database. Pages without proper connection to the tree root are never
 shown in the backend. They are removed.

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

Scan for record translations pointing to self
---------------------------------------------

 Class: TcaTablesTranslatedParentSelf
 Actions: update-fields, workspace-remove, risky
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
 Actions: update-fields
 Record translations ("translate" / "connected" mode, as opposed to "free" mode) use the
 database field "transOrigPointerField" (field name usually "l10n_parent" or "l18n_parent").
 This field points to the default language record. This health check verifies that target
 actually has sys_language_uid = 0. Violating localizations are set to the transOrigPointerField
 of the current target record.

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
 Actions: remove
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
 Actions: remove
 There must be only one localized record in "tt_content" per target language.
 Having more than one leads to various issues in FE and BE. This check finds
 duplicates, keeps the one with the lowest uid and soft-deletes others.

 [OK] No affected records found%w

Localized tt_content records must point to existing localization source
-----------------------------------------------------------------------

 Class: TtContentLocalizationSourceExists
 Actions: update-fields
 When l10n_source is not zero, the target record must exist.
 A broken l10n_source especially confuses the "Translate" button in page module.
 Affected records l10n_source is set to l18n_parent if set, to zero otherwise.

 [OK] No affected records found%w

Localized tt_content records must have localization source when parent is set
-----------------------------------------------------------------------------

 Class: TtContentLocalizationSourceSetWithParent
 Actions: update-fields
 When l18n_parent is not zero ("Connected mode"), l10n_source must not be zero.
 A broken l10n_source especially confuses the "Translate" button in page module.
 Affected records l10n_source is set to l18n_parent.

 [OK] No affected records found%w

Localized tt_content records must have logically correct localization source
----------------------------------------------------------------------------

 Class: TtContentLocalizationSourceLogicWithParent
 Actions: update-fields
 When tt_content l18n_parent and l10n_source are not zero but point to different uids,
 it indicates this record "source" has been derived from a different language record
 and not from the default language record. That different language record should have the
 same l18n_parent. If this is not the case, set the tt_content l10n_source to the
 value of l18n_parent to fix the inheritance chain.

 [OK] No affected records found%w

Scan for orphan sys_file_reference records
------------------------------------------

 Class: SysFileReferenceDangling
 Actions: remove
 Basic check of sys_file_reference: Records referenced in uid_local and uid_foreign
 must exist, otherwise that sys_file_reference row is obsolete and removed.

 [OK] No affected records found%w

Scan for deleted localized sys_file_reference records without parent
--------------------------------------------------------------------

 Class: SysFileReferenceDeletedLocalizedParentExists
 Actions: remove
 Soft deleted localized records in "sys_file_reference" (sys_language_uid > 0) having
 l10n_parent > 0 must point to a sys_language_uid = 0 existing language parent record.
 Records violating this are removed.

 [OK] No affected records found%w

Scan for localized sys_file_reference records without parent
------------------------------------------------------------

 Class: SysFileReferenceLocalizedParentExists
 Actions: risky, remove
 Localized records in "sys_file_reference" (sys_language_uid > 0) having
 l10n_parent > 0 must point to a sys_language_uid = 0 existing language parent record.
 Records violating this are REMOVED.
 This change is risky. Records with an invalid l10n_parent pointer typically throw
 an exception in the BE when edited. However, the FE often still shows such an image.
 As such, when this check REMOVES records, you may want to check them manually by looking
 at the referencing inline parent record indicated by fields "tablenames" and "uid_foreign"
 to eventually find a better solution manually, for instance by setting l10n_parent=0 or
 connecting it to the correct l10n_parent if in "connected mode", or by creating a new
 image relation and then letting dbdoctor remove this one after reloading the check.

 [OK] No affected records found%w

Scan for localized sys_file_reference records with deleted parent
-----------------------------------------------------------------

 Class: SysFileReferenceLocalizedParentDeleted
 Actions: risky, soft-delete, workspace-remove
 Localized, not deleted records in "sys_file_reference" (sys_language_uid > 0) having
 l10n_parent > 0 must point to a sys_language_uid = 0, not soft-deleted, language parent record.
 Records violating this are soft-deleted in live and removed if in workspaces.
 This change is risky. Records with a deleted=1 l10n_parent typically throw
 an exception in the BE when edited. However, the FE often still shows such an image.
 As such, when this check soft-deletes or removes records, you may want to check them manually by
 looking at the referencing inline parent record indicated by fields "tablenames" and "uid_foreign"
 to eventually find a better solution manually, for instance by setting l10n_parent=0 or
 connecting it to the correct l10n_parent if in "connected mode", or by creating a new
 image relation and then letting dbdoctor remove this one after reloading the check.

 [OK] No affected records found%w

Scan for localized sys_file_reference records with parent not in sync
---------------------------------------------------------------------

 Class: SysFileReferenceLocalizedFieldSync
 Actions: risky, soft-delete, workspace-remove
 Localized records in "sys_file_reference" (sys_language_uid > 0) must have fields "tablenames"
 and "fieldname" set to the same values as its language parent record.
 Records violating this indicate something is wrong with this localized record.
 This may happen for instance, when the tt_content ctype of a default language record is changed and
 relations are adapted after the record has been localized.
 This check is risky: It sets affected localized records to deleted=1 in live and removes
 them if they are workspace overlay records. Depending on what is wrong, this may change FE output.
 Look at "tablenames" and "uid_foreign" to see which inline parent record this relation is connected to,
 and eventually take care of affected records yourself by creating new localizations if needed.

 [OK] No affected records found%w

Scan for sys_file_reference records with invalid pid
----------------------------------------------------

 Class: SysFileReferenceInvalidPid
 Actions: update-fields
 Records in "sys_file_reference" must have "pid" set to the same pid as the
 parent record: If for instance a tt_content record on pid 5 references a sys_file, the
 sys_file_reference record should be on pid 5, too. This updates the pid of affected records.

 [OK] No affected records found%w

Scan for records on not existing pages
--------------------------------------

 Class: TcaTablesPidMissing
 Actions: remove
 TCA records have a pid field set to a single page. This page must exist.
 Records on pages that do not exist anymore are deleted.

 [OK] No affected records found%w

Scan for not-deleted records on pages set to deleted
----------------------------------------------------

 Class: TcaTablesPidDeleted
 Actions: soft-delete, remove, workspace-remove
 TCA records have a pid field set to a single page. This page must exist.
 This scan finds deleted=0 records pointing to pages having deleted=1.
 Affected records are soft deleted if possible, or removed.

 [OK] No affected records found%w

Scan for record translations with missing parent
------------------------------------------------

 Class: TcaTablesTranslatedLanguageParentMissing
 Actions: remove
 Record translations use the TCA ctrl field "transOrigPointerField"
 (DB field name usually "l10n_parent" or "l18n_parent"). This field points to a
 default language record. This health check verifies if that target exists.
 Affected records without language parent are removed.

 [OK] No affected records found%w

Scan for orphan sys_file_reference records
------------------------------------------

 Class: SysFileReferenceDangling
 Actions: remove
 Basic check of sys_file_reference: Records referenced in uid_local and uid_foreign
 must exist, otherwise that sys_file_reference row is obsolete and removed.

 [OK] No affected records found%w

Scan for not-deleted record translations with deleted parent
------------------------------------------------------------

 Class: TcaTablesTranslatedLanguageParentDeleted
 Actions: soft-delete, workspace-remove
 Record translations use the TCA ctrl field "transOrigPointerField"
 (DB field name usually "l10n_parent" or "l18n_parent"). This field points to a
 default language record. This health check verifies the target is not deleted=1.
 Affected records are set to deleted=1 if in live, or removed if in workspaces.

 [OK] No affected records found%w

Scan for record translations on wrong pid
-----------------------------------------

 Class: TcaTablesTranslatedLanguageParentDifferentPid
 Actions: update-fields, soft-delete, remove, workspace-remove
 Record translations use the TCA ctrl field "transOrigPointerField"
 (DB field name usually "l10n_parent" or "l18n_parent"). This field points to a
 default language record. This health check verifies translated records are on
 the same pid as the default language record. It will move, hide or remove affected
 records, which depends on potentially existing localizations on the target page.

 [OK] No affected records found%w

Scan for inline foreign field records with missing parent
---------------------------------------------------------

 Class: InlineForeignFieldChildrenParentMissing
 Actions: remove
 TCA inline foreign field records point to a parent record. This parent must exist.
 This check is for inline children defined *with* foreign_table_field in TCA.
 Inline children with missing parent are deleted.

 [OK] No affected records found%w

Scan for inline foreign field records with missing parent
---------------------------------------------------------

 Class: InlineForeignFieldNoForeignTableFieldChildrenParentMissing
 Actions: remove
 TCA inline foreign field records point to a parent record. This parent must exist.
 This check is for inline children defined *without* foreign_table_field in TCA.
 Inline children with missing parent are deleted.

 [OK] No affected records found%w

Scan for inline foreign field records with deleted=1 parent
-----------------------------------------------------------

 Class: InlineForeignFieldChildrenParentDeleted
 Actions: soft-delete, remove, workspace-remove
 TCA inline foreign field records point to a parent record. When this parent is
 soft-deleted, all children must be soft-deleted, too.
 This check finds not soft-deleted children and sets soft-deleted for for live records,
 or removes them when dealing with workspace records.

 [OK] No affected records found%w

Scan for inline foreign field records with deleted=1 parent
-----------------------------------------------------------

 Class: InlineForeignFieldNoForeignTableFieldChildrenParentDeleted
 Actions: soft-delete, remove, workspace-remove
 TCA inline foreign field records point to a parent record. When this parent is
 soft-deleted, all children must be soft-deleted, too.
 This check is for inline children defined *without* foreign_table_field in TCA.
 This check finds not soft-deleted children and sets soft-deleted for for live records,
 or removes them when dealing with workspace records.

 [OK] No affected records found%w

Scan for inline foreign field records with different language than their parent
-------------------------------------------------------------------------------

 Class: InlineForeignFieldChildrenParentLanguageDifferent
 Actions: update-fields, risky
 TCA inline foreign field child records point to a parent record. This check finds
 child records that have a different language than the parent record.
 Affected children are soft-deleted if the table is soft-delete aware, and
 hard deleted if not.

 [OK] No affected records found%w

Scan for inline foreign field records with different language than their parent
-------------------------------------------------------------------------------

 Class: InlineForeignFieldNoForeignTableFieldChildrenParentLanguageDifferent
 Actions: update-fields, risky
 TCA inline foreign field child records point to a parent record. This check finds
 child records that have a different language than the parent record.
 This check is for inline children defined *without* foreign_table_field in TCA.
 Affected children are soft-deleted if the table is soft-delete aware, and
 hard deleted if not.

 [OK] No affected records found%w
