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
use Lolli\Dbdoctor\Finding\Finding;
use Lolli\Dbdoctor\Finding\Reason;
use Lolli\Dbdoctor\Finding\RecordChange;
use Lolli\Dbdoctor\Finding\RecordChangeType;
use Lolli\Dbdoctor\Finding\RecordKey;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Find translated pages that have no sys_language_uid=0 parent.
 */
final readonly class PagesTranslatedLanguageParentMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Check pages with missing language parent',
            [self::TAG_REMOVE],
            [
                'This health check finds translated "pages" records (sys_language_uid > 0) with',
                'their default language record (l10n_parent field) not existing in the database.',
                'Those translated pages are never shown in backend and frontend and removed.',
            ],
        );
    }

    public function detect(HealthCheckRun $run): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // Deleted pages are considered as well, we remove all restrictions.
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('uid', 'pid', 'l10n_parent')
            ->from('pages')
            ->where($queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->orderBy('uid')
            ->executeQuery();
        $findings = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            try {
                $this->recordsHelper->getRecord($run->statements, 'pages', ['uid'], (int)$row['l10n_parent']);
            } catch (NoSuchRecordException $e) {
                $findings[] = new Finding(
                    new RecordKey('pages', (int)$row['uid']),
                    (int)$row['pid'],
                    [new RecordChange(RecordChangeType::Delete, 'pages', (int)$row['uid'])],
                    new Reason('parent-missing', 'Language parent "' . $row['l10n_parent'] . '" does not exist', ['parent' => (int)$row['l10n_parent']]),
                );
            }
        }
        return $findings;
    }

    public function getDetailCtrlFields(string $tableName): array
    {
        return ['transOrigPointerField'];
    }
}
