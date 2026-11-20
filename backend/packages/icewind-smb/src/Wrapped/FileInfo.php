<?php

/**
 * SPDX-FileCopyrightText: 2014 Robin Appelman <robin@icewind.nl>
 * SPDX-License-Identifier: MIT
 */

namespace Icewind\SMB\Wrapped;

use Icewind\SMB\ACL;
use Icewind\SMB\IFileInfo;

final class FileInfo implements IFileInfo
{
    /** @var string */
    private $path;

    /** @var string */
    private $name;

    /** @var int */
    private $size;

    /** @var int */
    private $time;

    /** @var int */
    private $mode;

    /** @var callable(): ACL[] */
    private $aclCallback;

    /**
     * @param string $path
     * @param string $name
     * @param int $size
     * @param int $time
     * @param int $mode
     * @param callable(): ACL[] $aclCallback
     */
    public function __construct(string $path, string $name, int $size, int $time, int $mode, callable $aclCallback)
    {
        $this->path = $path;
        $this->name = $name;
        $this->size = $size;
        $this->time = $time;
        $this->mode = $mode;
        $this->aclCallback = $aclCallback;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getMTime(): int
    {
        return $this->time;
    }

    public function isDirectory(): bool
    {
        return (bool)($this->mode & IFileInfo::MODE_DIRECTORY);
    }

    public function isReadOnly(): bool
    {
        return (bool)($this->mode & IFileInfo::MODE_READONLY);
    }

    public function isHidden(): bool
    {
        return (bool)($this->mode & IFileInfo::MODE_HIDDEN);
    }

    public function isSystem(): bool
    {
        return (bool)($this->mode & IFileInfo::MODE_SYSTEM);
    }

    public function isArchived(): bool
    {
        return (bool)($this->mode & IFileInfo::MODE_ARCHIVE);
    }

    /**
     * @return ACL[]
     */
    public function getAcls(): array
    {
        return ($this->aclCallback)();
    }
}
