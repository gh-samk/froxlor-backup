<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

/**
 * Checks the Twig syntax of all templates (parse only, no rendering) with
 * the Twig library bundled with a Froxlor installation. Froxlor's own
 * functions and filters are accepted as unknown.
 *
 *   php tests/twig-syntax.php /var/www/froxlor
 */

$froxlor = rtrim($argv[1] ?? '', '/');
if ($froxlor === '' || !is_file($froxlor . '/vendor/autoload.php')) {
	fwrite(STDERR, "Usage: php tests/twig-syntax.php <froxlor-directory with vendor/>\n");
	exit(2);
}
require $froxlor . '/vendor/autoload.php';

$dir = dirname(__DIR__) . '/templates';
$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader([$dir, $froxlor . '/templates']), ['cache' => false, 'autoescape' => 'html']);
$twig->registerUndefinedFunctionCallback(static fn($name) => new \Twig\TwigFunction($name, static fn() => ''));
$twig->registerUndefinedFilterCallback(static fn($name) => new \Twig\TwigFilter($name, static fn($v) => $v));

$failed = 0;
foreach (glob($dir . '/ExtBackup/*.twig') ?: [] as $file) {
	$name = 'ExtBackup/' . basename($file);
	try {
		$twig->parse($twig->tokenize(new \Twig\Source((string)file_get_contents($file), $name)));
		echo "  ok    $name\n";
	} catch (\Throwable $e) {
		echo "  FAIL  $name: " . $e->getMessage() . "\n";
		$failed++;
	}
}
echo 'Twig ' . \Twig\Environment::VERSION . ($failed === 0 ? ": all templates parse.\n" : ": $failed template(s) failed.\n");
exit($failed === 0 ? 0 : 1);
