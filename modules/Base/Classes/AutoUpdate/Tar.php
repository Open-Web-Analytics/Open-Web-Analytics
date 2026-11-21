<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

/**
 * Unpack a release tarball: plain files and directories only, every path
 * inside the destination.
 *
 * Read here rather than with PharData, which refuses the Release Packager's
 * archives (their members are named ./..., and it cannot extract "."), and
 * which would make automatic updates need the phar extension. The archive
 * is GNU or POSIX tar: ustar names with their prefix, GNU long names ('L')
 * and pax path records ('x'). A link, device or any other entry is refused,
 * as is a path that is absolute or climbs out with "..": a release ships
 * neither, so either means the file is not one.
 */
class Tar {

    const BLOCK = 512;

    /**
     * @param  string $tar
     * @param  string $dest an empty directory
     * @return int files written
     * @throws \RuntimeException when the archive is unreadable or holds what a release does not
     */
    public static function extract( $tar, $dest ) {

        $in = @fopen( (string) $tar, 'rb' );

        if ( $in === false ) {

            throw new \RuntimeException( sprintf( 'Could not open %s.', $tar ) );
        }

        $dest  = rtrim( (string) $dest, '/' );
        $files = 0;
        $long  = null;

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

                    if ( $relative !== '' && ! is_dir( $dest . '/' . $relative ) && ! @mkdir( $dest . '/' . $relative, 0755, true ) ) {

                        throw new \RuntimeException( sprintf( 'Could not create %s.', $relative ) );
                    }

                    continue;
                }

                if ( $type !== '0' || $relative === '' ) {

                    throw new \RuntimeException( sprintf( 'The archive holds %s, which is not a plain file or directory.', $name ) );
                }

                self::write( $in, $size, $dest . '/' . $relative, self::octal( substr( $header, 100, 8 ) ) & 0777 );
                $files++;
            }

        } finally {

            fclose( $in );
        }
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
     * @param resource $in
     * @param int      $size
     * @param string   $path
     * @param int      $mode
     * @return void
     */
    private static function write( $in, $size, $path, $mode ) {

        $dir = dirname( $path );

        if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) ) {

            throw new \RuntimeException( sprintf( 'Could not create %s.', $dir ) );
        }

        $out = @fopen( $path, 'xb' );

        if ( $out === false ) {

            throw new \RuntimeException( sprintf( 'Could not create %s; the archive may name it twice.', $path ) );
        }

        try {

            $left = $size;

            while ( $left > 0 ) {

                $chunk = fread( $in, min( 1048576, $left ) );

                if ( $chunk === false || $chunk === '' ) {

                    throw new \RuntimeException( 'The archive ends inside a member.' );
                }

                if ( fwrite( $out, $chunk ) !== strlen( $chunk ) ) {

                    throw new \RuntimeException( sprintf( 'Writing %s failed; the disk may be full.', $path ) );
                }

                $left -= strlen( $chunk );
            }

        } finally {

            fclose( $out );
        }

        chmod( $path, $mode & 0755 );
        self::skipPadding( $in, $size );
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
