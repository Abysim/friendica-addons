<?php

use Friendica\Core\L10n;
use Friendica\Core\Renderer;
use Friendica\Database\DBA;
use Friendica\DI;

function like_widget_name() {
	return "Shows likes";
}
function like_widget_help() {
	return "Search first item which contains <em>KEY</em> and print like/dislike count";
}

function like_widget_args(){
	return ["KEY"];
}

function like_widget_size(){
	return ['60px','20px'];
}


function like_widget_content(&$a, $conf){
	$args = explode(",",$_GET['a']);


	$baseq="SELECT COUNT(`post-view`.`id`) as `c`, `p`.`id`
					FROM `post-view`,
						(SELECT `i`.`id` FROM `post-view` as `i` WHERE
							`i`.`visible` = 1 AND `i`.`deleted` = 0
							AND (( `i`.`wall` = 1 AND `i`.`allow_cid` = ''
									AND `i`.`allow_gid` = ''
									AND `i`.`deny_cid`  = ''
									AND `i`.`deny_gid`  = '' )
								  OR `i`.`uid` = %d )
							AND `i`.`body` LIKE '%%%s%%' LIMIT 1) as `p`
					WHERE `post-view`.`parent` = `p`.`id` ";

	// count likes
	$r = q( $baseq . "AND `post-view`.`verb` = 'http://activitystrea.ms/schema/1.0/like'",
			intval($conf['uid']),
			DBA::escape($args[0])
	);
	$likes = $r[0]['c'];

	$dislikes = 0;
	$strdislike = '';
	if (!DI::pConfig()->get(local_user(), 'system', 'hide_dislike')) {
		// count dislikes
		$r = q( $baseq . "AND `post-view`.`verb` = 'http://purl.org/macgirvin/dfrn/1.0/dislike'",
				intval($conf['uid']),
				DBA::escape($args[0])
		);
		$dislikes = $r[0]['c'];
		$strdislike = DI::l10n()->tt("%d person doesn't like this", "%d people don't like this", $dislikes);
	}


	$o = "";

#	$t = file_get_contents( dirname(__file__). "/widget_like.tpl" );
	$t = Renderer::getMarkupTemplate("widget_like.tpl", "addon/widgets/");
	$o .= Renderer::replaceMacros($t, [
		'$like'		=> $likes,
		'$strlike'	=> DI::l10n()->tt("%d person likes this", "%d people like this", $likes),

		'$dislike'	=> $dislikes,
		'$strdislike'=> $strdislike,
	]);

	return $o;
}
