<?php
/**
 * Name: rendertime
 * Description: Shows the time that was needed to render the current page
 * Version: 0.1
 * Author: Michael Vogel <http://pirati.ca/profile/heluecht>
 *
 */
use Friendica\Core\Config;
use Friendica\Core\Hook;
use Friendica\Core\L10n;

function rendertime_install() {
	Hook::register('page_end', 'addon/rendertime/rendertime.php', 'rendertime_page_end');
}


function rendertime_uninstall() {
	Hook::unregister('init_1', 'addon/rendertime/rendertime.php', 'rendertime_init_1');
	Hook::unregister('page_end', 'addon/rendertime/rendertime.php', 'rendertime_page_end');
}

function rendertime_init_1(&$a) {
}

/**
 * @param Friendica\App $a
 * @param string $o
 */
function rendertime_page_end(&$a, &$o)
{

	$profiler = $a->getProfiler();

	$duration = microtime(true) - $profiler->get('start');

	$ignored_modules = ["fbrowser"];
	$ignored = in_array($a->module, $ignored_modules);

	if (is_site_admin() && (defaults($_GET, "mode", '') != "minimal") && !$a->is_mobile && !$a->is_tablet && !$ignored) {

		$o = $o . '<div class="renderinfo">' . L10n::t("Database: %s/%s, Network: %s, Rendering: %s, Session: %s, I/O: %s, Other: %s, Total: %s",
				round($profiler->get('database') - $profiler->get('database_write'), 3),
				round($profiler->get('database_write'), 3),
				round($profiler->get('network'), 2),
				round($profiler->get('rendering'), 2),
				round($profiler->get('parser'), 2),
				round($profiler->get('file'), 2),
				round($duration - $profiler->get('database')
					- $profiler->get('network') - $profiler->get('rendering')
					- $profiler->get('parser') - $profiler->get('file'), 2),
				round($duration, 2)
			//round($profiler->get('markstart'), 3)
			//round($profiler->get('plugin'), 3)
			) . '</div>';

		$o .= '<pre>';
		$o .= $profiler->getRendertimeString();
		$o .= '</pre>';
	}
}
