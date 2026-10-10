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
 * There must be only one live page translation per default language page and language.
 * DataHandler creates a second one for instance when a page has been localized in a
 * workspace first, then in live, and the workspace is published afterwards.
 *
 * @todo: This ignores workspace records for now, similar to TtContentLocalizedDuplicates.
 */
final class PagesTranslatedLanguageParentDuplicates extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for duplicate page translations');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_SOFT_DELETE);
        $io->text([
            'There must be only one translated "pages" record (sys_language_uid > 0) per',
            'default language page (l10n_parent) and language. This check finds duplicates,',
            'keeps the one the frontend shows and soft-deletes others: The visible one (not',
            'hidden, start and end time not excluding it) with the highest uid, or the one with',
            'the highest uid if none is visible.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        // Find combinations of l10n_parent and sys_language_uid having more than one live translation
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('l10n_parent', 'sys_language_uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('l10n_parent', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->groupBy('l10n_parent', 'sys_language_uid')
            ->having('COUNT(*) > 1')
            ->orderBy('l10n_parent')
            ->addOrderBy('sys_language_uid')
            ->executeQuery();
        $affectedRecords = [];
        while ($duplicate = $result->fetchAssociative()) {
            /** @var array<string, int|string> $duplicate */
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $translations = $queryBuilder->select('uid', 'pid', 'sys_language_uid', 'l10n_parent', 'hidden', 'starttime', 'endtime')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter((int)$duplicate['sys_language_uid'], Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter((int)$duplicate['l10n_parent'], Connection::PARAM_INT))
                )
                ->orderBy('uid')
                ->executeQuery()
                ->fetchAllAssociative();
            // Keep the translation the frontend shows: PageRepository->getPageOverlaysForLanguage() only
            // selects visible translations, and with multiple ones, the last row wins. There is no ORDER BY,
            // the highest uid is the typical last row. If none is visible, the highest uid is kept as well.
            $keepIndex = count($translations) - 1;
            foreach (array_reverse($translations, true) as $index => $translation) {
                if ($this->tcaHelper->isVisibleInFrontend('pages', $translation, (int)$GLOBALS['EXEC_TIME'])) {
                    $keepIndex = $index;
                    break;
                }
            }
            unset($translations[$keepIndex]);
            foreach ($translations as $translation) {
                /** @var array<string, int|string> $translation */
                $affectedRecords['pages'][] = $translation;
            }
        }
        return $affectedRecords;
    }

    protected function processRecords(HealthCheckRun $run, bool $simulate, array $affectedRecords): void
    {
        $updateFields = [
            'deleted' => [
                'value' => 1,
                'type' => Connection::PARAM_INT,
            ],
        ];
        $this->updateTcaRecordsOfTable($run, $simulate, 'pages', $affectedRecords['pages'] ?? [], $updateFields);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', ['transOrigPointerField']);
    }
}
