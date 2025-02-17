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
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Records in tt_content that are not soft-deleted must live on a pid that is not soft-deleted.
 * This check is relatively early - tt_content is most likely a top-down page->content
 * thing: If the page does not exist, that content will not be editable and most likely not shown.
 * There *may* be edge cases for tt_content records that are inline children, for example with
 * ext:news, this is ignored here.
 *
 * This one is an "early" version of TcaTablesPidDeleted, since tt_content plays
 * a more important role and needs earlier streamlining.
 */
final class TtContentPidDeleted extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for tt_content on soft-deleted pages');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_REMOVE);
        $io->text([
            'tt_content not soft-delete must have a "pid" page record that is not soft-deleted. Otherwise, they are',
            'most likely not editable. This is similar to the previous check, affected records will be soft-deleted',
            'if in live, and removed if in workspaces.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);

        // Do not consider deleted records, soft-deleted records on soft-deleted page are ok.
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder->select('uid', 'pid', 'deleted', 't3ver_wsid')->from('tt_content')->orderBy('uid');
        $queryBuilder->where($queryBuilder->expr()->gt('pid', 0));
        $result = $queryBuilder->executeQuery();

        $affectedRows = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                $pageRow = $recordsHelper->getRecord('pages', ['uid', 'deleted'], (int)$row['pid']);
                if ((int)$pageRow['deleted'] === 1) {
                    $affectedRows['tt_content'][] = $row;
                }
            } catch (NoSuchRecordException $e) {
                // Earlier test should have fixed this.
                throw new \RuntimeException(
                    'Record with uid="' . $row['uid'] . '" on table "tt_content"'
                    . ' has pid="' . $row['pid'] . '", but that page does not exist. A previous check'
                    . ' should have found and fixed this. Please repeat.',
                    1688979478
                );
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->softOrHardDeleteRecords($io, $simulate, $affectedRecords);
    }
}
