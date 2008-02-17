<?php

/*
 * Snapr
 * Version 0.1 beta 1
 * Copyright (C) 2007 Dan Fuhry
 *
 * This program is Free Software; you can redistribute and/or modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for details.
 */

$plugins->attachHook('acl_rule_init', 'gallery_setup_namespace($this);');

function gallery_setup_namespace(&$paths)
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  
  $paths->create_namespace('Gallery', 'Image:');
  
  $session->register_acl_type('gal_full_res', AUTH_ALLOW, 'View image at full resolution', array('read'), 'Gallery');
  $session->register_acl_type('snapr_add_tag', AUTH_DISALLOW, 'Add image tags (separate from adding normal tags)', array('read'), 'Gallery');
  
  $session->acl_extend_scope('read',                   'Gallery', $paths);
  $session->acl_extend_scope('post_comments',          'Gallery', $paths);
  $session->acl_extend_scope('edit_comments',          'Gallery', $paths);
  $session->acl_extend_scope('mod_comments',           'Gallery', $paths);
}

?>
