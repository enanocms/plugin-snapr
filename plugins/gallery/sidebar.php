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
 
//
// "Random Image" sidebar block
//

function gal_sidebar_block()
{
	global $db, $session, $paths, $template, $plugins; // Common objects
	
	$q = $db->sql_query('SELECT img_id,img_title FROM '.table_prefix.'gallery WHERE is_folder=0;');
	if ( !$q )
		$db->_die();
	
	$images = array();
	while ( $row = $db->fetchrow() )
	{
		$id = intval($row['img_id']);
		$images[$id] = $row['img_title'];
	}
	
	// Loop through all gallery images until we find one we can read (typically on the first try, but you never know...)
	$my_image = false;
	while ( count($images) > 0 )
	{
		$rand = array_rand($images);
		$image = $images[$rand];
		$acl = $session->fetch_page_acl(strval($rand), 'Gallery');
		if ( is_object($acl) && $acl->get_permissions('read') )
		{
			$my_image = $image;
			break;
		}
		unset($images[$rand]);
	}
	if ( $my_image )
	{
		// Generate sidebar HTML
		$image_link = '<div style="padding: 5px; text-align: center;">
 										<a href="' . makeUrlNS('Gallery', $rand) . '">
 											<img alt="&lt;thumbnail&gt;" src="' . makeUrlNS('Special', 'GalleryFetcher/thumb/' . $rand) . '" style="border-width: 0; display: block; margin: 0 auto 5px auto;" />
 											<span style="color: black;">' . htmlspecialchars($my_image) . '</span>
 										</a>
 									</div>';
	}
	else
	{
		$image_link = 'No images in the gallery.';
	}
	$template->sidebar_widget('Random image', $image_link);
}

$plugins->attachHook('compile_template', 'gal_sidebar_block();');

?>
