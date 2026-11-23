<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

/**
 * Read a release tarball: plain files and directories only, every path
 * inside the archive's root, no name twice.
 *
 * Read here rather than with PharData, which refuses the Release Packager's
 * archives (their members are named ./..., and it cannot extract "."), and
 * which would make automatic updates need the phar extension. The archive
 * is GNU or POSIX tar: ustar names with their prefix, GNU long names ('L')
 * and pax path records ('x'). A link, device or any other entry is refused,
 * as is a path that is absolute or climbs out with "..", and a name given
 * twice: a release ships none of them, so any means the file is not one.
 *
 * Every reader takes a path or an open handle. A handle is read from where
 * it is, so the caller rewinds it: the installer reads the private copy of
 * the tarball it verified (Downloads::open()), never the file again.
 *
 * Plain PHP with no other OWA class: cli.php requires it on its own before
 * OWA loads, to put the installed release back (updater.php `preboot`).
 */
class Tar {

    const BLOCK = 512;

    /** The most a member read whole (member()) may hold. */
    const MEMBER_MAX = 1048576;

    /**
     * Visit every directory and file, in archive order. $visit gets the
     * path relative to the root, 'dir' or 'file', the mode, the size, and
     * for a file a reader: call it with a handle to copy the data there, or
     * not at all to skip it.
     *
     * @param  string|resource                                                     $tar
     * @param  callable(string, string, int, int, (callable(resource): void)|null): void $visit
     * @return int files
     * @throws \RuntimeException when the archive is unreadable or holds what a release does not
     */
    public static function each( $tar, callable $visit ) {

        $own = ! is_resource( $tar );
        $in  = $own ? @fopen( (string) $tar, 'rb' ) : $tar;

        if ( $in === false ) {

            throw new \RuntimeException( sprintf( 'Could not open %s.', $tar ) );
        }

        $files = 0;
        $long  = null;
        $seen  = [];

        try {

            while ( true ) {

                $header = fread( $in, self::BLOCK );

                if ( $header === false || strlen( $header ) < self::BLOCK ) {

                    throw new \RuntimeException( 'The archive ends before its end-of-archive marker.' );
                }

                if ( $header === str_repeat( "\0", self::BLOCK ) ) {

                    return $files;
                }

                self::checksum( $header );

                $type = $header[156] === "\0" ? '0' : $header[156];
                $size = self::octal( substr( $header, 124, 12 ) );
                $name = $long ?? self::name( $header );
                $long = null;

                // A long name or a pax record is read whole: never one larger than a release has.
                if ( ( $type === 'L' || $type === 'x' || $type === 'g' ) && $size > self::MEMBER_MAX ) {

                    throw new \RuntimeException( sprintf( 'The archive has a header record of %d bytes, larger than any release has.', $size ) );
                }

                if ( $type === 'L' || $type === 'x' ) {

                    $data = self::read( $in, $size );
                    $long = $type === 'L' ? rtrim( $data, "\0" ) : self::paxPath( $data );

                    continue;
                }

                if ( $type === 'g' ) {

                    self::read( $in, $size );

                    continue;
                }

                $relative = self::relative( $name );

                if ( $type === '5' ) {

                    if ( $relative !== '' ) {

                        $visit( $relative, 'dir', self::octal( substr( $header, 100, 8 ) ) & 0777, 0, null );
                    }

                    continue;
                }

                if ( $type !== '0' || $relative === '' ) {

                    throw new \RuntimeException( sprintf( 'The archive holds %s, which is not a plain file or directory.', $name ) );
                }

                if ( isset( $seen[ $relative ] ) ) {

                    throw new \RuntimeException( sprintf( 'The archive holds %s twice.', $relative ) );
                }

                $seen[ $relative ] = true;
                $done              = false;
                $reader            = static function ( $out ) use ( $in, $size, $relative, &$done ) {

                    self::copy( $in, $out, $size, $relative );
                    $done = true;
                };

                $visit( $relative, 'file', self::octal( substr( $header, 100, 8 ) ) & 0777, $size, $reader );

                if ( ! $done ) {

                    self::skip( $in, $size );

                } else {

                    self::skipPadding( $in, $size );
                }

                $files++;
            }

        } finally {

            if ( $own ) {

                fclose( $in );
            }
        }
    }

    /**
     * Unpack into $dest, an empty directory. Modes keep at most 0755.
     *
     * @param  string|resource $tar
     * @param  string          $dest
     * @return int files written
     * @throws \RuntimeException
     */
    public static function extract( $tar, $dest ) {

        $dest = rtrim( (string) $dest, '/' );

        return self::each( $tar, static function ( $rel, $type, $mode, $size, $read ) use ( $dest ) {

            $path = $dest . '/' . $rel;
            $dir  = $type === 'dir' ? $path : dirname( $path );

            if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) ) {

                throw new \RuntimeException( sprintf( 'Could not create %s.', $dir ) );
            }

            if ( $type === 'dir' ) {

                return;
            }

            $out = @fopen( $path, 'xb' );

            if ( $out === false ) {

                throw new \RuntimeException( sprintf( 'Could not create %s.', $path ) );
            }

            try {

                $read( $out );

            } finally {

                fclose( $out );
            }

            chmod( $path, $mode & 0755 );
        } );
    }

    /**
     * Every file's path, relative to the root, checked as each() checks.
     *
     * @param  string|resource $tar
     * @return list<string>
     * @throws \RuntimeException
     */
    public static function files( $tar ) {

        $out = [];

        self::each( $tar, static function ( $rel, $type ) use ( &$out ) {

            if ( $type === 'file' ) {

                $out[] = $rel;
            }
        } );

        return $out;
    }

    /**
     * One file's contents, at most MEMBER_MAX; null when the archive has no
     * such file. The whole archive is read, so it is checked whole.
     *
     * @param  string|resource $tar
     * @param  string          $name relative to the root, as each() gives it
     * @return string|null
     * @throws \RuntimeException
     */
    public static function member( $tar, $name ) {

        $found = null;

        self::each( $tar, static function ( $rel, $type, $mode, $size, $read ) use ( $name, &$found ) {

            if ( $type !== 'file' || $rel !== $name ) {

                return;
            }

            if ( $size > self::MEMBER_MAX ) {

                throw new \RuntimeException( sprintf( '%s in the archive is larger than %d bytes.', $name, self::MEMBER_MAX ) );
            }

            $h = fopen( 'php://memory', 'w+b' );
            $read( $h );
            rewind( $h );
            $found = (string) stream_get_contents( $h );
            fclose( $h );
        } );

        return $found;
    }

    /**
     * A member's path relative to the archive's root, './' and trailing '/'
     * removed; '' for the root itself.
     *
     * @param  string $name
     * @return string
     * @throws \RuntimeException for an absolute path or one with ".."
     */
    public static function relative( $name ) {

        $name = (string) $name;

        if ( $name === '' || $name[0] === '/' || str_contains( $name, "\0" ) || str_contains( $name, '\\' ) ) {

            throw new \RuntimeException( sprintf( 'The archive holds an unsafe path: %s', $name ) );
        }

        $parts = [];

        foreach ( explode( '/', $name ) as $part ) {

            if ( $part === '' || $part === '.' ) {

                continue;
            }

            if ( $part === '..' ) {

                throw new \RuntimeException( sprintf( 'The archive holds an unsafe path: %s', $name ) );
            }

            $parts[] = $part;
        }

        return implode( '/', $parts );
    }

    /**
     * @param  string $header
     * @return string
     */
    private static function name( $header ) {

        $name   = rtrim( substr( $header, 0, 100 ), "\0" );
        $prefix = substr( $header, 257, 6 ) === "ustar\0" ? rtrim( substr( $header, 345, 155 ), "\0" ) : '';

        return $prefix !== '' ? $prefix . '/' . $name : $name;
    }

    /**
     * The path record of a pax extended header, or null.
     *
     * @param  string $data "<length> <key>=<value>\n" records
     * @return string|null
     */
    private static function paxPath( $data ) {

        $offset = 0;

        while ( $offset < strlen( $data ) && preg_match( '/^(\d+) /', substr( $data, $offset, 22 ), $m ) ) {

            $record = substr( $data, $offset + strlen( $m[0] ), (int) $m[1] - strlen( $m[0] ) - 1 );
            $offset += (int) $m[1];

            if ( str_starts_with( $record, 'path=' ) ) {

                return substr( $record, 5 );
            }

            if ( (int) $m[1] <= 0 ) {

                break;
            }
        }

        return null;
    }

    /**
     * @param  string $field
     * @return int
     */
    private static function octal( $field ) {

        $field = trim( (string) $field, "\0 " );

        if ( $field !== '' && ! preg_match( '/^[0-7]+$/', $field ) ) {

            throw new \RuntimeException( 'The archive has a malformed header.' );
        }

        return $field === '' ? 0 : (int) octdec( $field );
    }

    /**
     * @param  string $header
     * @return void
     */
    private static function checksum( $header ) {

        $sum = 0;

        for ( $i = 0; $i < self::BLOCK; $i++ ) {

            $sum += ( $i >= 148 && $i < 156 ) ? 32 : ord( $header[ $i ] );
        }

        if ( $sum !== self::octal( substr( $header, 148, 8 ) ) ) {

            throw new \RuntimeException( 'The archive has a header whose checksum does not match.' );
        }
    }

    /**
     * A member's data, padding consumed.
     *
     * @param  resource $in
     * @param  int      $size
     * @return string
     */
    private static function read( $in, $size ) {

        $data = $size > 0 ? (string) fread( $in, $size ) : '';

        if ( strlen( $data ) !== $size ) {

            throw new \RuntimeException( 'The archive ends inside a member.' );
        }

        self::skipPadding( $in, $size );

        return $data;
    }

    /**
     * A member's data, from the archive to $out.
     *
     * @param resource $in
     * @param resource $out
     * @param int      $size
     * @param string   $name
     * @return void
     */
    private static function copy( $in, $out, $size, $name ) {

        $left = $size;

        while ( $left > 0 ) {

            $chunk = fread( $in, min( 1048576, $left ) );

            if ( $chunk === false || $chunk === '' ) {

                throw new \RuntimeException( 'The archive ends inside a member.' );
            }

            if ( fwrite( $out, $chunk ) !== strlen( $chunk ) ) {

                throw new \RuntimeException( sprintf( 'Writing %s failed; the disk may be full.', $name ) );
            }

            $left -= strlen( $chunk );
        }
    }

    /**
     * Pass over a member's data and its padding.
     *
     * @param resource $in
     * @param int      $size
     * @return void
     */
    private static function skip( $in, $size ) {

        $left = $size + ( self::BLOCK - $size % self::BLOCK ) % self::BLOCK;

        while ( $left > 0 ) {

            $chunk = fread( $in, min( 1048576, $left ) );

            if ( $chunk === false || $chunk === '' ) {

                throw new \RuntimeException( 'The archive ends inside a member.' );
            }

            $left -= strlen( $chunk );
        }
    }

    /**
     * @param resource $in
     * @param int      $size
     * @return void
     */
    private static function skipPadding( $in, $size ) {

        $pad = ( self::BLOCK - $size % self::BLOCK ) % self::BLOCK;

        if ( $pad && strlen( (string) fread( $in, $pad ) ) !== $pad ) {

            throw new \RuntimeException( 'The archive ends inside a member.' );
        }
    }
}
