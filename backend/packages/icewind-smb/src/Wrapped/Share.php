<?php

/**
 * SPDX-FileCopyrightText: 2014 Robin Appelman <robin@icewind.nl>
 * SPDX-License-Identifier: MIT
 */

namespace Icewind\SMB\Wrapped;

use Icewind\SMB\AbstractShare;
use Icewind\SMB\ACL;
use Icewind\SMB\Exception\AlreadyExistsException;
use Icewind\SMB\Exception\AuthenticationException;
use Icewind\SMB\Exception\ConnectException;
use Icewind\SMB\Exception\ConnectionException;
use Icewind\SMB\Exception\DependencyException;
use Icewind\SMB\Exception\Exception;
use Icewind\SMB\Exception\FileInUseException;
use Icewind\SMB\Exception\InvalidHostException;
use Icewind\SMB\Exception\InvalidRequestException;
use Icewind\SMB\Exception\InvalidTypeException;
use Icewind\SMB\Exception\NotFoundException;
use Icewind\SMB\IFileInfo;
use Icewind\SMB\INotifyHandler;
use Icewind\SMB\IServer;
use Icewind\SMB\ISystem;
use Icewind\Streams\CallbackWrapper;

final class Share extends AbstractShare
{
    /**
     * @var IServer $server
     */
    private $server;

    /**
     * @var string $name
     */
    private $name;

    /**
     * @var Connection|null $connection
     */
    public $connection = null;

    /**
     * @var Parser
     */
    protected $parser;

    /**
     * @var ISystem
     */
    private $system;

    public const MODE_MAP = [
        FileInfo::MODE_READONLY => 'r',
        FileInfo::MODE_HIDDEN   => 'h',
        FileInfo::MODE_ARCHIVE  => 'a',
        FileInfo::MODE_SYSTEM   => 's',
    ];

    public const EXEC_CMD = 'exec';

    /**
     * @param IServer $server
     * @param string $name
     * @param ISystem $system
     */
    public function __construct(IServer $server, string $name, ISystem $system)
    {
        parent::__construct();
        $this->server = $server;
        $this->name = $name;
        $this->system = $system;
        $this->parser = new Parser('UTC');
    }

    private function getAuthFileArgument(): string
    {
        if ($this->server->getAuth()->getUsername() !== null) {
            return '--authentication-file=' . $this->system->getFD(3);
        }

        return '';

    }

    protected function getConnection(): Connection
    {
        $maxProtocol = $this->server->getOptions()->getMaxProtocol();
        $minProtocol = $this->server->getOptions()->getMinProtocol();
        $smbClient = $this->system->getSmbclientPath();
        $stdBuf = $this->system->getStdBufPath();
        if ($smbClient === null) {
            throw new Exception("Backend not available");
        }
        $command = sprintf(
            '%s %s%s -t %s %s %s %s %s %s',
            self::EXEC_CMD,
            ($stdBuf !== null) ? $stdBuf . ' -o0 ' : '',
            $smbClient,
            $this->server->getOptions()->getTimeout(),
            $this->getAuthFileArgument(),
            $this->server->getAuth()->getExtraCommandLineArguments(),
            ($maxProtocol !== null) ? "--option='client max protocol=" . $maxProtocol . "'" : "",
            ($minProtocol !== null) ? "--option='client min protocol=" . $minProtocol . "'" : "",
            escapeshellarg('//' . $this->server->getHost() . '/' . $this->name),
        );
        $connection = new Connection($command, $this->parser);
        $connection->writeAuthentication($this->server->getAuth()->getUsername(), $this->server->getAuth()->getPassword());
        $connection->connect();
        if (!$connection->isValid()) {
            throw new ConnectionException((string)$connection->readLine());
        }
        // some versions of smbclient add a help message in first of the first prompt
        $connection->clearTillPrompt();

        return $connection;
    }

    /**
     * @throws ConnectionException
     * @throws AuthenticationException
     * @throws InvalidHostException
     * @psalm-assert Connection $this->connection
     */
    protected function connect(): Connection
    {
        if ($this->connection and $this->connection->isValid()) {
            return $this->connection;
        }
        $this->connection = $this->getConnection();

        return $this->connection;
    }

    /**
     * @throws ConnectionException
     * @throws AuthenticationException
     * @throws InvalidHostException
     * @psalm-assert Connection $this->connection
     */
    protected function reconnect(): void
    {
        if ($this->connection === null) {
            $this->connect();
        } else {
            $this->connection->reconnect();
            if (!$this->connection->isValid()) {
                throw new ConnectionException();
            }
        }
    }

    /**
     * Get the name of the share
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    protected function simpleCommand(string $command, string $path): bool
    {
        $escapedPath = $this->escapePath($path);
        $cmd = $command . ' ' . $escapedPath;
        $output = $this->execute($cmd);

        return $this->parseOutput($output, $path);
    }

    /**
     * List the content of a remote folder
     *
     * @param string $path
     *
     * @throws NotFoundException
     * @throws InvalidTypeException
     * @return IFileInfo[]
     */
    public function dir(string $path): array
    {
        $escapedPath = $this->escapePath($path);
        $output = $this->execute('cd ' . $escapedPath);
        //check output for errors
        $this->parseOutput($output, $path);
        $output = $this->execute('dir');

        $this->execute('cd /');

        return $this->parser->parseDir($output, $path, function (string $path) {
            return $this->getAcls($path);
        });
    }

    /**
     * @param string $path
     * @return IFileInfo
     */
    public function stat(string $path): IFileInfo
    {
        // some windows server setups don't seem to like the allinfo command
        // use the dir command instead to get the file info where possible
        if ($path !== "" && $path !== "/") {
            $parent = dirname($path);
            $dir = $this->dir($parent);
            $file = array_values(array_filter($dir, function (IFileInfo $info) use ($path) {
                return $info->getPath() === $path;
            }));
            if ($file) {
                return $file[0];
            }
        }

        $escapedPath = $this->escapePath($path);
        $output = $this->execute('allinfo ' . $escapedPath);
        // Windows and non Windows Fileserver may respond different
        // to the allinfo command for directories. If the result is a single
        // line = error line, redo it with a different allinfo parameter
        if ($escapedPath == '""' && count($output) < 2) {
            $output = $this->execute('allinfo ' . '"."');
        }
        if (count($output) < 3) {
            $this->parseOutput($output, $path);
        }
        $stat = $this->parser->parseStat($output);

        return new FileInfo($path, basename($path), $stat['size'], $stat['mtime'], $stat['mode'], function () use ($path) {
            return $this->getAcls($path);
        });
    }

    /**
     * Create a folder on the share
     *
     * @param string $path
     *
     * @throws NotFoundException
     * @throws AlreadyExistsException
     * @return bool
     */
    public function mkdir(string $path): bool
    {
        return $this->simpleCommand('mkdir', $path);
    }

    /**
     * Remove a folder on the share
     *
     * @param string $path
     *
     * @throws NotFoundException
     * @throws InvalidTypeException
     * @return bool
     */
    public function rmdir(string $path): bool
    {
        return $this->simpleCommand('rmdir', $path);
    }

    /**
     * Delete a file on the share
     *
     * @param string $path
     * @param bool $secondTry
     * @throws InvalidTypeException
     * @throws NotFoundException
     * @throws \Exception
     * @return bool
     */
    public function del(string $path, bool $secondTry = false): bool
    {
        //del return a file not found error when trying to delete a folder
        //we catch it so we can check if $path doesn't exist or is of invalid type
        try {
            return $this->simpleCommand('del', $path);
        } catch (NotFoundException $e) {
            //no need to do anything with the result, we just check if this throws the not found error
            try {
                $this->simpleCommand('ls', $path);
            } catch (NotFoundException $e2) {
                throw $e;
            } catch (\Exception $e2) {
                throw new InvalidTypeException($path);
            }

            throw $e;
        } catch (FileInUseException $e) {
            if ($secondTry) {
                throw $e;
            }
            $this->reconnect();

            return $this->del($path, true);
        }
    }

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
    public function rename(string $from, string $to): bool
    {
        $path1 = $this->escapePath($from);
        $path2 = $this->escapePath($to);
        $output = $this->execute('rename ' . $path1 . ' ' . $path2);

        return $this->parseOutput($output, $to);
    }

    /**
     * Upload a local file
     *
     * @param string $source local file
     * @param string $target remove file
     *
     * @throws NotFoundException
     * @throws InvalidTypeException
     * @return bool
     */
    public function put(string $source, string $target): bool
    {
        $path1 = $this->escapeLocalPath($source); //first path is local, needs different escaping
        $path2 = $this->escapePath($target);
        $output = $this->execute('put ' . $path1 . ' ' . $path2);

        return $this->parseOutput($output, $target);
    }

    /**
     * Download a remote file
     *
     * @param string $source remove file
     * @param string $target local file
     *
     * @throws NotFoundException
     * @throws InvalidTypeException
     * @return bool
     */
    public function get(string $source, string $target): bool
    {
        $path1 = $this->escapePath($source);
        $path2 = $this->escapeLocalPath($target); //second path is local, needs different escaping
        $output = $this->execute('get ' . $path1 . ' ' . $path2);

        return $this->parseOutput($output, $source);
    }

    /**
     * Open a readable stream to a remote file
     *
     * @param string $source
     *
     * @throws NotFoundException
     * @throws InvalidTypeException
     * @return resource a read only stream with the contents of the remote file
     */
    public function read(string $source)
    {
        $source = $this->escapePath($source);
        // since returned stream is closed by the caller we need to create a new instance
        // since we can't re-use the same file descriptor over multiple calls
        $connection = $this->getConnection();
        stream_set_blocking($connection->getOutputStream(), false);

        $connection->write('get ' . $source . ' ' . $this->system->getFD(5));
        $connection->write('exit');
        $fh = $connection->getFileOutputStream();
        $fh = CallbackWrapper::wrap($fh, function () use ($connection) {
            $connection->write('');
        });
        if (!is_resource($fh)) {
            throw new Exception("Failed to wrap file output");
        }

        return $fh;
    }

    /**
     * Open a writable stream to a remote file
     *
     * @param string $target
     *
     * @throws NotFoundException
     * @throws InvalidTypeException
     * @return resource a write only stream to upload a remote file
     */
    public function write(string $target)
    {
        $target = $this->escapePath($target);
        // since returned stream is closed by the caller we need to create a new instance
        // since we can't re-use the same file descriptor over multiple calls
        $connection = $this->getConnection();

        $fh = $connection->getFileInputStream();
        $connection->write('put ' . $this->system->getFD(4) . ' ' . $target);
        $connection->write('exit');

        // use a close callback to ensure the upload is finished before continuing
        // this also serves as a way to keep the connection in scope
        $stream = CallbackWrapper::wrap($fh, function () use ($connection) {
            $connection->write('');
        }, null, function () use ($connection) {
            $connection->close(false); // dont terminate, give the upload some time
        });
        if (is_resource($stream)) {
            return $stream;
        }

        throw new InvalidRequestException($target);

    }

    /**
     * Append to stream
     * Note: smbclient does not support this (Use php-libsmbclient)
     *
     * @param string $target
     *
     * @throws DependencyException
     */
    public function append(string $target)
    {
        throw new DependencyException('php-libsmbclient is required for append');
    }

    /**
     * @param string $path
     * @param int $mode a combination of FileInfo::MODE_READONLY, FileInfo::MODE_ARCHIVE, FileInfo::MODE_SYSTEM and FileInfo::MODE_HIDDEN, FileInfo::NORMAL
     * @return mixed
     */
    public function setMode(string $path, int $mode)
    {
        $modeString = '';
        foreach (self::MODE_MAP as $modeByte => $string) {
            if ($mode & $modeByte) {
                $modeString .= $string;
            }
        }
        $path = $this->escapePath($path);

        // first reset the mode to normal
        $cmd = 'setmode ' . $path . ' -rsha';
        $output = $this->execute($cmd);
        $this->parseOutput($output, $path);

        if ($mode !== FileInfo::MODE_NORMAL) {
            // then set the modes we want
            $cmd = 'setmode ' . $path . ' ' . $modeString;
            $output = $this->execute($cmd);

            return $this->parseOutput($output, $path);
        }

        return true;

    }

    /**
     * @param string $path
     * @throws ConnectionException
     * @throws DependencyException
     * @return INotifyHandler
     */
    public function notify(string $path): INotifyHandler
    {
        if ($this->system->getStdBufPath() == null) { //stdbuf is required to disable smbclient's output buffering
            throw new DependencyException('stdbuf is required for usage of the notify command');
        }
        $connection = $this->getConnection(); // use a fresh connection since the notify command blocks the process
        $command = 'notify ' . $this->escapePath($path);
        $connection->write($command . PHP_EOL);

        return new NotifyHandler($connection, $path);
    }

    /**
     * @param string $command
     * @return string[]
     */
    protected function execute(string $command): array
    {
        $this->connect()->write($command);

        return $this->connect()->read();
    }

    /**
     * check output for errors
     *
     * @param string[] $lines
     * @param string $path
     *
     * @throws AlreadyExistsException
     * @throws \Icewind\SMB\Exception\AccessDeniedException
     * @throws \Icewind\SMB\Exception\NotEmptyException
     * @throws InvalidTypeException
     * @throws \Icewind\SMB\Exception\Exception
     * @throws NotFoundException
     * @return bool
     */
    protected function parseOutput(array $lines, string $path = ''): bool
    {
        if (count($lines) === 0) {
            return true;
        }
        $this->parser->checkForError($lines, $path);

    }

    /**
     * @param string $string
     * @return string
     */
    protected function escape(string $string): string
    {
        return escapeshellarg($string);
    }

    /**
     * @param string $path
     * @return string
     */
    protected function escapePath(string $path): string
    {
        $this->verifyPath($path);
        if ($path === '/') {
            $path = '';
        }
        $path = str_replace('/', '\\', $path);
        $path = str_replace('"', '^"', $path);
        $path = ltrim($path, '\\');

        return '"' . $path . '"';
    }

    /**
     * @param string $path
     * @return string
     */
    protected function escapeLocalPath(string $path): string
    {
        $path = str_replace('"', '\"', $path);

        return '"' . $path . '"';
    }

    /**
     * @param string $path
     * @throws ConnectionException
     * @throws ConnectException
     * @return ACL[]
     */
    protected function getAcls(string $path): array
    {
        $commandPath = $this->system->getSmbcAclsPath();
        if ($commandPath === null) {
            return [];
        }

        $command = sprintf(
            '%s %s %s %s/%s %s',
            $commandPath,
            $this->getAuthFileArgument(),
            $this->server->getAuth()->getExtraCommandLineArguments(),
            escapeshellarg('//' . $this->server->getHost()),
            escapeshellarg($this->name),
            escapeshellarg($path),
        );
        $connection = new RawConnection($command);
        $connection->writeAuthentication($this->server->getAuth()->getUsername(), $this->server->getAuth()->getPassword());
        $connection->connect();
        if (!$connection->isValid()) {
            throw new ConnectionException((string)$connection->readLine());
        }

        $rawAcls = $connection->readAll();

        return $this->parser->parseACLs($rawAcls);
    }

    public function getServer(): IServer
    {
        return $this->server;
    }

    public function __destruct()
    {
        unset($this->connection);
    }
}
