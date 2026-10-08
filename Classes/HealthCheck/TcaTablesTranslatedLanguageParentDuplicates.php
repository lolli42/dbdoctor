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
 * This check runs late in the chain: Inline children of duplicate parent translations,
 * for instance sys_file_reference rows of a duplicate tt_content translation, must have
 * been handled by inline parent checks before. Otherwise, a child of the kept parent
 * may be deleted instead of a child of the deleted parent.
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
        $affectedRecords = [];
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable(['pages', 'tt_content']) as $tableName) {
            /** @var string $languageField */
            $languageField = $this->tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);

            // Find combinations of translation parent and language having more than one live translation
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder->select($translationParentField, $languageField)
                ->from($tableName)
                ->where(
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->gt($translationParentField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                )
                ->groupBy($translationParentField, $languageField)
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
