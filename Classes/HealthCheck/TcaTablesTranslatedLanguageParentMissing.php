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
 * Tables with record translations must point to existing records in transOrigPointerField
 *
 * The table specific variants for tt_content and sys_file_reference run earlier in the chain,
 * this check is the fallback for them. pages has its own checks and is excluded.
 */
final readonly class TcaTablesTranslatedLanguageParentMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    protected function getDescription(): HealthCheckDescription
    {
        return new HealthCheckDescription(
            'Scan for record translations with missing parent',
            [self::TAG_REMOVE],
            [
                'Record translations use the TCA ctrl field "transOrigPointerField"',
                '(DB field name usually "l10n_parent" or "l18n_parent"). This field points to a',
                'default language record. This health check verifies if that target exists.',
                'Affected records without language parent are removed.',
            ],
        );
    }

    protected function detect(HealthCheckRun $run): array
    {
        $findings = [];
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable(['pages']) as $tableName) {
            /** @var string $languageField */
            $languageField = $this->tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            // Handle deleted=1 records too: If their language parent is gone, they shouldn't exist anymore, too.
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder->select('uid', 'pid', $translationParentField)->from($tableName)
                ->where(
                    // localized records
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    // in 'connected' mode - has a l10n_parent field > 0
                    $queryBuilder->expr()->gt($translationParentField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                )
                ->orderBy('uid')
                ->executeQuery();

            while ($localizedRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $localizedRow */
                try {
                    $this->recordsHelper->getRecord($run->statements, $tableName, ['uid'], (int)$localizedRow[$translationParentField]);
                } catch (NoSuchRecordException $e) {
                    $findings[] = new Finding(
                        new RecordKey($tableName, (int)$localizedRow['uid']),
                        (int)$localizedRow['pid'],
                        [new RecordChange(RecordChangeType::Delete, $tableName, (int)$localizedRow['uid'])],
                        new Reason('parent-missing', 'Language parent "' . $localizedRow[$translationParentField] . '" does not exist', ['parent' => (int)$localizedRow[$translationParentField]]),
                    );
                }
            }
        }
        return $findings;
    }

    protected function getDetailCtrlFields(string $tableName): array
    {
        return ['transOrigPointerField'];
    }
}
