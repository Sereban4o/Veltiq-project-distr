<?php

/**
 * SPDX-FileCopyrightText: 2014 Robin Appelman <robin@icewind.nl>
 * SPDX-License-Identifier: MIT
 */

namespace Icewind\SMB;

interface IFileInfo
{
    /*
     * Mappings of the DOS mode bits, as returned by smbc_getxattr() when the
     * attribute name "system.dos_attr.mode" (or "system.dos_attr.*" or
     * "system.*") is specified.
     */
    public const MODE_READONLY = 0x01;

    public const MODE_HIDDEN = 0x02;

    public const MODE_SYSTEM = 0x04;

    public const MODE_VOLUME_ID = 0x08;

    public const MODE_DIRECTORY = 0x10;

    public const MODE_ARCHIVE = 0x20;

    public const MODE_NORMAL = 0x80;

    public function getPath(): string;

    public function getName(): string;

    public function getSize(): int;

    public function getMTime(): int;

    public function isDirectory(): bool;

    public function isReadOnly(): bool;

    public function isHidden(): bool;

    public function isSystem(): bool;

    public function isArchived(): bool;

    /**
     * @return ACL[]
     */
    public function getAcls(): array;
}
