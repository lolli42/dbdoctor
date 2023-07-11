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
 * There must be no not soft-deleted localized tt_content records that have a
 * soft-deleted l18n_parent.
 */
final class TtContentLocalizedParentSoftDeleted extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Localized tt_content records with soft-deleted parent');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_SOFT_DELETE, self::TAG_WORKSPACE_REMOVE);
        $io->text([
            'Not soft-deleted localized records in "tt_content" (sys_language_uid > 0) having',
            'l18n_parent > 0 must point to a sys_language_uid = 0 language parent record that',
            'is not soft-deleted as well. Violating records are set to soft-deleted as well (or',
            'removed if in workspaces), since they are typically never rendered in FE, even',
            'though the BE renders them in page module.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $affectedRecords = [];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('uid', 'pid', 'sys_language_uid', 'l18n_parent', 't3ver_wsid')->from('tt_content')
            ->where(
                $queryBuilder->expr()->gt('sys_language_uid', 0),
                $queryBuilder->expr()->gt('l18n_parent', 0)
            )
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                $languageParent = $recordsHelper->getRecord('tt_content', ['uid', 'deleted'], (int)$row['l18n_parent']);
                if ((int)$languageParent['deleted'] === 1) {
                    $affectedRecords['tt_content'][] = $row;
                }
            } catch (NoSuchRecordException|NoSuchTableException $e) {
                throw new \RuntimeException(
                    'Should have been caught by previous TtContentLocalizedParentExists already',
                    1689065382
                );
            }
        }
        return $affectedRecords;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->softOrHardDeleteRecords($io, $simulate, $affectedRecords);
    }
}
