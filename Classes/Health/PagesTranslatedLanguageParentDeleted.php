<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Health;

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
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Find not-deleted translated pages that have a sys_language_uid=0 parent set to deleted.
 */
class PagesTranslatedLanguageParentDeleted extends AbstractHealth implements HealthInterface, HealthUpdateInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Check pages with deleted language parent');
        $io->text([
            '[DELETE] This health check finds translated and not deleted "pages" records (sys_language_uid > 0)',
            'with their default language record (l10n_parent field) set to deleted.',
            'Those translated pages are never shown in backend and frontend and should be set to deleted, too.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // Do not consider page translation records that have been set to deleted already.
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('uid', 'pid', 'deleted', 'sys_language_uid', 'l10n_parent')
            ->from('pages')
            ->where($queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)))
            ->orderBy('uid')
            ->executeQuery();
        $affectedRecords = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                $parentRecord = $recordsHelper->getRecord('pages', ['uid', 'deleted'], (int)$row['l10n_parent']);
                if ((bool)$parentRecord['deleted']) {
                    $affectedRecords['pages'][] = $row;
                }
            } catch (NoSuchRecordException $e) {
                // Earlier test should have fixed this.
                throw new \RuntimeException(
                    'Pages record with uid="' . $row['uid'] . '" and sys_language_uid="' . $row['sys_language_uid'] . '"'
                    . ' has l10n_parent="' . $row['l10n_parent'] . '", but that record does not exist. A previous check'
                    . ' should have found and fixed this. Please repeat.',
                    1647793648
                );
            }
        }
        return $affectedRecords;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $updateFields = [
            'deleted' => [
                'value' => 1,
                'type' => \PDO::PARAM_INT,
            ],
        ];
        $this->updateAllRecords($io, $simulate, 'pages', $affectedRecords['pages'], $updateFields);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', ['transOrigPointerField']);
    }
}
