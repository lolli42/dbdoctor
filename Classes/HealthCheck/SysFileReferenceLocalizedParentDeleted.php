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
 * This is related to SysFileReferenceLocalizedParentExists, and risky as well. See SysFileReferenceLocalizedParentExists
 * for more comments on this.
 */
final class SysFileReferenceLocalizedParentDeleted extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for localized sys_file_reference records with deleted parent');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_RISKY, self::TAG_SOFT_DELETE, self::TAG_WORKSPACE_REMOVE);
        $io->text([
            'Localized, not deleted records in "sys_file_reference" (sys_language_uid > 0) having',
            'l10n_parent > 0 must point to a sys_language_uid = 0, not soft-deleted, language parent record.',
            'Records violating this are soft-deleted in live and removed if in workspaces.',
            'This change is <error>risky</error>. Records with a deleted=1 l10n_parent typically throw',
            'an exception in the BE when edited. However, the FE often still shows such an image.',
            'As such, when this check soft-deletes or removes records, you may want to check them manually by',
            'looking at the referencing inline parent record indicated by fields "tablenames" and "uid_foreign"',
            'to eventually find a better solution manually, for instance by setting l10n_parent=0 or',
            'connecting it to the correct l10n_parent if in "connected mode", or by creating a new',
            'image relation and then letting dbdoctor remove this one after reloading the check.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $tableRows = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('uid', 'pid', 'sys_language_uid', 'l10n_parent', 't3ver_wsid')->from('sys_file_reference')
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
        // @todo: Risky. Similar possible strategies to mitigate this as in SysFileReferenceLocalizedParentExists.
        $this->softOrHardDeleteRecordsOfTable($io, $simulate, 'sys_file_reference', $affectedRecords['sys_file_reference'] ?? []);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', [], ['sys_language_uid', 'l10n_parent', 'deleted', 'tablenames', 'uid_foreign', 'fieldname', 'uid_local']);
    }
}
