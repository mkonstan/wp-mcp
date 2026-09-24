<?php
/**
 * A file stream that can be made to MISBEHAVE in the two exact ways the trace log's size cap has
 * to survive (1.1.1, sprint LOG+FLOOR round 2).
 *
 * WHY A STREAM WRAPPER AND NOT A MOCK. `wpmcp_trace_cap_file()` reads a tail, asks the descriptor
 * how big the file is, truncates it and writes the kept part back. The two defects round 2 closed
 * both live in the GAPS between those calls, and a gap cannot be reached from outside the
 * function - the only place a test can stand is inside the stream itself:
 *
 *   growOnStat()   appends bytes to the file the first time it is asked for its size AFTER a read.
 *                  That is the race exactly: on a host where `flock` is a no-op (NFS, some shared
 *                  hosting), another request can append an entry - WITH ITS TRACE ID ALREADY
 *                  HANDED TO A CALLER - between our tail read and our `ftruncate`, and the old
 *                  code would have discarded it. Appended through a SECOND handle, the way another
 *                  process would, so the size really moves on the inode rather than in a variable.
 *
 *   shortWrite()   accepts half of the first write and then refuses the rest, which is what a full
 *                  disk looks like. PHP's own `fwrite` loops over a userland `stream_write` while
 *                  it makes PROGRESS and stops when it returns 0 - MEASURED here: a 10-byte write
 *                  whose wrapper takes 5 and then returns 0 makes `fwrite` return 5. So a genuine
 *                  short write is observable, which is what makes the round-2 fix testable at all.
 *                  `recover: true` takes half and then behaves, which is the transient case a
 *                  retry is supposed to repair.
 *
 * THE INSTRUCTIONS ARE KEYED BY BACKING PATH, not held on the instance, because PHP constructs a
 * fresh wrapper object per `fopen` and the test has to arm the behaviour before that happens.
 *
 * `stream_lock()` RETURNS TRUE WITHOUT LOCKING, deliberately, and it is not laziness: that IS the
 * host this class exists to simulate. `flock` on NFS and on some shared hosts succeeds and
 * protects nothing, which is the whole premise of the race above.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

final class TraceStream
{
    public const PROTOCOL = 'wpmcptrace';

    /** path => bytes to append on the next stream_stat() that follows a read. */
    private static array $grow = [];

    /** path => bytes to append on EVERY stream_stat() that follows a read. */
    private static array $growForever = [];

    /** path => ['done' => bool, 'recover' => bool] */
    private static array $short = [];

    /**
     * PHP ASSIGNS THIS ITSELF, and it has to be declared.
     *
     * The stream layer sets `$context` on every wrapper instance it constructs. Since PHP 8.2 a
     * dynamic property on a class that is not `#[AllowDynamicProperties]` is DEPRECATED, and
     * phpunit.xml.dist has `failOnDeprecation="true"` - so without this line the unit tier is red,
     * and it is red only where deprecations are visible. It was green on this workstation and red on
     * all three CI legs above 8.1, because `bin/local-env.sh` runs PHP with `error_reporting=0` to
     * silence Local's imagick startup warning: the laptop cannot see a deprecation at all. Same
     * shape as the CRLF split RepoFile documents, from the other direction.
     *
     * @var resource|null
     */
    public $context;

    /** @var resource|false */
    private $handle = false;

    private string $path = '';

    private bool $hasRead = false;

    public static function register(): void
    {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    /** Forget every armed behaviour. Call in setUp(), so no test inherits another's stream. */
    public static function reset(): void
    {
        self::$grow        = [];
        self::$growForever = [];
        self::$short       = [];
    }

    /** The URL to open $path through this wrapper. */
    public static function url(string $path): string
    {
        return self::PROTOCOL . '://' . $path;
    }

    /**
     * Append $bytes to $path the first time its size is asked for after a read - i.e. inside the
     * window between the cap's tail read and its truncate.
     */
    public static function growOnStat(string $path, string $bytes): void
    {
        self::$grow[$path] = $bytes;
    }

    /**
     * Append $bytes on EVERY size request after a read, so the file can never be observed to hold
     * still. That is the host the cap must give up on rather than guess at.
     */
    public static function growOnStatForever(string $path, string $bytes): void
    {
        self::$growForever[$path] = $bytes;
    }

    /**
     * Make the first write to $path accept only half of what it is given.
     *
     * $recover false: every later write refuses too (a full disk).
     * $recover true:  later writes behave (an interrupted write a retry can finish).
     */
    public static function shortWrite(string $path, bool $recover = false): void
    {
        self::$short[$path] = ['done' => false, 'recover' => $recover];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->path   = substr($path, strlen(self::PROTOCOL . '://'));
        $this->handle = @fopen($this->path, $mode);

        return $this->handle !== false;
    }

    public function stream_read(int $count)
    {
        $this->hasRead = true;

        return fread($this->handle, $count);
    }

    public function stream_write(string $data)
    {
        $state = self::$short[$this->path] ?? null;

        if ($state !== null) {
            if (!$state['done']) {
                self::$short[$this->path]['done'] = true;

                $half = (int) floor(strlen($data) / 2);

                return $half > 0 ? fwrite($this->handle, substr($data, 0, $half)) : 0;
            }

            if (!$state['recover']) {
                return 0; // no progress: PHP stops looping and reports the short count
            }
        }

        return fwrite($this->handle, $data);
    }

    public function stream_stat()
    {
        $append = '';

        if ($this->hasRead) {
            if ((self::$grow[$this->path] ?? '') !== '') {
                $append = (string) self::$grow[$this->path];
                self::$grow[$this->path] = '';
            } elseif ((self::$growForever[$this->path] ?? '') !== '') {
                $append = (string) self::$growForever[$this->path];
            }
        }

        if ($append !== '') {
            // A SECOND HANDLE, because the point is that somebody ELSE wrote it.
            $other = @fopen($this->path, 'ab');

            if ($other !== false) {
                fwrite($other, $append);
                fclose($other);
            }
        }

        return fstat($this->handle);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell()
    {
        return ftell($this->handle);
    }

    public function stream_truncate(int $size): bool
    {
        return ftruncate($this->handle, $size);
    }

    public function stream_flush(): bool
    {
        return fflush($this->handle);
    }

    /** True without locking - see the class docblock: that is the host being simulated. */
    public function stream_lock(int $operation): bool
    {
        return true;
    }

    public function stream_close(): void
    {
        if ($this->handle !== false) {
            fclose($this->handle);
        }
    }
}
