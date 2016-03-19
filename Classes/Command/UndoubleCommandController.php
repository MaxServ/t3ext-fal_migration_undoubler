<?php
namespace MaxServ\FalMigrationUndoubler\Command;

/**
 *  Copyright notice
 *
 *  ⓒ 2016 ⊰ ℳichiel ℛoos ⊱ <michiel@maxserv.com>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is free
 *  software; you can redistribute it and/or modify it under the terms of the
 *  GNU General Public License as published by the Free Software Foundation;
 *  either version 2 of the License, or (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful, but
 *  WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 *  or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 *  more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 */

use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFileAccessPermissionsException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Undouble tasks
 *
 * ## Undoubling files which have duplicates inside the _migrated folder
 * All tasks should be executed on the contents of the _migrated folder first to resolve duplicates inside of there.
 *
 * ### Build a map of unique sha1 values
 * First we ask the database to give us a list of file uids and sha1 values. Then we turn that into an array of unique
 * sha1 values and lowest uids. These files are most likely the 'original' files. Not the copies with _01, _02 etc. We
 * call this the sha1Map.
 *
 * ### Build a map of duplicate files
 * Then we fetch a list of all the file uids and their sha1 values. We iterate over the the result and for each row we
 * look up the sha1 values in the sha1Map. If the value found in the sha1Map does not match the uid of the row, then
 * we are dealing with a duplicate. We know this because the sha1Map contains all unique sha1 values with the 'lowest'
 * ids.
 *
 * We add the uid of the duplicate row to the idMap array. The index is the row uid and the value is the (lower) id we
 * found in the sha1Map.
 *
 * ### Update references to duplicate files
 * Now we can use the constructed idMap to move references to duplicate files to the original id's.
 *
 * ### Remove duplicate files without references
 * If we are positive that all references pointing to duplicates have been updated, we can remove the duplicate files.
 *
 * ## Undoubling files that have duplicates outside of the _migrated folder
 * Now we execute the same tasks. This time however, we build the sha1 map only from files outside of the _migrated
 * folder.
 *
 * @since 1.0.0
 * @package MaxServ\FalMigrationUndoubler
 * @subpackage Controller
 */
class UndoubleCommandController extends AbstractCommandController
{
    /**
     * Is this a dry run?
     *
     * @since 1.0.0
     *
     * @var bool
     */
    protected $isDryRun = false;

    /**
     * File id map
     *
     * Index is the original uid, value is the 'new' uid
     *
     * @since 1.2.0
     *
     * @var array
     */
    protected $uidMap = [];

    /**
     * Unique Sha1 to id mapping
     *
     * @since 1.2.0
     *
     * @var array
     */
    protected $sha1Map = [];

    /**
     * Fields with a softref configuration in the TCA
     *
     * @since 1.1.0
     *
     * @var array
     */
    protected $softRefFields = ['typolink' => [], 'typolink_tag' => []];

    /**
     * A Resource storage
     *
     * @since 1.0.0
     *
     * @var \TYPO3\CMS\Core\Resource\ResourceStorage
     */
    protected $storage;

    /**
     * Initialize the storage repository.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        parent::__construct();
        /** @var $storageRepository \TYPO3\CMS\Core\Resource\StorageRepository */
        $storageRepository = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\StorageRepository');
        $storages = $storageRepository->findAll();
        $this->storage = $storages[0];
        $this->findSoftRefFields();
    }


    /**
     * Show status information
     *
     * @since 1.2.0
     *
     * @return void
     */
    public function statusCommand()
    {
        $this->headerMessage('Status');
        $this->warningMessage('All files');
        $totalFileCount = $this->getFileCount();
        $this->message('Total : ' . $this->successString($totalFileCount));
        $uniqueFileCount = $this->getUniqueFileCount();
        $this->message('Unique: ' . $this->successString($uniqueFileCount));
        $this->horizontalLine('', 22);
        $this->message('Remove: ' . $this->successString($totalFileCount - $uniqueFileCount) . ' (' . $this->errorString(GeneralUtility::formatSize($this->getPossibleSpaceSaved())) . ')');
        $this->message();
        $this->warningMessage('Files in the _migrated folder');
        $migratedFileCount = $this->getFileCountInMigratedFolder();
        $this->message('Total : ' . $this->successString($migratedFileCount));
        $migratedUniqueFileCount = $this->getUniqueFileCountInMigratedFolder();
        $this->message('Unique: ' . $this->successString($migratedUniqueFileCount));
        $this->horizontalLine('', 22);
        $this->message('Remove  ' . $this->successString($migratedFileCount - $migratedUniqueFileCount) . ' (' . $this->errorString(GeneralUtility::formatSize($this->getPossibleSpaceSavedInMigratedFolder())) . ')');
        $this->message();
        $this->warningMessage('Top 20 files with most duplicates');
        $mostDuplicates = $this->getFilesWithMostDuplicates();
        foreach ($mostDuplicates as $row) {
            $this->message($this->successString($row['total']) . ' ' . $row['identifier']);
        }
    }

    /**
     * Undouble files with duplicates inside of the _migrated folder
     *
     * @since 1.2.0
     *
     * @param bool $dryRun Do a test run, no modifications.
     *
     * @return void
     */
    public function migratedInternalCommand($dryRun = false)
    {
        $this->populateSha1MapFromMigratedFolder();
        $this->populateUidMap();

        $this->message('Found ' . $this->successString(count($this->uidMap)) . ' files to undouble');
        $this->message();

        $this->warningMessage('Calling updateTypolinkFields command');
        $this->updateTypolinkFieldsCommand('', '', '', $dryRun);
        $this->message();

        $this->warningMessage('Calling updateTypolinkTagFields command');
        $this->updateTypolinkTagFieldsCommand('', '', '', $dryRun);
        $this->message();

        $this->warningMessage('Calling migratedfiles command');
        $this->migrateFileReferences('', $dryRun);
    }

    /**
     * Undouble files with duplicates outside of the _migrated folder
     *
     * @since 1.2.0
     *
     * @param bool $dryRun Do a test run, no modifications.
     *
     * @return void
     */
    public function migratedCommand($dryRun = false)
    {
        $this->populateSha1Map();
        $this->populateUidMap();

        $this->message('Found ' . $this->successString(count($this->uidMap)) . ' files to undouble');
        $this->message();

        $this->warningMessage('Calling updateTypolinkFields command');
        $this->updateTypolinkFieldsCommand('', '', 'regular', $dryRun);
        $this->message();

        $this->warningMessage('Calling updateTypolinkTagFields command');
        $this->updateTypolinkTagFieldsCommand('', '', 'regular', $dryRun);
        $this->message();

        $this->warningMessage('Calling migratedfiles command');
        $this->migrateFileReferences('regular', $dryRun);
    }

    /**
     * Update typolink enabled fields
     *
     * Finds rich text fields with file references. If these links point to migratable files, they will be updated. The
     * TCA is inspected and all fields with a softref confiugration of type `typolink` will be processed. You can
     * also specify a table and field to process just that field.
     *
     * The `$mode` parameter can be set to either `internal` or `regular`. If it is 'internal', the operation will be
     * performed with files having duplicates 'inside' of the _migrated folder. If set to 'regular', the operation will
     * be performed with files having duplicates 'outside' of the _migrated folder.
     *
     * @since 1.1.0
     *
     * @param string $table The table to work on. Default: ``.
     * @param string $field The field to work on. Default: ``.
     * @param string $mode The mode to work in. Either 'internal' or 'regular'. Default: `internal`.
     * @param bool $dryRun Do a test run, no modifications.
     *
     * @return void
     */
    public function updateTypolinkFieldsCommand($table = '', $field = '', $mode = 'internal', $dryRun = false)
    {
        $table = preg_replace('/[^a-zA-Z0-9_-]/', '', $table);
        $field = preg_replace('/[^a-zA-Z0-9_-]/', '', $field);
        if ($table !== '' && $field !== '') {
            $tableAndFieldMap = [$table => [$field]];
        } else {
            $tableAndFieldMap = $this->softRefFields['typolink'];
        }

        $this->isDryRun = $dryRun;
        $updateCounter = 0;

        if (!count($this->uidMap)) {
            if ($mode === 'internal') {
                $this->populateSha1MapFromMigratedFolder();
            } else {
                $this->populateSha1Map();
            }
            $this->populateUidMap();
        }
        $total = count($this->uidMap);
        $this->infoMessage(
            'Found ' . $total . ' migratable records'
        );
        if (!$total) {
            return;
        }

        foreach ($tableAndFieldMap as $table => $fields) {
            foreach ($fields as $field) {
                $this->headerMessage('Updating typolink fields ' . $table . ' -> ' . $field);
                $typoLinkRows = $this->getTypoLinkFieldsWithReferences($table, $field);
                $totalTypoLink = count($typoLinkRows);
                $this->message('Found ' . $this->successString($totalTypoLink) . ' ' . $table . ' records that have a "file:" reference in the field ' . $field);
                if ($totalTypoLink) {
                    $this->message('Going over all of these records to find links with migratable id\'s . . .');
                    $updateFieldCounter = 0;
                    foreach ($typoLinkRows as $typoLinkRow) {
                        $updateCount = $this->updateTypoLinkFields($table, $field, $typoLinkRow);
                        $updateFieldCounter += $updateCount;
                    }
                    if ($updateFieldCounter) {
                        $this->message('Updated ' . $this->successString($updateFieldCounter) . ' references');
                    } else {
                        $this->message('Did ' . $this->successString('not') . ' find any updatable references');
                    }
                    $updateCounter += $updateFieldCounter;
                }
            }
        }
        $this->message();
        $this->message('Updated ' . $this->successString($updateCounter) . ' references.');
    }

    /**
     * Update typolink_tag enabled fields
     *
     * Finds rich text fields with file references. If these links point to migratable files, they will be updated. The
     * TCA is inspected and all fields with a softref configuration of type `typolink_tag` will be processed. You can
     * also specify a table and field to process just that field.
     *
     * The `$mode` parameter can be set to either `internal` or `regular`. If it is 'internal', the operation will be
     * performed with files having duplicates 'inside' of the _migrated folder. If set to 'regular', the operation will
     * be performed with files having duplicates 'outside' of the _migrated folder.
     *
     * @since 1.1.0
     *
     * @param string $table The table to work on. Default: ``.
     * @param string $field The field to work on. Default: ``.
     * @param string $mode The mode to work in. Either 'internal' or 'regular'. Default: `internal`.
     * @param bool $dryRun Do a test run, no modifications.
     *
     * @return void
     */
    public function updateTypolinkTagFieldsCommand($table = '', $field = '', $mode = 'internal', $dryRun = false)
    {
        $table = preg_replace('/[^a-zA-Z0-9_-]/', '', $table);
        $field = preg_replace('/[^a-zA-Z0-9_-]/', '', $field);
        if ($table !== '' && $field !== '') {
            $tableAndFieldMap = [$table => [$field]];
        } else {
            $tableAndFieldMap = $this->softRefFields['typolink_tag'];
        }

        $this->isDryRun = $dryRun;
        $updateCounter = 0;
        if (!count($this->uidMap)) {
            if ($mode === 'internal') {
                $this->populateSha1MapFromMigratedFolder();
            } else {
                $this->populateSha1Map();
            }
            $this->populateUidMap();
        }
        $total = count($this->uidMap);
        $this->infoMessage(
            'Found ' . $total . ' migratable records'
        );
        if (!$total) {
            return;
        }

        foreach ($tableAndFieldMap as $table => $fields) {
            foreach ($fields as $field) {
                $this->headerMessage('Updating fields from ' . $table . ' -> ' . $field);
                $typoLinkRows = $this->getTypoLinkTagFieldsWithReferences($table, $field);
                $totalTypoLink = count($typoLinkRows);
                $this->message('Found ' . $this->successString($totalTypoLink) . ' ' . $table . ' records that have a "<link>" tag in the field ' . $field);
                if ($totalTypoLink) {
                    $this->message('Going over all of these records to find links with migratable id\'s . . .');
                    $updateFieldCounter = 0;
                    foreach ($typoLinkRows as $typoLinkRow) {
                        $updateCount = $this->updateTypoLinkTagFields($table, $field, $typoLinkRow);
                        $updateFieldCounter += $updateCount;
                    }
                    if ($updateFieldCounter) {
                        $this->message('Updated ' . $this->successString($updateFieldCounter) . ' references');
                    } else {
                        $this->message('Did ' . $this->successString('not') . ' find any updatable references');
                    }
                    $updateCounter += $updateFieldCounter;
                }
            }
        }
        $this->message();
        $this->message('Updated ' . $this->successString($updateCounter) . ' references.');
    }

    /**
     * Migrate references to files in _migrated folder
     *
     * De-duplication of files in _migrated folder. Relations to files in migrated folder will be re-linked to an
     * identical file outside of the _migrated folder.
     *
     * The `$mode` parameter can be set to either `internal` or `regular`. If it is 'internal', the operation will be
     * performed with files having duplicates 'inside' of the _migrated folder. If set to 'regular', the operation will
     * be performed with files having duplicates 'outside' of the _migrated folder.
     *
     * @since 1.2.0
     *
     * @param string $mode The mode to work in. Either 'internal' or 'regular'. Default: `internal`.
     * @param bool $dryRun Do a test run, no modifications.
     *
     * @return void
     */
    public function migrateFileReferences($mode = 'internal', $dryRun = false)
    {
        $this->headerMessage('Normalizing _migrated folder');
        $this->isDryRun = $dryRun;
        $counter = 0;
        try {
            if (!count($this->uidMap)) {
                if ($mode === 'internal') {
                    $this->populateSha1MapFromMigratedFolder();
                } else {
                    $this->populateSha1Map();
                }
                $this->populateUidMap();
            }
            $total = count($this->uidMap);
            $this->infoMessage(
                'Found ' . $total . ' records in _migrated folder with references that have counterparts outside of there'
            );
            $updateCount = 0;
            foreach ($this->uidMap as $oldUid => $newUid) {
                $progress = number_format(100 * ($counter++ / $total), 1) . '% of ' . $total;
                if (!$this->isDryRun) {
                    $updates = $this->updateReferencesToFile($oldUid, $newUid);
                } else {
                    $updates = $this->countReferencesToFile($oldUid);
                }
                $updateCount += $updates;
                if ($updates) {
                    $this->message($progress . ' Updated ' . $this->successString($updates) . ' references for ' . $oldUid);
                }
            }
            $this->message();
            $this->message('Updated ' . $this->successString($updateCount) . ' references to files from the _migrated folder.');
        } catch (\RuntimeException $exception) {
            $this->errorMessage($exception->getMessage());
        }
    }

    /**
     * Remove files from _migrated folder
     *
     * Removes files with counterparts outside of the _migrated folder and without references
     * in sys_file_reference from the _migrated folder.
     *
     * @since 1.1.0
     *
     * @param bool $dryRun Do a test run, no modifications.
     * @param bool $iKnowWhatImDoing Do you know what you are doing?
     *
     * @return void
     */
    public function removeMigratedFilesCommand($dryRun = false, $iKnowWhatImDoing = false)
    {
        $this->headerMessage('Removing files without references from _migrated folder');
        if (!$iKnowWhatImDoing) {
            $this->warningMessage('This will remove files from the _migrated folder.');
            $this->warningMessage('Are you sure you don\'t have any typolink or typolink_tag enabled fields that may have references');
            $this->warningMessage('to these files? This task only checks the sys_file_reference table.');
            $this->warningMessage('');
            $this->warningMessage('You can update references to these files by running the commands:');
            $this->warningMessage('- undouble:migratedfiles');
            $this->warningMessage('- undouble:updtetypolinkfields');
            $this->warningMessage('- undouble:updtetypolinktagfields');
            $this->warningMessage('');
            $this->warningMessage('Please specify the option --i-know-what-im-doing');
            exit();
        }
        $this->isDryRun = $dryRun;
        $counter = 0;
        $freedBytes = 0;
        try {
            $result = $this->getMigratableDocumentsWithoutReferences();
            $total = count($result);
            $this->infoMessage(
                'Found ' . $total . ' records in _migrated folder that have counterparts outside of there'
            );
            foreach ($result as $row) {
                $progress = number_format(100 * ($counter++ / $total), 1) . '% of ' . $total;
                $this->infoMessage($progress . ' Removing ' . $row['oldIdentifier']);
                if (!$this->isDryRun) {
                    try {
                        $this->storage->deleteFile($this->storage->getFile($row['oldIdentifier']));
                        $freedBytes += $row['size'];
                    } catch (FileOperationErrorException $error) {
                        $this->errorMessage($error->getMessage());
                    } catch (InsufficientFileAccessPermissionsException $error) {
                        $this->errorMessage($error->getMessage());
                        $this->errorMessage('Please edit the _cli_lowlevel user in the backend and ensure this user has access to the _migrated filemount.');
                        exit();
                    }
                }
            }
            $this->message();
            $this->message('Removed ' . $this->successString($total) . ' files from the _migrated folder.');
            $this->message('Freed ' . $this->successString(GeneralUtility::formatSize($freedBytes)));
        } catch (\RuntimeException $exception) {
            $this->errorMessage($exception->getMessage());
        }
    }

    /**
     * Get array of sys_file records in the _migrated folder.
     *
     * @since 1.2.0
     *
     * @return array
     */
    protected function getDocumentsInMigratedFolder()
    {
        $result = $this->databaseConnection->sql_query('SELECT
              uid,
              sha1,
              size
            FROM sys_file
            WHERE
              identifier LIKE "/_migrated/%"
            ORDER BY
              uid
        ;');
        $rows = array();
        if ($result === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        } else {
            while ($row = $this->databaseConnection->sql_fetch_assoc($result)) {
                $rows[] = $row;
            }
        }
        $this->databaseConnection->sql_free_result($result);

        return $rows;
    }

    /**
     * Get array of sys_file records in the _migrated folder with sha1 matching documents outside of
     * the _migrated folder.
     *
     * @since 1.1.0
     *
     * @return array
     */
    protected function getMigratableDocuments()
    {
        $result = $this->databaseConnection->sql_query('SELECT
            migrated.size,
            migrated.uid         AS oldUid,
            alternate.uid        AS newUid,
            migrated.identifier  AS oldIdentifier,
            alternate.identifier AS newIdentifier
        FROM sys_file AS migrated
            JOIN sys_file AS alternate
                ON migrated.sha1 = alternate.sha1
        WHERE
            NOT migrated.uid = alternate.uid
            AND migrated.identifier LIKE "/_migrated/%"
            AND alternate.identifier NOT LIKE "/_migrated/%"
        ORDER BY
            oldUid ASC,
            newUid DESC
        ;');
        $rows = array();
        if ($result === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        } else {
            while ($row = $this->databaseConnection->sql_fetch_assoc($result)) {
                $rows[] = $row;
            }
        }
        $this->databaseConnection->sql_free_result($result);

        return $rows;
    }

    /**
     * Get array of sys_file records outside of the _migrated folder
     *
     * @since 1.2.0
     *
     * @return array
     */
    protected function getSha1ListForFilesOutsideMigratedFolder()
    {
        $result = $this->databaseConnection->sql_query('SELECT
              uid,
              sha1
            FROM sys_file
            WHERE identifier NOT LIKE "/_migrated/%"
            ORDER BY uid
        ;');
        $rows = array();
        if ($result === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        } else {
            while ($row = $this->databaseConnection->sql_fetch_assoc($result)) {
                $rows[] = $row;
            }
        }
        $this->databaseConnection->sql_free_result($result);

        return $rows;
    }

    /**
     * Get array of sys_file records in the _migrated folder
     *
     * @since 1.2.0
     *
     * @return array
     */
    protected function getSha1ListForMigratedFolder()
    {
        $result = $this->databaseConnection->sql_query('SELECT
              uid,
              sha1
            FROM sys_file
            WHERE identifier LIKE "/_migrated/%"
            ORDER BY uid
        ;');
        $rows = array();
        if ($result === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        } else {
            while ($row = $this->databaseConnection->sql_fetch_assoc($result)) {
                $rows[] = $row;
            }
        }
        $this->databaseConnection->sql_free_result($result);

        return $rows;
    }

    /**
     * Get number of files in sys_file
     *
     * @since 1.2.0
     *
     * @return integer
     */
    protected function getFileCount()
    {
        $result = $this->databaseConnection->sql_query('SELECT
              COUNT(*) AS total
            FROM sys_file
        ;');
        $count = 0;
        if ($result === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        } else {
            $row = $this->databaseConnection->sql_fetch_assoc($result);
            $count = $row['total'];
        }
        $this->databaseConnection->sql_free_result($result);

        return $count;
    }

    /**
     * Get number of files in sys_file that are in the _migrated folder
     *
     * @since 1.2.0
     *
     * @return integer
     */
    protected function getFileCountInMigratedFolder()
    {
        $result = $this->databaseConnection->sql_query('SELECT
              COUNT(*) AS total
            FROM sys_file
            WHERE identifier LIKE "/_migrated/%"
        ;');
        $count = 0;
        if ($result === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        } else {
            $row = $this->databaseConnection->sql_fetch_assoc($result);
            $count = $row['total'];
        }
        $this->databaseConnection->sql_free_result($result);

        return $count;
    }

    /**
     * Get number bytes that can possibly be saved by undoubling
     *
     * @since 1.2.0
     *
     * @return integer
     */
    protected function getPossibleSpaceSaved()
    {
        $result = $this->databaseConnection->sql_query('SELECT
              size,
              COUNT(uid) AS total
            FROM sys_file
            GROUP BY sha1;
        ;');
        $count = 0;
        if ($result === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        } else {
            while ($row = $this->databaseConnection->sql_fetch_assoc($result)) {
                if ($row['total'] > 1) {
                    $count += $row['size'] * ($row['total'] - 1);
                }
            }
        }
        $this->databaseConnection->sql_free_result($result);

        return $count;
    }

    /**
     * Get number bytes that can possibly be saved by undoubling inside of the _migratedfolder
     *
     * @since 1.2.0
     *
     * @return integer
     */
    protected function getPossibleSpaceSavedInMigratedFolder()
    {
        $result = $this->databaseConnection->sql_query('SELECT
              size,
              COUNT(uid) AS total
            FROM sys_file
            WHERE identifier LIKE "/_migrated/%"
            GROUP BY sha1;
        ;');
        $count = 0;
        if ($result === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        } else {
            while ($row = $this->databaseConnection->sql_fetch_assoc($result)) {
                if ($row['total'] > 1) {
                    $count += $row['size'] * ($row['total'] - 1);
                }
            }
        }
        $this->databaseConnection->sql_free_result($result);

        return $count;
    }

    /**
     * Get Files with the most duplicates
     *
     * @since 1.2.0
     *
     * @return array
     */
    protected function getFilesWithMostDuplicates()
    {
        $result = $this->databaseConnection->sql_query('SELECT
              identifier,
              COUNT(uid) AS total
            FROM sys_file
            GROUP BY sha1
            ORDER BY total DESC
            LIMIT 20;
        ;');
        $return = [];
        if ($result === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        } else {
            while ($row = $this->databaseConnection->sql_fetch_assoc($result)) {
                $return[] = $row;
            }
        }
        $this->databaseConnection->sql_free_result($result);

        return $return;
    }

    /**
     * Get number of unique sha1 values in sys_file
     *
     * @since 1.2.0
     *
     * @return integer
     */
    protected function getUniqueFileCount()
    {
        $result = $this->databaseConnection->sql_query('SELECT
              uid
            FROM sys_file
            GROUP BY sha1
        ;');
        $count = 0;
        if ($result === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        } else {
            $count = $this->databaseConnection->sql_num_rows($result);
        }
        $this->databaseConnection->sql_free_result($result);

        return $count;
    }

    /**
     * Get number of unique sha1 values in sys_file
     *
     * @since 1.2.0
     *
     * @return integer
     */
    protected function getUniqueFileCountInMigratedFolder()
    {
        $result = $this->databaseConnection->sql_query('SELECT
              uid
            FROM sys_file
            WHERE identifier LIKE "/_migrated/%"
            GROUP BY sha1
        ;');
        $count = 0;
        if ($result === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        } else {
            $count = $this->databaseConnection->sql_num_rows($result);
        }
        $this->databaseConnection->sql_free_result($result);

        return $count;
    }

    /**
     * Get array of sys_file records in the _migrated folder with sha1 matching documents outside of
     * the _migrated folder with references in the sys_file_reference table.
     *
     * @since 1.1.0
     *
     * @return array
     */
    protected function getMigratableDocumentsWithReferences()
    {
        $result = $this->databaseConnection->sql_query('SELECT
            migrated.size,
            migrated.uid         AS oldUid,
            alternate.uid        AS newUid,
            migrated.identifier  AS oldIdentifier,
            alternate.identifier AS newIdentifier
        FROM sys_file AS migrated
            JOIN sys_file AS alternate
                ON migrated.sha1 = alternate.sha1
            JOIN sys_file_reference
                ON sys_file_reference.uid_local = migrated.uid
        WHERE
            NOT migrated.uid = alternate.uid
            AND migrated.identifier LIKE "/_migrated/%"
            AND alternate.identifier NOT LIKE "/_migrated/%"
        GROUP BY
            migrated.uid
        ;');
        $rows = array();
        if ($result === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        } else {
            while ($row = $this->databaseConnection->sql_fetch_assoc($result)) {
                $rows[] = $row;
            }
        }
        $this->databaseConnection->sql_free_result($result);

        return $rows;
    }

    /**
     * Get array of sys_file records in the _migrated folder with sha1 matching documents outside of
     * the _migrated folder but without entries in the sys_file_reference table.
     *
     * @since 1.1.0
     *
     * @return array
     */
    protected function getMigratableDocumentsWithoutReferences()
    {
        $result = $this->databaseConnection->sql_query('SELECT
            migrated.size,
            migrated.uid         AS oldUid,
            alternate.uid        AS newUid,
            migrated.identifier  AS oldIdentifier,
            alternate.identifier AS newIdentifier
        FROM sys_file AS migrated
            JOIN sys_file AS alternate
                ON migrated.sha1 = alternate.sha1
            LEFT OUTER JOIN sys_file_reference
                ON sys_file_reference.uid_local = migrated.uid
        WHERE
            NOT migrated.uid = alternate.uid
            AND migrated.identifier LIKE "/_migrated/%"
            AND alternate.identifier NOT LIKE "/_migrated/%"
            AND sys_file_reference.uid_foreign IS NULL
        GROUP BY
            migrated.uid
        ;');
        $rows = array();
        if ($result === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        } else {
            while ($row = $this->databaseConnection->sql_fetch_assoc($result)) {
                $rows[] = $row;
            }
        }
        $this->databaseConnection->sql_free_result($result);

        return $rows;
    }

    /**
     * Get typolink enabled fields with references
     *
     * @since 1.1.0
     *
     * @param string $table
     * @param string $field
     *
     * @return array
     */
    private function getTypoLinkFieldsWithReferences($table, $field)
    {
        $result = array();
        $rows = $this->databaseConnection->exec_SELECTgetRows(
            'uid, ' . $field,
            $table,
            $field . ' LIKE  "file:%"'
        );
        if ($rows === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        } else {
            $result = $rows;
        }

        return $result;
    }

    /**
     * Get typolink_tag enabled fields with references
     *
     * @since 1.1.0
     *
     * @param string $table
     * @param string $field
     *
     * @return array
     */
    private function getTypoLinkTagFieldsWithReferences($table, $field)
    {
        $result = array();
        $rows = $this->databaseConnection->exec_SELECTgetRows(
            'uid, ' . $field,
            $table,
            '(' . $field . ' LIKE  "%<link file:%" OR ' . $field . ' LIKE "%&lt;link file:%")'
        );
        if ($rows === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        } else {
            $result = $rows;
        }

        return $result;
    }

    /**
     * Find fields in tables that have a softref configuration
     *
     * Go over all tables and fields in the TCA. For each field;
     * - Check if it has a 'config' key in the field configuration
     * - Check if the 'config' array has a 'softref' entry
     * - Split the softref config
     * - Check for 'typolink_tag' and 'typolink' strings
     *
     * @since 1.1.0
     *
     * These are the fields that can contain references to files
     *
     * @return void
     */
    private function findSoftRefFields()
    {
        foreach ($GLOBALS['TCA'] as $table => $tableConfiguration) {
            if (isset($tableConfiguration['columns'])) {
                foreach ($tableConfiguration['columns'] as $field => $fieldConfiguration) {
                    if (isset($fieldConfiguration['config'], $fieldConfiguration['config']['softref'])) {
                        $types = explode(',', $fieldConfiguration['config']['softref']);
                        foreach ($types as $type) {
                            if ($type === 'typolink') {
                                if (!isset($this->softRefFields['typolink'][$table])) {
                                    $this->softRefFields['typolink'][$table] = [];
                                }
                                $this->softRefFields['typolink'][$table][] = $field;
                            }
                            if ($type === 'typolink_tag') {
                                if (!isset($this->softRefFields['typolink_tag'][$table])) {
                                    $this->softRefFields['typolink_tag'][$table] = [];
                                }
                                $this->softRefFields['typolink_tag'][$table][] = $field;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Update typolink enabled fields with new references
     *
     * @since 1.1.0
     *
     * @param string $table
     * @param string $field
     * @param array $row
     *
     * @return mixed
     */
    private function updateTypoLinkFields($table, $field, array $row)
    {
        $updateCount = 0;
        $originalContent = $row[$field];
        $finalContent = $originalContent;
        $results = array();
        if (preg_match_all(
            '/(?:file:([0-9]+)([^$]*))/',
            $originalContent,
            $results,
            PREG_SET_ORDER
        )) {
            $matchingUids = [];
            foreach ($results as $result) {
                $searchString = $result[0];
                $matchingUid = (int)$result[1];
                if ($matchingUid > 0) {
                    $linkRemainder = $result[2];
                    if (isset($this->uidMap[$matchingUid])) {
                        $matchingUids[] = $matchingUid;
                        $replaceString = 'file:' . $this->uidMap[$matchingUid] . $linkRemainder;
                        $finalContent = str_replace($searchString, $replaceString, $finalContent);
                        $updateCount++;
                    }
                }
            }
            if ($finalContent !== $originalContent) {
                if (!$this->isDryRun) {
                    $this->databaseConnection->exec_UPDATEquery(
                        $table,
                        'uid=' . $row['uid'],
                        array($field => $finalContent)
                    );
                    foreach ($matchingUids as $matchingUid) {
                        $result = $this->databaseConnection->exec_UPDATEquery(
                            'sys_refindex',
                            'ref_uid = ' . $matchingUid
                            . ' AND ref_table = \'sys_file\''
                            . ' AND NOT tablename = \'sys_file_metadata\''
                            . ' AND NOT tablename = \'sys_file_reference\'',
                            array('ref_uid' => $this->uidMap[$matchingUid])
                        );
                        if ($result === null) {
                            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
                        }
                    }
                }
                $this->message('Updated ' . $this->warningString($table . ':' . $row['uid']) . ' with: ' . $this->successString($finalContent));
            }
        }

        return $updateCount;
    }

    /**
     * Update typolink_tag enabled fields with new references
     *
     * @since 1.1.0
     *
     * @param string $table
     * @param string $field
     * @param array $row
     *
     * @return mixed
     */
    private function updateTypoLinkTagFields($table, $field, array $row)
    {
        $updateCount = 0;

        $originalContent = $row[$field];
        $finalContent = $originalContent;
        $results = array();
        if (preg_match_all(
            '/(?:<link file:([0-9]+)([^>]*)?>(.*?)<\/link>)/',
            $originalContent,
            $results,
            PREG_SET_ORDER
        )) {
            $matchingUids = [];
            foreach ($results as $result) {
                $searchString = $result[0];
                $matchingUid = (int)$result[1];
                if ($matchingUid > 0) {
                    $linkRemainder = $result[2];
                    $linkText = $result[3];
                    if (isset($this->uidMap[$matchingUid])) {
                        $matchingUids[] = $matchingUid;
                        $replaceString = '<link file:' . $this->uidMap[$matchingUid] . $linkRemainder . '>' . $linkText . '</link>';
                        $finalContent = str_replace($searchString, $replaceString, $finalContent);
                        $updateCount++;
                    }
                }
            }

            if ($finalContent !== $originalContent) {
                if (!$this->isDryRun) {
                    $this->databaseConnection->exec_UPDATEquery(
                        $table,
                        'uid=' . $row['uid'],
                        array($field => $finalContent)
                    );
                    foreach ($matchingUids as $matchingUid) {
                        $result = $this->databaseConnection->exec_UPDATEquery(
                            'sys_refindex',
                            'ref_uid = ' . $matchingUid
                            . ' AND ref_table = \'sys_file\''
                            . ' AND NOT tablename = \'sys_file_metadata\''
                            . ' AND NOT tablename = \'sys_file_reference\'',
                            array('ref_uid' => $this->uidMap[$matchingUid])
                        );
                        if ($result === null) {
                            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
                        }
                    }
                }
                $this->message('Updated ' . $this->warningString($table . ':' . $row['uid']) . ' with: ' . $this->successString($finalContent));
            }
        }

        return $updateCount;
    }

    /**
     * Count file references to a certain file
     *
     * @since 1.2.0
     *
     * @param integer $oldUid
     *
     * @return int
     */
    protected function countReferencesToFile($oldUid)
    {
        $result = $this->databaseConnection->exec_SELECTcountRows(
            'uid',
            'sys_file_reference',
            'uid_local = ' . (int)$oldUid
        );
        if ($result === false) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        }
        return $result;
    }

    /**
     * Move file references from migrated file to other file
     *
     * @since 1.0.0
     *
     * @param integer $oldUid
     * @param integer $newUid
     *
     * @return int
     */
    protected function updateReferencesToFile($oldUid, $newUid)
    {
        $result = $this->databaseConnection->exec_UPDATEquery(
            'sys_file_reference',
            'uid_local = ' . (int)$oldUid,
            array('uid_local' => (int)$newUid)
        );
        if ($result === null) {
            $this->errorMessage('Database query failed. Error was: ' . $this->databaseConnection->sql_error());
        }
        $resultCount = $this->databaseConnection->sql_num_rows($result);
        $this->databaseConnection->sql_free_result($result);
        return $resultCount;
    }

    /**
     * Populate mapping of sha1 indexed array of lowest uid values
     *
     * These are most likely to be the original files
     *
     * @since 1.2.0
     *
     * @return void
     */
    protected function populateSha1Map()
    {
        $sha1List = $this->getSha1ListForFilesOutsideMigratedFolder();
        foreach ($sha1List as $row) {
            if (!isset($this->sha1Map[$row['sha1']])
                || (isset($this->sha1Map[$row['sha1']]) && $this->sha1Map[$row['sha1']] > $row['uid'])
            ) {
                $this->sha1Map[$row['sha1']] = (int)$row['uid'];
            }
        }
    }

    /**
     * Populate mapping of old-uid => new-uid indexed with old-uid
     *
     * These are most likely to be the original files
     *
     * @since 1.2.0
     *
     * @return void
     */
    protected function populateUidMap()
    {
        $documents = $this->getDocumentsInMigratedFolder();
        foreach ($documents as $document) {
            $document['uid'] = (int)$document['uid'];
            if ($document['uid'] !== $this->sha1Map[$document['sha1']]) {
                $this->uidMap[$document['uid']] = (int)$this->sha1Map[$document['sha1']];
            }
        }
    }

    /**
     * Populate mapping of sha1 indexed array of lowest uid values
     *
     * These are most likely to be the original files
     *
     * @since 1.2.0
     *
     * @return void
     */
    protected function populateSha1MapFromMigratedFolder()
    {
        $sha1List = $this->getSha1ListForMigratedFolder();
        foreach ($sha1List as $row) {
            if (!isset($this->sha1Map[$row['sha1']])
                || (isset($this->sha1Map[$row['sha1']]) && $this->sha1Map[$row['sha1']] > $row['uid'])
            ) {
                $this->sha1Map[$row['sha1']] = (int)$row['uid'];
            }
        }
    }
}
