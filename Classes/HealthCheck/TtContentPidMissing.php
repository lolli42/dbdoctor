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

/**
 * Records in tt_content must live on a pid that exists.
 * This check is relatively early - tt_content is most likely a top-down page->content
 * thing: If the page does not exist, that content will not be editable and most likely not shown.
 * There *may* be edge cases for tt_content records that are inline children, for example with
 * ext:news, this is ignored here.
 *
 * This one is an "early" version of TcaTablesPidMissing, since tt_content plays
 * a more important role and needs earlier streamlining.
 */
final class TtContentPidMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for tt_content on not existing pages');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_REMOVE);
        $io->text([
            'tt_content must have a "pid" page record that exists. Otherwise, they are most likely not editable',
            'and can be removed. There are potential exceptions for tt_content records that are inline children',
            'for example using "news" extension that may create such scenarios, but even then, those records',
            'are most likely not shown in FE. You may want to look at some cases manually if this instance',
            'has some weird scenarios where tt_content is used as inline child. Otherwise, it is usually ok',
            'to let dbdoctor just REMOVE tt_content records that are located at a page that does not exist.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);

        $affectedRows = [];
        // Iterate all TCA tables, but ignore pages table
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        // Consider deleted records: If the pid does not exist, they should be deleted, too.
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid')->from('tt_content')->orderBy('uid')->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            if ((int)$row['pid'] === 0) {
                // Records pointing to pid 0 are ok.
                continue;
            }
            try {
                $recordsHelper->getRecord('pages', ['uid'], (int)$row['pid']);
            } catch (NoSuchRecordException) {
                $affectedRows['tt_content'][] = $row;
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->deleteTcaRecords($io, $simulate, $affectedRecords);
    }
}
