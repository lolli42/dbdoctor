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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Localized tt_content records need a page translation in their language on their page.
 * Without it, they are not rendered on their page. They may still be rendered on other pages, for
 * instance with TypoScript CONTENT or RECORDS, which is why the check is marked risky.
 *
 * This is restricted to tt_content: Other tables, for instance news records in a storage
 * folder, are usually rendered without a page translation of their page, and the backend
 * allows translating records on pages without any page translation.
 */
final class TtContentLocalizedPageTranslationMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for localized tt_content records without page translation');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_SOFT_DELETE, self::TAG_WORKSPACE_REMOVE, self::TAG_RISKY);
        $io->text([
            'Localized tt_content records (sys_language_uid > 0) need a not deleted page translation',
            'in their language on their page, otherwise they are not rendered on their page. This check',
            'finds such records and soft-deletes them, workspace records are removed. tt_content records',
            'in sys folders are not checked: They are typically rendered by "Insert records" elements on',
            'other pages, which works without a page translation of the sys folder. Records on translated',
            'pages are not checked either: TcaTablesPidTranslatedPage moves them to the default page.',
            'Content rendered on other pages, for instance with TypoScript CONTENT or RECORDS, may be',
            'shown anyway: Check affected records carefully.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);

        /** @var array<int, array{doktype: int, language: int, translations: array<int, array<int, true>>}> $pageCache */
        $pageCache = [];

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        // Do not consider tt_content records that have been set to deleted already.
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('uid', 'pid', 'sys_language_uid', 'l18n_parent', 't3ver_wsid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->gt('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('uid')
            ->executeQuery();
        $affectedRecords = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            $pid = (int)$row['pid'];
            if (!isset($pageCache[$pid])) {
                try {
                    $pageRow = $recordsHelper->getRecord('pages', ['uid', 'doktype', 'sys_language_uid'], $pid);
                } catch (NoSuchRecordException $e) {
                    // Earlier test should have fixed this.
                    throw new \RuntimeException(
                        'tt_content record with uid="' . $row['uid'] . '" has pid="' . $pid . '", but that page'
                        . ' does not exist. A previous check should have found and fixed this. Please repeat.',
                        1791475200
                    );
                }
                $pageCache[$pid] = [
                    'doktype' => (int)$pageRow['doktype'],
                    'language' => (int)$pageRow['sys_language_uid'],
                    'translations' => $this->getPageTranslationWorkspaces($pid),
                ];
            }
            if ($pageCache[$pid]['doktype'] === PageRepository::DOKTYPE_SYSFOLDER) {
                continue;
            }
            if ($pageCache[$pid]['language'] > 0) {
                // A translated page as pid, for instance by core bug #110892: TcaTablesPidTranslatedPage
                // moves the record to the default language page, it is checked there in the next run.
                continue;
            }
            $languageId = (int)$row['sys_language_uid'];
            $workspaceId = (int)$row['t3ver_wsid'];
            $pageTranslationWorkspaces = $pageCache[$pid]['translations'][$languageId] ?? [];
            // A live record needs a live page translation, a workspace record needs a
            // page translation in live or in its own workspace.
            if (!isset($pageTranslationWorkspaces[0]) && !isset($pageTranslationWorkspaces[$workspaceId])) {
                $affectedRecords['tt_content'][] = $row;
            }
        }
        return $affectedRecords;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->softOrHardDeleteRecordsOfTable($io, $simulate, 'tt_content', $affectedRecords['tt_content'] ?? []);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', ['languageField', 'transOrigPointerField']);
    }

    /**
     * Languages of not deleted page translations of a page, with the workspaces they exist in.
     *
     * @return array<int, array<int, true>>
     */
    private function getPageTranslationWorkspaces(int $pageId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('sys_language_uid', 't3ver_wsid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery();
        $pageTranslationWorkspaces = [];
        while ($pageRow = $result->fetchAssociative()) {
            $pageTranslationWorkspaces[(int)$pageRow['sys_language_uid']][(int)$pageRow['t3ver_wsid']] = true;
        }
        return $pageTranslationWorkspaces;
    }
}
