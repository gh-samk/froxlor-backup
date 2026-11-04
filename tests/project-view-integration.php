<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

/**
 * Checks the contract that Project View relies on (ProjectViewIntegration)
 * - without a database.
 * Run:  php tests/project-view-integration.php
 */

require __DIR__ . '/../lib/Froxlor/Ext/Backup/ProjectViewIntegration.php';

use Froxlor\Ext\Backup\ProjectViewIntegration;

$failed = 0;
$check = static function (string $name, bool $ok) use (&$failed): void {
	echo ($ok ? '  ok    ' : '  FAIL  ') . $name . "\n";
	if (!$ok) {
		$failed++;
	}
};

$r = new ReflectionClass(ProjectViewIntegration::class);
$check('class is final', $r->isFinal());
$status = $r->getMethod('status');
$check('status(string, array): array', $status->isStatic() && $status->isPublic()
	&& array_map(static fn($p) => (string)$p->getType(), $status->getParameters()) === ['string', 'array']
	&& (string)$status->getReturnType() === 'array');
$params = $r->getMethod('restoreParams');
$check('restoreParams(string, int): array', $params->isStatic() && $params->isPublic()
	&& array_map(static fn($p) => (string)$p->getType(), $params->getParameters()) === ['string', 'int']
	&& (string)$params->getReturnType() === 'array');

$p = ProjectViewIntegration::restoreParams('customer1', 42);
$check('restoreParams: page, customer, project, scope', $p === ['page' => 'restore', 'customer' => 'customer1', 'project' => 42, 'scope' => 'all']);
$check('stale after 26 hours', ProjectViewIntegration::STALE_AFTER === 26 * 3600);

echo $failed === 0 ? "\nAll checks passed.\n" : "\n$failed check(s) failed.\n";
exit($failed === 0 ? 0 : 1);
