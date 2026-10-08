--TEST--
dbdoctor:health, second part of the health check chain
--ARGS--
dbdoctor:health
--FILE--
<?php declare(strict_types=1);
require_once __DIR__ . '/../../.Build/bin/typo3';
--EXPECTF--
Find and fix database inconsistencies
=====================================

%A
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

Scan for records on translated pages
------------------------------------

 Class: TcaTablesPidTranslatedPage
 Actions: update-fields
 TCA records have a pid field set to a single page. This must be a default language
 page: Records attached to a translated page, for instance "sys_file_reference" records
 of the translated page "media" field, are located on the default language page, too.
 Records pointing to a translated page are moved to its default language page.

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

Scan for translated records with language not in site configuration
-------------------------------------------------------------------

 Class: TcaTablesTranslatedLanguageNotInSiteConfiguration
 Actions: soft-delete, remove, workspace-remove
 Translated records reference a sys_language_uid. This language must be configured
 in the site configuration of the page they are located on. This check finds records
 with a sys_language_uid that does not exist in the site configuration. They are soft
 deleted if possible, or removed. Records outside of a site are not checked.

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
 Actions: soft-delete, workspace-remove
 TCA inline foreign field records point to a parent record. When this parent is
 soft-deleted, all children must be soft-deleted, too.
 This check finds not soft-deleted children and sets soft-deleted for for live records,
 or removes them when dealing with workspace records.

 [OK] No affected records found%w

Scan for inline foreign field records with deleted=1 parent
-----------------------------------------------------------

 Class: InlineForeignFieldNoForeignTableFieldChildrenParentDeleted
 Actions: soft-delete, workspace-remove
 TCA inline foreign field records point to a parent record. When this parent is
 soft-deleted, all children must be soft-deleted, too.
 This check is for inline children defined *without* foreign_table_field in TCA.
 This check finds not soft-deleted children and sets soft-deleted for for live records,
 or removes them when dealing with workspace records.

 [OK] No affected records found%w

Scan for inline foreign field records with different language than their parent
-------------------------------------------------------------------------------

 Class: InlineForeignFieldChildrenParentLanguageDifferent
 Actions: soft-delete, remove, workspace-remove, update-fields, risky
 TCA inline foreign field child records point to a parent record. This check finds
 child records that have a different language than the parent record.
 Affected children are soft-deleted if the table is soft-delete aware, and
 hard deleted if not. Children with language -1 (all languages) of a translated
 parent are shown in frontend along with their parent: Their language is set to
 the language of the parent instead.

 [OK] No affected records found%w

Scan for inline foreign field records with different language than their parent
-------------------------------------------------------------------------------

 Class: InlineForeignFieldNoForeignTableFieldChildrenParentLanguageDifferent
 Actions: soft-delete, remove, workspace-remove, update-fields, risky
 TCA inline foreign field child records point to a parent record. This check finds
 child records that have a different language than the parent record.
 This check is for inline children defined *without* foreign_table_field in TCA.
 Affected children are soft-deleted if the table is soft-delete aware, and
 hard deleted if not. Children with language -1 (all languages) of a translated
 parent are shown in frontend along with their parent: Their language is set to
 the language of the parent instead.

 [OK] No affected records found%w

Scan for group fields with relations to missing records
-------------------------------------------------------

 Class: GroupFieldRelationMissing
 Actions: update-fields
 Fields of TCA type "group" without MM table store their relations as comma
 separated list, for instance the tt_content field "records" of content type
 "Insert records". This check finds relations to records that do not exist
 and removes them from the list. Relations to soft-deleted records are kept:
 The backend does not remove them when a record is deleted, and they are
 needed when a record is restored using the recycler.

 [OK] No affected records found%w

Scan for group fields with MM relations to missing records
----------------------------------------------------------

 Class: GroupFieldMmRelationMissing
 Actions: remove
 Fields of TCA type "group" with MM table store their relations as rows in
 the MM table, for instance the sys_category field "items". This check finds
 MM rows pointing to records that do not exist and removes them. Relations to
 soft-deleted records are kept: The backend does not remove them when a
 record is deleted, and they are needed when a record is restored using the
 recycler. The number of relations in the field of the local record is not
 updated: dbdoctor ignores these count fields, see README.md.

 [OK] No affected records found%w

Scan for duplicate record translations
--------------------------------------

 Class: TcaTablesTranslatedLanguageParentDuplicates
 Actions: soft-delete, remove
 There must be only one translated record (TCA ctrl "languageField" > 0) per
 default language record (TCA ctrl "transOrigPointerField") and language.
 This check finds duplicates in all tables except "pages" and "tt_content", keeps
 the one with the lowest uid and soft-deletes others, or removes them if the
 table is not soft-delete aware.

 [OK] No affected records found%w

Scan for translated records with values not in sync with default language
-------------------------------------------------------------------------

 Class: TcaTablesTranslatedWithAllowLanguageSynchronization
 Actions: update-fields
 Fields with TCA "allowLanguageSynchronization" can use the value of the default language
 record in translations, the database field "l10n_state" stores this per field. This check
 finds live translations with l10n_state "parent" for a field, but a value different from
 the default language record. The frontend renders the value of the translation, so the
 l10n_state of such fields is set to "custom": The backend then shows the value as well, and
 it is not overwritten when the default language record is changed.

 [OK] No affected records found%w
