<?php
/*
	Plugin Name: Simple IDE
	Plugin URI: https://github.com/motekar/simple-ide
	Description: WordPress code editor with auto-completion of both WordPress and PHP functions with reference, syntax highlighting, line numbers, tabbed editing, automatic backup.
	Version: 1.0
	Author: Motekar
	Author URI: https://www.motekar.com
	Requires PHP: 5.5
	Requires at least: 4.9
	Tested up to: 6.4
	Text Domain: simple-ide

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

require 'vendor/autoload.php';

if ( ! class_exists( 'Simple_IDE' ) ) :
	class Simple_IDE {


		public $site_url, $plugin_url;
		private $menu_hook, $version;


		function __construct() {

			$this->get_plugin_version();

			// add Simple IDE to the menu
			add_action( 'admin_menu', array( $this, 'add_my_menu_page' ) );

			// hook for processing incoming image saves
			if ( is_admin() && isset( $_GET['simple_ide_save_image'] ) ) {

				// force local file method for testing - you could force other methods 'direct', 'ssh', 'ftpext' or 'ftpsockets'
				$this->override_fs_method( 'direct' );

				add_action( 'admin_init', array( $this, 'simple_ide_save_image' ) );
			}

			add_action( 'admin_init', array( $this, 'setup_hooks' ) );

			$this->site_url = get_bloginfo( 'url' );
		}


		public function override_fs_method( $method = 'direct' ) {
			if ( defined( 'FS_METHOD' ) ) {
				define( 'SIMPLE_IDE_FS_METHOD_FORCED_ELSEWHERE', FS_METHOD ); // make a note of the forced method
			} else {
				define( 'FS_METHOD', $method ); // force direct
			}
		}

		function is_plugin_page() {
			$current_screen = get_current_screen();

			if ( $current_screen->id === $this->menu_hook ) {
				return true;
			} else {
				return false;
			}
		}

		// get plugin version from header
		function get_plugin_version() {
			$plugin_data   = get_file_data( __FILE__, array( 'version' => 'Version' ), 'plugin' );
			$this->version = $plugin_data['version'];

			return $plugin_data['version'];
		} // get_plugin_version


		public function setup_hooks() {
			// force local file method until I've worked out how to implement the other methods
			// main problem being password wouldn't/isn't saved between requests
			// you could force other methods 'direct', 'ssh', 'ftpext' or 'ftpsockets'
			$this->override_fs_method( 'direct' );

			// Will only enqueue on Simple IDE page
			add_action( 'admin_print_scripts-' . $this->menu_hook, array( $this, 'add_admin_js' ) );
			add_action( 'admin_print_styles-' . $this->menu_hook, array( $this, 'add_admin_styles' ) );

			add_action( 'admin_print_footer_scripts', array( $this, 'print_find_dialog' ) );

			// setup jqueryFiletree list callback
			add_action( 'wp_ajax_jqueryFileTree', array( $this, 'jqueryFileTree_get_list' ) );
			// setup ajax function to get file contents for editing
			add_action( 'wp_ajax_simple_ide_get_file', array( $this, 'get_file' ) );
			// setup ajax function to save file contents and do automatic backup if needed
			add_action( 'wp_ajax_simple_ide_save_file', array( $this, 'save_file' ) );
			// setup ajax function to rename file/folder
			add_action( 'wp_ajax_simple_ide_rename_file', array( $this, 'rename_file' ) );
			// setup ajax function to delete file/folder
			add_action( 'wp_ajax_simple_ide_delete_file', array( $this, 'delete_file' ) );
			// setup ajax function to handle upload
			add_action( 'wp_ajax_simple_ide_upload_file', array( $this, 'upload_file' ) );
			// setup ajax function to handle download
			add_action( 'wp_ajax_simple_ide_download_file', array( $this, 'download_file' ) );
			// setup ajax function to unzip file
			add_action( 'wp_ajax_simple_ide_unzip_file', array( $this, 'unzip_file' ) );
			// setup ajax function to zip file
			add_action( 'wp_ajax_simple_ide_zip_file', array( $this, 'zip_file' ) );
			// setup ajax function to create new item (folder, file etc)
			add_action( 'wp_ajax_simple_ide_create_new', array( $this, 'create_new' ) );

			// setup ajax function to create new item (folder, file etc)
			add_action( 'wp_ajax_simple_ide_image_edit_key', array( $this, 'image_edit_key' ) );

			// setup ajax function for startup to get some debug info, checking permissions etc
			add_action( 'wp_ajax_simple_ide_startup_check', array( $this, 'startup_check' ) );

			// add a warning when navigating away from Simple IDE
			// it has to go after WordPress scripts otherwise WP clears the binding
			// This has been implemented in load-editor.js
			// todo:
			// add_action('admin_print_footer_scripts', array( $this, 'add_admin_nav_warning' ), 99);

			// Add body class to collapse the wp sidebar nav
			add_filter( 'admin_body_class', array( $this, 'hide_wp_sidebar_nav' ), 11 );

			// hide the update nag
			add_action( 'admin_menu', array( $this, 'hide_wp_update_nag' ) );

			add_filter(
				'plugin_action_links_' . plugin_basename( __FILE__ ),
				array( $this, 'plugin_action_links' )
			);
		}


		// add settings link to plugins page
		function plugin_action_links( $links ) {
			$settings_link = '<a href="' . admin_url( 'admin.php?page=simple_ide' ) . '" title="Manage Files">Manage Files</a>';

			array_unshift( $links, $settings_link );

			return $links;
		} // plugin_action_links


		public function hide_wp_sidebar_nav( $classes ) {
			global $hook_suffix;

			if ( ! $this->is_plugin_page() ) {
				return $classes;
			}

			if ( apply_filters( 'simple_ide_sidebar_folded', $hook_suffix === $this->menu_hook ) ) {
				return str_replace( 'auto-fold', '', $classes ) . ' folded';
			}
		}

		public function hide_wp_update_nag() {
			if ( ! $this->is_plugin_page() ) {
				return;
			}

			remove_action( 'admin_notices', 'update_nag', 3 );
		}

		public function add_admin_nav_warning() {
			?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			window.onbeforeunload = function() {
			return 'You are attempting to navigate away from Simple IDE. Make sure you have saved any changes made to your files otherwise they will be forgotten.';
			}
		});
		</script>
			<?php
		}

		public function add_admin_js() {
			$plugin_path = plugin_dir_url( __FILE__ );
			// include file tree
			wp_enqueue_script( 'jquery-file-tree', plugins_url( 'js/jqueryFileTree.js', __FILE__ ) );

			$ace_version = '1.32.5';
			// include ace
			wp_enqueue_script( 'ace', plugins_url( "js/ace-$ace_version/ace.js", __FILE__ ) );
			// include ace modes for css, javascript & php
			wp_enqueue_script( 'ace-mode-css', $plugin_path . "js/ace-$ace_version/mode-css.js" );
			wp_enqueue_script( 'ace-mode-less', $plugin_path . "js/ace-$ace_version/mode-less.js" );
			wp_enqueue_script( 'ace-mode-javascript', $plugin_path . "js/ace-$ace_version/mode-javascript.js" );
			wp_enqueue_script( 'ace-mode-php', $plugin_path . "js/ace-$ace_version/mode-php.js" );
			// include ace theme
			wp_enqueue_script( 'ace-theme', plugins_url( "js/ace-$ace_version/theme-dawn.js", __FILE__ ) ); // ambiance looks really nice for high contrast
			// wordpress-completion tags
			wp_enqueue_script( 'simple-ide-wordpress-completion', plugins_url( 'js/autocomplete/wordpress.js', __FILE__ ) );
			// php-completion tags
			wp_enqueue_script( 'simple-ide-php-completion', plugins_url( 'js/autocomplete/php.js', __FILE__ ) );
			// load editor
			wp_enqueue_script( 'simple-ide-load-editor', plugins_url( 'js/load-editor.js', __FILE__ ), array( 'jquery-ui-dialog' ) );
			// load filetree menu
			wp_enqueue_script( 'simple-ide-load-filetree-menu', plugins_url( 'js/load-filetree-menu.js', __FILE__ ) );
			// load autocomplete dropdown
			wp_enqueue_script( 'simple-ide-dd', plugins_url( 'js/jquery.dd.min.js', __FILE__ ) );

			// load color picker
			wp_enqueue_script( 'ImageColorPicker', plugins_url( 'js/ImageColorPicker.js', __FILE__ ), array( 'jquery-ui-widget' ), '0.3' );
		}

		public function add_admin_styles() {
			// main Simple IDE styles
			wp_enqueue_style( 'simple-ide-style', plugins_url( 'css/simple-ide.min.css', __FILE__ ), array( 'wp-jquery-ui-dialog' ) );
			// filetree styles
			wp_enqueue_style( 'simple-ide-filetree-style', plugins_url( 'css/jqueryFileTree.css', __FILE__ ) );
			// autocomplete dropdown styles
			wp_enqueue_style( 'simple-ide-dd-style', plugins_url( 'css/dd.css', __FILE__ ) );
		}

		public function jqueryFileTree_get_list() {
			// check the user has the permissions
			check_admin_referer( 'simple_ide_nonce' );
			if ( ! current_user_can( 'edit_themes' ) ) {
				wp_die( '<p>You do not have sufficient permissions to edit files for this site.</p>' );
			}

			// setup wp_filesystem api
			global $wp_filesystem;
			$url         = wp_nonce_url( 'admin.php?page=simple_ide', 'simple_ide_nonce' );
			$form_fields = null; // for now, but at some point the login info should be passed in here
			if ( false === ( $creds = request_filesystem_credentials( $url, FS_METHOD, false, false, $form_fields ) ) ) {
				// no credentials yet, just produced a form for the user to fill in
				return true; // stop the normal page form from displaying
			}

			if ( ! WP_Filesystem( $creds ) ) {
				return false;
			}

			$_POST['dir'] = urldecode( $_POST['dir'] );
			$root         = apply_filters( 'simple_ide_filesystem_root', WP_CONTENT_DIR );

			if ( $wp_filesystem->exists( $root . $_POST['dir'] ) ) {

				$files = $wp_filesystem->dirlist( $root . $_POST['dir'] );

				echo '<ul class="jqueryFileTree" style="display: none;">';
				if ( count( $files ) > 0 ) {

					// build separate arrays for folders and files
					$dir_array  = array();
					$file_array = array();
					foreach ( $files as $file => $file_info ) {
						if ( $file != '.' && $file != '..' && $file_info['type'] == 'd' ) {
							$file_string               = strtolower( preg_replace( '[._-]', '', $file ) );
							$dir_array[ $file_string ] = $file_info;
						} elseif ( $file != '.' && $file != '..' && $file_info['type'] == 'f' ) {
							$file_string                = strtolower( preg_replace( '[._-]', '', $file ) );
							$file_array[ $file_string ] = $file_info;
						}
					}

					// shot those arrays
					ksort( $dir_array );
					ksort( $file_array );

					// All dirs
					foreach ( $dir_array as $file => $file_info ) {
						echo '<li class="directory collapsed" draggable="true"><a href="#" rel="' . htmlentities( $_POST['dir'] . $file_info['name'] ) . '/" draggable="false">' . htmlentities( $file_info['name'] ) . '</a></li>';
					}
					// All files
					foreach ( $file_array as $file => $file_info ) {
						$ext = preg_replace( '/^.*\./', '', $file_info['name'] );
						echo "<li class=\"file ext_$ext\" draggable=\"true\"><a href=\"#\" rel=\"" . htmlentities( $_POST['dir'] . $file_info['name'] ) . '" draggable="false">' . htmlentities( $file_info['name'] ) . '</a></li>';
					}
				}
				// output toolbar for creating new file, folder etc
				echo "<li class=\"create_new\"><a class='new_directory' title='Create a new directory here.' href=\"#\" rel=\"{type: 'directory', path: '" . htmlentities( $_POST['dir'] ) . "'}\"></a> <a class='new_file' title='Create a new file here.' href=\"#\" rel=\"{type: 'file', path: '" . htmlentities( $_POST['dir'] ) . "'}\"></a><br style='clear:both;' /></li>";
				echo '</ul>';
			}

			die(); // this is required to return a proper result
		}


		public function get_file() {
			// check the user has the permissions
			check_admin_referer( 'simple_ide_nonce' );
			if ( ! current_user_can( 'edit_themes' ) ) {
				wp_die( '<p>You do not have sufficient permissions to edit files for this site.</p>' );
			}

			// setup wp_filesystem api
			global $wp_filesystem;
			$url         = wp_nonce_url( 'admin.php?page=simple_ide', 'simple_ide_nonce' );
			$form_fields = null; // for now, but at some point the login info should be passed in here
			if ( false === ( $creds = request_filesystem_credentials( $url, FS_METHOD, false, false, $form_fields ) ) ) {
				// no credentials yet, just produced a form for the user to fill in
				return true; // stop the normal page form from displaying
			}
			if ( ! WP_Filesystem( $creds ) ) {
				return false;
			}

			$root      = apply_filters( 'simple_ide_filesystem_root', WP_CONTENT_DIR );
			$file_name = $root . sanitize_text_field( $_POST['filename'] );
			echo $wp_filesystem->get_contents( $file_name );
			die(); // this is required to return a proper result
		}

		public function image_edit_key() {

			// check the user has the permissions
			check_admin_referer( 'simple_ide_nonce' );
			if ( ! current_user_can( 'edit_themes' ) ) {
				wp_die( '<p>You do not have sufficient permissions to edit files for this site.</p>' );
			}

			// create a nonce based on the image path
			echo wp_create_nonce( 'simple_ide_image_edit' . $_POST['file'] );
		}

		public function create_new() {
			// check the user has the permissions
			check_admin_referer( 'simple_ide_nonce' );
			if ( ! current_user_can( 'edit_themes' ) ) {
				wp_die( '<p>You do not have sufficient permissions to edit files for this site.</p>' );
			}

			// setup wp_filesystem api
			global $wp_filesystem;
			$url         = wp_nonce_url( 'admin.php?page=simple_ide', 'simple_ide_nonce' );
			$form_fields = null; // for now, but at some point the login info should be passed in here
			if ( false === ( $creds = request_filesystem_credentials( $url, FS_METHOD, false, false, $form_fields ) ) ) {
				// no credentials yet, just produced a form for the user to fill in
				return true; // stop the normal page form from displaying
			}
			if ( ! WP_Filesystem( $creds ) ) {
				return false;
			}

			$root = apply_filters( 'simple_ide_filesystem_root', WP_CONTENT_DIR );

			// check all required vars are passed
			if ( strlen( $_POST['path'] ) > 0 && strlen( $_POST['type'] ) > 0 && strlen( $_POST['file'] ) > 0 ) {
				$filename      = $_POST['file'];
				$special_chars = array( '?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', chr( 0 ) );
				$filename      = preg_replace( "#\x{00a0}#siu", ' ', $filename );
				$filename      = str_replace( $special_chars, '', $filename );
				$filename      = str_replace( array( '%20', '+' ), '-', $filename );
				$filename      = preg_replace( '/[\r\n\t -]+/', '-', $filename );

				$path = $_POST['path'];

				if ( $_POST['type'] == 'directory' ) {
					$write_result = $wp_filesystem->mkdir( $root . $path . $filename, FS_CHMOD_DIR );

					if ( $write_result ) {
						die( '1' ); // created
					} else {
						echo wp_kses_post( 'Problem creating directory' . $root . $path . $filename );
					}
				} elseif ( $_POST['type'] == 'file' ) {
					// write the file
					$write_result = $wp_filesystem->put_contents(
						$root . $path . $filename,
						'',
						FS_CHMOD_FILE // predefined mode settings for WP files
					);

					if ( $write_result ) {
						die( '1' ); // created
					} else {
						echo wp_kses_post( 'Problem creating file ' . $root . $path . $filename );
					}
				}
				// print_r($_POST);
			}

			echo '0';
			die(); // this is required to return a proper result
		}

		public function save_file() {
			// check the user has the permissions
			check_admin_referer( 'simple_ide_nonce' );
			if ( ! current_user_can( 'edit_themes' ) ) {
				wp_die( '<p>You do not have sufficient permissions to edit files for this site.</p>' );
			}

			$is_php  = false;

			// base64 required to make sure the content not blocked by WAF
			$content = base64_decode( sanitize_textarea_field( $_POST['content'] ) );

			/*
			* Check file syntax of PHP files by parsing the PHP
			* If a site is running low on memory this PHP parser library could well tip memory usage over the edge
			* Especially if you are editing a large PHP file.
			* Might be worth either making this syntax check optional or it only running if memory is available.
			* Symptoms: no response on file save, and errors in your log like "Fatal error: Allowed memory size of 8388608 bytes exhausted…"
			*/
			if ( preg_match( '#\.php$#i', $_POST['filename'] ) ) {

				$is_php = true;

				ini_set( 'xdebug.max_nesting_level', 2000 );

				$code = stripslashes( $content );

				$parser = ( new \PhpParser\ParserFactory() )->create( \PhpParser\ParserFactory::PREFER_PHP7 );

				try {
					$stmts = $parser->parse( $code );
				} catch ( \PhpParser\Error $e ) {
					echo 'Parse Error: ', $e->getMessage();
					die();
				}
			}

			// setup wp_filesystem api
			global $wp_filesystem;
			$url         = wp_nonce_url( 'admin.php?page=simple_ide', 'simple_ide_nonce' );
			$form_fields = null; // for now, but at some point the login info should be passed in here
			if ( false === ( $creds = request_filesystem_credentials( $url, FS_METHOD, false, false, $form_fields ) ) ) {
				// no credentials yet, just produced a form for the user to fill in
				return true; // stop the normal page form from displaying
			}
			if ( ! WP_Filesystem( $creds ) ) {
				echo 'Cannot initialise the WP file system API';
			}

			// save a copy of the file and create a backup just in case
			$root      = apply_filters( 'simple_ide_filesystem_root', WP_CONTENT_DIR );
			$file_name = $root . sanitize_text_field( $_POST['filename'] );

			// set backup filename
			$backup_path      = 'backups' . preg_replace( '#\.php$#i', '_' . date( 'Y-m-d-H' ) . '.php', $_POST['filename'] );
			$backup_path_full = WP_CONTENT_DIR . '/' . $backup_path;
			// create backup directory if not there
			$new_file_info = pathinfo( $backup_path_full );
			if ( ! $wp_filesystem->is_dir( $new_file_info['dirname'] ) ) {
				wp_mkdir_p( $new_file_info['dirname'] ); // should use the filesytem api here but there isn't a comparable command right now
			}

			if ( $is_php ) {
				// create the backup file adding some php to the file to enable direct restore
				global $current_user;
				wp_get_current_user();
				$user_md5 = md5( serialize( $current_user ) );

				$restore_php = '<?php /* start Simple IDE restore code */
                                    if ($_POST["restorewpnonce"] === "' . $user_md5 . $_POST['_wpnonce'] . '"){
                                        if ( file_put_contents ( "' . $file_name . '" ,  preg_replace("#<\?php /\* start Simple IDE(.*)end Simple IDE restore code \*/ \?>#s", "", file_get_contents("' . $backup_path_full . '") )  ) ){
                                            echo "Your file has been restored, overwritting the recently edited file! \n\n The active editor still contains the broken or unwanted code. If you no longer need that content then close the tab and start fresh with the restored file.";
                                        }
                                    }else{
                                        echo "-1";
                                    }
                                    die();
                            /* end Simple IDE restore code */ ?>';

				file_put_contents( $backup_path_full, $restore_php . file_get_contents( $file_name ) );
			} else {
				// do normal backup
				$wp_filesystem->copy( $file_name, $backup_path_full );
			}

			// save file
			if ( $wp_filesystem->put_contents( $file_name, $content ) ) {

				// lets create an extra long nonce to make it less crackable
				global $current_user;
				wp_get_current_user();
				$user_md5 = md5( serialize( $current_user ) );

				$result = '"' . $backup_path . ':::' . $user_md5 . '"';
			}

			die( $result ); // this is required to return a proper result
		}


		public function rename_file() {
			global $wp_filesystem;

			// check the user has the permissions
			check_admin_referer( 'simple_ide_nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( '<p>You do not have sufficient permissions to edit files for this site.</p>' );
			}

			$url         = wp_nonce_url( 'admin.php?page=simple_ide', 'simple_ide_nonce' );
			$form_fields = null; // for now, but at some point the login info should be passed in here
			$creds       = request_filesystem_credentials( $url, FS_METHOD, false, false, $form_fields );
			if ( false === $creds ) {
				// no credentials yet, just produced a form for the user to fill in
				return true; // stop the normal page form from displaying
			}
			if ( ! WP_Filesystem( $creds ) ) {
				echo 'Cannot initialise the WP file system API';
			}

			$root      = apply_filters( 'simple_ide_filesystem_root', WP_CONTENT_DIR );
			$file_name = $root . sanitize_text_field( $_POST['filename'] );
			$new_name  = dirname( $file_name ) . '/' . stripslashes( $_POST['newname'] );

			if ( ! $wp_filesystem->exists( $file_name ) ) {
				echo 'The target file doesn\'t exist!';
				exit;
			}

			if ( $wp_filesystem->exists( $new_name ) ) {
				echo 'The destination file exists!';
				exit;
			}

			// Move instead of rename
			$renamed = $wp_filesystem->move( $file_name, $new_name );

			if ( ! $renamed ) {
				echo 'The file could not be renamed!';
			}
			exit;
		}


		public function delete_file() {
			global $wp_filesystem;

			// check the user has the permissions
			check_admin_referer( 'simple_ide_nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( '<p>You do not have sufficient permissions to edit files for this site.</p>' );
			}

			$url         = wp_nonce_url( 'admin.php?page=simple_ide', 'simple_ide_nonce' );
			$form_fields = null; // for now, but at some point the login info should be passed in here
			$creds       = request_filesystem_credentials( $url, FS_METHOD, false, false, $form_fields );
			if ( false === $creds ) {
				// no credentials yet, just produced a form for the user to fill in
				return true; // stop the normal page form from displaying
			}
			if ( ! WP_Filesystem( $creds ) ) {
				echo 'Cannot initialise the WP file system API';
			}

			$root      = apply_filters( 'simple_ide_filesystem_root', WP_CONTENT_DIR );
			$file_name = $root . sanitize_text_field( $_POST['filename'] );

			if ( ! $wp_filesystem->exists( $file_name ) ) {
				echo 'The file doesn\'t exist!';
				exit;
			}

			$deleted = $wp_filesystem->delete( $file_name );

			if ( ! $deleted ) {
				echo 'The file couldn\'t be deleted.';
			}

			exit;
		}

		public function upload_file() {
			global $wp_filesystem;

			// check the user has the permissions
			check_admin_referer( 'simple_ide_nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( '<p>You do not have sufficient permissions to edit files for this site.</p>' );
			}

			$url         = wp_nonce_url( 'admin.php?page=simple_ide', 'simple_ide_nonce' );
			$form_fields = null; // for now, but at some point the login info should be passed in here
			$creds       = request_filesystem_credentials( $url, FS_METHOD, false, false, $form_fields );
			if ( false === $creds ) {
				// no credentials yet, just produced a form for the user to fill in
				return true; // stop the normal page form from displaying
			}
			if ( ! WP_Filesystem( $creds ) ) {
				echo 'Cannot initialise the WP file system API';
			}

			$root               = apply_filters( 'simple_ide_filesystem_root', WP_CONTENT_DIR );
			$destination_folder = $root . stripslashes( $_POST['destination'] );

			foreach ( $_FILES as $file ) {
				if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
					continue;
				}

				$destination = $destination_folder . $file['name'];

				if ( $wp_filesystem->exists( $destination ) ) {
					exit( $file['name'] . ' already exists!' );
				}

				if ( ! $wp_filesystem->move( $file['tmp_name'], $destination ) ) {
					exit( $file['name'] . ' could not be moved.' );
				}

				if ( ! $wp_filesystem->chmod( $destination ) ) {
					exit( $file['name'] . ' could not be chmod.' );
				}
			}
			exit;
		}

		public function download_file() {
			global $wp_filesystem;

			// check the user has the permissions
			check_admin_referer( 'simple_ide_nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( '<p>You do not have sufficient permissions to edit files for this site.</p>' );
			}

			$url         = wp_nonce_url( 'admin.php?page=simple_ide', 'simple_ide_nonce' );
			$form_fields = null; // for now, but at some point the login info should be passed in here
			$creds       = request_filesystem_credentials( $url, FS_METHOD, false, false, $form_fields );
			if ( false === $creds ) {
				// no credentials yet, just produced a form for the user to fill in
				return true; // stop the normal page form from displaying
			}
			if ( ! WP_Filesystem( $creds ) ) {
				echo 'Cannot initialise the WP file system API';
			}

			$root      = apply_filters( 'simple_ide_filesystem_root', WP_CONTENT_DIR );
			$file_name = $root . sanitize_text_field( $_POST['filename'] );

			if ( ! $wp_filesystem->exists( $file_name ) ) {
				echo 'The file doesn\'t exist!';
				exit;
			}

			header( 'Content-Description: File Transfer' );
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Disposition: filename="' . basename( $file_name ) . '"' );
			header( 'Expires: 0' );
			header( 'Cache-Control: must-revalidate' );
			header( 'Pragma: public' );

			echo $wp_filesystem->get_contents( $file_name );
			exit;
		}

		public function zip_file() {
			global $wp_filesystem;

			// check the user has the permissions
			check_admin_referer( 'simple_ide_nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( '<p>You do not have sufficient permissions to edit files for this site.</p>' );
			}

			$root      = apply_filters( 'simple_ide_filesystem_root', WP_CONTENT_DIR );
			$file_name = $root . sanitize_text_field( $_POST['filename'] );

			$url         = wp_nonce_url( 'admin.php?page=simple_ide', 'simple_ide_nonce' );
			$form_fields = null; // for now, but at some point the login info should be passed in here
			$creds       = request_filesystem_credentials( $url, FS_METHOD, false, false, $form_fields );
			if ( false === $creds ) {
				// no credentials yet, just produced a form for the user to fill in
				return true; // stop the normal page form from displaying
			}
			if ( ! WP_Filesystem( $creds ) ) {
				echo 'Cannot initialise the WP file system API';
			}

			if ( ! $wp_filesystem->exists( $file_name ) ) {
				echo 'Error: target file does not exist!';
				exit;
			}

			$ext = '.zip';
			switch ( apply_filters( 'simple_ide_compression_method', 'zip' ) ) {
				case 'gz':
					$ext = '.tar.gz';
					break;
				case 'tar':
					$ext = '.tar';
					break;
				case 'b2z':
					$ext = '.b2z';
					break;
				case 'zip':
					$ext = '.zip';
					break;
			}

			// Unzip a file to its current directory.
			if ( $wp_filesystem->is_dir( $file_name ) ) {
				$output_path = dirname( $file_name ) . '/' . basename( $file_name ) . $ext;
			} else {
				$output_path = $file_name;
				$output_path = strstr( $file_name, '.', true ) . $ext;
			}

			$zipped = self::do_zip_file( $file_name, $output_path );

			if ( is_wp_error( $zipped ) ) {
				printf( '%s: %s', $zipped->get_error_code(), $zipped->get_error_message() );
				exit;
			}

			exit;
		}

		protected function do_zip_file( $file, $to ) {
			// Unzip can use a lot of memory, but not this much hopefully
			@ini_set( 'memory_limit', apply_filters( 'admin_memory_limit', WP_MAX_MEMORY_LIMIT ) );

			$method = apply_filters( 'simple_ide_compression_method', 'zip' );

			switch ( $method ) {
				case 'gz':
				case 'tar':
					if ( class_exists( 'PharData' ) && apply_filters( 'unzip_file_use_phardata', true ) ) {
						exit( 'yes' );
					} else {
						exit( 'figure it out' );
					}

					/*
								if ( $method === 'gz' ) {
					$gz = gzopen( $to );
					}
					*/
					break;
				case 'b2z':
					exit( 'B2Z!' );
				case 'zip':
				default:
					if ( $method !== 'zip' ) {
						trigger_error( sprintf( '"%s" is not a valid compression mechanism.', $method ) );
					}

					if ( class_exists( 'ZipArchive' ) && apply_filters( 'unzip_file_use_ziparchive', true ) ) {
						return self::_zip_file_ziparchive( $file, $to );
					} else {
						// Fall through to PclZip if ZipArchive is not available, or encountered an error opening the file.
						return self::_zip_file_pclzip( $file, $to );
					}
					break;
			}
		}

		protected static function _zip_file_ziparchive( $file, $to ) {
			$z      = new ZipArchive();
			$opened = $z->open( $to, ZipArchive::CREATE );

			if ( $opened !== true ) {
				switch ( $opened ) {
					case ZipArchive::ER_EXISTS:
						return new WP_Error(
							'ZipArchive Error',
							'File already exists',
							ZipArchive::ER_EXISTS
						);
					case ZipArchive::ER_INCONS:
						return new WP_Error(
							'ZipArchive Error',
							'Archive inconsistent',
							ZipArchive::ER_INCONS
						);
					case ZipArchive::ER_INVAL:
						return new WP_Error(
							'ZipArchive Error',
							'Invalid argument',
							ZipArchive::ER_INVAL
						);
					case ZipArchive::ER_MEMORY:
						return new WP_Error(
							'ZipArchive Error',
							'Malloc failure',
							ZipArchive::ER_MEMORY
						);
					case ZipArchive::ER_NOENT:
						return new WP_Error(
							'ZipArchive Error',
							'No such file.',
							ZipArchive::ER_NOENT
						);
					case ZipArchive::ER_NOZIP:
						return new WP_Error(
							'ZipArchive Error',
							'Not a zip archive.',
							ZipArchive::ER_NOZIP
						);
					case ZipArchive::ER_OPEN:
						return new WP_Error(
							'ZipArchive Error',
							'Can\'t open file.',
							ZipArchive::ER_OPEN
						);
					case ZipArchive::ER_READ:
						return new WP_Error(
							'ZipArchive Error',
							'Read Error',
							ZipArchive::ER_READ
						);
					case ZipArchive::ER_SEEK:
						return new WP_Error(
							'ZipArchive Error',
							'Seek Error',
							ZipArchive::ER_SEEK
						);

					default:
						return new WP_Error(
							'ZipArchive Error',
							'Unknown Error',
							$opened
						);
						break;
				}
			}

			if ( is_dir( $file ) ) {
				$base = dirname( $file );
				$file = untrailingslashit( $file );

				$z = self::_zip_folder_ziparchive( $base, $file, $to, $z );
				if ( is_wp_error( $z ) ) {
					return $z;
				}
			} else {
				$z->addFile( $file, basename( $file ) );
			}

			$z->close();

			return true;
		}

		protected static function _zip_folder_ziparchive( $zip_base, $folder, $to, $z ) {
			$handle = opendir( $folder );
			while ( 1 ) {
				$file = readdir( $handle );

				if ( false === $file ) {
					break;
				}

				if ( ( $file != '.' ) && ( $file != '..' ) ) {
					$filePath    = "$folder/$file";
					$filePathRel = str_replace( $zip_base, '', $filePath );

					if ( $filePathRel[0] === '/' ) {
						$filePathRel = substr( $filePathRel, 1 );
					}

					if ( is_file( $filePath ) ) {
						$z->addFile( $filePath, $filePathRel );
					} elseif ( is_dir( $filePath ) ) {
						// Add sub-directory.
						$z->addEmptyDir( $filePathRel );
						self::_zip_folder_ziparchive( $zip_base, $filePath, $to, $z );
					}
				}
			}
			closedir( $handle );

			return $z;
		}

		protected static function _zip_file_pclzip( $file, $to ) {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

			$pz      = new PclZip( $to );
			$created = $pz->create( $file, PCLZIP_OPT_REMOVE_PATH, dirname( $file ) );

			if ( ! $created ) {
				return new WP_Error( 'PclZip Error', $pz->errorInfo( true ) );
			}

			return true;
		}

		protected static function _zip_file_phardata( $file, $to ) {
			$p = new PharData( $to );

			if ( is_dir( $file ) ) {
				$p->buildFromDirectory( $file );
			} else {
				$p->addFile( $file, basename( $file ) );
			}

			return true;
		}

		public function unzip_file() {
			global $wp_filesystem;

			// check the user has the permissions
			check_admin_referer( 'simple_ide_nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( '<p>You do not have sufficient permissions to edit files for this site.</p>' );
			}

			$root      = apply_filters( 'simple_ide_filesystem_root', WP_CONTENT_DIR );
			$file_name = $root . sanitize_text_field( $_POST['filename'] );

			$url         = wp_nonce_url( 'admin.php?page=simple_ide', 'simple_ide_nonce' );
			$form_fields = null; // for now, but at some point the login info should be passed in here
			$creds       = request_filesystem_credentials( $url, FS_METHOD, false, false, $form_fields );
			if ( false === $creds ) {
				// no credentials yet, just produced a form for the user to fill in
				return true; // stop the normal page form from displaying
			}
			if ( ! WP_Filesystem( $creds ) ) {
				echo 'Cannot initialise the WP file system API';
			}

			if ( ! $wp_filesystem->exists( $file_name ) ) {
				echo 'Error: Extraction path doesn\'t exist!';
				exit;
			}

			$unzipped = self::do_unzip_file( $file_name, dirname( $file_name ) );

			if ( is_wp_error( $unzipped ) ) {
				printf( '%s: %s', $unzipped->get_error_code(), $unzipped->get_error_message() );
				exit;
			}

			exit;
		}

		protected function do_unzip_file( $from, $to ) {
			if ( ! file_exists( $from ) ) {
				return new WP_Error( 'file-missing', 'Archive missing.' );
			}

			$fp    = fopen( $from, 'rb' );
			$bytes = fread( $fp, 2 );
			fclose( $fp );

			switch ( $bytes ) {
				case "\37\213":
					// gz
				case 'BZ':
					return new WP_Error( 'unimplemented', 'That method is not yet implemented.' );
				break;
				case 'PK':
					return unzip_file( $from, $to );
				default:
					return new WP_Error( 'unknown', 'Unknown archive type' );
			}
		}

		public function simple_ide_save_image() {
			$filennonce = explode( '::', $_POST['opt'] ); // file::nonce

			// check the user has a valid nonce
			// we are checking two variations of the nonce, one as-is and another that we have removed a trailing zero from
			// this is to get around some sort of bug where a nonce generated on another page has a trailing zero and a nonce generated/checked here doesn't have the zero
			if (
			! wp_verify_nonce( $filennonce[1], 'simple_ide_image_edit' . $filennonce[0] ) &&
			! wp_verify_nonce( rtrim( $filennonce[1], '0' ), 'simple_ide_image_edit' . $filennonce[0] )
			) {
				die( 'Security check' ); // die because both checks failed
			}
			// check the user has the permissions
			if ( ! current_user_can( 'edit_themes' ) ) {
				wp_die( '<p>You do not have sufficient permissions to edit files for this site.</p>' );
			}

			$_POST['content']  = base64_decode( $_POST['data'] ); // image content
			$_POST['filename'] = $filennonce[0]; // filename

			// setup wp_filesystem api
			global $wp_filesystem;
			$url         = wp_nonce_url( 'admin.php?page=simple_ide', 'simple_ide_nonce' );
			$form_fields = null; // for now, but at some point the login info should be passed in here
			if ( false === ( $creds = request_filesystem_credentials( $url, FS_METHOD, false, false, $form_fields ) ) ) {
				// no credentials yet, just produced a form for the user to fill in
				return true; // stop the normal page form from displaying
			}
			if ( ! WP_Filesystem( $creds ) ) {
				echo 'Cannot initialise the WP file system API';
			}

			// save a copy of the file and create a backup just in case
			$root      = apply_filters( 'simple_ide_filesystem_root', WP_CONTENT_DIR );
			$file_name = $root . sanitize_text_field( $_POST['filename'] );

			// set backup filename
			$backup_path = 'backups' . preg_replace( '#\.php$#i', '_' . date( 'Y-m-d-H' ) . '.php', $_POST['filename'] );
			$backup_path = WP_CONTENT_DIR . '/' . $backup_path;

			// create backup directory if not there
			$new_file_info = pathinfo( $backup_path );
			if ( ! $wp_filesystem->is_dir( $new_file_info['dirname'] ) ) {
				wp_mkdir_p( $new_file_info['dirname'] ); // should use the filesytem api here but there isn't a comparable command right now
			}

			// do backup
			$wp_filesystem->move( $file_name, $backup_path );

			// save file
			if ( $wp_filesystem->put_contents( $file_name, $_POST['content'] ) ) {
				$result = 'success';
			}

			if ( $result == 'success' ) {
				wp_die( '<p>' . __( '<strong>Image saved.</strong> <br />You may <a href="JavaScript:window.close();">close this window / tab</a>.' ) . '</p>' );
			} else {
				wp_die( '<p>' . __( '<strong>Problem saving image.</strong> <br /><a href="JavaScript:window.close();">Close this window / tab</a> and try editing the image again.' ) . '</p>' );
			}
			// print_r($_POST);
		}


		public function startup_check() {
			global $wp_filesystem, $wp_version;

			echo "\n\n\n\nSIMPLE IDE STARTUP CHECKS \n";
			echo "___________________ \n\n";

			// WordPress version
			echo 'WordPress version = ' . $wp_version . "\n\n";

			// check the user has the permissions
			check_admin_referer( 'simple_ide_nonce' );
			if ( ! current_user_can( 'edit_themes' ) ) {
				wp_die( '<p>' . __( 'You do not have sufficient permissions to edit templates for this site. SORRY' ) . '</p>' );
			}

			if ( defined( 'SIMPLE_IDE_FS_METHOD_FORCED_ELSEWHERE' ) ) {
				echo 'WordPress filesystem API has been forced to use the ' . SIMPLE_IDE_FS_METHOD_FORCED_ELSEWHERE . " method by another plugin/WordPress. \n\n";
			}

			// setup wp_filesystem api
			$simple_ide_filesystem_before = $wp_filesystem;

			$url         = wp_nonce_url( 'admin.php?page=simple_ide', 'simple_ide_nonce' );
			$form_fields = null; // for now, but at some point the login info should be passed in here
			ob_start();
			if ( false === ( $creds = request_filesystem_credentials( $url, FS_METHOD, false, false, $form_fields ) ) ) {
				// if we get here, then we don't have credentials yet,
				// but have just produced a form for the user to fill in,
				// so stop processing for now
				// return true; // stop the normal page form from displaying
			}
			ob_end_clean();
			if ( ! WP_Filesystem( $creds ) ) {

				echo "There has been a problem initialising the filesystem API \n\n";
				echo "Filesystem API before this plugin ran: \n\n" . print_r( $simple_ide_filesystem_before, true );
				echo "Filesystem API now: \n\n" . print_r( $wp_filesystem, true );
			}
			unset( $simple_ide_filesystem_before );

			$root = apply_filters( 'simple_ide_filesystem_root', WP_CONTENT_DIR );
			if ( isset( $wp_filesystem ) ) {

				// Running webservers user and group
				echo 'Web server user/group = ' . getenv( 'APACHE_RUN_USER' ) . ':' . getenv( 'APACHE_RUN_GROUP' ) . "\n";
				// wp-content user and group
				echo 'wp-content owner/group = ' . $wp_filesystem->owner( $root ) . ':' . $wp_filesystem->group( $root ) . "\n\n";

				// check we can list wp-content files
				if ( $wp_filesystem->exists( $root ) ) {

					$files = $wp_filesystem->dirlist( $root );
					if ( count( $files ) > 0 ) {
						echo 'wp-content folder exists and contains ' . count( $files ) . " files \n";
					} else {
						echo "wp-content folder exists but we cannot read it's contents \n";
					}
				}

				// $wp_filesystem->owner() $wp_filesystem->group() $wp_filesystem->is_writable() $wp_filesystem->is_readable()
				echo "\nUsing the " . $wp_filesystem->method . " method of the WP filesystem API\n";

				// wp-content editable?
				echo 'The wp-content folder ' . ( $wp_filesystem->is_readable( $root ) == 1 ? 'IS' : 'IS NOT' ) . ' readable and ' . ( $wp_filesystem->is_writable( $root ) == 1 ? 'IS' : 'IS NOT' ) . " writable by this method \n";

				// plugins folder editable
				echo 'The wp-content/plugins folder ' . ( $wp_filesystem->is_readable( $root . '/plugins' ) == 1 ? 'IS' : 'IS NOT' ) . ' readable and ' . ( $wp_filesystem->is_writable( $root . '/plugins' ) == 1 ? 'IS' : 'IS NOT' ) . " writable by this method \n";

				// themes folder editable
				echo 'The wp-content/themes folder ' . ( $wp_filesystem->is_readable( $root . '/themes' ) == 1 ? 'IS' : 'IS NOT' ) . ' readable and ' . ( $wp_filesystem->is_writable( $root . '/themes' ) == 1 ? 'IS' : 'IS NOT' ) . " writable by this method \n";
			}

			echo "___________________ \n\n\n\n";

			echo " If the file tree to the right is empty there is a possibility that your server permissions are not compatible with this plugin. \n The startup information above may shed some light on things. \n Paste that information into the support forum for further assistance.";

			die();
		}

		public function add_my_menu_page() {
			$this->menu_hook = add_menu_page( 'Simple IDE', 'Simple IDE', 'edit_themes', 'simple_ide', array( $this, 'my_menu_page' ), 'dashicons-editor-code' );
		}

		public function my_menu_page() {
			if ( ! current_user_can( 'edit_themes' ) ) {
				wp_die( '<p>You do not have sufficient permissions to edit files for this site.</p>' );
			}

			$app_url = get_bloginfo( 'url' ); // need to make this https if we are currently looking on the site using https (even though https for admin might not be forced it can still cause issues)
			if ( is_ssl() ) {
				$app_url = str_replace( 'http:', 'https:', $app_url );
			}

			?>
		<script>
		var simple_ide_app_path = "<?php echo plugin_dir_url( __FILE__ ); ?>";
		var user_nonce_addition = '';

		function the_filetree() {
			jQuery('#simple_ide_file_browser').fileTree({
			script: ajaxurl
			}, function(parent, file) {

			if (jQuery(parent).hasClass("create_new")) { //create new file/folder
				//to create a new item we need to know the name of it so show input

				var item = eval('(' + file + ')');

				//hide all inputs just incase one is selected
				jQuery(".new_item_inputs").hide();
				//show the input form for this
				jQuery("div.new_" + item.type).show();
				jQuery("div.new_" + item.type + " input[name='new_" + item.type + "']").focus();
				jQuery("div.new_" + item.type + " input[name='new_" + item.type + "']").attr("rel", file);


			} else if (jQuery(".simple_ide_tab[rel='" + file + "']").length > 0) { //focus existing tab
				jQuery(".simple_ide_tab[sessionrel='" + jQuery(".simple_ide_tab[rel='" + file + "']").attr("sessionrel") + "']").click(); //focus the already open tab
			} else { //open file

				var image_pattern = new RegExp("(\\.jpg$|\\.gif$|\\.png$|\\.bmp$)");
				if (image_pattern.test(file)) {
				//it's an image so open it for editing

				//using modal+iframe
				if ("lets not" == "use the modal for now") {

					var NewDialog = jQuery('<div id="MenuDialog">\
							<iframe src="http://www.sumopaint.com/app/?key=ebcdaezjeojbfgih&target=<?php echo get_bloginfo( 'url' ) . '?action=simple_ide_image_save'; ?>&url=<?php echo get_bloginfo( 'url' ) . '/wp-content'; ?>' + file + '&title=Edit image&service=Save back to Simple IDE" width="100%" height="600px"> </iframe>\
							</div>');
					NewDialog.dialog({
					modal: true,
					title: "title",
					show: 'clip',
					hide: 'clip',
					width: '800',
					height: '600'
					});

				} else { //open in new tab/window

					var data = {
					action: 'simple_ide_image_edit_key',
					file: file,
					_wpnonce: jQuery('#_wpnonce').val(),
					_wp_http_referer: jQuery('#_wp_http_referer').val()
					};
					var image_data = '';
					jQuery.ajaxSetup({
					async: false
					}); //we need to wait until we get the response before opening the window
					jQuery.post(ajaxurl, data, function(response) {

					//with the response (which is a nonce), build the json data to pass to the image editor. The edit key (nonce) is only valid to edit this image
					image_data = file + '::' + response;

					});

					jQuery.ajaxSetup({
					async: true
					}); //enable async again


					window.open('http://www.sumopaint.com/app/?key=ebcdaezjeojbfgih&url=<?php echo $app_url . '/wp-content'; ?>' + file + '&opt=' + image_data + '&title=Edit image&service=Save back to Simple IDE&target=<?php echo urlencode( $app_url . '/wp-admin/admin.php?simple_ide_save_image=yes' ); ?>');

				}

				} else {
				jQuery(parent).addClass('wait');

				simple_ide_set_file_contents(file, function() {

					//once file loaded remove the wait class/indicator
					jQuery(parent).removeClass('wait');

				});

				jQuery('#filename').val(file);
				}

			}

			});
		}



		jQuery(document).ready(function($) {

			//                $("#fancyeditordiv").css("height", ($('body').height()-120) + 'px' );

			// Handler for .ready() called.
			the_filetree();

			//inialise the color assist
			$("#simple_ide_color_assist img").ImageColorPicker({
			afterColorSelected: function(event, color) {
				jQuery("#simple_ide_color_assist_input").val(color);
			}
			});
			$("#simple_ide_color_assist").hide(); //hide it until it's needed

			$("#simple_ide_color_assist_send").click(function(e) {
			e.preventDefault();
			editor.insert(jQuery("#simple_ide_color_assist_input").val().replace('#', ''));

			$("#simple_ide_color_assist").hide(); //hide it until it's needed again
			});

			$(".close_color_picker a").click(function(e) {
			e.preventDefault();
			$("#simple_ide_color_assist").hide(); //hide it until it's needed again
			});

			$("#simple_ide_toolbar_buttons").on('click', "a.restore", function(e) {
			e.preventDefault();
			var file_path = jQuery(".simple_ide_tab.active", "#simple_ide_toolbar").data("backup");

			jQuery("#simple_ide_message").hide(); //might be shortly after a save so a message may be showing, which we don't need
			jQuery("#simple_ide_message").html('<span><strong>File available for restore</strong><p> ' + file_path + '</p><a class="button red restore now" href="' + simple_ide_app_path + file_path + '">Restore this file now &#10012;</a><a class="button restore cancel" href="#">Cancel &#10007;</a><br /><em class="note"><strong>note: </strong>You can browse all file backups if you navigate to the backups folder (plugins/simple-ide/backups/..) using the filetree.</em></span>');
			jQuery("#simple_ide_message").show();
			});
			$("#simple_ide_toolbar_buttons").on('click', "a.restore.now", function(e) {
			e.preventDefault();

			var data = {
				restorewpnonce: user_nonce_addition + jQuery('#_wpnonce').val()
			};
			jQuery.post(simple_ide_app_path + jQuery(".simple_ide_tab.active", "#simple_ide_toolbar").data("backup"), data, function(response) {

				if (response == -1) {
				alert("Problem restoring file.");
				} else {
				alert(response);
				jQuery("#simple_ide_message").hide();
				}

			});

			});
			$("#simple_ide_toolbar_buttons").on('click', "a.cancel", function(e) {
			e.preventDefault();

			jQuery("#simple_ide_message").hide(); //might be shortly after a save so a message may be showing, which we don't need
			});

		});
		</script>

		<div class="u-flex u-flex-col u-h-full u-bg-white">

		<div class="u-p-2 u-border-b u-border-gray-300 u-flex u-items-center">
			<img class="u-h-6 u-w-auto" src="<?php echo plugins_url( 'images/simple-ide-logo.png', __FILE__ ); ?>" alt="">

			<button type="button" class="simple-ide-settings button u-ml-auto u-flex u-items-center u-p-2">
			<svg xmlns="http://www.w3.org/2000/svg" class="u-h-5 u-w-5 u-mr-2" viewBox="0 0 20 20" fill="currentColor">
				<path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" />
			</svg>
			<span>Settings</span>
			</button>
		</div>

		<div class="u-flex u-flex-row-reverse u-flex-grow u-min-h-0 u-divide-x u-divide-x-reverse u-divide-gray-300">

			<!-- sidebar -->
			<div class="u-bg-gray-100 u-flex u-flex-col u-flex-shrink-0 u-w-1/4 u-overflow-y-auto u-divide-y u-divide-gray-300">

			<div id="simple_ide_info">
				<div id="simple_ide_info_content" class="u-p-2"></div>
			</div>

			<div id="simple_ide_color_assist" class="u-relative">
				<div class="u-border-b u-border-gray-300 u-text-xs u-font-bold u-px-2 u-py-1 u-flex u-items-center">
				<span class="u-uppercase">Color Assist</span>
				<a href="" class="close-color-picker u-ml-auto u-no-underline">
					<svg xmlns="http://www.w3.org/2000/svg" class="u-block u-h-4 u-w-4" viewBox="0 0 20 20" fill="currentColor">
					<path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
					</svg>
				</a>
				</div>
				<img src='<?php echo plugins_url( 'images/color-wheel.png', __FILE__ ); ?>' />
				<input type="button" class="button" id="simple_ide_color_assist_send" value="&lt; Send to editor" />
				<input type="text" id="simple_ide_color_assist_input" name="simple_ide_color_assist_input" value="" />
			</div>

			<div id="simple-ide-filetree" class="u-relative">
				<div class="u-border-b u-border-gray-300 u-text-xs u-font-bold u-px-2 u-py-1 u-flex u-items-center">
				<span class="u-uppercase">Files</span>
				</div>
				<div id="simple_ide_file_browser"></div>
				<div class="new_file new_item_inputs">
				<label for="new_folder">File name</label><input class="has_data" name="new_file" type="text" rel="" value="" placeholder="filename.ext" />
				<a href="#" id="simple_ide_create_new_file" class="button-primary">CREATE</a>
				</div>
				<div class="new_directory new_item_inputs">
				<label for="new_directory">Directory name</label><input class="has_data" name="new_directory" type="text" rel="" value="" placeholder="foldername" />
				<a href="#" id="simple_ide_create_new_directory" class="button-primary">CREATE</a>
				</div>
			</div>

			</div>

			<div class="u-flex u-flex-col u-flex-grow u-min-w-0">

			<div id="simple_ide_toolbar" class="u-relative u-bg-gray-100 u-flex u-border-b u-border-gray-300">
				<div id="simple_ide_toolbar_tabs" class="u-flex-1 u-flex u-overflow-x-auto"></div>

				<div id="simple_ide_toolbar_buttons" style="display: none;">
				<div class="u-flex u-items-center u-justify-end">
					<div id="simple_ide_message" class="u-mr-4"></div>

					<div class="u-flex u-items-center u-p-1 u-gap-1">
					<a class="button restore" style="display:none;" title="Restore the active tab" href="#">Restore</a>

					<a href="#" id="simple_ide_save" title="Keyboard shortcut to save [Ctrl/Cmd + S]" class="button-primary u-flex u-items-center u-p-2">
						<svg xmlns="http://www.w3.org/2000/svg" class="u-h-5 u-w-5 u-mr-2" viewBox="0 0 20 20" fill="currentColor">
						<path d="M7.707 10.293a1 1 0 10-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 11.586V6h5a2 2 0 012 2v7a2 2 0 01-2 2H4a2 2 0 01-2-2V8a2 2 0 012-2h5v5.586l-1.293-1.293zM9 4a1 1 0 012 0v2H9V4z" />
						</svg>
						<span>Save</span>
					</a>
					</div>

					<input type="hidden" id="filename" name="filename" value="" />
					<?php
					if ( function_exists( 'wp_nonce_field' ) ) {
						wp_nonce_field( 'simple_ide_nonce' );
					}
					?>
				</div>
				</div>
			</div>

			<div id="fancyeditordiv"></div>

			<div id="simple-ide-statusbar" class="u-flex u-items-center u-px-2 u-h-6 u-border-t u-border-gray-300 u-text-xs u-text-gray-600">
				<span id="simple_ide_footer_message"></span>
				<span id="simple_ide_footer_message_last_saved"></span>
				<span id="simple_ide_footer_message_unsaved" class="u-text-red-700"></span>
			</div>

			</div>

		</div>

		</div>

			<?php
		}

		public function print_find_dialog() {
			if ( ! $this->is_plugin_page() ) {
				return;
			}
			?>
		<div id="editor_find_dialog" title="Find..." style="padding: 0px; display: none;">
			<?php if ( false ) : ?>
			<ul>
			<li><a href="#find-inline">Text</a></li>
			<li><a href="#find-func">Function</a></li>
			</ul>
		<?php endif; ?>
		<form id="find-inline" style="position: relative; padding: 4px; margin: 0px; height: 100%; overflow: hidden; width: 400px;">
			<label class="left"> Find<input type="search" name="find" /></label>
			<label class="left"> Replace<input type="search" name="replace" /></label>
			<div class="clear" style="height: 33px;"></div>

			<label><input type="checkbox" name="wrap" checked="checked" /> Wrap Around</label>
			<label><input type="checkbox" name="case" /> Case Sensitive</label>
			<label><input type="checkbox" name="whole" /> Match Whole Word</label>
			<label><input type="checkbox" name="regexp" /> Regular Expression</label>

			<div class="search_direction">
			Direction:
			<label><input type="radio" name="direction" value="0" /> Up</label>
			<label><input type="radio" name="direction" value="1" checked="checked" /> Down</label>
			</div>
			<div class="right">
			<input type="submit" name="submit" value="Find" class="action_button" />
			<input type="button" name="replace" value="Replace" class="action_button" />
			<input type="button" name="replace_all" value="Replace All" class="action_button" />
			<input type="button" name="cancel" value="Cancel" class="action_button" />
			</div>
		</form>
			<?php if ( false ) : ?>
			<form id="find-func">
			<label class="left"> Function<input type="search" name="find" /></label>
			<div class="right">
				<input type="submit" name="submit" value="Find Function" class="action_button" />
			</div>
			</form>
		<?php endif; ?>
		</div>
		<div id="editor_goto_dialog" title="Go to..." style="padding: 0px; display: none;"></div>
			<?php
		}
	}

	$simple_ide = new Simple_IDE();

endif; // class_exists check
