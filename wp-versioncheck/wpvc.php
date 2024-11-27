<?php
$args = getopt('', [
    'su', 'skip-update',
    'suc', 'skip-update-check',
    'save', 'save-usage-data',
    'update', 'update-files',
    'ufo', 'update-files-only',
	'php:',
	'wp:',
]);

$skip_update_check = isset($args['suc']) || isset($args['skip-update-check']);
$skip_update = isset($args['su']) || isset($args['skip-update']);
$save_usage_data = isset($args['save']) || isset($args['save-usage-data']);
$update_files = isset($args['update']) || isset($args['update-files']);
$update_files_only = isset($args['ufo']) || isset($args['update-files-only']);

$sourceDirs = [];
$prevArg = '';
foreach(array_slice($argv, 1) as $arg) {
    if(
		!str_starts_with($arg, '-') && (
			!str_starts_with($prevArg, '-')
			|| ($args[ltrim($prevArg, '-')] ?? null) === false
		)
	) {
		$fullPath = realpath($arg);
		if($fullPath) {
			$sourceDirs[] = realpath($arg);
		} else {
			echo "Could not resolve path '$arg'";
			exit(1);
		}
    }
	$prevArg = $arg;
}

if(empty($sourceDirs)) {
	$sourceDirs = [getcwd()];
}

require_once 'WordPressVersionChecker.php';

if($update_files_only) {
	$php_version = $args['php'] ?? null;
	$error = false;
	if($php_version && str_starts_with($php_version, '-')) {
		echo "--php argument requires a value";
		$error = true;
	}
	$wp_version = $args['wp'] ?? null;
	if($wp_version && str_starts_with($wp_version, '-')) {
		echo "--wp argument requires a value";
		$error = true;
	}

	if(empty($wp_version) && empty($php_version)) {
		echo "When the --update-files-only/--ufo argument is provided, at least one of --php and --wp arguments is required.";
		$error = true;
	}

	if($error) {
		exit(1);
	}

	foreach($sourceDirs as $sourceDir) {
		WordPressVersionChecker::updateFiles($sourceDir, $wp_version, $php_version);
	}
	exit;
}

if(!$skip_update_check) {
    WordPressVersionChecker::check_update($skip_update);
}

foreach($sourceDirs as $sourceDir) {
	WordPressVersionChecker::check_version($sourceDir, $save_usage_data, $update_files);
}
