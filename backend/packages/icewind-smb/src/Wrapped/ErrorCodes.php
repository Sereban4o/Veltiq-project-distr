<?php

/**
 * SPDX-FileCopyrightText: 2014 Robin Appelman <robin@icewind.nl>
 * SPDX-License-Identifier: MIT
 */

namespace Icewind\SMB\Wrapped;

final class ErrorCodes
{
    /**
     * connection errors
     */
    public const LogonFailure = 'NT_STATUS_LOGON_FAILURE';

    public const BadHostName = 'NT_STATUS_BAD_NETWORK_NAME';

    public const Unsuccessful = 'NT_STATUS_UNSUCCESSFUL';

    public const ConnectionRefused = 'NT_STATUS_CONNECTION_REFUSED';

    public const NoLogonServers = 'NT_STATUS_NO_LOGON_SERVERS';

    public const PathNotFound = 'NT_STATUS_OBJECT_PATH_NOT_FOUND';

    public const NoSuchFile = 'NT_STATUS_NO_SUCH_FILE';

    public const ObjectNotFound = 'NT_STATUS_OBJECT_NAME_NOT_FOUND';

    public const NameCollision = 'NT_STATUS_OBJECT_NAME_COLLISION';

    public const AccessDenied = 'NT_STATUS_ACCESS_DENIED';

    public const DirectoryNotEmpty = 'NT_STATUS_DIRECTORY_NOT_EMPTY';

    public const FileIsADirectory = 'NT_STATUS_FILE_IS_A_DIRECTORY';

    public const NotADirectory = 'NT_STATUS_NOT_A_DIRECTORY';

    public const SharingViolation = 'NT_STATUS_SHARING_VIOLATION';

    public const InvalidParameter = 'NT_STATUS_INVALID_PARAMETER';

    public const RevisionMismatch = 'NT_STATUS_REVISION_MISMATCH';
}
