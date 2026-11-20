<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Robin Appelman <robin@icewind.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Icewind\SMB;

final class ACL
{
    public const TYPE_ALLOW = 0;

    public const TYPE_DENY = 1;

    public const MASK_READ = 0x0001;

    public const MASK_WRITE = 0x0002;

    public const MASK_EXECUTE = 0x00020;

    public const MASK_DELETE = 0x10000;

    public const FLAG_OBJECT_INHERIT = 0x1;

    public const FLAG_CONTAINER_INHERIT = 0x2;

    /** @var int */
    private $type;

    /** @var int */
    private $flags;

    /** @var int */
    private $mask;

    public function __construct(int $type, int $flags, int $mask)
    {
        $this->type = $type;
        $this->flags = $flags;
        $this->mask = $mask;
    }

    /**
     * Check if the acl allows a specific permissions
     *
     * Note that this does not take inherited acls into account
     *
     * @param int $mask one of the ACL::MASK_* constants
     * @return bool
     */
    public function allows(int $mask): bool
    {
        return $this->type === self::TYPE_ALLOW && ($this->mask & $mask) === $mask;
    }

    /**
     * Check if the acl allows a specific permissions
     *
     * Note that this does not take inherited acls into account
     *
     * @param int $mask one of the ACL::MASK_* constants
     * @return bool
     */
    public function denies(int $mask): bool
    {
        return $this->type === self::TYPE_DENY && ($this->mask & $mask) === $mask;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getFlags(): int
    {
        return $this->flags;
    }

    public function getMask(): int
    {
        return $this->mask;
    }
}
