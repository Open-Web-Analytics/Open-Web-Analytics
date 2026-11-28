<?php
namespace OWA\Module\Base\Controller;

/**
 * A user's application passwords: id, name, access and last use.
 *
 *   cmd=app-password-list user=<user id>
 *
 * The id is the token's first twelve characters, which is what
 * cmd=app-password-revoke takes. There is no secret in this output.
 */
class AppPasswordListCli extends AppPasswordsCli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Lists a user\'s application passwords, with the id app-password-revoke takes.',
            'arguments'   => [
                'user' => [
                    'type'        => 'user',
                    'required'    => true,
                    'description' => 'The user whose passwords to list.',
                ],
            ],
        ];
    }

    public function action() {

        $user_id = (string) $this->param( 'user' );

        $rows = \OWA\Module\Base\Classes\AppPasswords::forUser( $user_id );

        if ( ! $rows ) {

            $this->write( [ sprintf( '%s has no application passwords.', $user_id ) ] );

            return;
        }

        $lines = [ sprintf( '%-12s  %-5s  %-16s  %-16s  %-15s  %s',
            'id', 'scope', 'created', 'last used', 'from', 'name' ) ];

        foreach ( $rows as $row ) {

            $lines[] = sprintf( '%-12s  %-5s  %-16s  %-16s  %-15s  %s',
                $row['lookup_id'],
                $row['scope'],
                date( 'Y-m-d H:i', (int) $row['created_at'] ),
                $row['last_used_at'] ? date( 'Y-m-d H:i', (int) $row['last_used_at'] ) : 'never',
                (string) $row['last_used_ip'],
                $row['name'] );
        }

        $this->write( $lines );
    }
}
