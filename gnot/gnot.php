<?php
/**
 * Name: Gnot
 * Description: Thread email comment notifications on Gmail and anonymise them
 * Version: 1.0
 * Author: Mike Macgirvin <http://macgirvin.com/profile/mike>
 * 
 *
 */
use Friendica\Core\Hook;
use Friendica\Core\Logger;
use Friendica\Core\Renderer;
use Friendica\DI;
use Friendica\Domain\Model\Notify\Type;

function gnot_install() {

	Hook::register('addon_settings', 'addon/gnot/gnot.php', 'gnot_settings');
	Hook::register('addon_settings_post', 'addon/gnot/gnot.php', 'gnot_settings_post');
	Hook::register('enotify_mail', 'addon/gnot/gnot.php', 'gnot_enotify_mail');

	Logger::log("installed gnot");
}

/**
 *
 * Callback from the settings post function.
 * $post contains the $_POST array.
 * We will make sure we've got a valid user account
 * and if so set our configuration setting for this person.
 *
 */

function gnot_settings_post($a,$post) {
	if(! local_user() || empty($_POST['gnot-submit']))
		return;

	DI::pConfig()->set(local_user(),'gnot','enable',intval($_POST['gnot']));
}


/**
 *
 * Called from the Addon Setting form. 
 * Add our own settings info to the page.
 *
 */



function gnot_settings(&$a,&$s) {

	if(! local_user())
		return;

	/* Add our stylesheet to the page so we can make our settings look nice */

	DI::page()['htmlhead'] .= '<link rel="stylesheet"  type="text/css" href="' . DI::baseUrl()->get() . '/addon/gnot/gnot.css' . '" media="all" />' . "\r\n";

	/* Get the current state of our config variable */

	$gnot = intval(DI::pConfig()->get(local_user(),'gnot','enable'));

	$gnot_checked = (($gnot) ? ' checked="checked" ' : '' );
	
	$t = Renderer::getMarkupTemplate('settings.tpl', 'addon/gnot/');
	/* Add some HTML to the existing form */

	$s .= Renderer::replaceMacros($t, [
		'$title' => DI::l10n()->t('Gnot Settings') ,
		'$submit' => DI::l10n()->t('Save Settings'),
		'$enable' => DI::l10n()->t('Enable this addon?'),
		'$enabled' => $gnot_checked,
		'$text' => DI::l10n()->t("Allows threading of email comment notifications on Gmail and anonymising the subject line.")
	]);
}


function gnot_enotify_mail(&$a,&$b) {
	if((! $b['uid']) || (! intval(DI::pConfig()->get($b['uid'], 'gnot','enable'))))
		return;
	if($b['type'] == Type::COMMENT)
		$b['subject'] = DI::l10n()->t('[Friendica:Notify] Comment to conversation #%d', $b['parent']);
}
