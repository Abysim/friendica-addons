<?php

if(! function_exists("string_plural_select_fr")) {
function string_plural_select_fr($n){
	$n = intval($n);
	return intval($n > 1);
}}
$a->strings['IRC Settings'] = 'Paramètres de l\'IRC';
$a->strings['Save Settings'] = 'Sauvegarder les paramètres';
