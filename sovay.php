<?php
/**
 * Plugin Name: Sovay
 * Plugin URI: http://wordpress.org/extend/plugins/Sovay 
 * Description: Allows the use of Sovay for account registration, authentication, and commenting. 
 * Version: 0.94-beta
 * Author: Veritrix Inc. 
 * Author URI: http://www.sovay.press/ 
 * Text Domain: Sovay 
 * License: Dual GPL (http://www.fsf.org/licensing/licenses/info/GPLv2.html) and Modified BSD (http://www.fsf.org/licensing/licenses/index_html#ModifiedBSD)
 */

define ( 'SOVAY_PLUGIN_REVISION', preg_replace( '/\$Rev: (.+) \$/', '\\1',
	'$Rev: 519 $') ); // this needs to be on a separate line so that svn:keywords can work its magic

// last plugin revision that required database schema changes
define ( 'SOVAY_DB_REVISION', 24426);

// default values for which modules to activate
if (!defined('SOVAY_ENABLE_CONSUMER')) {
	define('SOVAY_ENABLE_CONSUMER', true);
}
if (!defined('SOVAY_ENABLE_COMMENTS')) {
	define('SOVAY_ENABLE_COMMENTS', true);
}
if (!defined('SOVAY_ENABLE_SERVER')) {
	define('SOVAY_ENABLE_SERVER', false);
}
if (!defined('SOVAY_ENABLE_ADMIN_PANELS')) {
	define('SOVAY_ENABLE_ADMIN_PANELS', true);
}


$openid_include_path = dirname(__FILE__) . '/lib';

// check source of randomness
if ( !@is_readable('/dev/urandom') ) { 
	define('Auth_OpenID_RAND_SOURCE', null); 
}

set_include_path( $openid_include_path . PATH_SEPARATOR . get_include_path() );

require_once dirname(__FILE__) . '/common.php';
require_once dirname(__FILE__) . '/store.php';

if (SOVAY_ENABLE_CONSUMER) {
	require_once dirname(__FILE__) . '/consumer.php';
	require_once dirname(__FILE__) . '/login.php';
}

if (SOVAY_ENABLE_ADMIN_PANELS) {
	require_once dirname(__FILE__) . '/admin_panels.php';
}

if (SOVAY_ENABLE_CONSUMER && SOVAY_ENABLE_COMMENTS) {
	require_once dirname(__FILE__) . '/comments.php';
}

if (SOVAY_ENABLE_SERVER) {
	require_once dirname(__FILE__) . '/server.php';
}

// register activation (and similar) hooks
register_activation_hook('Sovay/sovay.php', 'sovay_activate_plugin');
register_deactivation_hook('Sovay/sovay.php', 'sovay_deactivate_plugin');
register_uninstall_hook('Sovay/sovay.php', 'sovay_uninstall_plugin');

// run activation function if new revision of plugin
if ( get_option('sovay_plugin_revision') === false || SOVAY_PLUGIN_REVISION != get_option('sovay_plugin_revision') ) {
	add_action('admin_init', 'sovay_activate_plugin');
}


// ---------------- //
// Public Functions //
// ---------------- //

/**
 * Check if the user has any OpenIDs.
 *
 * @param mixed $user the username or ID.  If not provided, the current user will be used.
 * @return bool true if the user has any OpenIDs
 * @since 1.0
 */
function is_user_openid($user = null) {
	$urls = get_user_openids($user);
	return ( !empty($urls) );
}


/**
 * Check if the current comment was submitted using an OpenID. Useful for 
 * <pre><?php echo ( is_comment_openid() ? 'Submitted with OpenID' : '' ); ?></pre>
 *
 * @param int $id comment ID to check for.  If not provided, the current comment will be used.
 * @return bool true if the comment was submitted using an OpenID
 * @access public
 * @since 1.0
 */
function is_comment_openid($id = null) {
	if ( is_numeric($id) ) {
		$comment = get_comment($id);
	} else {
		global $comment;
	}

	$openid_comments = get_post_meta($comment->comment_post_ID, 'openid_comments', true);

	if ( is_array($openid_comments) ) {
		if ( in_array($comment->comment_ID, $openid_comments) ) {
			return true;
		}
	}

	return false;
}


/**
 * Get the OpenID identities for the specified user.
 *
 * @param mixed $id_or_name the username or ID.  If not provided, the current user will be used.
 * @return array array of user's OpenID identities
 * @access public
 * @since 3.0
 */
function get_user_openids($id_or_name = null) {
	$user = get_userdata_by_various($id_or_name);

	if ( $user ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare('SELECT url FROM '.openid_identity_table().' WHERE user_id = %s', $user->ID) );
	} else {
		return array();
	}
}


/**
 * Get the user associated with the specified OpenID.
 *
 * @param string $openid identifier to match
 * @return int|null ID of associated user, or null if no associated user
 * @access public
 * @since 3.0
 */
function get_user_by_openid($url) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare('SELECT user_id FROM '.openid_identity_table().' WHERE url = %s', $url) );
}


/**
 * Get a simple OpenID input field.
 *
 * @access public
 * @since 2.0
 */
function openid_input() {
	return '<input type="text" id="openid_identifier" name="openid_identifier" />';
}


/**
 * Convenience method to get user data by ID, username, or from current user.
 *
 * @param mixed $id_or_name the username or ID.  If not provided, the current user will be used.
 * @return bool|object False on failure, User DB row object
 * @access public
 * @since 3.0
 */
if (!function_exists('get_userdata_by_various')) :
function get_userdata_by_various($id_or_name = null) {
	if ( $id_or_name === null ) {
		$user = wp_get_current_user();
		if ($user == null) return false;
		return $user->data;
	} else if ( is_numeric($id_or_name) ) {
		return get_user_by('id', $id_or_name);
	} else {
		return get_user_by('login', $id_or_name);
	}
}
endif;

// -- end of public functions


/**
 * Get the file for the plugin, including the path.  This method will handle the case where the 
 * actual plugin files do not reside within the WordPress directory on the filesystem (such as 
 * a symlink).  The standard value should be 'openid/openid.php' unless files or folders have
 * been renamed.
 *
 * @return string plugin file
 */
function openid_plugin_file() {
	static $file;

	if ( empty($file) ) {
		$path = 'openid';

		$base = plugin_basename(__FILE__);
		if ( $base != __FILE__ ) {
			$path = basename(dirname($base));
		}

		$file = $path . '/' . basename(__FILE__);
	}

	return $file;
}

