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

use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Exception\NoSuchTableException;
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Not deleted localized sys_file_reference records must point to a sys_language_uid=0 parent that is not deleted.
 */
class SysFileReferenceLocalizedParentDeleted extends AbstractHealthCheck implements HealthCheckInterface, HealthUpdateInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for localized sys_file_reference records with deleted parent');
        $io->text([
            '[UPDATE] Localized, not deleted records in "sys_file_reference" (sys_language_uid > 0) having',
            '         l10n_parent > 0 must point to a sys_language_uid = 0, not deleted, language parent record.',
            '         Records violating this are set to deleted.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $tableRows = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('uid', 'pid', 'sys_language_uid', 'l10n_parent')->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->gt('l10n_parent', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
            )
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                $languageParentRecord = $recordsHelper->getRecord('sys_file_reference', ['uid', 'deleted'], (int)$row['l10n_parent']);
            } catch (NoSuchRecordException|NoSuchTableException $e) {
                // Record existence has been checked by SysFileReferenceLocalizedParentExists already.
                // This can only happen if such a broken record has been added meanwhile, ignore it now.
                continue;
            }
            if ((int)$languageParentRecord['deleted'] === 1) {
                $tableRows['sys_file_reference'][] = $row;
            }
        }
        return $tableRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->updateAllRecords(
            $io,
            $simulate,
            'sys_file_reference',
            $affectedRecords['sys_file_reference'] ?? [],
            [
                'deleted' => [
                    'value' => 1,
                    'type' => \PDO::PARAM_INT,
                ],
            ]
        );
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', [], ['sys_language_uid', 'l10n_parent', 'deleted', 'tablenames', 'uid_foreign', 'fieldname', 'uid_local']);
    }
}
