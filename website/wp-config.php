<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'wordpress' );

/** Database username */
define( 'DB_USER', 'wp1' );

/** Database password */
define( 'DB_PASSWORD', '123123' );

/** Database hostname */
define( 'DB_HOST', 'db' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '[:w~B^wi$84vwh`XwJ8H]Fkx%LSS[q!WQvuu1A`N>.VBes>EAW9WeD9WX!_{?};7' );
define( 'SECURE_AUTH_KEY',  'UrAXlw.n|K|(.;o]O!. 3H_6(Zv{&u7`w%ZSDNZHU,xcH9e+Ju#``:,8aoRA/k]u' );
define( 'LOGGED_IN_KEY',    '^i$[K@GMVq?Iqf [018yG8XeMRa/O7Z7xX)IZhv**UB/L?}:*kt2PX8x,e<w!mMn' );
define( 'NONCE_KEY',        'YEs(2}&W|Hf7;cei[rXj%4#:wPc[>zj/vVRHUm~#uULYI]2__GE;V<=`HkgEMA#.' );
define( 'AUTH_SALT',        '=sj%M<v@F#[4If(:,(A~$ 2Mm# ,U(jEznWLtOzT,(+C<L3Z.jZ+P[q0i~Rdyl(6' );
define( 'SECURE_AUTH_SALT', 'WZdjuo)sD3c)kg/0[{6)1viPp+G^D6VwdE<mNnOGxh}/xZnJ]>j(bkI.Zvi_]#XY' );
define( 'LOGGED_IN_SALT',   'pw_v>%s5Dylh+D5/of6T&*C<7dmi6UiD?w[P++Xjg^7vXZuns##D`2x6+FrlmEL[' );
define( 'NONCE_SALT',       'JH2]aUccb,phSJMS`nfFofF;RX,Nm>zDbG@JATQXX?]A;zydsqcWfGpS20rI5]9}' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */


/* Add any custom values between this line and the "stop editing" line. */
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false); // Prevent displaying errors on the site



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
