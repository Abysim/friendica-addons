<?php
/**
 * Name: XMPP (Jabber)
 * Description: Embedded XMPP (Jabber) client
 * Version: 0.1
 * Author: Michael Vogel <https://pirati.ca/profile/heluecht>
 * Status: Unsupported
 */

use Friendica\App;
use Friendica\Core\Hook;
use Friendica\Core\Renderer;
use Friendica\DI;
use Friendica\Util\Strings;

function xmpp_install()
{
	Hook::register('addon_settings', 'addon/xmpp/xmpp.php', 'xmpp_addon_settings');
	Hook::register('addon_settings_post', 'addon/xmpp/xmpp.php', 'xmpp_addon_settings_post');
	Hook::register('page_end', 'addon/xmpp/xmpp.php', 'xmpp_script');
	Hook::register('logged_in', 'addon/xmpp/xmpp.php', 'xmpp_login');
}

function xmpp_addon_settings_post()
{
	if (!local_user() || empty($_POST['xmpp-settings-submit'])) {
		return;
	}

	DI::pConfig()->set(local_user(), 'xmpp', 'enabled', $_POST['xmpp_enabled'] ?? false);
	DI::pConfig()->set(local_user(), 'xmpp', 'individual', $_POST['xmpp_individual'] ?? false);
	DI::pConfig()->set(local_user(), 'xmpp', 'bosh_proxy', $_POST['xmpp_bosh_proxy'] ?? '');
}

function xmpp_addon_settings(App $a, &$s)
{
	if (!local_user()) {
		return;
	}

	/* Add our stylesheet to the xmpp so we can make our settings look nice */

	DI::page()['htmlhead'] .= '<link rel="stylesheet"  type="text/css" href="' . DI::baseUrl()->get() . '/addon/xmpp/xmpp.css' . '" media="all" />' . "\r\n";

	/* Get the current state of our config variable */

	$enabled = intval(DI::pConfig()->get(local_user(), 'xmpp', 'enabled'));
	$enabled_checked = (($enabled) ? ' checked="checked" ' : '');

	$individual = intval(DI::pConfig()->get(local_user(), 'xmpp', 'individual'));
	$individual_checked = (($individual) ? ' checked="checked" ' : '');

	$bosh_proxy = DI::pConfig()->get(local_user(), "xmpp", "bosh_proxy");

	/* Add some HTML to the existing form */
	$s .= '<span id="settings_xmpp_inflated" class="settings-block fakelink" style="display: block;" onclick="openClose(\'settings_xmpp_expanded\'); openClose(\'settings_xmpp_inflated\');">';
	$s .= '<h3>' . DI::l10n()->t('XMPP-Chat (Jabber)') . '</h3>';
	$s .= '</span>';
	$s .= '<div id="settings_xmpp_expanded" class="settings-block" style="display: none;">';
	$s .= '<span class="fakelink" onclick="openClose(\'settings_xmpp_expanded\'); openClose(\'settings_xmpp_inflated\');">';
	$s .= '<h3>' . DI::l10n()->t('XMPP-Chat (Jabber)') . '</h3>';
	$s .= '</span>';

	$s .= '<div id="xmpp-settings-wrapper">';
	$s .= '<label id="xmpp-enabled-label" for="xmpp-enabled">' . DI::l10n()->t('Enable Webchat') . '</label>';
	$s .= '<input id="xmpp-enabled" type="checkbox" name="xmpp_enabled" value="1" ' . $enabled_checked . '/>';
	$s .= '<div class="clear"></div>';

	if (DI::config()->get("xmpp", "central_userbase")) {
		$s .= '<label id="xmpp-individual-label" for="xmpp-individual">' . DI::l10n()->t('Individual Credentials') . '</label>';
		$s .= '<input id="xmpp-individual" type="checkbox" name="xmpp_individual" value="1" ' . $individual_checked . '/>';
		$s .= '<div class="clear"></div>';
	}

	if (!DI::config()->get("xmpp", "central_userbase") || DI::pConfig()->get(local_user(), "xmpp", "individual")) {
		$s .= '<label id="xmpp-bosh-proxy-label" for="xmpp-bosh-proxy">' . DI::l10n()->t('Jabber BOSH host') . '</label>';
		$s .= ' <input id="xmpp-bosh-proxy" type="text" name="xmpp_bosh_proxy" value="' . $bosh_proxy . '" />';
		$s .= '<div class="clear"></div>';
	}

	$s .= '</div>';

	/* provide a submit button */

	$s .= '<div class="settings-submit-wrapper" ><input type="submit" name="xmpp-settings-submit" class="settings-submit" value="' . DI::l10n()->t('Save Settings') . '" /></div></div>';
}

function xmpp_login()
{
	if (empty($_SESSION['allow_api'])) {
		$password = Strings::getRandomHex(16);
		DI::pConfig()->set(local_user(), 'xmpp', 'password', $password);
	}
}

function xmpp_addon_admin(App $a, &$o)
{
	$t = Renderer::getMarkupTemplate('admin.tpl', 'addon/xmpp/');

	$o = Renderer::replaceMacros($t, [
		'$submit' => DI::l10n()->t('Save Settings'),
		'$bosh_proxy' => ['bosh_proxy', DI::l10n()->t('Jabber BOSH host'), DI::config()->get('xmpp', 'bosh_proxy'), ''],
		'$central_userbase' => ['central_userbase', DI::l10n()->t('Use central userbase'), DI::config()->get('xmpp', 'central_userbase'), DI::l10n()->t('If enabled, users will automatically login to an ejabberd server that has to be installed on this machine with synchronized credentials via the "auth_ejabberd.php" script.')],
	]);
}

function xmpp_addon_admin_post()
{
	$bosh_proxy = (!empty($_POST['bosh_proxy']) ? trim($_POST['bosh_proxy']) : '');
	$central_userbase = (!empty($_POST['central_userbase']) ? intval($_POST['central_userbase']) : false);

	DI::config()->set('xmpp', 'bosh_proxy', $bosh_proxy);
	DI::config()->set('xmpp', 'central_userbase', $central_userbase);
}

function xmpp_script(App $a)
{
	xmpp_converse($a);
}

function xmpp_converse(App $a)
{
	if (!local_user()) {
		return;
	}

	if (($_GET['mode'] ?? '') == 'minimal') {
		return;
	}

	if (DI::mode()->isMobile() || DI::mode()->isMobile()) {
		return;
	}

	if (!DI::pConfig()->get(local_user(), "xmpp", "enabled")) {
		return;
	}

	if (in_array(DI::args()->getQueryString(), ["admin/federation/"])) {
		return;
	}

	DI::page()['htmlhead'] .= '<link type="text/css" rel="stylesheet" media="screen" href="addon/xmpp/converse/css/converse.css" />' . "\n";
	DI::page()['htmlhead'] .= '<script src="addon/xmpp/converse/builds/converse.min.js"></script>' . "\n";

	if (DI::config()->get("xmpp", "central_userbase") && !DI::pConfig()->get(local_user(), "xmpp", "individual")) {
		$bosh_proxy = DI::config()->get("xmpp", "bosh_proxy");

		$password = DI::pConfig()->get(local_user(), "xmpp", "password", '', true);

		if ($password == "") {
			$password = Strings::getRandomHex(16);
			DI::pConfig()->set(local_user(), "xmpp", "password", $password);
		}

		$jid = $a->user["nickname"] . "@" . DI::baseUrl()->getHostname() . "/converse-" . Strings::getRandomHex(5);

		$auto_login = "auto_login: true,
			authentication: 'login',
			jid: '$jid',
			password: '$password',
			allow_logout: false,";
	} else {
		$bosh_proxy = DI::pConfig()->get(local_user(), "xmpp", "bosh_proxy");

		$auto_login = "";
	}

	if ($bosh_proxy == "") {
		return;
	}

	if (in_array($a->argv[0], ["delegation", "logout"])) {
		$additional_commands = "converse.user.logout();\n";
	} else {
		$additional_commands = "";
	}

	$on_ready = "";

	$initialize = "converse.initialize({
					bosh_service_url: '$bosh_proxy',
					keepalive: true,
					message_carbons: false,
					forward_messages: false,
					play_sounds: true,
					sounds_path: 'addon/xmpp/converse/sounds/',
					roster_groups: false,
					show_controlbox_by_default: false,
					show_toolbar: true,
					allow_contact_removal: false,
					allow_registration: false,
					hide_offline_users: true,
					allow_chat_pending_contacts: false,
					allow_dragresize: true,
					auto_away: 0,
					auto_xa: 0,
					csi_waiting_time: 300,
					auto_reconnect: true,
					$auto_login
					xhr_user_search: false
				});\n";

	DI::page()['htmlhead'] .= "<script>
					require(['converse'], function (converse) {
						$initialize
						converse.listen.on('ready', function (event) {
							$on_ready
						});
						$additional_commands
					});
				</script>";
}
