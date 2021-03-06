<?php
/**!info**
{
	"Plugin Name"  : "Snapr",
	"Plugin URI"   : "http://enanocms.org/plugin/snapr",
	"Description"  : "Provides an intuitive image gallery system with a browser, viewer for individual images, upload interface, and comment system integration.",
	"Author"       : "Dan Fuhry",
	"Version"      : "0.1b4",
	"Author URI"   : "http://enanocms.org/",
	"Version list" : ['0.1b1', '0.1b2', '0.1 beta 3', '0.1b3', '0.1b4']
}
**!*/

global $db, $session, $paths, $template, $plugins; // Common objects

define('GALLERY_VERSION', '0.1b2');

if ( !defined('ENANO_ATLEAST_1_1') )
{
	$fn = basename(__FILE__);
	setConfig("plugin_$fn", '0');
	die_semicritical('Snapr can\'t load on this site', '<p>This version of Snapr requires Enano 1.1.6 or later.</p>');
}

$magick_path = getConfig('imagemagick_path', '/usr/bin/convert');
$have_gd_scale_support = function_exists('imagecreatetruecolor') && function_exists('imagejpeg') && function_exists('imagecopyresampled');
if ( (!file_exists($magick_path) || !is_executable($magick_path)) && !$have_gd_scale_support )
{
	$fn = basename(__FILE__);
	// set disabled flag with new plugin system
	if ( defined('ENANO_ATLEAST_1_1') && defined('PLUGIN_DISABLED') )
	{
		$q = $db->sql_query('UPDATE ' . table_prefix . "plugins SET plugin_flags = plugin_flags | " . PLUGIN_DISABLED . " WHERE plugin_filename = 'Gallery.php';");
		if ( !$q )
			$db->_die();
	  
		// kill off cache
		global $cache;
		$cache->purge('plugins');
	}
	else
	{
		// old plugin system
		setConfig("plugin_$fn", '0');
	}
	
	die_semicritical('Snapr can\'t load on this site', '<p>You must have ImageMagick or GD installed and working to use this plugin. The plugin has been disabled, please setup ImageMagick and then re-enable it.</p>');
}

$plugins->attachHook('pgsql_set_serial_list', '$primary_keys[table_prefix."gallery"] = "img_id";');

/**!install dbms="mysql"; **

CREATE TABLE {{TABLE_PREFIX}}gallery(
	img_id int(12) NOT NULL auto_increment,
	is_folder tinyint(1) NOT NULL DEFAULT 0,
	folder_parent int(12) DEFAULT NULL,
	img_title varchar(255) NOT NULL DEFAULT '',
	img_desc longtext NOT NULL DEFAULT '',
	print_sizes longtext NOT NULL DEFAULT '',
	img_filename varchar(255) NOT NULL,
	img_time_upload int(12) NOT NULL DEFAULT 0,
	img_time_mod int(12) NOT NULL DEFAULT 0,
	img_author int(12) NOT NULL DEFAULT 1,
	img_tags longtext,
	PRIMARY KEY ( img_id )
);

CREATE FULLTEXT INDEX {{TABLE_PREFIX}}gal_idx ON {{TABLE_PREFIX}}gallery(img_title, img_desc);

INSERT INTO {{TABLE_PREFIX}}gallery(img_title,img_desc,img_filename,img_time_upload,img_time_mod,img_tags) VALUES
	('Welcome to Snapr!',
	 'You''re past the hard part - Snapr is set up and working on your server. What you''re looking at now is what most users will see when they look at an image in your gallery. The next step is to [[Special:GalleryUpload|upload some images]]. After that, make your gallery publicly accessible by adding a link to the [[Special:Gallery|browser]], if you haven''t already done so. See the README file included with Snapr for more information.',
	 'snapr-logo.png',
	 UNIX_TIMESTAMP(),
	 UNIX_TIMESTAMP(),
	 '[]');

**!*/

/**!uninstall dbms="mysql"; **
ALTER TABLE {{TABLE_PREFIX}}gallery DROP INDEX {{TABLE_PREFIX}}gal_idx;
DROP TABLE {{TABLE_PREFIX}}gallery;

**!*/

/**!upgrade dbms="mysql"; from="0.1b1"; to="0.1b2"; **
ALTER TABLE {{TABLE_PREFIX}}gallery ADD COLUMN img_tags longtext;
**!*/

/**!upgrade dbms="mysql"; from="0.1b2"; to="0.1b3"; **
**!*/

/**!upgrade dbms="mysql"; from="0.1 beta 3"; to="0.1b3"; **
**!*/

/**!upgrade dbms="mysql"; from="0.1b3"; to="0.1b4"; **
ALTER TABLE {{TABLE_PREFIX}}gallery ADD COLUMN img_author int(12) NOT NULL DEFAULT 1;
ALTER TABLE {{TABLE_PREFIX}}gallery ADD COLUMN processed tinyint(1) NOT NULL DEFAULT 1;
-- Set all images to authorship by the first administrator we can find
UPDATE {{TABLE_PREFIX}}gallery SET img_author = ( SELECT user_id FROM {{TABLE_PREFIX}}users WHERE user_level = 9 ORDER BY user_id DESC LIMIT 1 ), processed = 1;

**!*/

require( ENANO_ROOT . '/plugins/gallery/functions.php' );
require( ENANO_ROOT . '/plugins/gallery/nssetup.php' );
require( ENANO_ROOT . '/plugins/gallery/viewimage.php' );
require( ENANO_ROOT . '/plugins/gallery/browser.php' );
require( ENANO_ROOT . '/plugins/gallery/upload.php' );
require( ENANO_ROOT . '/plugins/gallery/fetcher.php' );
require( ENANO_ROOT . '/plugins/gallery/search.php' );
require( ENANO_ROOT . '/plugins/gallery/sidebar.php' );
require( ENANO_ROOT . '/plugins/gallery/imagetag.php' );

?>
