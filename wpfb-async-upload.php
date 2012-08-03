<?php
/**
 * Accepts file uploads from swfupload or other asynchronous upload methods.
 *
 */

define('TMP_FILE_MAX_AGE', 3600*3);

ob_start();
define('WP_ADMIN', true);

if ( defined('ABSPATH') )
	require_once(ABSPATH . 'wp-load.php');
else
	require_once(dirname(__FILE__).'/../../../wp-load.php');

// Flash often fails to send cookies with the POST or upload, so we need to pass it in GET or POST instead
if ( is_ssl() && empty($_COOKIE[SECURE_AUTH_COOKIE]) && !empty($_REQUEST['auth_cookie']) )
	$_COOKIE[SECURE_AUTH_COOKIE] = $_REQUEST['auth_cookie'];
elseif ( empty($_COOKIE[AUTH_COOKIE]) && !empty($_REQUEST['auth_cookie']) )
	$_COOKIE[AUTH_COOKIE] = $_REQUEST['auth_cookie'];
if ( empty($_COOKIE[LOGGED_IN_COOKIE]) && !empty($_REQUEST['logged_in_cookie']) )
	$_COOKIE[LOGGED_IN_COOKIE] = $_REQUEST['logged_in_cookie'];
unset($current_user);

require_once(ABSPATH.'wp-admin/admin.php');
ob_end_clean();

if(!WP_DEBUG) {
	send_nosniff_header();
	error_reporting(0);
}
@header('Content-Type: text/plain; charset=' . get_option('blog_charset'));

if ( !current_user_can('upload_files') )
	wp_die(__('You do not have permission to upload files.'));
	
check_admin_referer(WPFB.'-async-upload');	

wpfb_loadclass('Admin');

if(!empty($_REQUEST['delupload']))
{
	$del_upload = @json_decode(stripslashes($_REQUEST['delupload']));
	if($del_upload && is_file($tmp = WPFB_Core::UploadDir().'/.tmp/'.str_replace(array('../','.tmp/'),'',$del_upload->tmp_name)))
		echo (int)@unlink($tmp);

	// delete other old temp files
	require_once(ABSPATH . 'wp-admin/includes/file.php');
	$tmp_files = list_files(WPFB_Core::UploadDir().'/.tmp');
	foreach($tmp_files as $tmp) {
		if((time()-filemtime($tmp)) >= TMP_FILE_MAX_AGE)
			@unlink($tmp);
	}
	exit;
}

if(empty($_FILES['async-upload']))
	wp_die(__('No file was uploaded.', WPFB));	


if(!@is_uploaded_file($_FILES['async-upload']['tmp_name'])
	|| !($tmp = WPFB_Admin::GetTmpFile($_FILES['async-upload']['name'])) || !@move_uploaded_file($_FILES['async-upload']['tmp_name'], $tmp))
{
	echo '<div class="error-div">
	<a class="dismiss" href="#" onclick="jQuery(this).parents(\'div.media-item\').slideUp(200, function(){jQuery(this).remove();});">' . __('Dismiss') . '</a>
	<strong>' . sprintf(__('&#8220;%s&#8221; has failed to upload due to an error'), esc_html($_FILES['async-upload']['name']) ) . '</strong></div>';
	exit;
}
$_FILES['async-upload']['tmp_name'] = trim(substr($tmp, strlen(WPFB_Core::UploadDir())),'/');

@header('Content-Type: application/json; charset=' . get_option('blog_charset'));
echo json_encode($_FILES['async-upload']);

?> 