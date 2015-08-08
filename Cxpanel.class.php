<?php
// vim: set ai ts=4 sw=4 ft=php:
//
/**
 *Name         : Cxpanel.class.php
 *Author       : Andrew Nagy
 *Author       : Michael Yara
 *Created      : February 3, 2015
 *Last Updated : February 4, 2015
 *Version      : 4.1
 *Purpose      : BMO Class definition for the cxpanel module.
 *Copyright    : 2015 HEHE Enterprises, LLC
 *
 *	The following files in this module are subject to the above copyright:
 *	./brand.php
 *	./functions.inc.php
 *	./index.php
 *	./install.php
 *	./modify.php
 *	./page.cxpanel_menu.php
 *	./page.cxpanel.php
 *	./uninstall.php
 *	./lib/cxpanel.class.php
 *	./lib/dialplan.class.php
 *	./lib/logger.class.php
 *	./lib/table.class.php
 *	./lib/util.php
 */
namespace FreePBX\modules;
class Cxpanel implements \BMO {
	public function __construct($freepbx = null) {
		if ($freepbx == null) {
			throw new \Exception("Not given a FreePBX Object");
		}
		$this->freepbx = $freepbx;
	}

	public function doConfigPageInit($page) {
	}

	public function install() {

	}
	public function uninstall() {

	}
	public function backup(){

	}
	public function restore($backup){

	}
	public function genConfig() {
	}

	/**
	 * Hook functionality from userman when a user is updated
	 * @param {int} $id      The userman user id
	 * @param {string} $display The display page name where this was executed
	 * @param {array} $data    Array of data to be able to use
	 */
	public function usermanUpdateUser($id, $display, $data) {
		if(!function_exists('cxpanel_get_config')) {
			include(__DIR__.'/functions.inc.php');
		}

		$this->userman = $this->freepbx->Userman;

		//Set the add flag on the user
		$add = isset($_REQUEST['cxpanel_add_user']) ? $_REQUEST['cxpanel_add_user'] : '1';
		$this->userman->setModuleSettingByID($id, 'cxpanel', 'add', $add);

		//If a new password was set mark the user's password as dirty
		$passwordDirty = !empty($data['password']) ? '1' : '0';
		$this->userman->setModuleSettingByID($id, 'cxpanel', 'password_dirty', $passwordDirty);

		$newUsername = ($data['prevUsername'] != $data['username']) ? '1' : '0';
		//Flag FreePBX for reload
		needreload();

		/*
		* If the following conditions are met, attempt to apply the password change immediately.
		*
		*  - sync_with_userman is enabled
		*  - The user is flagged to be added to the server
		*  - The password has changed
		*
		* If the server is not up, or the user does not yet exist, do nothing.
		*/

		if($add == '1' && ($passwordDirty == '1' || $newUsername == '1')) {
			$serverInformation = cxpanel_server_get();

			if($serverInformation['sync_with_userman'] == '1') {

				try {

					//Set up the REST connection
					$webProtocol = ($serverInformation['api_use_ssl'] == '1') ? 'https' : 'http';
					$baseApiUrl = $webProtocol . '://' . $serverInformation['api_host'] . ':' . $serverInformation['api_port'] . '/communication_manager/api/resource/';
					$pest = new \CXPestJSON($baseApiUrl);
					$pest->setupAuth($serverInformation['api_username'], $serverInformation['api_password']);

					//Lookup the core server id based on the slug specified in the database
					$coreServer = $pest->get("server/coreServers/getBySlug/" . $serverInformation['name']);
					$coreServerId = $coreServer->id;

					/*
					* Locate the user on the server. If found update the password.
					*
					* We cannot grab the exact user directly, as we do not know the
					* id of the user on the server, and not all versions of the server have
					* the getByUsername REST resource.
					*
					* prevUsername is always set, regardless if there was a username change or not
					*/
					$serverUsers = $pest->get("core/" . $coreServerId . "/users");
					foreach($serverUsers as $serverUser) {
						if((string)$serverUser->username == $data['prevUsername']) {

							/*
							* Send an event to update the password on the user.
							*
							* We only have the plain text version of the password here
							* so we do not call the "noHash" REST resource. The password
							* will be hashed by the server during the update.
							*/
							$serverUser->username = $data['username'];
							if(!empty($data['password'])) {
								$serverUser->password = $data['password'];
							}
							$pest->put("core/" . $coreServerId . "/users/" . $serverUser->id, $serverUser);

							//The password is no longer dirty
							$this->userman->setModuleSettingByID($id, 'cxpanel', 'password_dirty', '0');

							break;
						}
					}

				} catch (\Exception $e) {
					//The server may be down, or the configured core server is not available
				}
			}
		}
	}

	/**
	 * Hook functionality for sending an email from userman
	 * @param {int} $id      The userman user id
	 * @param {string} $display The display page name where this was executed
	 * @param {array} $data    Array of data to be able to use
	 */
	public function usermanSendEmail($id, $display, $data) {
		if(!function_exists('cxpanel_get_config')) {
			include(__DIR__.'/functions.inc.php');
		}
		include(__DIR__."/brand.php");
		$cxpanelBrandName = !empty($cxpanelBrandName) ? $cxpanelBrandName : "iSymphony";

		$this->userman = $this->freepbx->Userman;

		$isUser = $this->userman->getModuleSettingByID($id, 'cxpanel', 'add');

		if(!$isUser) {
			return array();
		}
		//Collect email settings and user data
		$serverInformation = cxpanel_server_get();

		/*
		* If set utilize the client_host stored in the database else utilize the host
		* from the current URL.
		*/
		$clientHost = $serverInformation['client_host'];
		if($clientHost == "") {
			$httpHost = explode(':', $_SERVER['HTTP_HOST']);
			$clientHost = $httpHost[0];
		}

		//Check if the we need to use https
		$protocol = $serverInformation['client_use_ssl'] == '1' ? 'https' : 'http';
		
		$final = array();
		$final[] = "\t".sprintf(_('%s Login: %s'), $cxpanelBrandName, $protocol. '://' . $clientHost . ':' . $serverInformation['client_port'] . '/client/client');
		return $final;
	}
}
