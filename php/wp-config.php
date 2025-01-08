<?php
/** Enable W3 Total Cache */
define('WP_CACHE', true); // Added by W3 Total Cache

/** Enable W3 Total Cache */

/** Enable W3 Total Cache */
 //Added by WP-Cache Manager


/**
 * The base configurations of the WordPress.
 *
 * This file has the following configurations: MySQL settings, Table Prefix,
 * Secret Keys, WordPress Language, and ABSPATH. You can find more information
 * by visiting {@link http://codex.wordpress.org/Editing_wp-config.php Editing
 * wp-config.php} Codex page. You can get the MySQL settings from your web host.
 *
 * This file is used by the wp-config.php creation script during the
 * installation. You don't have to use the web site, you can just copy this file
 * to "wp-config.php" and fill in the values.
 *
 * @package WordPress
 */

define('WP_HOME', 'https://localhost');

define('WP_SITEURL', 'https://localhost');

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'boxrox-test_local');

/** MySQL database username */
define('DB_USER', 'boxrox-test_local');

/** MySQL database password */
define('DB_PASSWORD', 'this_is_local_environment');

/** MySQL hostname */
define('DB_HOST', 'boxrox-test-db');

/** Database Charset to use in creating database tables. */
define('DB_CHARSET', 'utf8');

/** The Database Collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY',         'H,F=Igfi@6(JX-[;]TZX+ow(XjrQ-|hiE(#nyA86~iX[88.2-RZ^/F&?XrNqS6w<');
define('SECURE_AUTH_KEY',  's/}U<gsr;:S|jbWFAgKFfU}Q1+{OF|NFg-`-$1I_bA)<Fv27R`>6I{by}r#>O&HZ');
define('LOGGED_IN_KEY',    '0}x7nE#dKCG,Wz$,ZV%%qXPn~&8B,VR8JYq$Xr| V|mLItC~+28=3mfCFO{P12MC');
define('NONCE_KEY',        'VlossRk[Dw~CV}$j !pwFgrq)EcBQx)gP9W?_qe+MWR:N5+$r)~E|4#BzmemHW~Y');
define('AUTH_SALT',        '?]~KB#o]5+WhhZ{6VcRVRvzt!gnU}N,@~LS9!+}+s<a@wymZP|*_?4Jumo-,e2Pc');
define('SECURE_AUTH_SALT', '-%P]GmXl[sLUPzz<-3^-{|SsUWccOabwzFOHE.>1vT#Y5/bI+ N^ ,v29%)],#g{');
define('LOGGED_IN_SALT',   '_,bpBCNutF E6McS)^t092iRXk1>wO`cv{Y-c%&0D{&;+%-*E.7./=c7/+{U~7Ka');
define('NONCE_SALT',       'W1|8K|Wct4,(XH`w3h#*z0CR(:bEO]2[Pm~8jwWS{wx?l@ezuHZS5L.onb6_r[{e');

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each a unique
 * prefix. Only numbers, letters, and underscores please!
 */
$table_prefix  = 'cms_wp_';

/**
 * WordPress Localized Language, defaults to English.
 *
 * Change this to localize WordPress. A corresponding MO file for the chosen
 * language must be installed to wp-content/languages. For example, install
 * de_DE.mo to wp-content/languages and set WPLANG to 'de_DE' to enable German
 * language support.
 */
define('WPLANG', '');

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 */

 // Enable WP_DEBUG mode
define('WP_DEBUG', false);


/** Absolute path to the WordPress directory. */
if ( !defined('ABSPATH') )
	define('ABSPATH', dirname(__FILE__) . '/');

/** Sets up WordPress vars and included files. */
require_once(ABSPATH . 'wp-settings.php');

// Disable CRONJOBS in WP, will be done with Crontab
define('DISABLE_WP_CRON', true);

// BOX List DB Settings
define( 'WP_MEMORY_LIMIT', '512M' );
define( 'WP_MAX_MEMORY_LIMIT', '512M' );
define('WP_ALLOW_REPAIR', true);
