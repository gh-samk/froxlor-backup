<?php

namespace Froxlor\Ext\Backup;

use Froxlor\Database\Database;
use Froxlor\Settings;
use PDO;

/**
 * The record of what belonged to a customer at the time of the backup. It is
 * stored in the snapshot as manifest.json.
 *
 * Why: a restore should also offer things that no longer exist in Froxlor
 * (deleted customer, deleted mailbox). And restore target paths come from
 * Froxlor and the manifest, never from a form.
 *
 * The restore code reads databases[].name, .failed and .tables[].name/.file;
 * these keys are part of the snapshot format (see FORMAT).
 */
class Manifest
{
	/** Format version written into every manifest. */
	public const FORMAT = 1;

	/**
	 * All customers that are backed up, with what the backup needs.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function customers(): array
	{
		$stmt = Database::prepare('
			SELECT `customerid`, `loginname`, `documentroot`, `guid`, `deactivated`, `name`, `firstname`, `company`, `email`
			FROM `' . TABLE_PANEL_CUSTOMERS . '` ORDER BY `loginname` ASC
		');
		Database::pexecute($stmt);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * The manifest of one customer.
	 */
	public static function forCustomer(array $customer): array
	{
		$id = (int)$customer['customerid'];
		$mailBase = rtrim((string)Settings::Get('system.vmail_homedir'), '/');

		return [
			'format' => self::FORMAT,
			'created' => time(),
			'customer' => [
				'id' => $id,
				'login' => (string)$customer['loginname'],
				'name' => trim(($customer['firstname'] ?? '') . ' ' . ($customer['name'] ?? '')),
				'company' => (string)($customer['company'] ?? ''),
				'documentroot' => (string)$customer['documentroot'],
				'uid' => (int)$customer['guid'],
				'deactivated' => (int)$customer['deactivated'] === 1,
			],
			'mail_dir' => $mailBase !== '' ? $mailBase . '/' . $customer['loginname'] : '',
			'domains' => self::domains($id),
			'mailboxes' => self::mailboxes($id),
			'databases' => [], // filled in by the backup after the dump (with the tables)
			'ftp' => self::ftp($id),
			'assignments' => ProjectAssignments::forCustomer($id),
		];
	}

	/**
	 * The customer's databases as Froxlor knows them.
	 *
	 * @return array<int,array{id:int, name:string, server:int, description:string}>
	 */
	public static function databasesOfCustomer(int $customerId): array
	{
		$stmt = Database::prepare('
			SELECT `id`, `databasename`, `dbserver`, `description`
			FROM `' . TABLE_PANEL_DATABASES . '` WHERE `customerid` = :c ORDER BY `databasename` ASC
		');
		Database::pexecute($stmt, ['c' => $customerId]);

		$list = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$list[] = [
				'id' => (int)$r['id'],
				'name' => (string)$r['databasename'],
				'server' => (int)$r['dbserver'],
				'description' => (string)$r['description'],
			];
		}

		return $list;
	}

	private static function domains(int $id): array
	{
		$stmt = Database::prepare('
			SELECT `id`, `domain`, `documentroot`, `parentdomainid`, `isemaildomain`, `letsencrypt`, `deactivated`
			FROM `' . TABLE_PANEL_DOMAINS . '` WHERE `customerid` = :c ORDER BY `domain` ASC
		');
		Database::pexecute($stmt, ['c' => $id]);

		return array_map(static fn(array $r): array => [
			'id' => (int)$r['id'],
			'domain' => (string)$r['domain'],
			'documentroot' => (string)$r['documentroot'],
			'main' => (int)$r['parentdomainid'] === 0,
			'mail_domain' => (int)$r['isemaildomain'] === 1,
		], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	private static function mailboxes(int $id): array
	{
		$stmt = Database::prepare('
			SELECT `id`, `email`, `username`, `domainid`, `homedir`, `maildir`, `quota`
			FROM `' . TABLE_MAIL_USERS . '` WHERE `customerid` = :c ORDER BY `email` ASC
		');
		Database::pexecute($stmt, ['c' => $id]);

		return array_map(static fn(array $r): array => [
			'id' => (int)$r['id'],
			'address' => (string)$r['email'],
			'username' => (string)$r['username'],
			'domainid' => (int)$r['domainid'],
			'homedir' => (string)$r['homedir'],
			'maildir' => (string)$r['maildir'],
		], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	private static function ftp(int $customerId): array
	{
		$stmt = Database::prepare('
			SELECT `id`, `username`, `homedir` FROM `' . TABLE_FTP_USERS . '` WHERE `customerid` = :c ORDER BY `username` ASC
		');
		Database::pexecute($stmt, ['c' => $customerId]);

		return array_map(static fn(array $r): array => [
			'id' => (int)$r['id'],
			'username' => (string)$r['username'],
			'homedir' => (string)$r['homedir'],
		], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}
}
