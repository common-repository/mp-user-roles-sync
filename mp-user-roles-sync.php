<?php
/*
 Plugin Name: MailPoet User Roles Sync
 Version: 1.0
 Plugin URI: https://wordpress.org/plugins/mailpoet-user-roles-sync/
 Description: Automatically creates a MailPoet list for each user role and keeps it synchronized
 Author: Marcus Sykes
 Author URI: http://msyk.es
 */
class MailPoet_User_Roles_Sync {
	
	public static function init(){
		if( !defined('WYSIJA') ) return; //don't run when MailPoet isn't running
		if( is_admin() ) include('mp-user-roles-sync-admin.php');
		add_action('set_user_role', 'MailPoet_User_Roles_Sync::update_user_role', 10, 3);
		add_action('add_user_role', 'MailPoet_User_Roles_Sync::update_user_role', 10, 2);
		add_action('remove_user_role', 'MailPoet_User_Roles_Sync::remove_user_role', 10, 2);
	}
	
	public static function activate(){
		include_once('mp-user-roles-sync-admin.php');
		MailPoet_User_Roles_Sync_Admin::list_setup( true );
		/* If people don't like the way we auto-create the lists, we can uncomment this and switch activation hooks at the bottom of this file
		$lists = !empty(MailPoet_User_Roles_Sync_Admin::$admin_notices['lists']) ? MailPoet_User_Roles_Sync_Admin::$admin_notices['lists'] : 0;
		$subscribers = !empty(MailPoet_User_Roles_Sync_Admin::$admin_notices['subscribers']) ? MailPoet_User_Roles_Sync_Admin::$admin_notices['subscribers'] : 0;
		wp_redirect(admin_url("admin.php?page=wysija_subscribers&action=lists&lists-synced=$lists&subs-synced=$subscribers"));
		die();
		*/
	}

	public static function get_lists(){
		global $wpdb;
		$results = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."wysija_list WHERE namekey LIKE 'wp-role-%'", ARRAY_A );
		if( !is_wp_error($results) ){
			return $results;
		}else{
			return array();
		}
	}

	//this function will remove a user from other role lists on mailpoet and add them to another list (or create if doesn't exist)
	public static function update_user_role($user_id, $roles, $old_roles = array()){
		global $wpdb, $wp_roles;
		if( !is_array($roles) ) $roles = array($roles);
		$lists = self::get_lists();
		$rows = array();
		foreach( $roles as $role ){
			foreach($lists as $list){
				$list_role = str_replace('wp-role-', '', $list['namekey']);
				if( $list_role == $role ){
					$rows['add'][] = absint($list['list_id']);
				}elseif( in_array($list_role, $old_roles) ){
					$rows['remove'][] = absint($list['list_id']);
				}
			}
		}
		if( !empty($rows) ){
			//get user id from mailpoet
			$mp_user_id = $wpdb->get_var("SELECT user_id FROM ".$wpdb->prefix."wysija_user WHERE wpuser_id=".absint($user_id));
			if( !empty($mp_user_id) ){
				$mp_user_id = absint($mp_user_id);
				$now = time();
				if( !empty($rows['add']) ){
					foreach( $rows['add'] as $k => $list_id ){
						$rows['add'][$k] = $wpdb->prepare('(%d, %d, %d, 0)', array($list_id, $mp_user_id, $now));
					}
					$sql = "INSERT INTO  ".$wpdb->prefix."wysija_user_list (`list_id`, `user_id`, `sub_date`, `unsub_date`) VALUES ". implode(', ', $rows['add']);
					$wpdb->query($sql);
				}
				if( !empty($rows['remove']) ){
					$wpdb->query('DELETE FROM '.$wpdb->prefix."wysija_user_list WHERE user_id=$mp_user_id AND list_id IN (".implode(',', $rows['remove']).")");
				}
			}
		}
	}

	public static function remove_user_role($user_id, $role){
		self::update_user_role($user_id, false, array($role));
	}
}
add_action('plugins_loaded', 'MailPoet_User_Roles_Sync::init');
register_activation_hook(__FILE__, 'MailPoet_User_Roles_Sync::activate');
//register_activation_hook(__FILE__, create_function('$a', "add_action('shutdown', 'MailPoet_User_Roles_Sync::activate');"));
