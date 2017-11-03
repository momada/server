<?php
/**
 * @copyright Copyright (c) 2017 Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\User_LDAP\Jobs;

use OC\BackgroundJob\TimedJob;
use OC\ServerNotAvailableException;
use OCA\User_LDAP\Access;
use OCA\User_LDAP\Configuration;
use OCA\User_LDAP\Connection;
use OCA\User_LDAP\FilesystemHelper;
use OCA\User_LDAP\Helper;
use OCA\User_LDAP\LDAP;
use OCA\User_LDAP\LogWrapper;
use OCA\User_LDAP\Mapping\UserMapping;
use OCA\User_LDAP\User\Manager;
use OCA\User_LDAP\User_LDAP;
use OCP\Image;
use OCP\IServerContainer;

class Sync extends TimedJob {
	/** @var IServerContainer */
	protected $c;
	/** @var  Helper */
	protected $ldapHelper;
	/** @var  LDAP */
	protected $ldap;
	/** @var  Manager */
	protected $userManager;
	/** @var UserMapping */
	protected $mapper;
	/** @var int */
	protected $maxInterval = 12 * 60 * 60; // 12h
	/** @var int */
	protected $minInterval = 30 * 60; // 30min

	public function __construct() {
		$this->setInterval(
			\OC::$server->getConfig()->getAppValue(
				'user_ldap',
				'background_sync_interval',
				$this->minInterval
			)
		);
	}

	/**
	 * updates the interval
	 *
	 * the idea is to adjust the interval depending on the amount of known users
	 * and the attempt to update each user one day. At most it would run every
	 * 30 minutes, and at least every 12 hours.
	 */
	public function updateInterval() {
		$minPagingSize = $this->getMinPagingSize();
		$mappedUsers = $this->mapper->count();

		$runsPerDay = ($minPagingSize === 0) ? $this->maxInterval : $mappedUsers / $minPagingSize;
		$interval = floor(24 * 60 * 60 / $runsPerDay);
		$interval = min(max($interval, $this->minInterval), $this->maxInterval);

		$this->c->getConfig()->setAppValue('user_ldap', 'background_sync_interval', $interval);
	}

	/**
	 * returns the smallest configured paging size
	 * @return int
	 */
	protected function getMinPagingSize() {
		$config = $this->c->getConfig();
		$configKeys = $config->getAppKeys('user_ldap');
		$configKeys = array_filter($configKeys, function($key) {
			return strpos($key, 'ldap_paging_size') !== false;
		});
		$minPagingSize = null;
		foreach ($configKeys as $configKey) {
			$pagingSize = $config->getAppValue('user_ldap', $configKey, $minPagingSize);
			$minPagingSize = $minPagingSize === null ? $pagingSize : min($minPagingSize, $pagingSize);
		}
		return (int)$minPagingSize;
	}

	/**
	 * @param array $argument
	 */
	protected function run($argument) {
		$this->setArgument($argument);

		$isBackgroundJobModeAjax = $this->c->getConfig()
				->getAppValue('core', 'backgroundjobs_mode', 'ajax') === 'ajax';
		if($isBackgroundJobModeAjax) {
			return;
		}

		$cycleData = $this->getCycle();
		if($cycleData === null) {
			$cycleData = $this->determineNextCycle();
			if($cycleData === null) {
				$this->updateInterval();
				return;
			}
		}

		if(!$this->qualifiesToRun($cycleData)) {
			$this->updateInterval();
			return;
		}

		try {
			$expectMoreResults = $this->runCycle($cycleData);
			if ($expectMoreResults) {
				$this->increaseOffset($cycleData);
			} else {
				$this->determineNextCycle();
			}
			$this->updateInterval();
		} catch (ServerNotAvailableException $e) {
			$this->determineNextCycle();
		}
	}

	/**
	 * @param array $cycleData
	 * @return bool whether more results are expected from the same configuration
	 */
	public function runCycle($cycleData) {
		$connection = new Connection($this->ldap, $cycleData['prefix']);
		$access = new Access($connection, $this->ldap, $this->userManager, $this->ldapHelper, $this->c);
		$access->setUserMapper($this->mapper);

		$filter = $access->combineFilterWithAnd(array(
			$access->connection->ldapUserFilter,
			$access->connection->ldapUserDisplayName . '=*',
			$access->getFilterPartForUserSearch('')
		));
		$results = $access->fetchListOfUsers(
			$filter,
			$access->userManager->getAttributes(),
			$connection->ldapPagingSize,
			$cycleData['offset'],
			true
		);

		if($connection->ldapPagingSize === 0) {
			return true;
		}
		return count($results) !== $connection->ldapPagingSize;
	}

	/**
	 * returns the info about the current cycle that should be run, if any,
	 * otherwise null
	 *
	 * @return array|null
	 */
	public function getCycle() {
		$prefixes = $this->ldapHelper->getServerConfigurationPrefixes(true);
		if(count($prefixes) === 0) {
			return null;
		}

		$config = $this->c->getConfig();
		$cycleData = [
			'prefix' => $config->getAppValue('user_ldap', 'background_sync_prefix', null),
			'offset' => (int)$config->getAppValue('user_ldap', 'background_sync_offset', 0),
		];

		if(
			$cycleData['prefix'] !== null
			&& in_array($cycleData['prefix'], $prefixes)
		) {
			return $cycleData;
		}

		return null;
	}

	/**
	 * Save the provided cycle information in the DB
	 *
	 * @param array $cycleData
	 */
	public function setCycle(array $cycleData) {
		$config = $this->c->getConfig();
		$config->setAppValue('user_ldap', 'background_sync_prefix', $cycleData['prefix']);
		$config->setAppValue('user_ldap', 'background_sync_offset', $cycleData['offset']);
	}

	/**
	 * returns data about the next cycle that should run, if any, otherwise
	 * null. It also always goes for the next LDAP configuration!
	 *
	 * @param array|null $cycleData the old cycle
	 * @return array|null
	 */
	public function determineNextCycle(array $cycleData = null) {
		$prefixes = $this->ldapHelper->getServerConfigurationPrefixes(true);
		if(count($prefixes) === 0) {
			return null;
		}

		// get the next prefix in line and remember it
		$oldPrefix = $cycleData === null ? null : $cycleData['prefix'];
		$prefix = $this->getNextPrefix($oldPrefix);
		if($prefix === null) {
			return null;
		}
		$cycleData['prefix'] = $prefix;
		$cycleData['offset'] = 0;
		$this->setCycle(['prefix' => $prefix, 'offset' => 0]);

		return $cycleData;
	}

	/**
	 * Checks whether the provided cycle should be run. Currently only the
	 * last configuration change goes into account (at least one hour).
	 *
	 * @param $cycleData
	 * @return bool
	 */
	protected function qualifiesToRun($cycleData) {
		$config = $this->c->getConfig();
		$lastChange = $config->getAppValue('user_ldap', $cycleData['prefix'] . '_lastChange', 0);
		if((time() - $lastChange) > 60 * 30) {
			return true;
		}
		return false;
	}

	/**
	 * increases the offset of the current cycle for the next run
	 *
	 * @param $cycleData
	 */
	protected function increaseOffset($cycleData) {
		$ldapConfig = new Configuration($cycleData['prefix']);
		$cycleData['offset'] += (int)$ldapConfig->ldapPagingSize;
		$this->setCycle($cycleData);
	}

	/**
	 * determines the next configuration prefix based on the last one (if any)
	 *
	 * @param string|null $lastPrefix
	 * @return string|null
	 */
	protected function getNextPrefix($lastPrefix) {
		$prefixes = $this->ldapHelper->getServerConfigurationPrefixes(true);
		$noOfPrefixes = count($prefixes);
		if($noOfPrefixes === 0) {
			return null;
		}
		$i = $lastPrefix === null ? false : array_search($lastPrefix, $prefixes, true);
		if($i === false) {
			$i = -1;
		} else {
			$i++;
		}

		if(!isset($prefixes[$i])) {
			$i = 0;
		}
		return $prefixes[$i];
	}

	/**
	 * "fixes" DI
	 *
	 * @param array $argument
	 */
	public function setArgument($argument) {
		if(isset($argument['c'])) {
			$this->c = $argument['c'];
		} else {
			$this->c = \OC::$server;
		}

		if(isset($argument['helper'])) {
			$this->ldapHelper = $argument['helper'];
		} else {
			$this->ldapHelper = new Helper($this->c->getConfig());
		}

		if(isset($argument['ldapWrapper'])) {
			$this->ldap = $argument['ldapWrapper'];
		} else {
			$this->ldap = new LDAP();
		}

		if(isset($argument['userManager'])) {
			$this->userManager = $argument['userManager'];
		} else {
			$this->userManager = new Manager(
				$this->c->getConfig(),
				new FilesystemHelper(),
				new LogWrapper(),
				$this->c->getAvatarManager(),
				new Image(),
				$this->c->getDatabaseConnection(),
				$this->c->getUserManager(),
				$this->c->getNotificationManager()
			);
		}

		if(isset($argument['mapper'])) {
			$this->mapper = $argument['mapper'];
		} else {
			$this->mapper = new UserMapping($this->c->getDatabaseConnection());
		}
	}
}
