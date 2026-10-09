--TEST--
dbdoctor:health, all health checks of the chain in their order
--ARGS--
dbdoctor:health
--FILE--
<?php declare(strict_types=1);
require_once __DIR__ . '/../../.Build/bin/typo3';
--EXPECT--
Find and fix database inconsistencies
=====================================

OK  WorkspacesNotLoadedRecordsDangling
OK  WorkspacesRecordsOfDeletedWorkspaces
OK  TcaTablesDeleteFlagZeroOrOne
OK  WorkspacesSoftDeletedRecords
OK  WorkspacesPidNegative
OK  WorkspacesT3verStateNotZeroInLive
OK  WorkspacesT3verStateMinusOne
OK  WorkspacesT3verStateThree
OK  SysRedirectInvalidPid
OK  TcaTablesLanguageLessThanOneHasZeroLanguageParent
OK  TcaTablesLanguageLessThanOneHasZeroLanguageSource
OK  PagesLanguageNegative
OK  PagesBrokenTree
OK  PagesPidDeleted
OK  PagesTranslatedLanguageParentSelf
OK  PagesTranslatedLanguageParentMissing
OK  PagesTranslatedLanguageParentDeleted
OK  PagesTranslatedLanguageParentDifferentPid
OK  PagesTranslatedLanguageParentDuplicates
OK  SysFileMetadataMissing
OK  SysFileMetadataTranslatedParentInvalid
OK  TcaTablesTranslatedParentSelf
OK  TcaTablesTranslatedParentInvalidPointer
OK  TtContentPidMissing
OK  TtContentPidDeleted
OK  TtContentDeletedLocalizedParentExists
OK  TtContentLocalizedParentExists
OK  TtContentLocalizedParentSoftDeleted
OK  TtContentDeletedLocalizedParentDifferentPid
OK  TtContentLocalizedParentDifferentPid
OK  TtContentLocalizedDuplicates
OK  TtContentLocalizedPageTranslationMissing
OK  TcaTablesTranslationSourceExists
OK  TcaTablesTranslationSourceSetWithParent
OK  TcaTablesTranslationSourceLogicWithParent
OK  SysFileReferenceDangling
OK  SysFileReferenceDeletedLocalizedParentExists
OK  SysFileReferenceLocalizedParentExists
OK  SysFileReferenceLocalizedParentDeleted
OK  SysFileReferenceLocalizedFieldSync
OK  SysFileReferenceInvalidPid
OK  TcaTablesPidMissing
OK  TcaTablesPidDeleted
OK  TcaTablesPidTranslatedPage
OK  TcaTablesTranslatedLanguageParentMissing
OK  TcaTablesTranslatedLanguageParentDeleted
OK  TcaTablesTranslatedLanguageParentDifferentPid
OK  TcaTablesTranslatedLanguageNotInSiteConfiguration
OK  InlineForeignFieldChildrenParentMissing
OK  InlineForeignFieldNoForeignTableFieldChildrenParentMissing
OK  InlineForeignFieldChildrenParentDeleted
OK  InlineForeignFieldNoForeignTableFieldChildrenParentDeleted
OK  InlineForeignFieldChildrenParentLanguageDifferent
OK  InlineForeignFieldNoForeignTableFieldChildrenParentLanguageDifferent
OK  GroupFieldRelationMissing
OK  GroupFieldMmRelationMissing
OK  TcaTablesTranslatedLanguageParentDuplicates
OK  TcaTablesTranslatedWithAllowLanguageSynchronization
