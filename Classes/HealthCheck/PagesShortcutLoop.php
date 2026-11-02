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

use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;
use Lolli\Dbdoctor\Helper\LoopHelper;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shortcut pages pointing to themselves, or shortcut chains looping back, can not be resolved
 * by the frontend: Core v13 PageRepository->getPageShortcut() throws a RuntimeException, a
 * request to such a page ends with HTTP 500. Core v14 throws CircularPageReferenceChainException,
 * the request ends with "page not found". Menus and links catch both and skip the page.
 * The backend does not prevent such loops, only the suggest wizard of "shortcut" excludes
 * the page itself.
 *
 * Only shortcuts with an explicit target are followed: "shortcut_mode" 0 with a "shortcut" set,
 * and in core v14 also the removed mode 2 "random subpage", which v14 resolves like mode 0 if
 * "shortcut" is set. Loops of these exist independent of time, frontend user groups and sorting.
 * Hidden pages are followed, they loop as soon as they are visible, and in preview.
 *
 * Translations have their own "shortcut" and "shortcut_mode", the target is the uid of a default
 * language page, which the frontend overlays with its translation again. Loops are searched per
 * language, with the translation of a page if it exists, and its default language record otherwise.
 * A translation pointing to its own default language page is the shortest loop of a language.
 *
 * The page with the lowest uid of each loop is changed to "shortcut_mode" 1 "first subpage" with
 * "shortcut" 0: Both core versions resolve this to the first visible subpage, or "page not found"
 * if there is none. In loops of a language, only translations are changed, the page with the lowest
 * default language uid having a translation in the loop. Pages leading into a loop without being part
 * of it are not changed, they resolve as soon as the loop is broken.
 *
 * Not handled, candidates for further checks:
 * - Loops including "first subpage" (mode 1) or "parent page" (mode 3) shortcuts, for instance a
 *   shortcut to the first subpage whose first subpage is a shortcut back. These depend on hidden
 *   pages, start and end time, frontend user groups and sorting at request time.
 * - Core v14 also follows pages of type "Link" (doktype 3) pointing to a page in the same chain.
 *   Their target is a typolink in field "link".
 * - Workspace versions of pages with their own "shortcut" values, only live pages are followed.
 * - Languages in "strict" mode do not fall back to the default language page if a translation is
 *   missing. A loop of a language including such pages ends with "page not found" instead of a loop.
 */
final readonly class PagesShortcutLoop extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private LoopHelper $loopHelper,
    ) {}

    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for shortcut pages pointing to themselves or looping',
            [ActionTag::UpdateFields],
            [
                'Pages of type "Shortcut" pointing to themselves, or chains of shortcuts looping back,',
                'can not be resolved by the frontend: Core v13 answers with an error, core v14 with',
                '"page not found". Only shortcuts with a selected target page are followed, per',
                'language. The page with the lowest uid of each loop is changed to "first subpage",',
                'which resolves to its first visible subpage. In loops of a language, only page',
                'translations are changed.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        // Default language pages of type shortcut: uid => target uid, null if the target is not explicit.
        $defaultTargets = [];
        // Default language pages of type shortcut: uid => pid
        $defaultPids = [];
        // Translations by language and default language uid: uid, pid, target uid or null.
        $translations = [];
        $queryBuilder = $this->getLivePagesQueryBuilder();
        $result = $queryBuilder
            ->andWhere($queryBuilder->expr()->eq('doktype', $queryBuilder->createNamedParameter(PageRepository::DOKTYPE_SHORTCUT, Connection::PARAM_INT)))
            ->orderBy('uid')
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string|null> $row */
            $target = $this->getExplicitTarget($row);
            if ((int)$row['sys_language_uid'] === 0) {
                $defaultTargets[(int)$row['uid']] = $target;
                $defaultPids[(int)$row['uid']] = (int)$row['pid'];
            } elseif ((int)$row['sys_language_uid'] > 0 && (int)$row['l10n_parent'] > 0) {
                $translations[(int)$row['sys_language_uid']][(int)$row['l10n_parent']] = [
                    'uid' => (int)$row['uid'],
                    'pid' => (int)$row['pid'],
                    'target' => $target,
                ];
            }
        }
        // Translations of default language shortcut pages that are no shortcut: They end a chain in their language.
        foreach (array_chunk(array_keys($defaultTargets), 100) as $defaultUids) {
            $queryBuilder = $this->getLivePagesQueryBuilder();
            $result = $queryBuilder
                ->andWhere(
                    $queryBuilder->expr()->neq('doktype', $queryBuilder->createNamedParameter(PageRepository::DOKTYPE_SHORTCUT, Connection::PARAM_INT)),
                    $queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->in('l10n_parent', $queryBuilder->createNamedParameter($defaultUids, Connection::PARAM_INT_ARRAY))
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string|null> $row */
                $translations[(int)$row['sys_language_uid']][(int)$row['l10n_parent']] = [
                    'uid' => (int)$row['uid'],
                    'pid' => (int)$row['pid'],
                    'target' => null,
                ];
            }
        }

        $findings = [];
        $defaultNodes = array_keys($defaultTargets);
        $defaultNext = static fn(int $uid): ?int => $defaultTargets[$uid] ?? null;
        foreach ($this->loopHelper->getLoops($defaultNodes, $defaultNext) as $loop) {
            $uid = min($loop);
            $findings[] = $this->getFinding($uid, $defaultPids[$uid], 0, $loop);
        }
        ksort($translations);
        foreach ($translations as $languageId => $languageTranslations) {
            $nodes = array_unique(array_merge($defaultNodes, array_keys($languageTranslations)));
            sort($nodes);
            $next = static function (int $uid) use ($languageTranslations, $defaultTargets): ?int {
                if (isset($languageTranslations[$uid])) {
                    return $languageTranslations[$uid]['target'];
                }
                return $defaultTargets[$uid] ?? null;
            };
            foreach ($this->loopHelper->getLoops($nodes, $next) as $loop) {
                $translatedUids = array_values(array_filter($loop, static fn(int $uid): bool => isset($languageTranslations[$uid])));
                if ($translatedUids === []) {
                    // A loop of default language pages, handled in the default language.
                    continue;
                }
                $defaultUid = min($translatedUids);
                $findings[] = $this->getFinding(
                    $languageTranslations[$defaultUid]['uid'],
                    $languageTranslations[$defaultUid]['pid'],
                    (int)$languageId,
                    $loop
                );
            }
        }
        return $findings;
    }

    public function getDetailDbFields(string $tableName): array
    {
        return ['doktype', 'shortcut', 'shortcut_mode'];
    }

    /**
     * Target page uid of a shortcut page if it is selected explicitly, as core PageRepository->getPageShortcut()
     * resolves it: Not for "first subpage" and "parent page", in core v13 also not for "random subpage".
     *
     * @param array<string, int|string|null> $row
     */
    private function getExplicitTarget(array $row): ?int
    {
        $shortcutMode = (int)$row['shortcut_mode'];
        // The core reads the first item, "shortcut" is a group field with maxitems 1.
        $target = GeneralUtility::intExplode(',', (string)$row['shortcut'])[0];
        if ($target <= 0
            || $shortcutMode === PageRepository::SHORTCUT_MODE_FIRST_SUBPAGE
            || $shortcutMode === PageRepository::SHORTCUT_MODE_PARENT_PAGE
            // Mode 2 "random subpage" exists in core v13 only
            || ($shortcutMode === 2 && (new Typo3Version())->getMajorVersion() < 14)
        ) {
            return null;
        }
        return $target;
    }

    /**
     * @param non-empty-list<int> $loop
     */
    private function getFinding(int $uid, int $pid, int $languageId, array $loop): Finding
    {
        $chain = implode(' -> ', [...$loop, $loop[0]]);
        return new Finding(
            new RecordKey('pages', $uid),
            $pid,
            [
                new RecordChange(RecordChangeType::Update, 'pages', $uid, [
                    'shortcut_mode' => ['value' => PageRepository::SHORTCUT_MODE_FIRST_SUBPAGE, 'type' => Connection::PARAM_INT],
                    'shortcut' => ['value' => 0, 'type' => Connection::PARAM_INT],
                ]),
            ],
            new Reason(
                'shortcut-loop',
                'Shortcut loop in language ' . $languageId . ': ' . $chain,
                ['language' => $languageId, 'loop' => $loop]
            ),
        );
    }

    private function getLivePagesQueryBuilder(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('uid', 'pid', 'sys_language_uid', 'l10n_parent', 'shortcut', 'shortcut_mode')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
        $workspaceIdField = $this->tcaHelper->getWorkspaceIdField('pages');
        if ($workspaceIdField !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq($workspaceIdField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
        }
        return $queryBuilder;
    }
}
