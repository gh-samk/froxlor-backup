<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

/**
 * Checks the translations of the extension (English/German):
 *
 *   php tests/lang.php            check everything
 *   php tests/lang.php <path>…    only check source files below these paths
 *
 * - Every text in Lang::t('…') (PHP) and backup_t('…') (Twig) needs a
 *   German entry in lib/Froxlor/Ext/Backup/lang/de.php.
 * - Placeholders (%s, %d, %1$s …) must match between English and German.
 * - Catalog entries that are used nowhere and texts that are identical in
 *   both languages are notes (not errors).
 * The texts must be ONE literal in the source (no concatenation), otherwise
 * this script cannot find them.
 */

$root = dirname(__DIR__);
$catalog = require $root . '/lib/Froxlor/Ext/Backup/lang/de.php';
if (!is_array($catalog)) {
	echo "  FAIL lang/de.php does not return an array\n";
	exit(1);
}

$targets = array_slice($argv, 1) ?: [$root];
$files = [];
foreach ($targets as $target) {
	$target = realpath($target) ?: $target;
	if (is_file($target)) {
		$files[] = $target;
		continue;
	}
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$path = $f->getPathname();
		if (preg_match('~/(\.git|tests|lang)/~', $path) || !preg_match('~\.(php|twig)$~', $path)) {
			continue;
		}
		$files[] = $path;
	}
}

$unquote = static function (string $lit): string {
	$q = $lit[0];
	$in = substr($lit, 1, -1);
	return $q === "'" ? strtr($in, ["\\'" => "'", '\\\\' => '\\']) : stripcslashes($in);
};
$spec = static function (string $s): array {
	preg_match_all('/%(?:\d+\$)?[-+ 0\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', str_replace('%%', '', $s), $m);
	$l = $m[0];
	sort($l);
	return $l;
};

$used = [];
$errors = 0;
$notes = 0;
foreach ($files as $file) {
	$text = file_get_contents($file);
	$pattern = str_ends_with($file, '.twig')
		? '~(?<![\w.$>:])backup_t\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")~s'
		: '~Lang::t\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")~s';
	if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
		continue;
	}
	foreach ($matches[1] as [$lit, $offset]) {
		$en = $unquote($lit);
		$used[$en] = true;
		$line = substr_count(substr($text, 0, $offset), "\n") + 1;
		$where = str_replace($root . '/', '', $file) . ':' . $line;
		if (!isset($catalog[$en])) {
			echo "  FAIL $where  no German translation: " . mb_substr($en, 0, 70) . "\n";
			$errors++;
			continue;
		}
		if ($spec($en) !== $spec($catalog[$en])) {
			echo "  FAIL $where  placeholders do not match: " . mb_substr($en, 0, 70) . "\n";
			$errors++;
		}
	}
}
foreach ($catalog as $en => $de) {
	if ($en === $de && mb_strlen($en) > 12) {
		echo "  note: German equals English: " . mb_substr($en, 0, 70) . "\n";
		$notes++;
	}
}
if (count($targets) === 1 && $targets[0] === $root) {
	foreach (array_diff_key($catalog, $used) as $en => $de) {
		echo "  note: catalog entry not used: " . mb_substr((string)$en, 0, 70) . "\n";
		$notes++;
	}
}
echo count($used) . " texts used, " . count($catalog) . " in the catalog, $errors errors, $notes notes\n";
exit($errors > 0 ? 1 : 0);
