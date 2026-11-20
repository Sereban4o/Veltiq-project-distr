<?php

/**
 * SPDX-FileCopyrightText: 2018 Robin Appelman <robin@icewind.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Icewind\SMB;

interface IOptions
{
    public const PROTOCOL_NT1 = 'NT1';

    public const PROTOCOL_SMB2 = 'SMB2';

    public const PROTOCOL_SMB2_02 = 'SMB2_02';

    public const PROTOCOL_SMB2_22 = 'SMB2_22';

    public const PROTOCOL_SMB2_24 = 'SMB2_24';

    public const PROTOCOL_SMB3 = 'SMB3';

    public const PROTOCOL_SMB3_00 = 'SMB3_00';

    public const PROTOCOL_SMB3_02 = 'SMB3_02';

    public const PROTOCOL_SMB3_10 = 'SMB3_10';

    public const PROTOCOL_SMB3_11 = 'SMB3_11';

    public function getTimeout(): int;

    public function getMinProtocol(): ?string;

    public function getMaxProtocol(): ?string;
}
