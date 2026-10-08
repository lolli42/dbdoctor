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
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\InvalidPathException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Type\File\ImageInfo;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Each sys_file record needs a default language sys_file_metadata record. The core creates
 * it when indexing a file, but does not re-create it when it is missing: Image dimensions,
 * alternative text and similar are then empty, and images can not be cropped in backend.
 */
final class SysFileMetadataMissing extends AbstractHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
    ) {}

    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for sys_file records without sys_file_metadata record');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_INSERT);
        $io->text([
            'Each "sys_file" record needs a default language "sys_file_metadata" record. The core creates',
            'it when a file is indexed, but does not re-create a missing one: Image dimensions are then',
            'unknown and images can not be cropped in backend. This check creates missing records. As',
            'with core indexing, width and height of images in local storages are read from the file.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();
        $expr = $queryBuilder->expr();
        // A live metadata record in default language or "all languages" counts, as in MetaDataRepository->findByFileUid().
        $result = $queryBuilder->select('file.uid', 'file.pid', 'file.storage', 'file.identifier', 'file.missing')
            ->from('sys_file', 'file')
            ->leftJoin(
                'file',
                'sys_file_metadata',
                'metadata',
                (string)$expr->and(
                    $expr->eq('metadata.file', $queryBuilder->quoteIdentifier('file.uid')),
                    $expr->in('metadata.sys_language_uid', $queryBuilder->createNamedParameter([0, -1], Connection::PARAM_INT_ARRAY)),
                    $expr->eq('metadata.t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
            )
            ->where($expr->isNull('metadata.uid'))
            ->orderBy('file.uid')
            ->executeQuery();
        $affectedRecords = [];
        while ($row = $result->fetchAssociative()) {
            /** @var array<string, int|string> $row */
            $affectedRecords['sys_file'][] = $row;
        }
        return $affectedRecords;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        $this->outputTableInsertBefore($io, $simulate, 'sys_file_metadata');
        $count = 0;
        foreach ($affectedRecords['sys_file'] ?? [] as $fileRow) {
            $fields = [
                'pid' => [
                    'value' => 0,
                    'type' => Connection::PARAM_INT,
                ],
                'file' => [
                    'value' => (int)$fileRow['uid'],
                    'type' => Connection::PARAM_INT,
                ],
                'crdate' => [
                    'value' => (int)$GLOBALS['EXEC_TIME'],
                    'type' => Connection::PARAM_INT,
                ],
                'tstamp' => [
                    'value' => (int)$GLOBALS['EXEC_TIME'],
                    'type' => Connection::PARAM_INT,
                ],
                'l10n_diffsource' => [
                    'value' => '',
                    'type' => Connection::PARAM_LOB,
                ],
            ];
            [$width, $height] = $this->getImageDimensions($fileRow);
            if ($width > 0 && $height > 0) {
                $fields['width'] = [
                    'value' => $width,
                    'type' => Connection::PARAM_INT,
                ];
                $fields['height'] = [
                    'value' => $height,
                    'type' => Connection::PARAM_INT,
                ];
            }
            $this->insertSingleTcaRecord($io, $simulate, $recordsHelper, 'sys_file_metadata', $fields);
            $count++;
        }
        $this->outputTableInsertAfter($io, $simulate, 'sys_file_metadata', $count);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', [], ['storage', 'identifier', 'missing']);
    }

    /**
     * Width and height of images in local storages, as core Indexer->extractRequiredMetaData() does
     * when indexing a file. Remote storages must provide dimensions by metadata extractors, which
     * is out of scope here. Missing files and broken identifiers get no dimensions.
     *
     * @param array<string, int|string> $fileRow
     * @return array{0: int, 1: int}
     */
    private function getImageDimensions(array $fileRow): array
    {
        if ((int)$fileRow['missing'] === 1) {
            return [0, 0];
        }
        try {
            $file = $this->resourceFactory->getFileObject((int)$fileRow['uid']);
            // Not File->isImage(): It asks the driver for the file size if sys_file has none,
            // which throws for not existing files. The extension check is the same.
            if ($file->getStorage()->getDriverType() !== 'Local'
                || !GeneralUtility::inList(strtolower($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] ?? ''), $file->getExtension())
            ) {
                return [0, 0];
            }
            $filePath = $file->getForLocalProcessing(false);
        } catch (FileDoesNotExistException) {
            // sys_file record has been removed meanwhile
            return [0, 0];
        } catch (InvalidPathException) {
            // Identifier with ".." or "//"
            return [0, 0];
        }
        if (!is_file($filePath)) {
            // ImageInfo would fall back to ImageMagick for not existing files
            return [0, 0];
        }
        $imageInfo = GeneralUtility::makeInstance(ImageInfo::class, $filePath);
        return [(int)$imageInfo->getWidth(), (int)$imageInfo->getHeight()];
    }
}
