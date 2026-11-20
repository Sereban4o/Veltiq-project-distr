<?php

/**
 * SPDX-FileCopyrightText: 2014 Robin Appelman <robin@icewind.nl>
 * SPDX-License-Identifier: MIT
 */

namespace Icewind\SMB;

use Icewind\SMB\Exception\AlreadyExistsException;
use Icewind\SMB\Exception\InvalidRequestException;
use Icewind\SMB\Exception\InvalidTypeException;
use Icewind\SMB\Exception\NotFoundException;

interface IShare
{
    /**
     * Get the name of the share
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Download a remote file
     *
     * @param string $source remote file
     * @param string $target local file
     *
     * @throws NotFoundException
     * @throws InvalidTypeException
     * @return bool
     */
    public function get(string $source, string $target): bool;

    /**
     * Upload a local file
     *
     * @param string $source local file
     * @param string $target remote file
     *
     * @throws NotFoundException
     * @throws InvalidTypeException
     * @return bool
     */
    public function put(string $source, string $target): bool;

    /**
     * Open a readable stream to a remote file
     *
     * @param string $source
     *
     * @throws NotFoundException
     * @throws InvalidTypeException
     * @return resource a read only stream with the contents of the remote file
     */
    public function read(string $source);

    /**
     * Open a writable stream to a remote file
     * Note: This method will truncate the file to 0bytes
     *
     * @param string $target
     *
     * @throws NotFoundException
     * @throws InvalidTypeException
     * @return resource a write only stream to upload a remote file
     */
    public function write(string $target);

    /**
     * Open a writable stream to a remote file and set the cursor to the end of the file
     *
     * @param string $target
     *
     * @throws NotFoundException
     * @throws InvalidTypeException
     * @throws InvalidRequestException
     * @return resource a write only stream to upload a remote file
     */
    public function append(string $target);

    /**
     * Rename a remote file
     *
     * @param string $from
     * @param string $to
     *
     * @throws NotFoundException
     * @throws AlreadyExistsException
     * @return bool
     */
    public function rename(string $from, string $to): bool;

    /**
     * Delete a file on the share
     *
     * @param string $path
     *
     * @throws NotFoundException
     * @throws InvalidTypeException
     * @return bool
     */
    public function del(string $path): bool;

    /**
     * List the content of a remote folder
     *
     * @param string $path
     *
     * @throws NotFoundException
     * @throws InvalidTypeException
     * @return IFileInfo[]
     */
    public function dir(string $path): array;

    /**
     * @param string $path
     *
     * @throws NotFoundException
     * @return IFileInfo
     */
    public function stat(string $path): IFileInfo;

    /**
     * Create a folder on the share
     *
     * @param string $path
     *
     * @throws NotFoundException
     * @throws AlreadyExistsException
     * @return bool
     */
    public function mkdir(string $path): bool;

    /**
     * Remove a folder on the share
     *
     * @param string $path
     *
     * @throws NotFoundException
     * @throws InvalidTypeException
     * @return bool
     */
    public function rmdir(string $path): bool;

    /**
     * @param string $path
     * @param int $mode a combination of FileInfo::MODE_READONLY, FileInfo::MODE_ARCHIVE, FileInfo::MODE_SYSTEM and FileInfo::MODE_HIDDEN, FileInfo::NORMAL
     * @return mixed
     */
    public function setMode(string $path, int $mode);

    /**
     * @param string $path
     * @return INotifyHandler
     */
    public function notify(string $path);

    /**
     * Get the IServer instance for this share
     *
     * @return IServer
     */
    public function getServer(): IServer;
}
