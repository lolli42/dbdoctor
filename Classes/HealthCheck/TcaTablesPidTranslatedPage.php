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

/**
 * TCA records must not point to translated pages, the default language page is the
 * pid of all records, including those attached to a translated page.
 * DataHandler set the translated page as pid of relations like page media and inline
 * children when copying a translated page in workspaces, see core issue #110892.
 */
final readonly class TcaTablesPidTranslatedPage extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for records on translated pages');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE);
        $io->text([
            'TCA records have a pid field set to a single page. This must be a default language',
            'page: Records attached to a translated page, for instance "sys_file_reference" records',
            'of the translated page "media" field, are located on the default language page, too.',
            'Records pointing to a translated page are moved to its default language page.',
        ]);
    }

    protected function getAffectedRecords(HealthCheckRun $run): array
    {
        $translatedPages = $this->getTranslatedPages();
        if ($translatedPages === []) {
            return [];
        }
        $affectedRows = [];
        foreach ($this->tcaHelper->getNextTcaTable(['pages']) as $tableName) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid')->from($tableName)
                ->where($queryBuilder->expr()->gt('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
                ->orderBy('uid')
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                if (isset($translatedPages[(int)$row['pid']])) {
                    $row['_reasonBroken'] = 'Translated page, default language page: ' . $translatedPages[(int)$row['pid']];
                    $affectedRows[$tableName][] = $row;
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(HealthCheckRun $run, bool $simulate, array $affectedRecords): void
    {
        $translatedPages = $this->getTranslatedPages();
        foreach ($affectedRecords as $tableName => $rows) {
            $this->outputTableUpdateBefore($run, $simulate, $tableName);
            $count = 0;
            foreach ($rows as $row) {
                $fields = [
                    'pid' => [
                        'value' => $translatedPages[(int)$row['pid']],
                        'type' => Connection::PARAM_INT,
                    ],
                ];
                $this->updateSingleTcaRecord($run, $simulate, $tableName, (int)$row['uid'], $fields);
                $count++;
            }
            $this->outputTableUpdateAfter($run, $simulate, $tableName, $count);
        }
    }

    protected function recordDetails(HealthCheckRun $run, array $affectedRecords): void
    {
        $this->outputRecordDetails($run, $affectedRecords, '_reasonBroken');
    }

    /**
     * Map of translated page uids to the uid of their default language page.
     *
     * @return array<int, int>
     */
    private function getTranslatedPages(): array
    {
        $languageField = $this->tcaHelper->getLanguageField('pages');
        $translationParentField = $this->tcaHelper->getTranslationParentField('pages');
        if ($languageField === null || $translationParentField === null) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('uid', $translationParentField)->from('pages')
            ->where(
                $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt($translationParentField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            );
        $workspaceIdField = $this->tcaHelper->getWorkspaceIdField('pages');
        if ($workspaceIdField !== null) {
            // Workspace overlays are no valid pid targets at all, only consider live and new-placeholder pages.
            $queryBuilder->andWhere($queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
        }
        $result = $queryBuilder->executeQuery();
        $translatedPages = [];
        while ($row = $result->fetchAssociative()) {
            $translatedPages[(int)$row['uid']] = (int)$row[$translationParentField];
        }
        return $translatedPages;
    }
}
