<?php
class MailPoet_User_Roles_Sync_Admin {
	public static $admin_notices = array();
	
	public static function init(){
		if( !empty($_REQUEST['page']) && !empty($_REQUEST['action']) && $_REQUEST['page'] == 'wysija_subscribers' ){
			add_action('admin_init', 'MailPoet_User_Roles_Sync_Admin::admin_init', 1);
		}
	}

	public static function admin_init(){
		switch( $_REQUEST['action'] ){
			case 'lists':
				$force_check = !empty($_REQUEST['sync-roles']) && wp_verify_nonce($_REQUEST['sync-roles'], 'mpurs_sync_roles'.get_current_user_id());
				self::list_setup( $force_check );
				if( $force_check ){
					$mpurs = array();
					if( array_key_exists('lists', self::$admin_notices) ) $mpurs['lists-synced'] = self::$admin_notices['lists'];
					if( array_key_exists('subscribers', self::$admin_notices) ) $mpurs['subs-synced'] = self::$admin_notices['subscribers'];
					wp_redirect(add_query_arg($mpurs, esc_url_raw(wp_get_referer())));
					exit;
				}else{
					if( array_key_exists('lists-synced', $_REQUEST) ) self::$admin_notices['lists'] = $_REQUEST['lists-synced'];
					if( array_key_exists('subs-synced', $_REQUEST) ) self::$admin_notices['subscribers'] = $_REQUEST['subs-synced'];
					if( !empty(self::$admin_notices) ){
						add_action('admin_notices', 'MailPoet_User_Roles_Sync_Admin::admin_notices');
					}
				}
				add_action('admin_footer', 'MailPoet_User_Roles_Sync_Admin::list_button_js');
				break;
			case 'synchlist':
				if( !empty($_REQUEST['id']) && !empty($_REQUEST['_wpnonce']) && wp_verify_nonce($_REQUEST['_wpnonce'], 'wysija_subscribers-action_synchlist-id_'.$_REQUEST['id']) ){
					self::sync_lists($_REQUEST['id']);
				}
				break;
		}
	}

	public static function list_setup( $force_check = false ){
		global $wp_roles, $wpdb;
		$lists = MailPoet_User_Roles_Sync::get_lists();
		$lists_added = 0;
		if( count($lists) == 0 || $force_check ){
			//no role lists, so we add them all
			if( $force_check ){
				$role_lists = array();
				foreach($lists as $list ){
					$role_lists[] = str_replace('wp-role-', '', $list['namekey']);
				}
			}
			$rows = array();
			foreach($wp_roles->role_names as $role => $role_name){
				if( $force_check && in_array($role, $role_lists) ) continue; //already exists, don't add again
				$rows['name'] = 'WordPress Users - '.$role_name;
				$rows['namekey'] = 'wp-role-'.$role;
				$rows['description'] = 'WordPress Users with the '. $role_name.' role';
				$wpdb->insert($wpdb->prefix."wysija_list", $rows, array('%s','%s','%s'));
				$lists_added++;
			}
			self::sync_lists();
		}
		if( $force_check || $lists_added > 0 ){
			self::$admin_notices['lists'] = $lists_added;
			add_action('admin_notices', 'MailPoet_User_Roles_Sync_Admin::admin_notices');
		}
	}

	public static function sync_lists( $only_list_id = false ){
		global $wpdb;
		$lists = MailPoet_User_Roles_Sync::get_lists();
		$new_users = $previous_users = 0;
		foreach($lists as $list){
			if( $only_list_id !== false && $only_list_id != $list['list_id'] ) continue; //if we're syncing one list, skip others
			$role = str_replace('wp-role-', '', $list['namekey']);
			$list_id = absint($list['list_id']);
			$wpdb->query("DELETE FROM ".$wpdb->prefix."wysija_user_list WHERE list_id ='$list_id'");
			$previous_users += $wpdb->rows_affected;
			//make map of mp users to wp users
			$mp_users = array();
			$user_id_map = $wpdb->get_results("SELECT user_id, wpuser_id FROM ".$wpdb->prefix."wysija_user WHERE wpuser_id != 0", ARRAY_A);
			foreach($user_id_map as $u){
				$mp_users[$u['wpuser_id']] = $u['user_id'];
			}
			$rows = array();
			foreach( get_users(array('role'=>$role)) as $user ){
				if( !empty($mp_users[$user->ID]) ){
					$rows[] = $wpdb->prepare('(%d, %d, %d, 0)', array($list_id, $mp_users[$user->ID], time()) );
					$new_users++;
				}
			}
			if( !empty($rows) ){
				$sql = "INSERT INTO  ".$wpdb->prefix."wysija_user_list (`list_id`, `user_id`, `sub_date`, `unsub_date`) VALUES ". implode(', ', $rows);
				$wpdb->query($sql);
			}
		}
		self::$admin_notices['subscribers'] = $new_users - $previous_users;
		add_action('admin_notices', 'MailPoet_User_Roles_Sync_Admin::admin_notices');
	}

	public static function admin_notices( $activated ){
		if( !empty(self::$admin_notices) ){
			echo '<div class="updated">';
			echo '<p>'. esc_html__('MailPoet User Role Sync Updated :', 'mp-user-roles-sync') . '</p>';
			if( array_key_exists('lists', self::$admin_notices) ){
				echo '<p>';
				echo esc_html(sprintf(_n('%s list added.', '%s lists added.', self::$admin_notices['lists'], 'mp-user-roles-sync'), self::$admin_notices['lists']));
				echo '</p>';
			}
			if( array_key_exists('subscribers', self::$admin_notices) ){
				echo '<p>';
				$subscribers = self::$admin_notices['subscribers'];
				if( $subscribers >= 0 ){
					echo esc_html(sprintf(_n('%s subscriber added.', '%d subscribers added.', $subscribers, 'mp-user-roles-sync'), $subscribers));
				}elseif( $subscribers < 0 ){
					echo esc_html(sprintf(_n('%s subscriber removed.', '%d subscribers removed.', $subscribers, 'mp-user-roles-sync'), $subscribers * -1 ));
				}
				echo '</p>';
			}
			if( get_option('mpurs_activated') ){
				echo '<p>'. esc_html__('Visit your new lists.', 'mp-user-roles-sync'). '</p>';
			}
			echo '</div>';
			self::$admin_notices = array(); //prevent extra runs
		}
	}

	public static function list_button_js(){
		$nonce = wp_create_nonce('mpurs_sync_roles'.get_current_user_id());
		$text = esc_html__('Sync Role Lists','mp-user-roles-sync')
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($){
			$('h2').append('<a href="?page=wysija_subscribers&amp;action=lists&sync-roles=<?php echo $nonce; ?>" class="add-new-h2"><?php echo $text; ?></a>');
		});
		</script>
		<?php
	}
	
}
MailPoet_User_Roles_Sync_Admin::init();