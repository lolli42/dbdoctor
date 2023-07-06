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

/**
 * Localized sys_file_reference records must point to a sys_language_uid=0 parent that exists.
 * This check is risky since it may remove images from FE. See comments.
 */
final class SysFileReferenceLocalizedParentExists extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for localized sys_file_reference records without parent');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_RISKY, self::TAG_REMOVE);
        $io->text([
            'Localized records in "sys_file_reference" (sys_language_uid > 0) having',
            'l10n_parent > 0 must point to a sys_language_uid = 0 existing language parent record.',
            'Records violating this are REMOVED.',
            'This change is <error>risky</error>. Records with an invalid l10n_parent pointer typically throw',
            'an exception in the BE when edited. However, the FE often still shows such an image.',
            'As such, when this check REMOVES records, you may want to check them manually by looking',
            'at the referencing inline parent record indicated by fields "tablenames" and "uid_foreign"',
            'to eventually find a better solution manually.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $tableRows = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
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
                $recordsHelper->getRecord('sys_file_reference', ['uid'], (int)$row['l10n_parent']);
            } catch (NoSuchRecordException|NoSuchTableException $e) {
                // Match if parent does not exist at all
                $tableRows['sys_file_reference'][] = $row;
            }
        }
        return $tableRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        // @todo: Possible improvement. We could try to look at the uid_foreign/tablesnames inline parent record,
        //        see if is "connected mode", has a sys_language_uid=0 l10n_parent on the same pid, look up the
        //        sys_file_reference children attached to the same fieldname (which must not be a flexform field),
        //        then see if one of them has the same uid_local "image" attached, and see if there is no other
        //        existing localized record of this one. Then set l10n_parent to the uid of that sys_file_reference record.
        //        This would allow us to "reconnect" at least some affected records to the "most likely" correct l10n_parent
        //        instead of removing the relation. Also, workspaces has to be considered for all this as well.
        //        A second strategy to mitigate this is to set l10n_parent=0, which will make that relation
        //        "free mode". This however may lead to funny behavior in BE, when the inline parent is
        //        "connected mode". Needs further investigation if considered.
        $this->deleteTcaRecordsOfTable($io, $simulate, 'sys_file_reference', $affectedRecords['sys_file_reference'] ?? []);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', [], ['sys_language_uid', 'l10n_parent', 'deleted', 'tablenames', 'uid_foreign', 'fieldname', 'uid_local']);
    }
}
