<?php
namespace Deployer;
require 'recipe/laravel.php';

set('ssh_type', 'native');

// Configuration

/** ========================================================================
 * Make sure the following Env Vars are set
 * DEP_USER    SSH User for Login (if http_user is different add new var)
 * DEP_SERVER  SSH Hostname
 * DEP_PUBKEY  Path to public key
 * DEP_PRIKEY  Path to private key
 * DEP_PATH    Path to deploy to on server
 =========================================================================== */

set('repository', 'git@github.com:obstschale/wichteln.git');
set('http_user', getenv('DEP_USER'));
set('keep_releases', 3);

// Servers

server('production', getenv('DEP_SERVER'))
    ->user(getenv('DEP_USER'))
    ->identityFile(getenv('DEP_PUBKEY'), getenv('DEP_PRIKEY'))
    ->set('deploy_path', getenv('DEP_PATH'))
    ->set('branch', 'master');


// Tasks

desc('Make writable dirs');
task('deploy:writable', function () {
	$dirs = join(' ', get('writable_dirs'));
	$mode = get('writable_mode');
	$sudo = get('writable_use_sudo') ? 'sudo' : '';
	$httpUser = get('http_user', false);

	if (empty($dirs)) {
		return;
	}

	if ($httpUser === false && $mode !== 'chmod') {
		// Detect http user in process list.
		$httpUser = run("ps axo user,comm | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | head -1 | cut -d\\  -f1")->toString();

		if (empty($httpUser)) {
			throw new \RuntimeException(
				"Can't detect http user name.\n" .
				"Please setup `http_user` config parameter."
			);
		}
	}

	try {
		cd('{{release_path}}');

		// Create directories if they don't exist
		run("mkdir -p $dirs");

		if ($mode === 'chown') {
			// Change owner.
			// -R   operate on files and directories recursively
			// -L   traverse every symbolic link to a directory encountered
			run("$sudo chown -RL $httpUser $dirs");
		} elseif ($mode === 'chgrp') {
			// Change group ownership.
			// -R   operate on files and directories recursively
			// -L   if a command line argument is a symbolic link to a directory, traverse it
			$httpGroup = get('http_group', false);
			if ($httpGroup === false) {
				throw new \RuntimeException("Please setup `http_group` config parameter.");
			}
			run("$sudo chgrp -RH $httpGroup $dirs");
		} elseif ($mode === 'chmod') {
			$recursive = get('writable_chmod_recursive') ? '-R' : '';
			run("$sudo chmod $recursive {{writable_chmod_mode}} $dirs");
		} elseif ($mode === 'acl') {
			if (strpos(run("chmod 2>&1; true"), '+a') !== false) {
				// Try OS-X specific setting of access-rights

				run("$sudo chmod +a \"$httpUser allow delete,write,append,file_inherit,directory_inherit\" $dirs");
				run("$sudo chmod +a \"`whoami` allow delete,write,append,file_inherit,directory_inherit\" $dirs");
			} elseif (commandExist('setfacl')) {
				if (!empty($sudo)) {
					run("$sudo setfacl -RL -m u:\"$httpUser\":rwX -m u:`whoami`:rwX $dirs");
					run("$sudo setfacl -dRL -m u:\"$httpUser\":rwX -m u:`whoami`:rwX $dirs");
				} else {
					// When running without sudo, exception may be thrown
					// if executing setfacl on files created by http user (in directory that has been setfacl before).
					// These directories/files should be skipped.
					// Now, we will check each directory for ACL and only setfacl for which has not been set before.
					$writeableDirs = get('writable_dirs');
					foreach ($writeableDirs as $dir) {
						// Check if ACL has been set or not
						$hasfacl = run("getfacl -p $dir | grep \"^user:$httpUser:.*w\" | wc -l")->toString();
						// Set ACL for directory if it has not been set before
						//if (!$hasfacl) {
						//	run("setfacl -RL -m u:\"$httpUser\":rwX -m u:`whoami`:rwX $dir");
						//	run("setfacl -dRL -m u:\"$httpUser\":rwX -m u:`whoami`:rwX $dir");
						//}
					}
				}
			} else {
				throw new \RuntimeException("Cant't set writable dirs with ACL.");
			}
		} else {
			throw new \RuntimeException("Unknown writable_mode `$mode`.");
		}
	} catch (\RuntimeException $e) {
		$formatter = Deployer::get()->getHelper('formatter');

		$errorMessage = [
			"Unable to setup correct permissions for writable dirs.                  ",
			"You need to configure sudo's sudoers files to not prompt for password,",
			"or setup correct permissions manually.                                  ",
		];
		write($formatter->formatBlock($errorMessage, 'error', true));

		throw $e;
	}
});
