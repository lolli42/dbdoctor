<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\HealthCheck;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * There must be only one live translation per default language record and language.
 * Similar to PagesTranslatedLanguageParentDuplicates and TtContentLocalizedDuplicates
 * for all other language aware tables.
 *
 * Translations of inline children are only duplicates if they have the same inline parent:
 * Each translation of a parent has its own translated children, for instance sys_file_reference
 * rows of two duplicate translations of a news record. Children of a parent translation deleted
 * by this check are handled by the inline parent checks in the next run.
 *
 * @todo: This ignores workspace records for now, similar to TtContentLocalizedDuplicates.
 */
final class TcaTablesTranslatedLanguageParentDuplicates extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for duplicate record translations');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_SOFT_DELETE, self::TAG_REMOVE);
        $io->text([
            'There must be only one translated record (TCA ctrl "languageField" > 0) per',
            'default language record (TCA ctrl "transOrigPointerField") and language.',
            'This check finds duplicates in all tables except "pages" and "tt_content", keeps',
            'the one with the lowest uid and soft-deletes others, or removes them if the',
            'table is not soft-delete aware.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        // Fields pointing to the inline parent of inline child tables
        $inlineParentFields = [];
        foreach ($this->tcaHelper->getNextInlineForeignFieldChildTcaTable() as $inlineChildTable) {
            $inlineParentFields[$inlineChildTable['tableName']] = [$inlineChildTable['fieldNameOfParentTableUid'], $inlineChildTable['fieldNameOfParentTableName']];
        }
        foreach ($this->tcaHelper->getNextInlineForeignFieldNoForeignTableFieldChildTcaTable() as $inlineChildTable) {
            $inlineParentFields[$inlineChildTable['tableName']] ??= [$inlineChildTable['fieldNameOfParentTableUid']];
        }

        $affectedRecords = [];
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable(['pages', 'tt_content']) as $tableName) {
            /** @var string $languageField */
            $languageField = $this->tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
            $parentFields = $inlineParentFields[$tableName] ?? [];

            // Find combinations of translation parent, language and inline parent having more than one live translation
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder->select($translationParentField, $languageField, ...$parentFields)
                ->from($tableName)
                ->where(
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->gt($translationParentField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                )
                ->groupBy($translationParentField, $languageField, ...$parentFields)
                ->having('COUNT(*) > 1')
                ->orderBy($translationParentField)
                ->addOrderBy($languageField);
            if ($workspaceIdField) {
                $queryBuilder->andWhere($queryBuilder->expr()->eq($workspaceIdField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
            }
            $result = $queryBuilder->executeQuery();

            $selectFields = ['uid', 'pid', $languageField, $translationParentField];
            if ($workspaceIdField) {
                $selectFields[] = $workspaceIdField;
            }
            while ($duplicate = $result->fetchAssociative()) {
                /** @var array<string, int|string> $duplicate */
                $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
                $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                $queryBuilder->select(...$selectFields)
                    ->from($tableName)
                    ->where(
                        $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter((int)$duplicate[$languageField], Connection::PARAM_INT)),
                        $queryBuilder->expr()->eq($translationParentField, $queryBuilder->createNamedParameter((int)$duplicate[$translationParentField], Connection::PARAM_INT))
                    )
                    ->orderBy('uid');
                if ($workspaceIdField) {
                    $queryBuilder->andWhere($queryBuilder->expr()->eq($workspaceIdField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
                }
                foreach ($parentFields as $index => $parentField) {
                    // First field is the uid of the inline parent, second one its table name
                    $parameter = $index === 0
                        ? $queryBuilder->createNamedParameter((int)$duplicate[$parentField], Connection::PARAM_INT)
                        : $queryBuilder->createNamedParameter((string)$duplicate[$parentField]);
                    $queryBuilder->andWhere($queryBuilder->expr()->eq($parentField, $parameter));
                }
                $translations = $queryBuilder->executeQuery()->fetchAllAssociative();
                // The translation with the lowest uid is kept, others are soft-deleted or removed.
                array_shift($translations);
                foreach ($translations as $translation) {
                    /** @var array<string, int|string> $translation */
                    $affectedRecords[$tableName][] = $translation;
                }
            }
        }
        return $affectedRecords;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->softOrHardDeleteRecords($io, $simulate, $affectedRecords);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', ['transOrigPointerField']);
    }
}
