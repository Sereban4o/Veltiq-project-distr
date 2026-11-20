<?php

return [
    /*
    |--------------------------------------------------------------------------
    | File storage root
    |--------------------------------------------------------------------------
    |
    | Directory shown by the file manager. The admin panel can override this
    | value at runtime (stored in the `settings` table). Paths are always
    | resolved and confined to this root.
    |
    */
    'path' => env('STORAGE_BROWSER_PATH', storage_path('app/files')),

    /*
    |--------------------------------------------------------------------------
    | Network storage connection (preferred over the legacy local path)
    |--------------------------------------------------------------------------
    |
    | When an address is set the file manager connects directly to the remote
    | storage over smb/sftp/ftp/webdav. Credentials are separate from the
    | address and are normally stored (encrypted) in the settings table.
    */
    'address' => env('STORAGE_BROWSER_ADDRESS'),
    'username' => env('STORAGE_BROWSER_USERNAME'),
    'password' => env('STORAGE_BROWSER_PASSWORD'),
    'domain' => env('STORAGE_BROWSER_DOMAIN'),
    'connect_timeout' => (int) env('STORAGE_BROWSER_CONNECT_TIMEOUT', 10),
    'read_timeout' => (int) env('STORAGE_BROWSER_READ_TIMEOUT', 60),
    'verify_tls' => (bool) env('STORAGE_BROWSER_VERIFY_TLS', true),
    'ca_bundle' => env('STORAGE_BROWSER_CA_BUNDLE'),
    'smb_min_protocol' => env('STORAGE_BROWSER_SMB_MIN_PROTOCOL', 'SMB2'),
    'smb_max_protocol' => env('STORAGE_BROWSER_SMB_MAX_PROTOCOL', 'SMB3'),

    // Largest text file that can be opened in the editor (bytes).
    'max_edit_size' => (int) env('STORAGE_BROWSER_MAX_EDIT_SIZE', 2 * 1024 * 1024),

    // Extensions treated as text even when the MIME guess is ambiguous.
    'text_extensions' => [
        'txt', 'md', 'markdown', 'log', 'csv', 'tsv', 'json', 'xml', 'yml', 'yaml',
        'ini', 'conf', 'cfg', 'env', 'html', 'htm', 'css', 'scss', 'less', 'js',
        'ts', 'tsx', 'jsx', 'php', 'py', 'rb', 'go', 'rs', 'java', 'kt', 'kts',
        'c', 'h', 'cpp', 'hpp', 'cs', 'sh', 'bash', 'ps1', 'bat', 'sql', 'svg',
    ],
];
