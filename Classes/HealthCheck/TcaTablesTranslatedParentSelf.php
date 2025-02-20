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
 * Localized records must not point to their own uid in their transOrigPointerField.
 */
final class TcaTablesTranslatedParentSelf extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for record translations pointing to self');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE, self::TAG_WORKSPACE_REMOVE, self::TAG_RISKY);
        $io->text([
            'Record translations ("translate" / "connected" mode, as opposed to "free" mode) use the',
            'database field "transOrigPointerField" (field name usually "l10n_parent" or "l18n_parent").',
            'This field should point to the default language record. This health check scans for not',
            'soft-deleted and localized records that point to their own uid in "transOrigPointerField".',
            'They are soft-deleted in live and removed if they are workspace overlay records.',
            'This change is considered risky since depending on configuration, such records may still be',
            'shown in the Frontend and will disappear when deleted.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        $affectedRows = [];
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable(['pages']) as $tableName) {
            /** @var string $languageField */
            $languageField = $this->tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
            $isTableWorkspaceAware = !empty($workspaceIdField);
            $selectFields = ['uid', 'pid', $languageField, $translationParentField];
            if ($isTableWorkspaceAware) {
                $selectFields[] = $workspaceIdField;
            }
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $result = $queryBuilder->select(...$selectFields)->from($tableName)
                ->where(
                    // localized records
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    // AND uid = l10n_parent
                    $queryBuilder->expr()->eq($tableName . '.uid', $tableName . '.' . $translationParentField)
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($localizedRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $localizedRow */
                $affectedRows[$tableName][] = $localizedRow;
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->softOrHardDeleteRecords($io, $simulate, $affectedRecords);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', ['languageField', 'transOrigPointerField']);
    }
}
