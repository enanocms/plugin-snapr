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

##
## UPLOAD INTERFACE
##

$plugins->attachHook('session_started', 'register_special_page("GalleryUpload", "Image gallery upload");');

function page_Special_GalleryUpload()
{
	global $db, $session, $paths, $template, $plugins; // Common objects
	
	if ( $session->user_level < USER_LEVEL_ADMIN )
	{
		die_friendly('Access denied', '<p>You need to have administrative rights to use the gallery\'s upload features.</p>');
	}
	
	$zip_support = ( class_exists('ZipArchive') || ( file_exists('/usr/bin/unzip') && is_executable('/usr/bin/unzip') ) );
	
	$errors = array();
	$template->add_header('<link rel="stylesheet" type="text/css" href="' . scriptPath . '/plugins/gallery/dropdown.css" />');
	$template->add_header('<script type="text/javascript" src="' . scriptPath . '/plugins/gallery/gallery-bits.js"></script>');
	
	$max_size_field = get_max_size_field();
	
	//
	// EDIT IMAGES
	//  
	if ( isset($_GET['edit_img']) )
	{
		$edit_parms = $_GET['edit_img'];
		$regex = '/^((([0-9]+),)*)?([0-9]+?)$/';
		if ( !preg_match($regex, $edit_parms) )
		{
			die_friendly('Bad request', '<p>$_GET[\'edit_img\'] must be a comma-separated list of image IDs.</p>');
		}
		
		// process any uploaded images
		// FIXME is this a bad place for this?
		$limit = isset($_GET['ajax']) ? '' : "LIMIT 5";
		$q = $db->sql_query('SELECT img_id FROM ' . table_prefix . "gallery WHERE is_folder = 0 AND processed = 0 $limit;");
		if ( !$q )
			$db->_die();
		if ( $db->numrows() > 0 )
		{
			while ( $row = $db->fetchrow($q) )
			{
				snapr_process_image($row['img_id']);
			}
			$q = $db->sql_query('SELECT COUNT(img_id) FROM ' . table_prefix . "gallery WHERE is_folder = 0 AND processed = 0;");
			if ( !$q )
				$db->_die();
			list($count) = $db->fetchrow_num();
			$db->free_result();
			if ( intval($count) > 0 )
			redirect(makeUrlNS('Special', 'GalleryUpload', "edit_img={$_GET['edit_img']}"), "Processing images", "Processing images... $count remaining", 1);
		}
		
		if ( !isset($_GET['ajax']) )
			$template->header();
		
		snapr_editform($edit_parms);
		
		if ( !isset($_GET['ajax']) )
			$template->footer();
		
		return;
	}
	//
	// REMOVE IMAGES
	// 
	else if ( isset($_GET['rm']) )
	{
		$warnings = array();
		
		if ( !preg_match('/^[0-9]+$/', $_GET['rm']) )
			die_friendly('Bad Request', '<p>$_GET[rm] needs to be an integer.</p>');
		
		$rm_id = intval($_GET['rm']);
		
		if ( isset($_POST['confirmed']) )
		{
			// The user confirmed the request. Start plowing through data to decide what to delete.
			
			// Array of images and folder rows to delete
			$del_imgs = array($rm_id);
			// Array of files to delete
			$del_files = array();
			// Array of comment entries to delete
			$del_comments = array();
			
			$all_children = gal_fetch_all_children($rm_id);
			$del_imgs = array_merge($del_imgs, $all_children);
			
			$imglist = 'img_id=' . implode(' OR img_id=', $del_imgs);
			$sql = "SELECT img_id, img_filename FROM ".table_prefix."gallery WHERE ( $imglist ) AND is_folder!=1;";
			
			if ( !$db->sql_query($sql) )
			{
				$db->_die();
			}
			
			while ( $row = $db->fetchrow() )
			{
				$files = array(
						ENANO_ROOT . '/files/' . $row['img_filename'],
						ENANO_ROOT . '/cache/' . $row['img_filename'] . '-thumb.jpg',
						ENANO_ROOT . '/cache/' . $row['img_filename'] . '-preview.jpg'
					);
				$del_files = array_merge($del_files, $files);
				
				$del_comments[] = intval($row['img_id']);
			}
			
			$commentlist = 'page_id=\'' . implode('\' OR page_id=\'', $del_imgs) . '\'';
			
			// Main deletion cycle
			
			foreach ( $del_files as $file )
			{
				@unlink($file) or $warnings[] = 'Could not delete file ' . $file;
			}
			
			if ( !$db->sql_query('DELETE FROM '.table_prefix.'gallery WHERE ' . $imglist . ';') )
			{
				$warnings[] = 'Main delete query failed: ' . $db->get_error();
			}
			
			if ( !$db->sql_query('DELETE FROM '.table_prefix.'comments WHERE ( ' . $commentlist . ' ) AND namespace=\'Gallery\';') )
			{
				$warnings[] = 'Comment delete query failed: ' . $db->get_error();
			}
			
			if ( count($warnings) > 0 )
			{
				$template->header();
				
				echo '<h3>Error during deletion process</h3>';
				echo '<p>The deletion process generated some warnings which are shown below.</p>';
				echo '<ul><li>' . implode('</li><li>', $warnings) . '</li></ul>';
				
				$template->footer();
			}
			else
			{
				redirect(makeUrlNS('Special', 'Gallery'), 'Deletion successful', 'The selected item has been deleted from the gallery. You will now be transferred to the gallery index.', 2);
			}
			
		}
		else
		{
			// Removal form
			$template->header();
			
			echo '<form action="' . makeUrlNS('Special', 'GalleryUpload', 'rm=' . $rm_id, true) . '" method="post" enctype="multipart/form-data">';
			echo $max_size_field;
			
			echo '<h3>Are you sure you want to delete this item?</h3>';
			echo '<p>If you continue, this item will be permanently deleted from the gallery &ndash; no rollbacks.</p>';
			echo '<p>If this is an image, the image files will be removed from the filesystem, and all comments associated with the image will be deleted, as well as the image\'s title, description, and location.</p>';
			echo '<p>If this is a folder, all of its contents will be removed. Any images will be removed from the filesystem and all comments and metadata associated with images in this folder or any folders in it will be permanently deleted.</p>';
			
			echo '<p><input type="submit" name="confirmed" value="Continue with delete" /></p>';
			
			echo '</form>';
			
			$template->footer();
		}
		return;
	}
	else if ( isset($_GET['ajax_proc_status']) )
	{
		$q = $db->sql_query("SELECT COUNT(img_id) FROM " . table_prefix . "gallery WHERE processed = 0;");
		if ( !$q )
			$db->_die();
		list($count) = $db->fetchrow_num();
		echo $count;
		return;
	}
	else
	{
		if ( isset($_POST['do_upload']) )
		{
			$files =& $_FILES['files'];
			$numfiles = count($files['name']);
			$idlist = array();
			$destfolder = intval($_POST['targetfolder']);
			if ( $destfolder < 1 )
				$destfolder = NULL;
			for ( $i = 0; $i < $numfiles; $i++ )
			{
				$ext = get_file_extension($files['name'][$i]);
				if ( snapr_extension_allowed($ext) )
				{
					// normal image
					$result = snapr_insert_image($files['tmp_name'][$i], $destfolder, $files['name'][$i]);
					if ( $result !== false )
						$idlist[] = $result;
				}
				else if ( strtolower($ext) == 'zip' )
				{
					// zip file
					$zipidlist = snapr_process_zip($files['tmp_name'][$i], $destfolder);
					if ( $zipidlist )
						$idlist = array_merge($idlist, $zipidlist);
				}
				else
				{
					// FIXME handle unsupported files... maybe?
				}
			}
			$idlist = implode(',', $idlist);
			echo '<div class="idlist">[' . $idlist . ']</div>';
			echo '<noscript>Images uploaded successfully. Please <a href="' . makeUrlNS('Special', 'GalleryUpload', "edit_img=$idlist") . '">click here to continue</a>.</noscript>';
			//snapr_editform($idlist);
			return;
		}
		
		// Oh yes, the image uploader!
		$template->preload_js(array('jquery', 'jquery-ui', 'upload'));
		$template->header();
		
		?>
		<form action="" method="post" enctype="multipart/form-data" id="snaprupload">
		
		<script type="text/javascript">
		//<![CDATA[
		addOnloadHook(function()
			{
				attachHook('snaprupload_ajaxupload_init', 'snapr_upload_init(ajaxupload);');
			});
		function snapr_upload_init(au)
		{
			au.upload_start = function()
			{
				$(this.form).hide();
				$(this.statusbox).html('<h2 class="uploadgoing">Uploading pictures...</h2><div class="progress" style="margin: 15px 0;"></div><p class="uploadstatus">&nbsp;</p>');
				$('div.progress', this.statusbox).progressbar({value: 0});
			};
			
			au.status = function(state)
			{
				if ( !state.done && !state.cancel_upload )
				{
					var rawpct = state.bytes_processed / state.content_length;
					var pct = (Math.round((rawpct) * 1000)) / 10;
					var elapsed = state.current_time - state.start_time;
					var rawbps = state.bytes_processed / elapsed;
					var kbps = Math.round((rawbps) / 1024);
					var remain_bytes = state.content_length - state.bytes_processed;
					var remain_time = Math.round(remain_bytes / rawbps);
					
					$('p.uploadstatus', this.statusbox).html(pct + '% complete / ' + kbps + ' KB/s / ' + humanize_time(elapsed) + ' elapsed / ' + humanize_time(remain_time) + ' remaining');
					$('div.progress', this.statusbox).progressbar('value', pct);
				}
			};
			
			au.upload_success = function(childbody)
			{
				$(this.statusbox).html('<div class="info-box"></div>' + childbody.innerHTML);
				var idlist = parseJSON($('div.idlist', this.statusbox).text());
				$('div.idlist', this.statusbox).remove();
				var s = idlist.length == 1 ? '' : 's';
				$('div.info-box', this.statusbox).html(idlist.length + ' image'+s+' were uploaded successfully. Please wait while they are processed...');
				$(this.statusbox).append('<div class="progress" style="margin: 15px 0;"></div><p class="uploadstatus">&nbsp;</p>');
				$('div.progress', this.statusbox).progressbar({value: 0});
				var au = this;
				ajaxGet(makeUrlNS('Special', 'GalleryUpload', 'edit_img=' + implode(',', idlist) + '&ajax=true'), function(ajax)
					{
						if ( ajax.readyState == 4 )
						{
							window.clearTimeout(snapr_refresh_timer);
							$(au.statusbox).html(ajax.responseText);
						}
					});
				snapr_refresh_proc(au, idlist);
			};
		}
		
		window.snapr_refresh_timer = false;
		
		function snapr_refresh_proc(au, idlist)
		{
			void(au);
			void(idlist);
			ajaxGet(makeUrlNS('Special', 'GalleryUpload', 'ajax_proc_status'), function(ajax)
				{
					if ( ajax.readyState == 4 )
					{
						var n = idlist.length - Number(ajax.responseText);
						var pct = (n / idlist.length) * 100;
						$('div.progress', au.statusbox).progressbar('value', pct);
						$('p.uploadstatus', au.statusbox).html(n + " of " + idlist.length + " images processed");
						if ( pct < 100 )
							window.snapr_refresh_timer = setTimeout(function()
								{
									snapr_refresh_proc(au, idlist);
								}, 1000);
					}
				});
		}
		//]]>
		</script>
		<?php ajax_upload_js('snaprupload'); ?>
		
		<div class="tblholder">
			<table border="0" cellspacing="1" cellpadding="4">
				<tr>
					<th colspan="2">Upload files to the gallery</th>
				</tr>
				<tr>
					<td class="row1">
						Select files:
					</td>
					<td class="row1">
						<input type="hidden" name="do_upload" value="yes" />
						<input type="file" size="50" name="files[]" />
						<input type="button" class="addanother" value="+" />
					</td>
				</tr>
				<tr>
					<td class="row2">
						Upload into folder:
					</td>
					<td class="row2">
					<?php echo gallery_hier_formfield('targetfolder', true); ?>
					</td>
				</tr>
				<tr>
					<td class="row3" colspan="2" style="text-align: center; line-height: 24px;">
						<strong>Supported formats:</strong>
						<br />
						
						<img alt="Checkmark" src="<?php echo cdnPath; ?>/images/check.png" style="vertical-align: middle;" /> JPEG images &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
						<img alt="Checkmark" src="<?php echo cdnPath; ?>/images/check.png" style="vertical-align: middle;" /> PNG images &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
						<img alt="Checkmark" src="<?php echo cdnPath; ?>/images/check.png" style="vertical-align: middle;" /> GIF images &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
						<?php if ( $zip_support ): ?>
						<img alt="Checkmark" src="<?php echo cdnPath; ?>/images/check.png" style="vertical-align: middle;" /> Zip archives
						<?php else: ?>
						<img alt="X mark" src="<?php echo cdnPath; ?>/images/checkbad.png" style="vertical-align: middle;" /> Zip archives
						<?php endif; ?><br />
						<small>Maximum file size: <strong><?php echo ini_get('upload_max_filesize'); ?></strong></small>
						<?php echo $max_size_field; ?>
					</td>
				</tr>
				<tr>
					<th colspan="2" class="subhead">
						<input type="submit" value="Upload" />
					</th>
				</tr>
			</table>
		</div>
		</form>
		<script type="text/javascript">
		// <![CDATA[
		addOnloadHook(function()
			{
				$('input.addanother').click(function()
					{
						$(this).before('<br />');
						var inp = document.createElement('input');
						$(inp).attr('type', 'file').attr('size', '50').attr('name', 'files[]');
						this.parentNode.insertBefore(inp, this);
						$(this).before(' ');
						return false;
					});
			});
		// ]]>
		</script>
		<?php
	}
	
	
	$template->footer();
	
}

function snapr_editform($edit_parms)
{
	global $db, $session, $paths, $template, $plugins; // Common objects
	
	$max_size_field = get_max_size_field();
	$errors = array();
	$idlist = explode(',', $edit_parms);
	$num_edit = count($idlist);
	$idlist = "SELECT img_id,img_title,img_desc,img_filename,is_folder FROM ".table_prefix."gallery WHERE img_id=" . implode(' OR img_id=', $idlist) . ';';
	
	if ( !$e = $db->sql_query($idlist) )
		$db->_die();
	
	if ( isset($_POST['edit_do_save']) )
	{
		@set_time_limit(0);
		
		$arr_img_data = array();
		while ( $row = $db->fetchrow($e) )
			$arr_img_data[$row['img_id']] = $row;
		
		// Allow breaking out
		switch(true):case true:
			
			if ( !is_array($_POST['img']) )
			{
				$errors[] = 'No images passed to processor.';
				break;
			}
			
			// Main updater loop
			foreach ( $_POST['img'] as $img_id => $img_data )
			{
				
				if ( !preg_match('/^[0-9]+$/', $img_id) )
				{
					$errors[] = 'SQL injection attempted!';
					break 2;
				}
				
				// Array of columns to update
				$to_update = array();
				
				$key = 'reupload_' . $img_data['id'];
				if ( isset($_FILES[$key]) )
				{
					$file =& $_FILES[ $key ];
					if ( $file['tmp_name'] != '' )
					{
						// Reupload
						$filename = ENANO_ROOT . '/files/' . $arr_img_data[ $img_data['id'] ]['img_filename'];
						if ( !unlink($filename) )
						{
							$errors[] = "Could not delete $filename";
							break 2;
						}
						if ( !@move_uploaded_file($file['tmp_name'], $filename) )
						{
							$errors[] = "Could not move uploaded file to $filename";
							break 2;
						}
						
						//
						// Create scaled images
						//
						
						// Create thumbnail image
						$thumb_filename = ENANO_ROOT . '/cache/' . $arr_img_data[ $img_data['id'] ]['img_filename'] . '-thumb.jpg';
						if ( !unlink($thumb_filename) )
						{
							$errors[] = "Could not delete $thumb_filename";
							break 2;
						}
						
						if ( !scale_image($filename, $thumb_filename, 80, 80) )
						{
							$errors[] = 'Couldn\'t scale image '.$i.': ImageMagick failed us';
							break 2;
						}
						
						// Create preview image
						$preview_filename = ENANO_ROOT . '/cache/' . $arr_img_data[ $img_data['id'] ]['img_filename'] . '-preview.jpg';
						if ( !unlink($preview_filename) )
						{
							$errors[] = "Could not delete $preview_filename";
							break 2;
						}
						
						if ( !scale_image($filename, $preview_filename, 640, 480) )
						{
							$errors[] = 'Couldn\'t scale image '.$i.': ImageMagick failed us';
							break 2;
						}
						
						$to_update['img_time_mod'] = strval(time());
					}
				}
				
				$vars = array(
					'year' => date('Y'),
					'month' => date('F'),
					'day' => date('d'),
					'time12' => date('g:i A'),
					'time24' => date('G:i')
				);
				
				// Image name/title
				
				$title = $template->makeParserText($img_data['title']);
				$title->assign_vars($vars);
				$executed = $title->run();
				if ( $executed == '_id' )
				{
					$errors[] = 'You cannot name an image or folder "_id", this name is reserved for internal functions.';
					break 2;
				}
				if ( $executed == '' )
				{
					$errors[] = 'Please enter a name for the item with unique ID ' . $img_data['id'] . '. <pre>' . print_r($_POST,true) . '</pre>';
					break 2;
				}
				$to_update['img_title'] = $executed;
				
				// Image description
				
				if ( isset($img_data['desc']) )
				{
					$desc = $template->makeParserText($img_data['desc']);
					$desc->assign_vars($vars);
					$executed = $desc->run();
					$executed = RenderMan::preprocess_text($executed, false, false);
					$to_update['img_desc'] = $executed;
				}
				
				// Folder
				$target_folder = false;
				
				if ( !empty($_POST['override_folder']) )
				{
					if ( $_POST['override_folder'] == 'NULL' || preg_match('/^[0-9]+$/', $_POST['override_folder']) )
					{
						$target_folder = $_POST['override_folder'];
					}
				}
				
				if ( !empty($img_data['folder']) )
				{
					if ( $img_data['folder'] == 'NULL' || preg_match('/^[0-9]+$/', $img_data['folder']) )
					{
						$target_folder = $img_data['folder'];
					}
				}
				
				if ( $target_folder )
				{
					// Make sure we're not trying to move a folder to itself or a subdirectory of itself
					
					$children = gal_fetch_all_children(intval($img_data['id']));
					if ( $img_data['id'] == $target_folder || in_array($target_folder, $children) )
					{
						$errors[] = 'You are trying to move a folder to itself, or to a subdirectory of itself, which is not allowed. If done manually (i.e. via an SQL client) this will result in infinite loops in the folder sorting code.';
						break 2;
					}
					
					$to_update['folder_parent'] = $target_folder;
				}
				
				if ( count($to_update) > 0 )
				{
					$up_keys = array_keys($to_update);
					$up_vals = array_values($to_update);
					
					$bin_cols = array('folder_parent');
					
					$sql = 'UPDATE ' . table_prefix.'gallery SET ';
					
					foreach ( $up_keys as $i => $key )
					{
						if ( in_array($key, $bin_cols) )
						{
							$sql .= $key . '=' . $up_vals[$i] . ',';
						}
						else
						{
							$sql .= $key . '=\'' . $db->escape($up_vals[$i]) . '\',';
						}
					}
					
					$sql = preg_replace('/,$/i', '', $sql) . ' WHERE img_id=' . $img_data['id'] . ';';
					
					if ( !$db->sql_query($sql) )
					{
						$db->_die();
					}
					
				}
				
			}
			
			echo '<div class="info-box" style="margin-left: 0;">Your changes have been saved.</div>';
			
		endswitch;
		
		// Rerun select query to make sure information in PHP memory is up-to-date
		if ( !$e = $db->sql_query($idlist) )
			$db->_die();
		
	}
	
	if ( count($errors) > 0 )
	{
		echo '<div class="error-box" style="margin-left: 0;">
						<b>The following errors were encountered while updating the image data:</b><br />
						<ul>
							<li>' . implode("</li>\n        <li>", $errors) . '</li>
						</ul>
					</div>';
	}
	
	echo '<form action="' . makeUrlNS('Special', 'GalleryUpload', 'edit_img=' . $edit_parms, true) . '" method="post" enctype="multipart/form-data">';
	
	echo $max_size_field;
	
	if ( $row = $db->fetchrow($e) )
	{
		
		echo '<div class="tblholder">
						<table border="0" cellspacing="1" cellpadding="4">';
		echo '    <tr><th class="subhead">Information</th></tr>';
		echo '    <tr><td class="row3">
								As with the upload form, the following variables can be used. <b>Note that when editing images, the {id} and {autotitle} variables will be ignored.</b>';
		?>
				<ul>
					<li>{year}: The current year (<?php echo date('Y'); ?>)</li>
					<li>{month}: The current month (<?php echo date('F'); ?>)</li>
					<li>{day}: The day of the month (<?php echo date('d'); ?>)</li>
					<li>{time12}: 12-hour time (<?php echo date('g:i A'); ?>)</li>
					<li>{time24}: 24-hour time (<?php echo date('G:i'); ?>)</li>
				</ul>
		<?php
		echo '        </td></tr>';
		echo '  </table>
					</div>';
		
		$i = 0;
		do
		{
			$thumb_url = makeUrlNS('Special', 'GalleryFetcher/thumb/' . $row['img_id'], false, true);
			
			# Type: folder
			if ( $row['is_folder'] == 1 ):
			
			// Image ID tracker
			echo '<input type="hidden" name="img[' . $i . '][id]" value="' . $row['img_id'] . '" />';
			
			//
			// Editor table
			//
			
			$folders = gallery_imgid_to_folder(intval($row['img_id']));
			foreach ( $folders as $j => $xxx )
			{
				$folder =& $folders[$j];
				$folder = sanitize_page_id($folder);
			}
			$folders = array_reverse($folders);
			$gal_href = implode('/', $folders) . ( count($folders) > 0 ? '/' : '' ) . sanitize_page_id($row['img_title']);
			
			echo '<div class="tblholder">
							<table border="0" cellspacing="1" cellpadding="4">';
			
			echo '<tr><th colspan="2">Folder: ' . htmlspecialchars($row['img_title']) . '</th></tr>';
			
			// Primary key
			echo '<tr>
							<td class="row2">Unique ID:</td>
							<td class="row1">' . $row['img_id'] . ' (<a href="' . makeUrlNS('Special', 'Gallery/' . $gal_href) . '">view folder contents</a>)</td>
						</tr>';
						
			// Path info
			echo '<tr>
							<td class="row2">Parent folders:</td>
							<td class="row1">' . /* Yeah it's dirty, but hey, it gets the job done ;-) */ ( ( $x = str_replace('&amp;raquo;', '&raquo;', htmlspecialchars(str_replace('_', ' ', implode(' &raquo; ', $folders)))) ) ? $x : '&lt;in root&gt;' ) . '</td>
						</tr>';
			
			// Image name
			
			echo '<tr>
							<td class="row2">Folder name:</td>
							<td class="row1"><input type="text" style="width: 98%;" name="img[' . $i . '][title]" value="' . htmlspecialchars($row['img_title']) . '" size="43" /></td>
						</tr>';
						
			// Mover widget
			?>
			<tr>
				<td class="row2">Move to folder:</td>
				<td class="row1">
					<div class="toggle">
						<div class="head" onclick="gal_toggle( ( IE ? this.nextSibling : this.nextSibling.nextSibling ), this.childNodes[1]);">
							<img alt="&gt;&gt;" src="<?php echo scriptPath; ?>/plugins/gallery/toggle-closed.png" class="toggler" />
							Select folder
						</div>
						<div class="body">
							<?php
								echo gallery_hier_formfield('img[' . $i . '][folder]', false);
							?>
							<br />
							<a href="#" onclick="gal_unset_radios('img[<?php echo $i; ?>][folder]'); return false;">Unselect field</a>
						</div>
					</div>
				</td>
			</tr>
			<?php
			
			// Finish table
			echo '</table>';
			echo '</div>';
			
			# Type: image
			else:
			
			// Image ID tracker
			echo '<input type="hidden" name="img[' . $i . '][id]" value="' . $row['img_id'] . '" />';
			
			//
			// Editor table
			//
			
			echo '<div class="tblholder">
							<table border="0" cellspacing="1" cellpadding="4">';
			
			echo '<tr><th colspan="2">Image: ' . htmlspecialchars($row['img_title']) . '</th></tr>';
			
			// Primary key
			echo '<tr>
							<td class="row2">Unique ID:</td>
							<td class="row1">' . $row['img_id'] . ' (<a href="' . makeUrlNS('Gallery', $row['img_id']) . '">view image\'s page</a>)</td>
						</tr>';
						
			// Thumbnail
			
			echo '<tr>
							<td class="row2">Thumbnail:</td>
							<td class="row1"><img alt="Thumbnail image" src="' . $thumb_url . '" /></td>
						</tr>';
			
			// Image name
			
			echo '<tr>
							<td class="row2">Image title:</td>
							<td class="row1"><input type="text" style="width: 98%;" name="img[' . $i . '][title]" value="' . htmlspecialchars($row['img_title']) . '" size="43" /></td>
						</tr>';
						
			// Image description
			
			echo '<tr>
							<td class="row2">Image description:</td>
							<td class="row1"><textarea rows="10" cols="40" style="width: 98%;" name="img[' . $i . '][desc]">' . htmlspecialchars($row['img_desc']) . '</textarea></td>
						</tr>';
						
			// ACL editor trigger
			
			echo '<tr>
							<td class="row2">Permissions:</td>
							<td class="row1"><input type="button" onclick="ajaxOpenACLManager(\'' . $row['img_id'] . '\', \'Gallery\');" value="Edit permissions" /><br /><small>Only works in Firefox 1.5 or later, Safari 3.x or later, or Opera 9.0 or later.</small></td>
						</tr>';
						
			// Mover widget
			?>
			<tr>
				<td class="row2">Move to folder:</td>
				<td class="row1">
					<div class="toggle">
						<div class="head" onclick="gal_toggle( ( IE ? this.nextSibling : this.nextSibling.nextSibling ), this.childNodes[1]);">
							<img alt="&gt;&gt;" src="<?php echo scriptPath; ?>/plugins/gallery/toggle-closed.png" class="toggler" />
							Select folder
						</div>
						<div class="body">
							<?php
								echo gallery_hier_formfield('img[' . $i . '][folder]', false);
							?>
							<br />
							<a href="#" onclick="gal_unset_radios('img[<?php echo $i; ?>][folder]'); return false;">Unselect field</a>
						</div>
					</div>
				</td>
			</tr>
			<?php
						
			// File replacer
			
			echo '<tr>
							<td class="row2">Upload new version:</td>
							<td class="row1"><input type="file" name="reupload_' . $row['img_id'] . '" size="30" style="width: 98%;" /></td>
						</tr>';
						
			// Finish table
			echo '</table>';
			echo '</div>';
			
			endif;
			
			$i++;
		}
		while ( $row = $db->fetchrow($e) );
		$db->free_result();
		
		echo '<div class="tblholder">
						<table border="0" cellspacing="1" cellpadding="4">';
		// Mover widget
		if ( $num_edit > 1 ):
		?>
		<tr>
			<td class="row2">Move all to folder:<br /><small>Other folder fields on this page can override this for individual images.</small></td>
			<td class="row1" style="width: 70%;">
				<div class="toggle">
					<div class="head" onclick="gal_toggle( ( IE ? this.nextSibling : this.nextSibling.nextSibling ), this.childNodes[1]);">
						<img alt="&gt;&gt;" src="<?php echo scriptPath; ?>/plugins/gallery/toggle-closed.png" class="toggler" />
						Select folder
					</div>
					<div class="body">
						<?php
							echo gallery_hier_formfield('override_folder', false);
						?>
						<br />
						<a href="#" onclick="gal_unset_radios('override_folder'); return false;">Unselect field</a>
					</div>
				</div>
			</td>
		</tr>
		<?php
		endif;
			
		echo '    <tr><th class="subhead" colspan="2"><input type="submit" name="edit_do_save" value="Save changes" /></th></tr>';
		echo '  </table>
					</div>';
		
	}
	else
	{
		echo '<p>No images that matched the ID list could be found.</p>';
	}
	
	echo '</form>';
}

function get_max_size_field()
{
	$max_size = @ini_get('upload_max_filesize');
	$max_size_field = '';
	if ( $max_size )
	{
		if ( preg_match('/M$/i', $max_size) )
		{
			$max_size = intval($max_size) * 1048576;
		}
		else if ( preg_match('/K$/i', $max_size) )
		{
			$max_size = intval($max_size) * 1024;
		}
		else if ( preg_match('/G$/i', $max_size) )
		{
			$max_size = intval($max_size) * 1048576 * 1024;
		}
		$max_size = intval($max_size);
		$max_size_field = "\n" . '<input type="hidden" name="MAX_FILE_SIZE" value="' . $max_size . '" />' . "\n";
	}
	return $max_size_field;
}

?>
