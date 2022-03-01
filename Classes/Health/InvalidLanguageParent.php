<?php

declare(strict_types=1);

namespace Lolli\Dbhealth\Health;

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

use Lolli\Dbhealth\Commands\HealthCommand;
use Lolli\Dbhealth\Exception\NoSuchRecordException;
use Lolli\Dbhealth\Helper\RecordsHelper;
use Lolli\Dbhealth\Helper\TcaHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Check if translated records point to existing records.
 */
class InvalidLanguageParent extends AbstractHealth implements HealthInterface
{
    private ConnectionPool $connectionPool;

    public function __construct(
        ConnectionPool $connectionPool
    ) {
        $this->connectionPool = $connectionPool;
    }

    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for record translations with invalid parent');
        $io->text([
            'Record translations ("translate" / "connected" mode, as opposed to "free" mode) use the',
            'database field "transOrigPointerField" (DB field name usually "l10n_parent" or "l18n_parent").',
            'This field points to a default language record. This health check verifies if that target',
            'exists in the database, is on the same page, and the deleted flag is in sync. Having "dangling"',
            'localized records on a page can otherwise trigger various issue when the page is copied or similar.',
        ]);
    }

    public function process(SymfonyStyle $io, HealthCommand $command): int
    {
        $danglingRows = $this->getDanglingRows();
        $this->outputMainSummary($io, $danglingRows);
        if (empty($danglingRows)) {
            return self::RESULT_OK;
        }

        while (true) {
            switch ($io->ask('<info>Remove records [y,a,r,p,d,?]?</info> ', '?')) {
                case 'y':
                    $this->deleteRecords($io, $danglingRows);
                    $danglingRows = $this->getDanglingRows();
                    $this->outputMainSummary($io, $danglingRows);
                    if (empty($danglingRows['pages'])) {
                        return self::RESULT_OK;
                    }
                    break;
                case 'a':
                    return self::RESULT_ABORT;
                case 'r':
                    $danglingRows = $this->getDanglingRows();
                    $this->outputMainSummary($io, $danglingRows);
                    if (empty($danglingRows)) {
                        return self::RESULT_OK;
                    }
                    break;
                case 'p':
                    $this->outputAffectedPages($io, $danglingRows);
                    break;
                case 'd':
                    $this->outputRecordDetails($io, $danglingRows, '_reasonBroken', ['transOrigPointerField']);
                    break;
                case 'h':
                default:
                    $io->text([
                        '    y - remove (DELETE, no soft-delete!) records',
                        '    a - abort now',
                        '    r - reload possibly changed data',
                        '    p - show record per page',
                        '    d - show record details',
                        '    ? - print help',
                    ]);
                    break;
            }
        }
    }

    /**
     * @return array<string, array<int, array<string, int|string>>>
     */
    private function getDanglingRows(): array
    {
        /** @var TcaHelper $tcaHelper */
        $tcaHelper = $this->container->get(TcaHelper::class);
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);

        $danglingRows = [];
        foreach ($tcaHelper->getNextLanguageAwareTcaTable() as $tableName) {
            /** @var string $languageField */
            $languageField = $tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $tcaHelper->getTranslationParentField($tableName);
            $deletedField = $tcaHelper->getDeletedField($tableName);

            $parentRowFields = [
                'uid',
                'pid',
                $languageField,
            ];
            if ($deletedField) {
                $parentRowFields[] = $deletedField;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            // Query could be potentially optimized with a self-join, but well ...
            $result = $queryBuilder->select('uid', 'pid', $translationParentField)->from($tableName)
                ->where(
                    // localized records
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    // in 'connected' mode
                    $queryBuilder->expr()->gt($translationParentField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
                )
                ->orderBy('uid')
                ->executeQuery();

            while ($localizedRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $localizedRow */
                $broken = false;
                try {
                    $parentRow = $recordsHelper->getRecord($tableName, $parentRowFields, (int)$localizedRow[$translationParentField]);
                    if ($deletedField && (int)$parentRow[$deletedField] === 1) {
                        $broken = true;
                        $localizedRow['_reasonBroken'] = 'Parent set to deleted';
                    } elseif ((int)$localizedRow['pid'] !== (int)$parentRow['pid']) {
                        $broken = true;
                        $localizedRow['_reasonBroken'] = 'Parent is on different page';
                    }
                } catch (NoSuchRecordException $e) {
                    $broken = true;
                    $localizedRow['_reasonBroken'] = 'Missing parent';
                }
                if ($broken) {
                    $danglingRows[$tableName][(int)$localizedRow['uid']] = $localizedRow;
                }
            }
        }
        return $danglingRows;
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $danglingRows
     */
    private function outputMainSummary(SymfonyStyle $io, array $danglingRows): void
    {
        if (!count($danglingRows)) {
            $io->success('No localized records with invalid parent');
        } else {
            $ioText = [
                'Found localized records with invalid parent in ' . count($danglingRows) . ' tables:',
            ];
            $tablesString = '';
            foreach ($danglingRows as $tableName => $rows) {
                if (!empty($tablesString)) {
                    $tablesString .= "\n";
                }
                $tablesString .= '"' . $tableName . '": ' . count($rows) . ' records';
            }
            $ioText[] = $tablesString;
            $io->warning($ioText);
        }
    }
}
