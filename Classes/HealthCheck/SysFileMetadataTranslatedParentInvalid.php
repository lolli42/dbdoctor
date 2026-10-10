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
 * Translated sys_file_metadata records must point to the default language record of their own file.
 *
 * sys_file_metadata is a bit special compared to other language aware tables, due to its cross
 * dependency with sys_file: Each sys_file record has exactly one default language sys_file_metadata
 * record (field "file"), and translations of it must have the same "file" value. This allows a more
 * precise fix than the generic transOrigPointerField checks, which would delete translations with a
 * missing or self-pointing language parent: The language parent of a metadata translation is known,
 * it is the default language record of its file. SysFileMetadataMissing runs before this check and
 * creates missing default language records, so translated alternative texts, titles and descriptions
 * can be kept by pointing them to the right record. The default record may be an "all languages"
 * record (sys_language_uid -1), as in MetaDataRepository->findByFileUid(). The frontend never overlays
 * it, so TcaTablesTranslatedParentInvalidPointer removes its translations later.
 */
final readonly class SysFileMetadataTranslatedParentInvalid extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for translated sys_file_metadata records with invalid language parent');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE);
        $io->text([
            'Translated "sys_file_metadata" records must point to the default language record of their',
            'file in "l10n_parent". This check finds live translations pointing to a not existing record,',
            'to no record, to themselves, or to the record of a different file, and sets "l10n_parent" to',
            'the default language record of their file. The translation is kept this way, instead of being',
            'deleted by later generic checks. Translations of an "all languages" default record (language',
            '-1) are never shown in frontend, a later check removes them.',
        ]);
    }

    protected function getAffectedRecords(HealthCheckRun $run): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $queryBuilder->getRestrictions()->removeAll();
        $expr = $queryBuilder->expr();
        // Join the default language record of the same file. Translations of files without default
        // language record are not found: SysFileMetadataMissing creates them, they are handled in the
        // next run if this run only simulated.
        $result = $queryBuilder
            ->select('translation.uid', 'translation.pid', 'translation.file', 'translation.sys_language_uid', 'translation.l10n_parent')
            ->addSelectLiteral($expr->min('parent.uid', 'default_uid'))
            ->from('sys_file_metadata', 'translation')
            ->join(
                'translation',
                'sys_file_metadata',
                'parent',
                (string)$expr->and(
                    $expr->eq('parent.file', $queryBuilder->quoteIdentifier('translation.file')),
                    $expr->in('parent.sys_language_uid', $queryBuilder->createNamedParameter([0, -1], Connection::PARAM_INT_ARRAY)),
                    $expr->eq('parent.t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
            )
            ->where(
                $expr->gt('translation.sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                // Live records only: Workspace records would need to be resolved against workspace versions.
                $expr->eq('translation.t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->groupBy('translation.uid', 'translation.pid', 'translation.file', 'translation.sys_language_uid', 'translation.l10n_parent')
            ->orderBy('translation.uid')
            ->executeQuery();
        $affectedRecords = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            if ((int)$row['l10n_parent'] !== (int)$row['default_uid']) {
                $row['_reasonBroken'] = 'l10n_parent should be ' . (int)$row['default_uid'];
                $affectedRecords['sys_file_metadata'][] = $row;
            }
        }
        return $affectedRecords;
    }

    protected function processRecords(HealthCheckRun $run, bool $simulate, array $affectedRecords): void
    {
        $rows = $affectedRecords['sys_file_metadata'] ?? [];
        $this->outputTableUpdateBefore($run, $simulate, 'sys_file_metadata');
        foreach ($rows as $row) {
            $fields = [
                'l10n_parent' => [
                    'value' => (int)$row['default_uid'],
                    'type' => Connection::PARAM_INT,
                ],
            ];
            $this->updateSingleTcaRecord($run, $simulate, 'sys_file_metadata', (int)$row['uid'], $fields);
        }
        $this->outputTableUpdateAfter($run, $simulate, 'sys_file_metadata', count($rows));
    }

    protected function recordDetails(HealthCheckRun $run, array $affectedRecords): void
    {
        $this->outputRecordDetails($run, $affectedRecords, '_reasonBroken', ['languageField', 'transOrigPointerField'], ['file']);
    }
}
