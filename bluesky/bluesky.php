<?php
/**
 * Name: Bluesky Connector
 * Description: Post to Bluesky
 * Version: 1.1
 * Author: Michael Vogel <https://pirati.ca/profile/heluecht>
 *
 * @todo
 * - Links in outgoing comments
 * - Outgoing mentions
 *
 * Possibly not possible:
 * - only fetch new posts
 *
 * Currently not possible, due to limitations in Friendica
 * - mute contacts https://atproto.com/lexicons/app-bsky-graph#appbskygraphmuteactor
 * - unmute contacts https://atproto.com/lexicons/app-bsky-graph#appbskygraphunmuteactor
 */

use Friendica\Content\Text\BBCode;
use Friendica\Content\Text\HTML;
use Friendica\Content\Text\Plaintext;
use Friendica\Core\Config\Util\ConfigFileManager;
use Friendica\Core\Hook;
use Friendica\Core\Logger;
use Friendica\Core\Protocol;
use Friendica\Core\Renderer;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Contact;
use Friendica\Model\Item;
use Friendica\Model\ItemURI;
use Friendica\Model\Photo;
use Friendica\Model\Post;
use Friendica\Network\HTTPClient\Client\HttpClientAccept;
use Friendica\Network\HTTPClient\Client\HttpClientOptions;
use Friendica\Protocol\Activity;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Strings;

const BLUESKY_DEFAULT_POLL_INTERVAL = 10; // given in minutes
const BLUESKY_HOST = 'https://bsky.app'; // Hard wired until Bluesky will run on multiple systems

function bluesky_install()
{
	Hook::register('load_config',             __FILE__, 'bluesky_load_config');
	Hook::register('hook_fork',               __FILE__, 'bluesky_hook_fork');
	Hook::register('post_local',              __FILE__, 'bluesky_post_local');
	Hook::register('notifier_normal',         __FILE__, 'bluesky_send');
	Hook::register('jot_networks',            __FILE__, 'bluesky_jot_nets');
	Hook::register('connector_settings',      __FILE__, 'bluesky_settings');
	Hook::register('connector_settings_post', __FILE__, 'bluesky_settings_post');
	Hook::register('cron',                    __FILE__, 'bluesky_cron');
	Hook::register('support_follow',          __FILE__, 'bluesky_support_follow');
	Hook::register('support_probe',           __FILE__, 'bluesky_support_probe');
	Hook::register('follow',                  __FILE__, 'bluesky_follow');
	Hook::register('unfollow',                __FILE__, 'bluesky_unfollow');
	Hook::register('block',                   __FILE__, 'bluesky_block');
	Hook::register('unblock',                 __FILE__, 'bluesky_unblock');
	Hook::register('check_item_notification', __FILE__, 'bluesky_check_item_notification');
	Hook::register('probe_detect',            __FILE__, 'bluesky_probe_detect');
	Hook::register('item_by_link',            __FILE__, 'bluesky_item_by_link');
}

function bluesky_load_config(ConfigFileManager $loader)
{
	DI::app()->getConfigCache()->load($loader->loadAddonConfig('bluesky'), \Friendica\Core\Config\ValueObject\Cache::SOURCE_STATIC);
}

function bluesky_check_item_notification(array &$notification_data)
{
	$did = DI::pConfig()->get($notification_data['uid'], 'bluesky', 'did');

	if (!empty($did)) {
		$notification_data['profiles'][] = $did;
	}
}

function bluesky_probe_detect(array &$hookData)
{
	// Don't overwrite an existing result
	if (isset($hookData['result'])) {
		return;
	}

	// Avoid a lookup for the wrong network
	if (!in_array($hookData['network'], ['', Protocol::BLUESKY])) {
		return;
	}

	$pconfig = DBA::selectFirst('pconfig', ['uid'], ["`cat` = ? AND `k` = ? AND `v` != ?", 'bluesky', 'access_token', '']);
	if (empty($pconfig['uid'])) {
		return;
	}

	if (parse_url($hookData['uri'], PHP_URL_SCHEME) == 'did') {
		$did = $hookData['uri'];
	} elseif (preg_match('#^' . BLUESKY_HOST . '/profile/(.+)#', $hookData['uri'], $matches)) {
		$did = bluesky_get_did($pconfig['uid'], $matches[1]);
		if (empty($did)) {
			return;
		}
	} else {
		return;
	}

	$token = bluesky_get_token($pconfig['uid']);
	if (empty($token)) {
		return;
	}

	$data = bluesky_get($pconfig['uid'], '/xrpc/app.bsky.actor.getProfile?actor=' . $did, HttpClientAccept::JSON, [HttpClientOptions::HEADERS => ['Authorization' => ['Bearer ' . $token]]]);
	if (empty($data)) {
		return;
	}

	$hookData['result'] = bluesky_get_contact($data, 0, $pconfig['uid']);

	// Authoritative probe should set the result even if the probe was unsuccessful
	if ($hookData['network'] == Protocol::BLUESKY && empty($hookData['result'])) {
		$hookData['result'] = [];
	}
}

function bluesky_item_by_link(array &$hookData)
{
	// Don't overwrite an existing result
	if (isset($hookData['item_id'])) {
		return;
	}

	$token = bluesky_get_token($hookData['uid']);
	if (empty($token)) {
		return;
	}

	if (!preg_match('#^' . BLUESKY_HOST . '/profile/(.+)/post/(.+)#', $hookData['uri'], $matches)) {
		return;
	}

	$did = bluesky_get_did($hookData['uid'], $matches[1]);
	if (empty($did)) {
		return;
	}

	Logger::debug('Found bluesky post', ['url' => $hookData['uri'], 'handle' => $matches[1], 'did' => $did, 'cid' => $matches[2]]);

	$uri = 'at://' . $did . '/app.bsky.feed.post/' . $matches[2];

	$uri = bluesky_fetch_missing_post($uri, $hookData['uid'], 0);
	Logger::debug('Got post', ['profile' => $matches[1], 'cid' => $matches[2], 'result' => $uri]);
	if (!empty($uri)) {
		$item = Post::selectFirst(['id'], ['uri' => $uri, 'uid' => $hookData['uid']]);
		if (!empty($item['id'])) {
			$hookData['item_id'] = $item['id'];
		}
	}
}

function bluesky_support_follow(array &$data)
{
	if ($data['protocol'] == Protocol::BLUESKY) {
		$data['result'] = true;
	}
}

function bluesky_support_probe(array &$data)
{
	if ($data['protocol'] == Protocol::BLUESKY) {
		$data['result'] = true;
	}
}

function bluesky_follow(array &$hook_data)
{
	$token = bluesky_get_token($hook_data['uid']);
	if (empty($token)) {
		return;
	}

	Logger::debug('Check if contact is bluesky', ['data' => $hook_data]);
	$contact = DBA::selectFirst('contact', [], ['network' => Protocol::BLUESKY, 'url' => $hook_data['url'], 'uid' => [0, $hook_data['uid']]]);
	if (empty($contact)) {
		return;
	}

	$record = [
		'subject'   => $contact['url'],
		'createdAt' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
		'$type'     => 'app.bsky.graph.follow'
	];
	
	$post = [
		'collection' => 'app.bsky.graph.follow',
		'repo'       => DI::pConfig()->get($hook_data['uid'], 'bluesky', 'did'),
		'record'     => $record
	];
	
	$activity = bluesky_post($hook_data['uid'], '/xrpc/com.atproto.repo.createRecord', json_encode($post), ['Content-type' => 'application/json', 'Authorization' => ['Bearer ' . $token]]);
	if (!empty($activity->uri)) {
		$hook_data['contact'] = $contact;
		Logger::debug('Successfully start following', ['url' => $contact['url'], 'uri' => $activity->uri]);
	}
}

function bluesky_unfollow(array &$hook_data)
{
	$token = bluesky_get_token($hook_data['uid']);
	if (empty($token)) {
		return;
	}

	if ($hook_data['contact']['network'] != Protocol::BLUESKY) {
		return;
	}

	$data = bluesky_get($hook_data['uid'], '/xrpc/app.bsky.actor.getProfile?actor=' . $hook_data['contact']['url'], HttpClientAccept::JSON, [HttpClientOptions::HEADERS => ['Authorization' => ['Bearer ' . $token]]]);
	if (empty($data->viewer) || empty($data->viewer->following)) {
		return;
	}

	bluesky_delete_post($data->viewer->following, $hook_data['uid']);

	$hook_data['result'] = true;
}

function bluesky_block(array &$hook_data)
{
	$token = bluesky_get_token($hook_data['uid']);
	if (empty($token)) {
		return;
	}

	Logger::debug('Check if contact is bluesky', ['data' => $hook_data]);
	$contact = DBA::selectFirst('contact', [], ['network' => Protocol::BLUESKY, 'url' => $hook_data['url'], 'uid' => [0, $hook_data['uid']]]);
	if (empty($contact)) {
		return;
	}

	$record = [
		'subject'   => $contact['url'],
		'createdAt' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
		'$type'     => 'app.bsky.graph.block'
	];
	
	$post = [
		'collection' => 'app.bsky.graph.block',
		'repo'       => DI::pConfig()->get($hook_data['uid'], 'bluesky', 'did'),
		'record'     => $record
	];
	
	$activity = bluesky_post($hook_data['uid'], '/xrpc/com.atproto.repo.createRecord', json_encode($post), ['Content-type' => 'application/json', 'Authorization' => ['Bearer ' . $token]]);
	if (!empty($activity->uri)) {
		$cdata = Contact::getPublicAndUserContactID($hook_data['contact']['id'], $hook_data['uid']);
		if (!empty($cdata['user'])) {
			Contact::remove($cdata['user']);
		}
		Logger::debug('Successfully blocked contact', ['url' => $hook_data['contact']['url'], 'uri' => $activity->uri]);
	}
}

function bluesky_unblock(array &$hook_data)
{
	$token = bluesky_get_token($hook_data['uid']);
	if (empty($token)) {
		return;
	}

	if ($hook_data['contact']['network'] != Protocol::BLUESKY) {
		return;
	}

	$data = bluesky_get($hook_data['uid'], '/xrpc/app.bsky.actor.getProfile?actor=' . $hook_data['contact']['url'], HttpClientAccept::JSON, [HttpClientOptions::HEADERS => ['Authorization' => ['Bearer ' . $token]]]);
	if (empty($data->viewer) || empty($data->viewer->blocking)) {
		return;
	}

	bluesky_delete_post($data->viewer->blocking, $hook_data['uid']);

	$hook_data['result'] = true;
}

function bluesky_settings(array &$data)
{
	if (!DI::userSession()->getLocalUserId()) {
		return;
	}

	$enabled     = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'bluesky', 'post') ?? false;
	$def_enabled = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'bluesky', 'post_by_default') ?? false;
	$host        = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'bluesky', 'host') ?: 'https://bsky.social';
	$handle      = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'bluesky', 'handle');
	$did         = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'bluesky', 'did');
	$token       = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'bluesky', 'access_token');
	$import      = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'bluesky', 'import') ?? false;

	$status = $token ? DI::l10n()->t("You are authenticated to Bluesky. For security reasons the password isn't stored.") : DI::l10n()->t('You are not authenticated. Please enter the app password.');

	$t    = Renderer::getMarkupTemplate('connector_settings.tpl', 'addon/bluesky/');
	$html = Renderer::replaceMacros($t, [
		'$enable'    => ['bluesky', DI::l10n()->t('Enable Bluesky Post Addon'), $enabled],
		'$bydefault' => ['bluesky_bydefault', DI::l10n()->t('Post to Bluesky by default'), $def_enabled],
		'$import'    => ['bluesky_import', DI::l10n()->t('Import the remote timeline'), $import],
		'$host'      => ['bluesky_host', DI::l10n()->t('Bluesky host'), $host, '', '', 'readonly'],
		'$handle'    => ['bluesky_handle', DI::l10n()->t('Bluesky handle'), $handle],
		'$did'       => ['bluesky_did', DI::l10n()->t('Bluesky DID'), $did, DI::l10n()->t('This is the unique identifier. It will be fetched automatically, when the handle is entered.'), '', 'readonly'],
		'$password'  => ['bluesky_password', DI::l10n()->t('Bluesky app password'), '', DI::l10n()->t("Please don't add your real password here, but instead create a specific app password in the Bluesky settings.")],
		'$status'    => $status
	]);

	$data = [
		'connector' => 'bluesky',
		'title'     => DI::l10n()->t('Bluesky Import/Export'),
		'image'     => 'images/bluesky.jpg',
		'enabled'   => $enabled,
		'html'      => $html,
	];
}

function bluesky_settings_post(array &$b)
{
	if (empty($_POST['bluesky-submit'])) {
		return;
	}

	$old_host   = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'bluesky', 'host');
	$old_handle = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'bluesky', 'handle');
	$old_did    = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'bluesky', 'did');

	$host   = $_POST['bluesky_host'];
	$handle = $_POST['bluesky_handle'];

	DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'bluesky', 'post',            intval($_POST['bluesky']));
	DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'bluesky', 'post_by_default', intval($_POST['bluesky_bydefault']));
	DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'bluesky', 'host',            $host);
	DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'bluesky', 'handle',          $handle);
	DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'bluesky', 'import',          intval($_POST['bluesky_import']));

	if (!empty($host) && !empty($handle)) {
		if (empty($old_did) || $old_host != $host || $old_handle != $handle) {
			DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'bluesky', 'did', bluesky_get_did(DI::userSession()->getLocalUserId(), DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'bluesky', 'handle')));
		}
	} else {
		DI::pConfig()->delete(DI::userSession()->getLocalUserId(), 'bluesky', 'did');
	}

	if (!empty($_POST['bluesky_password'])) {
		bluesky_create_token(DI::userSession()->getLocalUserId(), $_POST['bluesky_password']);
	}
}

function bluesky_jot_nets(array &$jotnets_fields)
{
	if (!DI::userSession()->getLocalUserId()) {
		return;
	}

	if (DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'bluesky', 'post')) {
		$jotnets_fields[] = [
			'type'  => 'checkbox',
			'field' => [
				'bluesky_enable',
				DI::l10n()->t('Post to Bluesky'),
				DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'bluesky', 'post_by_default')
			]
		];
	}
}

function bluesky_cron()
{
	$last = DI::keyValue()->get('bluesky_last_poll');

	$poll_interval = intval(DI::config()->get('bluesky', 'poll_interval'));
	if (!$poll_interval) {
		$poll_interval = BLUESKY_DEFAULT_POLL_INTERVAL;
	}

	if ($last) {
		$next = $last + ($poll_interval * 60);
		if ($next > time()) {
			Logger::notice('poll interval not reached');
			return;
		}
	}
	Logger::notice('cron_start');

	$abandon_days = intval(DI::config()->get('system', 'account_abandon_days'));
	if ($abandon_days < 1) {
		$abandon_days = 0;
	}

	$abandon_limit = date(DateTimeFormat::MYSQL, time() - $abandon_days * 86400);

	$pconfigs = DBA::selectToArray('pconfig', [], ['cat' => 'bluesky', 'k' => 'import', 'v' => true]);
	foreach ($pconfigs as $pconfig) {
		if ($abandon_days != 0) {
			if (!DBA::exists('user', ["`uid` = ? AND `login_date` >= ?", $pconfig['uid'], $abandon_limit])) {
				Logger::notice('abandoned account: timeline from user will not be imported', ['user' => $pconfig['uid']]);
				continue;
			}
		}

		Logger::notice('importing timeline - start', ['user' => $pconfig['uid']]);
		bluesky_fetch_timeline($pconfig['uid']);
		Logger::notice('importing timeline - done', ['user' => $pconfig['uid']]);
		Logger::notice('importing notifications - start', ['user' => $pconfig['uid']]);
		bluesky_fetch_notifications($pconfig['uid']);
		Logger::notice('importing notifications - done', ['user' => $pconfig['uid']]);
	}

	$last_clean = DI::keyValue()->get('bluesky_last_clean');
	if (empty($last_clean) || ($last_clean + 86400 < time())) {
		Logger::notice('Start contact cleanup');
		$contacts = DBA::select('account-user-view', ['id', 'pid'], ["`network` = ? AND `uid` != ? AND `rel` = ?", Protocol::BLUESKY, 0, Contact::NOTHING]);
		while ($contact = DBA::fetch($contacts)) {
			Worker::add(Worker::PRIORITY_LOW, 'MergeContact', $contact['pid'], $contact['id'], 0);
		}
		DBA::close($contacts);
		DI::keyValue()->set('bluesky_last_clean', time());
		Logger::notice('Contact cleanup done');
	}

	Logger::notice('cron_end');

	DI::keyValue()->set('bluesky_last_poll', time());
}

function bluesky_hook_fork(array &$b)
{
	if ($b['name'] != 'notifier_normal') {
		return;
	}

	$post = $b['data'];

	if (($post['created'] !== $post['edited']) && !$post['deleted']) {
		DI::logger()->info('Editing is not supported by the addon');
		$b['execute'] = false;
		return;
	}

	if (DI::pConfig()->get($post['uid'], 'bluesky', 'import')) {
		// Don't post if it isn't a reply to a bluesky post
		if (($post['parent'] != $post['id']) && !Post::exists(['id' => $post['parent'], 'network' => Protocol::BLUESKY])) {
			Logger::notice('No bluesky parent found', ['item' => $post['id']]);
			$b['execute'] = false;
			return;
		}
	} elseif (!strstr($post['postopts'] ?? '', 'bluesky') || ($post['parent'] != $post['id']) || $post['private']) {
		DI::logger()->info('Activities are never exported when we don\'t import the bluesky timeline', ['uid' => $post['uid']]);
		$b['execute'] = false;
		return;
	}
}

function bluesky_post_local(array &$b)
{
	if ($b['edit']) {
		return;
	}

	if (!DI::userSession()->getLocalUserId() || (DI::userSession()->getLocalUserId() != $b['uid'])) {
		return;
	}

	if ($b['private'] || $b['parent']) {
		return;
	}

	$bluesky_post   = intval(DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'bluesky', 'post'));
	$bluesky_enable = (($bluesky_post && !empty($_REQUEST['bluesky_enable'])) ? intval($_REQUEST['bluesky_enable']) : 0);

	// if API is used, default to the chosen settings
	if ($b['api_source'] && intval(DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'bluesky', 'post_by_default'))) {
		$bluesky_enable = 1;
	}

	if (!$bluesky_enable) {
		return;
	}

	if (strlen($b['postopts'])) {
		$b['postopts'] .= ',';
	}

	$b['postopts'] .= 'bluesky';
}

function bluesky_send(array &$b)
{
	if (($b['created'] !== $b['edited']) && !$b['deleted']) {
		return;
	}

	if ($b['gravity'] != Item::GRAVITY_PARENT) {
		Logger::debug('Got comment', ['item' => $b]);

		if ($b['deleted']) {
			$uri = bluesky_get_uri_class($b['uri']);
			if (empty($uri)) {
				Logger::debug('Not a bluesky post', ['uri' => $b['uri']]);
				return;
			}
			bluesky_delete_post($b['uri'], $b['uid']);
			return;
		}

		$root   = bluesky_get_uri_class($b['parent-uri']);
		$parent = bluesky_get_uri_class($b['thr-parent']);

		if (empty($root) || empty($parent)) {
			Logger::debug('No bluesky post', ['parent' => $b['parent'], 'thr-parent' => $b['thr-parent']]);
			return;
		}

		if ($b['gravity'] == Item::GRAVITY_COMMENT) {
			Logger::debug('Posting comment', ['root' => $root, 'parent' => $parent]);
			bluesky_create_post($b, $root, $parent);
			return;
		} elseif (in_array($b['verb'], [Activity::LIKE, Activity::ANNOUNCE])) {
			bluesky_create_activity($b, $parent);
		}
		return;
	} elseif ($b['private'] || !strstr($b['postopts'], 'bluesky')) {
		return;
	}

	bluesky_create_post($b);
}

function bluesky_create_activity(array $item, stdClass $parent = null)
{
	$uid = $item['uid'];
	$token = bluesky_get_token($uid);
	if (empty($token)) {
		return;
	}

	$did  = DI::pConfig()->get($uid, 'bluesky', 'did');

	if ($item['verb'] == Activity::LIKE) {
		$record = [
			'subject'   => $parent,
			'createdAt' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
			'$type'     => 'app.bsky.feed.like'
		];

		$post = [
			'collection' => 'app.bsky.feed.like',
			'repo'       => $did,
			'record'     => $record
		];
	} elseif ($item['verb'] == Activity::ANNOUNCE) {
		$record = [
			'subject'   => $parent,
			'createdAt' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
			'$type'     => 'app.bsky.feed.repost'
		];

		$post = [
			'collection' => 'app.bsky.feed.repost',
			'repo'       => $did,
			'record'     => $record
		];
	}

	$activity = bluesky_post($uid, '/xrpc/com.atproto.repo.createRecord', json_encode($post), ['Content-type' => 'application/json', 'Authorization' => ['Bearer ' . $token]]);
	if (empty($activity)) {
		return;
	}
	Logger::debug('Activity done', ['return' => $activity]);
	$uri = bluesky_get_uri($activity);
	Item::update(['extid' => $uri], ['id' => $item['id']]);
	Logger::debug('Set extid', ['id' => $item['id'], 'extid' => $activity]);
}

function bluesky_create_post(array $item, stdClass $root = null, stdClass $parent = null)
{
	$uid = $item['uid'];
	$token = bluesky_get_token($uid);
	if (empty($token)) {
		return;
	}

	$did  = DI::pConfig()->get($uid, 'bluesky', 'did');
	$urls = bluesky_get_urls($item['body']);

	$msg = Plaintext::getPost($item, 300, false, BBCode::CONNECTORS);
	foreach ($msg['parts'] as $key => $part) {

		$facets = bluesky_get_facets($part, $urls);

		$record = [
			'text'      => $facets['body'],
			'createdAt' => DateTimeFormat::utcNow(DateTimeFormat::ATOM),
			'$type'     => 'app.bsky.feed.post'
		];

		if (!empty($facets['facets'])) {
			$record['facets'] = $facets['facets'];
		}

		if (!empty($root)) {
			$record['reply'] = ['root' => $root, 'parent' => $parent];
		}

		if ($key == count($msg['parts']) - 1) {
			$record = bluesky_add_embed($uid, $msg, $record);
		}

		$post = [
			'collection' => 'app.bsky.feed.post',
			'repo'       => $did,
			'record'     => $record
		];

		$parent = bluesky_post($uid, '/xrpc/com.atproto.repo.createRecord', json_encode($post), ['Content-type' => 'application/json', 'Authorization' => ['Bearer ' . $token]]);
		if (empty($parent)) {
			return;
		}
		Logger::debug('Posting done', ['return' => $parent]);
		if (empty($root)) {
			$root = $parent;
		}
		if (($key == 0) && ($item['gravity'] != Item::GRAVITY_PARENT)) {
			$uri = bluesky_get_uri($parent);
			Item::update(['extid' => $uri], ['id' => $item['id']]);
			Logger::debug('Set extid', ['id' => $item['id'], 'extid' => $uri]);
		}
	}
}

function bluesky_get_urls(string $body): array
{
	// Remove all hashtags and mentions
	$body = preg_replace("/([#@!])\[url\=(.*?)\](.*?)\[\/url\]/ism", '', $body);

	$urls = [];

	// Search for pure links
	if (preg_match_all("/\[url\](https?:.*?)\[\/url\]/ism", $body, $matches)) {
		foreach ($matches[1] as $url) {
			$urls[] = $url;
		}
	}

	// Search for links with descriptions
	if (preg_match_all("/\[url\=(https?:.*?)\].*?\[\/url\]/ism", $body, $matches)) {
		foreach ($matches[1] as $url) {
			$urls[] = $url;
		}
	}
	return $urls;
}

function bluesky_get_facets(string $body, array $urls): array
{
	$facets = [];

	foreach ($urls as $url) {
		$pos = strpos($body, $url);
		if ($pos === false) {
			continue;
		}
		if ($pos > 0) {
			$prefix = substr($body, 0, $pos);
		} else {
			$prefix = '';
		}
		$linktext = Strings::getStyledURL($url);
		$body = $prefix . $linktext . substr($body, $pos + strlen($url));

		$facet = new stdClass;
		$facet->index = new stdClass;
		$facet->index->byteEnd   = $pos + strlen($linktext);
		$facet->index->byteStart = $pos;

		$feature = new stdClass;
		$feature->uri = $url;
		$type = '$type';
		$feature->$type = 'app.bsky.richtext.facet#link';

		$facet->features = [$feature];
		$facets[] = $facet;
	}

	return ['facets' => $facets, 'body' => $body];
}

function bluesky_add_embed(int $uid, array $msg, array $record): array
{
	if (($msg['type'] != 'link') && !empty($msg['images'])) {
		$images = [];
		foreach ($msg['images'] as $image) {
			$photo = Photo::selectFirst(['resource-id'], ['id' => $image['id']]);
			$photo = Photo::selectFirst([], ["`resource-id` = ? AND `scale` > ?", $photo['resource-id'], 0], ['order' => ['scale']]);
			$blob = bluesky_upload_blob($uid, $photo);
			if (!empty($blob) && count($images) < 4) {
				$images[] = ['alt' => $image['description'] ?? '', 'image' => $blob];
			}
		}
		if (!empty($images)) {
			$record['embed'] = ['$type' => 'app.bsky.embed.images', 'images' => $images];
		}
	} elseif ($msg['type'] == 'link') {
		$record['embed'] = [
			'$type'    => 'app.bsky.embed.external',
			'external' => [
				'uri'         => $msg['url'],
				'title'       => $msg['title'],
				'description' => $msg['description'],
			]
		];
		if (!empty($msg['image'])) {
			$photo = Photo::createPhotoForExternalResource($msg['image']);
			$blob = bluesky_upload_blob($uid, $photo);
			if (!empty($blob)) {
				$record['embed']['external']['thumb'] = $blob;
			}
		}
	}
	return $record;
}

function bluesky_upload_blob(int $uid, array $photo): ?stdClass
{
	$content = Photo::getImageForPhoto($photo);
	$data = bluesky_post($uid, '/xrpc/com.atproto.repo.uploadBlob', $content, ['Content-type' => $photo['type'], 'Authorization' => ['Bearer ' . bluesky_get_token($uid)]]);
	if (empty($data)) {
		return null;
	}

	Logger::debug('Uploaded blob', ['return' => $data]);
	return $data->blob;
}

function bluesky_delete_post(string $uri, int $uid)
{
	$token = bluesky_get_token($uid);
	$parts = bluesky_get_uri_parts($uri);
	if (empty($parts)) {
		Logger::debug('No uri delected', ['uri' => $uri]);
		return;
	}
	bluesky_post($uid, '/xrpc/com.atproto.repo.deleteRecord', json_encode($parts), ['Content-type' => 'application/json', 'Authorization' => ['Bearer ' . $token]]);
	Logger::debug('Deleted', ['parts' => $parts]);
}

function bluesky_fetch_timeline(int $uid)
{
	$data = bluesky_get($uid, '/xrpc/app.bsky.feed.getTimeline', HttpClientAccept::JSON, [HttpClientOptions::HEADERS => ['Authorization' => ['Bearer ' . bluesky_get_token($uid)]]]);
	if (empty($data)) {
		return;
	}

	if (empty($data->feed)) {
		return;
	}

	foreach (array_reverse($data->feed) as $entry) {
		bluesky_process_post($entry->post, $uid);
		if (!empty($entry->reason)) {
			bluesky_process_reason($entry->reason, bluesky_get_uri($entry->post), $uid);
		}
	}

	// @todo Support paging
	// [cursor] => 1684670516000::bafyreidq3ilwslmlx72jf5vrk367xcc63s6lrhzlyup2bi3zwcvso6w2vi
}

function bluesky_process_reason(stdClass $reason, string $uri, int $uid)
{
	$type = '$type';
	if ($reason->$type != 'app.bsky.feed.defs#reasonRepost') {
		return;
	}

	$contact = bluesky_get_contact($reason->by, $uid, $uid);

	$item = [
		'network'       => Protocol::BLUESKY,
		'uid'           => $uid,
		'wall'          => false,
		'uri'           => $reason->by->did . '/app.bsky.feed.repost/' . $reason->indexedAt,
		'private'       => Item::UNLISTED,
		'verb'          => Activity::POST,
		'contact-id'    => $contact['id'],
		'author-name'   => $contact['name'],
		'author-link'   => $contact['url'],
		'author-avatar' => $contact['avatar'],
		'verb'          => Activity::ANNOUNCE,
		'body'          => Activity::ANNOUNCE,
		'gravity'       => Item::GRAVITY_ACTIVITY,
		'object-type'   => Activity\ObjectType::NOTE,
		'thr-parent'    => $uri,
	];

	if (Post::exists(['uri' => $item['uri'], 'uid' => $uid])) {
		return;
	}

	$item['owner-name']   = $item['author-name'];
	$item['owner-link']   = $item['author-link'];
	$item['owner-avatar'] = $item['author-avatar'];
	if (Item::insert($item)) {
		$cdata = Contact::getPublicAndUserContactID($contact['id'], $uid);
		Item::update(['post-reason' => Item::PR_ANNOUNCEMENT, 'causer-id' => $cdata['public']], ['uri' => $uri, 'uid' => $uid]);
	}
}

function bluesky_fetch_notifications(int $uid)
{
	$result = bluesky_get($uid, '/xrpc/app.bsky.notification.listNotifications', HttpClientAccept::JSON, [HttpClientOptions::HEADERS => ['Authorization' => ['Bearer ' . bluesky_get_token($uid)]]]);
	if (empty($result->notifications)) {
		return;
	}
	foreach ($result->notifications as $notification) {
		$uri = bluesky_get_uri($notification);
		if (Post::exists(['uri' => $uri, 'uid' => $uid]) || Post::exists(['extid' => $uri, 'uid' => $uid])) {
			Logger::debug('Notification already processed', ['uid' => $uid, 'reason' => $notification->reason, 'uri' => $uri, 'indexedAt' => $notification->indexedAt]);
			continue;
		}
		Logger::debug('Process notification', ['uid' => $uid, 'reason' => $notification->reason, 'uri' => $uri, 'indexedAt' => $notification->indexedAt]);
		switch ($notification->reason) {
			case 'like':
				$item = bluesky_get_header($notification, $uri, $uid, $uid);
				$item['gravity'] = Item::GRAVITY_ACTIVITY;
				$item['body'] = $item['verb'] = Activity::LIKE;
				$item['thr-parent'] = bluesky_get_uri($notification->record->subject);
				$result = Item::insert($item);
				Logger::debug('Got like', ['uid' => $uid, 'result' => $result]);
				break;

			case 'repost':
				$item = bluesky_get_header($notification, $uri, $uid, $uid);
				$item['gravity'] = Item::GRAVITY_ACTIVITY;
				$item['body'] = $item['verb'] = Activity::ANNOUNCE;
				$item['thr-parent'] = bluesky_get_uri($notification->record->subject);
				$result = Item::insert($item);
				Logger::debug('Got repost', ['uid' => $uid, 'result' => $result]);
				break;

			case 'follow':
				$contact = bluesky_get_contact($notification->author, $uid, $uid);
				Logger::debug('New follower', ['uid' => $uid, 'nick' => $contact['nick']]);
				break;

			case 'mention':
				$result = bluesky_process_post($notification, $uid);
				Logger::debug('Got mention', ['uid' => $uid, 'result' => $result]);
				break;

			case 'reply':
				$result = bluesky_process_post($notification, $uid);
				Logger::debug('Got reply', ['uid' => $uid, 'result' => $result]);
				break;

			case 'quote':
				$result = bluesky_process_post($notification, $uid);
				Logger::debug('Got quote', ['uid' => $uid, 'result' => $result]);
				break;

			default:
				Logger::notice('Unhandled reason', ['reason' => $notification->reason]);
				break;
		}
	}
}

function bluesky_process_post(stdClass $post, int $uid): int
{
	$uri = bluesky_get_uri($post);

	if (Post::exists(['uri' => $uri, 'uid' => $uid]) || Post::exists(['extid' => $uri, 'uid' => $uid])) {
		return 0;
	}

	Logger::debug('Importing post', ['uid' => $uid, 'indexedAt' => $post->indexedAt, 'uri' => $post->uri, 'cid' => $post->cid]);

	$item = bluesky_get_header($post, $uri, $uid, $uid);

	$item = bluesky_get_content($item, $post->record, $uid);

	if (!empty($post->embed)) {
		$item = bluesky_add_media($post->embed, $item, $uid);
	}
	return item::insert($item);
}

function bluesky_get_header(stdClass $post, string $uri, int $uid, int $fetch_uid): array
{
	$parts = bluesky_get_uri_parts($uri);
	if (empty($post->author)) {
		return [];
	}
	$contact = bluesky_get_contact($post->author, $uid, $fetch_uid);
	$item = [
		'network'       => Protocol::BLUESKY,
		'uid'           => $uid,
		'wall'          => false,
		'uri'           => $uri,
		'guid'          => $post->cid,
		'private'       => Item::UNLISTED,
		'verb'          => Activity::POST,
		'contact-id'    => $contact['id'],
		'author-name'   => $contact['name'],
		'author-link'   => $contact['url'],
		'author-avatar' => $contact['avatar'],
		'plink'         => $contact['alias'] . '/post/' . $parts->rkey,
	];

	$item['uri-id']       = ItemURI::getIdByURI($uri);
	$item['owner-name']   = $item['author-name'];
	$item['owner-link']   = $item['author-link'];
	$item['owner-avatar'] = $item['author-avatar'];

	return $item;
}

function bluesky_get_content(array $item, stdClass $record, int $uid): array
{
	if (!empty($record->reply)) {
		$item['parent-uri'] = bluesky_get_uri($record->reply->root);
		$item['parent-uri'] = bluesky_fetch_missing_post($item['parent-uri'], $uid, $item['contact-id']);
		$item['thr-parent'] = bluesky_get_uri($record->reply->parent);
		$item['thr-parent'] = bluesky_fetch_missing_post($item['thr-parent'], $uid, $item['contact-id']);
	}

	$item['body']    = bluesky_get_text($record, $uid);
	$item['created'] = DateTimeFormat::utc($record->createdAt, DateTimeFormat::MYSQL);
	return $item;
}

function bluesky_get_text(stdClass $record, int $uid): string
{
	$text = $record->text;

	if (empty($record->facets)) {
		return $text;
	}

	$facets = [];
	foreach ($record->facets as $facet) {
		$facets[$facet->index->byteStart] = $facet;
	}
	krsort($facets);

	foreach ($facets as $facet) {
		$prefix   = substr($text, 0, $facet->index->byteStart);
		$linktext = substr($text, $facet->index->byteStart, $facet->index->byteEnd - $facet->index->byteStart);
		$suffix   = substr($text, $facet->index->byteEnd);

		$url  = '';
		$type = '$type';
		foreach ($facet->features as $feature) {

			switch ($feature->$type) {
				case 'app.bsky.richtext.facet#link':
					$url = $feature->uri;
					break;

				case 'app.bsky.richtext.facet#mention':
					$contact = Contact::selectFirst(['id'], ['nurl' => $feature->did, 'uid' => [0, $uid]]);
					if (!empty($contact['id'])) {
						$url = DI::baseUrl() . '/contact/' . $contact['id'];
						if (substr($linktext, 0, 1) == '@') {
							$prefix .= '@';
							$linktext = substr($linktext, 1);
						}
					}
					break;

				default:
					Logger::notice('Unhandled feature type', ['type' => $feature->$type, 'record' => $record]);
					break;
			}
		}
		if (!empty($url)) {
			$text = $prefix . '[url=' . $url . ']' . $linktext . '[/url]' . $suffix;
		}
	}
	return $text;
}

function bluesky_add_media(stdClass $embed, array $item, int $fetch_uid): array
{
	$type = '$type';
	switch ($embed->$type) {
		case 'app.bsky.embed.images#view':
			foreach ($embed->images as $image) {
				$media = [
					'uri-id'      => $item['uri-id'],
					'type'        => Post\Media::IMAGE,
					'url'         => $image->fullsize,
					'preview'     => $image->thumb,
					'description' => $image->alt,
				];
				Post\Media::insert($media);
			}
			break;

		case 'app.bsky.embed.external#view':
			$media = [
				'uri-id' => $item['uri-id'],
				'type'        => Post\Media::HTML,
				'url'         => $embed->external->uri,
				'name'        => $embed->external->title,
				'description' => $embed->external->description,
			];
			Post\Media::insert($media);
			break;

		case 'app.bsky.embed.record#view':
			$uri = bluesky_get_uri($embed->record);
			$shared = Post::selectFirst(['uri-id'], ['uri' => $uri, 'uid' => $item['uid']]);
			if (empty($shared)) {
				$shared = bluesky_get_header($embed->record, $uri, 0, $fetch_uid);
				if (!empty($shared)) {
					$shared = bluesky_get_content($shared, $embed->record->value, $item['uid']);

					if (!empty($embed->record->embeds)) {
						foreach ($embed->record->embeds as $single) {
							$shared = bluesky_add_media($single, $shared, $fetch_uid);
						}
					}
					$id = Item::insert($shared);
					$shared = Post::selectFirst(['uri-id'], ['id' => $id]);
				}
			}
			if (!empty($shared)) {
				$item['quote-uri-id'] = $shared['uri-id'];
			}
			break;

		case 'app.bsky.embed.recordWithMedia#view':
			$uri = bluesky_get_uri($embed->record->record);
			$shared = Post::selectFirst(['uri-id'], ['uri' => $uri, 'uid' => $item['uid']]);
			if (empty($shared)) {
				$shared = bluesky_get_header($embed->record->record, $uri, 0, $fetch_uid);
				if (!empty($shared)) {
					$shared = bluesky_get_content($shared, $embed->record->record->value, $item['uid']);

					if (!empty($embed->record->embeds)) {
						foreach ($embed->record->record->embeds as $single) {
							$shared = bluesky_add_media($single, $shared, $fetch_uid);
						}
					}

					if (!empty($embed->media)) {
						bluesky_add_media($embed->media, $item, $fetch_uid);
					}

					$id = Item::insert($shared);
					$shared = Post::selectFirst(['uri-id'], ['id' => $id]);
				}
			}
			if (!empty($shared)) {
				$item['quote-uri-id'] = $shared['uri-id'];
			}
			break;

		default:
			Logger::notice('Unhandled embed type', ['type' => $embed->$type, 'embed' => $embed]);
			break;
	}
	return $item;
}

function bluesky_get_uri(stdClass $post): string
{
	return $post->uri . ':' . $post->cid;
}

function bluesky_get_uri_class(string $uri): ?stdClass
{
	if (empty($uri)) {
		return null;
	}

	$elements = explode(':', $uri);
	if (empty($elements) || ($elements[0] != 'at')) {
		$post = Post::selectFirstPost(['extid'], ['uri' => $uri]);
		return bluesky_get_uri_class($post['extid'] ?? '');
	}

	$class = new stdClass;

	$class->cid = array_pop($elements);
	$class->uri = implode(':', $elements);

	if ((substr_count($class->uri, '/') == 2) && (substr_count($class->cid, '/') == 2)) {
		$class->uri .= ':' . $class->cid;
		$class->cid = '';
	}

	return $class;
}

function bluesky_get_uri_parts(string $uri): ?stdClass
{
	$class = bluesky_get_uri_class($uri);
	if (empty($class)) {
		return null;
	}

	$parts = explode('/', substr($class->uri, 5));

	$class = new stdClass;

	$class->repo       = $parts[0];
	$class->collection = $parts[1];
	$class->rkey       = $parts[2];

	return $class;
}

function bluesky_fetch_missing_post(string $uri, int $uid, int $causer): string
{
	if (Post::exists(['uri' => $uri, 'uid' => [$uid, 0]])) {
		Logger::debug('Post exists', ['uri' => $uri]);
		return $uri;
	}

	$reply = Post::selectFirst(['uri'], ['extid' => $uri, 'uid' => [$uid, 0]]);
	if (!empty($reply['uri'])) {
		return $reply['uri'];
	}

	Logger::debug('Fetch missing post', ['uri' => $uri]);
	$class = bluesky_get_uri_class($uri);
	$fetch_uri = $class->uri;

	$data = bluesky_get($uid, '/xrpc/app.bsky.feed.getPosts?uris=' . urlencode($fetch_uri), HttpClientAccept::JSON, [HttpClientOptions::HEADERS => ['Authorization' => ['Bearer ' . bluesky_get_token($uid)]]]);
	if (empty($data)) {
		return '';
	}

	if ($causer != 0) {
		$cdata = Contact::getPublicAndUserContactID($causer, $uid);
	}

	foreach ($data->posts as $post) {
		$uri = bluesky_get_uri($post);
		$item = bluesky_get_header($post, $uri, $uid, $uid);
		$item = bluesky_get_content($item, $post->record, $uid);

		$item['post-reason'] = Item::PR_FETCHED;

		if (!empty($cdata['public'])) {
			$item['causer-id']   = $cdata['public'];
		}

		if (!empty($post->embed)) {
			$item = bluesky_add_media($post->embed, $item, $uid);
		}
		$id = Item::insert($item);
		Logger::debug('Stored item', ['id' => $id, 'uri' => $uri]);
	}

	return $uri;
}

function bluesky_get_contact(stdClass $author, int $uid, int $fetch_uid): array
{
	$condition = ['network' => Protocol::BLUESKY, 'uid' => 0, 'url' => $author->did];
	$contact = Contact::selectFirst(['id', 'updated'], $condition);

	$update = empty($contact) || $contact['updated'] < DateTimeFormat::utc('now -24 hours');

	$public_fields = $fields = bluesky_get_contact_fields($author, $fetch_uid, $update);

	$public_fields['uid'] = 0;
	$public_fields['rel'] = Contact::NOTHING;

	if (empty($contact)) {
		$cid = Contact::insert($public_fields);
	} else {
		$cid = $contact['id'];
		Contact::update($public_fields, ['id' => $cid], true);
	}

	if ($uid != 0) {
		$condition = ['network' => Protocol::BLUESKY, 'uid' => $uid, 'url' => $author->did];

		$contact = Contact::selectFirst(['id', 'rel', 'uid'], $condition);
		if (!isset($fields['rel']) && isset($contact['rel'])) {
			$fields['rel'] = $contact['rel'];
		} elseif (!isset($fields['rel'])) {
			$fields['rel'] = Contact::NOTHING;
		}
	}

	if (($uid != 0) && ($fields['rel'] != Contact::NOTHING)) {
		if (empty($contact)) {
			$cid = Contact::insert($fields);
		} else {
			$cid = $contact['id'];
			Contact::update($fields, ['id' => $cid], true);
		}
		Logger::debug('Get user contact', ['id' => $cid, 'uid' => $uid, 'update' => $update]);
	} else {
		Logger::debug('Get public contact', ['id' => $cid, 'uid' => $uid, 'update' => $update]);
	}
	if (!empty($author->avatar)) {
		Contact::updateAvatar($cid, $author->avatar);
	}

	return Contact::getById($cid);
}

function bluesky_get_contact_fields(stdClass $author, int $uid, bool $update): array
{
	$fields = [
		'uid'      => $uid,
		'network'  => Protocol::BLUESKY,
		'priority' => 1,
		'writable' => true,
		'blocked'  => false,
		'readonly' => false,
		'pending'  => false,
		'url'      => $author->did,
		'nurl'     => $author->did,
		'alias'    => BLUESKY_HOST . '/profile/' . $author->handle,
		'name'     => $author->displayName,
		'nick'     => $author->handle,
		'addr'     => $author->handle,
	];

	if (!$update) {
		Logger::debug('Got contact fields', ['uid' => $uid, 'url' => $fields['url']]);
		return $fields;
	}

	$data = bluesky_get($uid, '/xrpc/app.bsky.actor.getProfile?actor=' . $author->did, HttpClientAccept::JSON, [HttpClientOptions::HEADERS => ['Authorization' => ['Bearer ' . bluesky_get_token($uid)]]]);
	if (empty($data)) {
		Logger::debug('Error fetching contact fields', ['uid' => $uid, 'url' => $fields['url']]);
		return $fields;
	}

	$fields['updated'] = DateTimeFormat::utcNow(DateTimeFormat::MYSQL);

	if (!empty($data->description)) {
		$fields['about'] = HTML::toBBCode($data->description);
	}

	if (!empty($data->banner)) {
		$fields['header'] = $data->banner;
	}

	if (!empty($data->viewer)) {
		if (!empty($data->viewer->following) && !empty($data->viewer->followedBy)) {
			$fields['rel'] = Contact::FRIEND;
		} elseif (!empty($data->viewer->following) && empty($data->viewer->followedBy)) {
			$fields['rel'] = Contact::SHARING;
		} elseif (empty($data->viewer->following) && !empty($data->viewer->followedBy)) {
			$fields['rel'] = Contact::FOLLOWER;
		} else {
			$fields['rel'] = Contact::NOTHING;
		}
	}

	Logger::debug('Got updated contact fields', ['uid' => $uid, 'url' => $fields['url']]);
	return $fields;
}

function bluesky_get_did(int $uid, string $handle): string
{
	$data = bluesky_get($uid, '/xrpc/com.atproto.identity.resolveHandle?handle=' . $handle);
	if (empty($data)) {
		return '';
	}
	Logger::debug('Got DID', ['return' => $data]);
	return $data->did;
}

function bluesky_get_token(int $uid): string
{
	$token   = DI::pConfig()->get($uid, 'bluesky', 'access_token');
	$created = DI::pConfig()->get($uid, 'bluesky', 'token_created');
	if (empty($token)) {
		return '';
	}

	if ($created + 300 < time()) {
		return bluesky_refresh_token($uid);
	}
	return $token;
}

function bluesky_refresh_token(int $uid): string
{
	$token = DI::pConfig()->get($uid, 'bluesky', 'refresh_token');

	$data = bluesky_post($uid, '/xrpc/com.atproto.server.refreshSession', '', ['Authorization' => ['Bearer ' . $token]]);
	if (empty($data)) {
		return '';
	}

	Logger::debug('Refreshed token', ['return' => $data]);
	DI::pConfig()->set($uid, 'bluesky', 'access_token', $data->accessJwt);
	DI::pConfig()->set($uid, 'bluesky', 'refresh_token', $data->refreshJwt);
	DI::pConfig()->set($uid, 'bluesky', 'token_created', time());
	return $data->accessJwt;
}

function bluesky_create_token(int $uid, string $password): string
{
	$did = DI::pConfig()->get($uid, 'bluesky', 'did');

	$data = bluesky_post($uid, '/xrpc/com.atproto.server.createSession', json_encode(['identifier' => $did, 'password' => $password]), ['Content-type' => 'application/json']);
	if (empty($data)) {
		return '';
	}

	Logger::debug('Created token', ['return' => $data]);
	DI::pConfig()->set($uid, 'bluesky', 'access_token', $data->accessJwt);
	DI::pConfig()->set($uid, 'bluesky', 'refresh_token', $data->refreshJwt);
	DI::pConfig()->set($uid, 'bluesky', 'token_created', time());
	return $data->accessJwt;
}

function bluesky_post(int $uid, string $url, string $params, array $headers): ?stdClass
{
	try {
		$curlResult = DI::httpClient()->post(DI::pConfig()->get($uid, 'bluesky', 'host') . $url, $params, $headers);
	} catch (\Exception $e) {
		Logger::notice('Exception on post', ['exception' => $e]);
		return null;
	}

	if (!$curlResult->isSuccess()) {
		Logger::notice('API Error', ['error' => json_decode($curlResult->getBody()) ?: $curlResult->getBody()]);
		return null;
	}

	return json_decode($curlResult->getBody());
}

function bluesky_get(int $uid, string $url, string $accept_content = HttpClientAccept::DEFAULT, array $opts = []): ?stdClass
{
	try {
		$curlResult = DI::httpClient()->get(DI::pConfig()->get($uid, 'bluesky', 'host') . $url, $accept_content, $opts);
	} catch (\Exception $e) {
		Logger::notice('Exception on get', ['exception' => $e]);
		return null;
	}

	if (!$curlResult->isSuccess()) {
		Logger::notice('API Error', ['error' => json_decode($curlResult->getBody()) ?: $curlResult->getBody()]);
		return null;
	}

	return json_decode($curlResult->getBody());
}