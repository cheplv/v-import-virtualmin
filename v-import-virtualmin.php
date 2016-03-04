#!/usr/bin/php -q
<?php
set_time_limit(0);
declare(ticks=1);

$remoteUser = "";
$remoteServer = "";

function webminImportUserDomainDNS($user, $domain, $domainInfo) {
	echo "Importing User Domain DNS: " . $user['Unix username'].", ".$domain['URL']."\n";
	$userName = $user['Unix username'];
	$domainHost = $domainInfo['host'];
	
	$vestaIPS = vestaExec('v-list-sys-ips', true);
	$vestaDNS = vestaExec('v-list-dns-domains "'.$userName.'"', true);
	
	list($vestaIP, $vestaIPData) = each($vestaIPS);
	
	if (empty($vestaDNS[$domainHost])) {
		$rv = vestaExec('v-add-dns-domain "'.$userName.'" "'.$domainHost.'" '.$vestaIP);
	}
}

function webminImportUserDomainMysql($user, $domain, $domainInfo) {
	echo "Importing User Domain Mysql: " . $user['Unix username'].", ".$domain['URL']."\n";
	$userName = $user['Unix username'];
	$mysqlUser = $domain['Username for mysql'];
	$mysqlPass = $domain['Password for mysql'];
	$domainHost = $domainInfo['host'];
	
	$result = webminExec('virtualmin list-databases --multiline --domain "'.$domainHost.'"');
	$domainDatabases = webminParseMultiline($result, true);
	$vestaDatabases = vestaExec('v-list-databases "'.$userName.'"', true);
	//var_dump($user, $domain, $domainInfo);
	//var_dump($domainDatabases, $vestaDatabases);
	
	foreach($domainDatabases as $domainDatabase => $domainDatabaseInfo) {
		$vestaDatabase = '';
		if (strpos($domainDatabase, $userName) !== FALSE) {
			$vestaDatabase = substr($domainDatabase, strlen($userName));
		} else {
			$vestaDatabase = $domainDatabase;
		}
		
		if ($vestaDatabase == '') {
			$vestaDatabase = 'db';
		}
		
		$vestaDatabaseFull = $userName . '_' . $vestaDatabase;
		
		if (empty($vestaDatabases[$vestaDatabaseFull])) {
			echo "Create User Database: $userName, $domainDatabase, $vestaDatabase\n";
			$rv = vestaExec('v-add-database "'.$userName.'" "'.$vestaDatabase.'" "'.$vestaDatabase.'" "'.$mysqlPass.'"');
		}
		
		$rv = webminExec("mysqldump --triggers --routines -u $mysqlUser -p'$mysqlPass' $domainDatabase", "mysql $userName"."_"."$vestaDatabase");
		echo "Import Mysql Database: $userName"."_"."$vestaDatabase\n";
		echo implode("\n", $rv);
	}
}

function webminImportUserDomainMail($user, $domain, $domainInfo) {
	$imapCopyTemplate = dirname(__FILE__).'/imapcopy.cfg.template';
	if (!file_exists($imapCopyTemplate)) return false;
	echo "Importing User Domain Mail: " . $user['Unix username'].", ".$domain['URL']."\n";
	$userName = $user['Unix username'];
	$domainHost = $domainInfo['host'];
	
	$result = webminExec('virtualmin list-users --email-only --multiline --domain "'.$domainHost.'"');
	$domainMails = webminParseMultiline($result, true);
	
	$vestaDomainMails = vestaExec('v-list-mail-accounts "'.$userName.'" "'.$domainHost.'"', true);
	
	//var_dump($domainMails, $vestaDomainMails);
	$imapFileContents = file_get_contents($imapCopyTemplate);
	$imapFileContents = str_replace('%sourceServer%', $domainInfo['host'], $imapFileContents);
		
	if (sizeof($domainMails)) {
		foreach($domainMails as $domainUser => $domainMailInfo) {
			$domainMailUser = $domainMailInfo['Email address'];
			$domainMailPass = $domainMailInfo['Password'];
			
			if (empty($vestaDomainMails[$domainUser])) {
				echo "Creating New Mail: $domainUser, $domainHost\n";
				$rv = vestaExec('v-add-mail-account "'.$userName.'" "'.$domainHost.'" "'.$domainUser.'" "'.$domainMailPass.'"');
				if (is_array($rv)) {
					echo trim(implode("\n", $rv));
				} else {
					echo $rv;
				}
				
			}
			
			$imapFileContents .= "Copy \"$domainMailUser\" \"$domainMailPass\" \"$domainMailUser\" \"$domainMailPass\"\n";
		}

		$imapCopyConfigFile = 'imapcopy.cfg';
		$rv = file_put_contents($imapCopyConfigFile, $imapFileContents);
		if ($rv !== FALSE) {
			systemExec('imapcopy');
			unlink($imapCopyConfigFile);
		}
	}
}

function webminImportUserDomainWeb($user, $domain, $domainInfo) {
	echo "Importing User Domain Web: " . $user['Unix username'].", ".$domain['URL']."\n";
	$userName = $user['Unix username'];
	$domainHost = $domainInfo['host'];
	
	$vUserDomains = vestaExec('v-list-web-domains ' . $userName, true);
	if (empty($vUserDomains[$domainHost])) {
		$rv = vestaExec('v-add-domain' . ' "'.$userName.'"' . ' "'.$domainHost.'"');
		echo "Creating Vesta User Domain: $userName, $domainHost\n";
	}
	
	$vDomain = vestaExec('v-list-web-domain' . ' "'.$userName.'"' . ' "'.$domainHost.'"', true);
	
	if (empty($vDomain[$domainHost])) {
		echo "ERROR: Vesta domain fetch info failed, $domainHost\n";
		die();
	}
	
	$vDomainInfo = $vDomain[$domainHost];
		
	//var_dump($vDomainInfo, $domain);
	$rv = rsyncExec($user, $domain['HTML directory'], $vDomainInfo['DOCUMENT_ROOT']);
	return $rv;
}

function webminImportUserDomain($user, $domain) {
	echo "Importing User Domain: " . $user['Unix username'].", ".$domain['URL']."\n";
	$domainInfo = parse_url($domain['URL']);
	
	$domainOpts = explode(' ', trim($domain['Features']));
	
	//if (array_search('dns', $domainOpts) !== FALSE) webminImportUserDomainDNS($user, $domain, $domainInfo);
	if (array_search('web', $domainOpts) !== FALSE) webminImportUserDomainWeb($user, $domain, $domainInfo);
	if (array_search('mysql', $domainOpts) !== FALSE) webminImportUserDomainMysql($user, $domain, $domainInfo);
	if (array_search('mail', $domainOpts) !== FALSE) webminImportUserDomainMail($user, $domain, $domainInfo);
	echo "\n\n";
}

function webminListUserDomains($user) {
	$result = webminExec("virtualmin list-domains --multiline --user " . $user['Unix username']);
	$webminUserDomains = webminParseMultiline($result);
	return $webminUserDomains;
}

function webminImportUser($user) {
	echo "Importing User: " . $user['Unix username']."\n";
	
	$vestaUsers = vestaExec('v-list-sys-users', true);
	$userName = $user['Unix username'];
	$userPass = $user['Password'];
	$userEmail = !empty($user['Email address']) ? $user['Email address'] : "info@".$userName.".nodomain";
	
	if (array_search($userName, $vestaUsers) === FALSE) {
		$rv = vestaExec('v-add-user' . ' "'.$userName.'"' . ' "'.$userPass.'"' . ' "'.$userEmail.'"');
		echo "Creating Vesta User: $userName : $rv\n";
	}
	
	$userDomains = webminListUserDomains($user);
	
	foreach($userDomains as $userDomain) {
		webminImportUserDomain($user, $userDomain);
	}
	
}

function webminListUsers($serverUsers = true) {
	$result = webminExec("virtualmin list-users --multiline --all-domains --include-owner");
	$webminUsers = webminParseMultiline($result);
	
	if ($serverUsers) {
		foreach($webminUsers as $userIdx => $userData) {
			if (empty($userData['User type']) || $userData['User type'] != 'Server owner') {
				unset($webminUsers[$userIdx]);
				continue;
			}
		}
	}
	
	return $webminUsers;
}

function usage() {
	echo "Usage: v-import-virtualmin ssh://user@remote-server.host:port <user>";
	die();
}

function webminParseMultiline($data, $dataKeys = false) {
	$result = [];
	$resultIdx = -1;
	if (!is_array($data)) {
		$data = explode('\n', $data);
	}
	
	$childPrefix = '    ';
	
	foreach($data as $line) {
		$linePrefix = substr($line, 0, strlen($childPrefix));
		$line = trim($line);
		if ($linePrefix == $childPrefix) {
			$ex = explode(':', $line, 2);
			$result[$resultIdx][$ex[0]] = trim($ex[1]);
		} else {
			if ($dataKeys) {
				$resultIdx = $line;
			} else {
				$resultIdx++;
			}
			//echo "Parent: $resultIdx : $line\n";
			$result[$resultIdx] = [];
		}
	}
	return $result;
}

function webminExec($cmd, $localCmd='') {
	global $remoteServer;
	$result = [];
	
	$remoteCmd = 'ssh -p ' . $remoteServer['port'] . ' ' . $remoteServer['user'].'@'.$remoteServer['host'];
	$remoteCmd .= ' "'.$cmd.'"';
	
	if ($localCmd != '') {
		$remoteCmd .= ' | ' . $localCmd;
	}
	
	$rv = exec($remoteCmd, $result);
	return $result;	
}

function vestaExec($cmd, $json = false) {
	if ($json) {
		$cmd .= ' json';
	}
	
	$result = [];
	$rv = exec($cmd, $result);
	return (!$json) ? $rv : json_decode(implode("\n", $result), true);
}

function rsyncExec($user, $src, $dst) {
	global $remoteServer;
	$userName = $user['Unix username'];
	
	echo "Vesta Sync Directories: $src, $dst\n";
	$cmd = 'rsync -az --delete-after --stats ';
	if ($remoteServer['port'] != 22) {
		$cmd .= '-e "ssh -p ' . $remoteServer['port'] . '" ';
	}
	
	$src = '/' . trim($src, '/') . '/';
	
	$cmd .= "--chown=$userName:$userName ";
	
	$cmd .= $remoteServer['user'] . '@' . $remoteServer['host'] . ':' . $src . ' ' . $dst;
	echo "$cmd\n";
	return system($cmd);
}

function systemExec($cmd) {
	return system($cmd);
}

if (empty($argv[1])) {
	usage();
}

$remoteServer = parse_url($argv[1]);

if (empty($remoteServer['scheme']) || $remoteServer['scheme'] != 'ssh') {
	usage();
}

if (empty($remoteServer['user'])) {
	$remoteServer['user'] = 'root';
}

if (empty($remoteServer['port'])) {
	$remoteServer['port'] = 22;
}

$users = webminListUsers();
if (!empty($argv[2])) {
	$selUsers = [];
	foreach($users as $user) {
		if ($user['Unix username'] == $argv[2]) {
			$selUsers[] = $user;
			break;
		}
	}
	
	if (sizeof($selUsers) != 1) {
		echo "ERROR: Remote user not found\n";
		die();
	}
	$users = $selUsers;
}

foreach($users as $user) {
	webminImportUser($user);
}
