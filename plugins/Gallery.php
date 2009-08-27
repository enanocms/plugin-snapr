<?php
/*
Plugin Name: Snapr
Plugin URI: http://enanocms.org/plugin/snapr
Description: Provides an intuitive image gallery system with a browser, viewer for individual images, upload interface, and comment system integration.
Author: Dan Fuhry
Version: 0.1 beta 3
Author URI: http://enanocms.org/
*/

global $db, $session, $paths, $template, $plugins; // Common objects

define('GALLERY_VERSION', '0.1b2');

if ( !defined('ENANO_ATLEAST_1_1') )
{
  $fn = basename(__FILE__);
  setConfig("plugin_$fn", '0');
  die_semicritical('Snapr can\'t load on this site', '<p>This version of Snapr requires Enano 1.1.6 or later.</p>');
}

$magick_path = getConfig('imagemagick_path');
if ( !file_exists($magick_path) || !is_executable($magick_path) )
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
  
  die_semicritical('Snapr can\'t load on this site', '<p>You must have ImageMagick installed and working to use this plugin. The plugin has been disabled, please setup ImageMagick and then re-enable it.</p>');
}

if ( !getConfig('gallery_version') )
{
  $q = $db->sql_query('CREATE TABLE '.table_prefix.'gallery(
                        img_id int(12) NOT NULL auto_increment,
                        is_folder tinyint(1) NOT NULL DEFAULT 0,
                        folder_parent int(12) DEFAULT NULL,
                        img_title varchar(255) NOT NULL DEFAULT \'\',
                        img_desc longtext NOT NULL DEFAULT \'\',
                        print_sizes longtext NOT NULL DEFAULT \'\',
                        img_filename varchar(255) NOT NULL,
                        img_time_upload int(12) NOT NULL DEFAULT 0,
                        img_time_mod int(12) NOT NULL DEFAULT 0,
                        img_tags longtext,
                        PRIMARY KEY ( img_id )
                      );');
  
  if ( !$q )
    $db->_die();
  
  $q = $db->sql_query('CREATE FULLTEXT INDEX '.table_prefix.'gal_idx ON '.table_prefix.'gallery(img_title, img_desc);');
  
  if ( !$q )
    $db->_die();
  
  $q = $db->sql_query('INSERT INTO '.table_prefix.'gallery(img_title,img_desc,img_filename,img_time_upload,img_time_mod,img_tags) VALUES(\'Welcome to Snapr!\', \'You\'\'re past the hard part - Snapr is set up and working on your server. What you\'\'re looking at now is what most users will see when they look at an image in your gallery. The next step is to [[Special:GalleryUpload|upload some images]]. After that, make your gallery publicly accessible by adding a link to the [[Special:Gallery|browser]], if you haven\'\'t already done so. See the README file included with Snapr for more information.\', \'snapr-logo.png\', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), \'[]\');');
  
  if ( !$q )
    $db->_die();
  
  setConfig('gallery_version', GALLERY_VERSION);
}
if ( getConfig('gallery_version') == '0.1b1' )
{
  $q = $db->sql_query('ALTER TABLE ' . table_prefix . 'gallery ADD COLUMN img_tags longtext;');
  if ( !$q )
    $db->_die();
  setConfig('gallery_version', '0.1b2');
}

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
