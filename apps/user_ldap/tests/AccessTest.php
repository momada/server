<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @copyright Copyright (c) 2016, Lukas Reschke <lukas@statuscode.ch>
 *
 * @author Andreas Fischer <bantu@owncloud.com>
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Lukas Reschke <lukas@statuscode.ch>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\User_LDAP\Tests;

use OCA\User_LDAP\Access;
use OCA\User_LDAP\Connection;
use OCA\User_LDAP\Exceptions\ConstraintViolationException;
use OCA\User_LDAP\FilesystemHelper;
use OCA\User_LDAP\Helper;
use OCA\User_LDAP\ILDAPWrapper;
use OCA\User_LDAP\LDAP;
use OCA\User_LDAP\LogWrapper;
use OCA\User_LDAP\Mapping\UserMapping;
use OCA\User_LDAP\User\Manager;
use OCA\User_LDAP\User\User;
use OCP\IAvatarManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Image;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Test\TestCase;

/**
 * Class AccessTest
 *
 * @group DB
 *
 * @package OCA\User_LDAP\Tests
 */
class AccessTest extends TestCase {
	/** @var Connection|\PHPUnit_Framework_MockObject_MockObject */
	private $connection;
	/** @var LDAP|\PHPUnit_Framework_MockObject_MockObject */
	private $ldap;
	/** @var Manager|\PHPUnit_Framework_MockObject_MockObject */
	private $userManager;
	/** @var Helper|\PHPUnit_Framework_MockObject_MockObject */
	private $helper;
	/** @var Access */
	private $access;

	public function setUp() {
		$this->connection = $this->createMock(Connection::class);
		$this->ldap = $this->createMock(LDAP::class);
		$this->userManager = $this->createMock(Manager::class);
		$this->helper = $this->createMock(Helper::class);

		$this->access = new Access(
			$this->connection,
			$this->ldap,
			$this->userManager,
			$this->helper
		);
	}

	private function getConnectorAndLdapMock() {
		$lw  = $this->createMock(ILDAPWrapper::class);
		$connector = $this->getMockBuilder(Connection::class)
			->setConstructorArgs([$lw, null, null])
			->getMock();
		$um = $this->getMockBuilder(Manager::class)
			->setConstructorArgs([
				$this->createMock(IConfig::class),
				$this->createMock(FilesystemHelper::class),
				$this->createMock(LogWrapper::class),
				$this->createMock(IAvatarManager::class),
				$this->createMock(Image::class),
				$this->createMock(IDBConnection::class),
				$this->createMock(IUserManager::class),
				$this->createMock(INotificationManager::class)])
			->getMock();
		$helper = new Helper(\OC::$server->getConfig());

		return array($lw, $connector, $um, $helper);
	}

	public function testEscapeFilterPartValidChars() {
		$input = 'okay';
		$this->assertTrue($input === $this->access->escapeFilterPart($input));
	}

	public function testEscapeFilterPartEscapeWildcard() {
		$input = '*';
		$expected = '\\\\*';
		$this->assertTrue($expected === $this->access->escapeFilterPart($input));
	}

	public function testEscapeFilterPartEscapeWildcard2() {
		$input = 'foo*bar';
		$expected = 'foo\\\\*bar';
		$this->assertTrue($expected === $this->access->escapeFilterPart($input));
	}

	/**
	 * @dataProvider convertSID2StrSuccessData
	 * @param array $sidArray
	 * @param $sidExpected
	 */
	public function testConvertSID2StrSuccess(array $sidArray, $sidExpected) {
		$sidBinary = implode('', $sidArray);
		$this->assertSame($sidExpected, $this->access->convertSID2Str($sidBinary));
	}

	public function convertSID2StrSuccessData() {
		return array(
			array(
				array(
					"\x01",
					"\x04",
					"\x00\x00\x00\x00\x00\x05",
					"\x15\x00\x00\x00",
					"\xa6\x81\xe5\x0e",
					"\x4d\x6c\x6c\x2b",
					"\xca\x32\x05\x5f",
				),
				'S-1-5-21-249921958-728525901-1594176202',
			),
			array(
				array(
					"\x01",
					"\x02",
					"\xFF\xFF\xFF\xFF\xFF\xFF",
					"\xFF\xFF\xFF\xFF",
					"\xFF\xFF\xFF\xFF",
				),
				'S-1-281474976710655-4294967295-4294967295',
			),
		);
	}

	public function testConvertSID2StrInputError() {
		$sidIllegal = 'foobar';
		$sidExpected = '';

		$this->assertSame($sidExpected, $this->access->convertSID2Str($sidIllegal));
	}

	public function testGetDomainDNFromDNSuccess() {
		$inputDN = 'uid=zaphod,cn=foobar,dc=my,dc=server,dc=com';
		$domainDN = 'dc=my,dc=server,dc=com';

		$this->ldap->expects($this->once())
			->method('explodeDN')
			->with($inputDN, 0)
			->will($this->returnValue(explode(',', $inputDN)));

		$this->assertSame($domainDN, $this->access->getDomainDNFromDN($inputDN));
	}

	public function testGetDomainDNFromDNError() {
		$inputDN = 'foobar';
		$expected = '';

		$this->ldap->expects($this->once())
			->method('explodeDN')
			->with($inputDN, 0)
			->will($this->returnValue(false));

		$this->assertSame($expected, $this->access->getDomainDNFromDN($inputDN));
	}

	public function dnInputDataProvider() {
		return  [[
			[
				'input' => 'foo=bar,bar=foo,dc=foobar',
				'interResult' => array(
					'count' => 3,
					0 => 'foo=bar',
					1 => 'bar=foo',
					2 => 'dc=foobar'
				),
				'expectedResult' => true
			],
			[
				'input' => 'foobarbarfoodcfoobar',
				'interResult' => false,
				'expectedResult' => false
			]
		]];
	}

	/**
	 * @dataProvider dnInputDataProvider
	 */
	public function testStringResemblesDN($case) {
		list($lw, $con, $um, $helper) = $this->getConnectorAndLdapMock();
		$access = new Access($con, $lw, $um, $helper);

		$lw->expects($this->exactly(1))
			->method('explodeDN')
			->will($this->returnCallback(function ($dn) use ($case) {
				if($dn === $case['input']) {
					return $case['interResult'];
				}
				return null;
			}));

		$this->assertSame($case['expectedResult'], $access->stringResemblesDN($case['input']));
	}

	/**
	 * @dataProvider dnInputDataProvider
	 * @param $case
	 */
	public function testStringResemblesDNLDAPmod($case) {
		list(, $con, $um, $helper) = $this->getConnectorAndLdapMock();
		$lw = new LDAP();
		$access = new Access($con, $lw, $um, $helper);

		if(!function_exists('ldap_explode_dn')) {
			$this->markTestSkipped('LDAP Module not available');
		}

		$this->assertSame($case['expectedResult'], $access->stringResemblesDN($case['input']));
	}

	public function testCacheUserHome() {
		$this->connection->expects($this->once())
			->method('writeToCache');

		$this->access->cacheUserHome('foobar', '/foobars/path');
	}

	public function testBatchApplyUserAttributes() {
		$this->ldap->expects($this->any())
			->method('isResource')
			->willReturn(true);

		$this->ldap->expects($this->any())
			->method('getAttributes')
			->willReturn(['displayname' => ['bar', 'count' => 1]]);

		/** @var UserMapping|\PHPUnit_Framework_MockObject_MockObject $mapperMock */
		$mapperMock = $this->createMock(UserMapping::class);
		$mapperMock->expects($this->any())
			->method('getNameByDN')
			->willReturn(false);
		$mapperMock->expects($this->any())
			->method('map')
			->willReturn(true);

		$userMock = $this->createMock(User::class);

		// also returns for userUuidAttribute
		$this->access->connection->expects($this->any())
			->method('__get')
			->will($this->returnValue('displayName'));

		$this->access->setUserMapper($mapperMock);

		$displayNameAttribute = strtolower($this->access->connection->ldapUserDisplayName);
		$data = [
			[
				'dn' => ['foobar'],
				$displayNameAttribute => 'barfoo'
			],
			[
				'dn' => ['foo'],
				$displayNameAttribute => 'bar'
			],
			[
				'dn' => ['raboof'],
				$displayNameAttribute => 'oofrab'
			]
		];

		$userMock->expects($this->exactly(count($data)))
			->method('processAttributes');

		$this->userManager->expects($this->exactly(count($data)))
			->method('get')
			->will($this->returnValue($userMock));

		$this->access->batchApplyUserAttributes($data);
	}

	public function testBatchApplyUserAttributesSkipped() {
		/** @var UserMapping|\PHPUnit_Framework_MockObject_MockObject $mapperMock */
		$mapperMock = $this->createMock(UserMapping::class);
		$mapperMock->expects($this->any())
			->method('getNameByDN')
			->will($this->returnValue('a_username'));

		$userMock = $this->createMock(User::class);

		$this->access->connection->expects($this->any())
			->method('__get')
			->will($this->returnValue('displayName'));

		$this->access->setUserMapper($mapperMock);

		$displayNameAttribute = strtolower($this->access->connection->ldapUserDisplayName);
		$data = [
			[
				'dn' => ['foobar'],
				$displayNameAttribute => 'barfoo'
			],
			[
				'dn' => ['foo'],
				$displayNameAttribute => 'bar'
			],
			[
				'dn' => ['raboof'],
				$displayNameAttribute => 'oofrab'
			]
		];

		$userMock->expects($this->never())
			->method('processAttributes');

		$this->userManager->expects($this->never())
			->method('get');

		$this->access->batchApplyUserAttributes($data);
	}

	public function dNAttributeProvider() {
		// corresponds to Access::resemblesDN()
		return array(
			'dn' => array('dn'),
			'uniqueMember' => array('uniquemember'),
			'member' => array('member'),
			'memberOf' => array('memberof')
		);
	}

	/**
	 * @dataProvider dNAttributeProvider
	 * @param $attribute
	 */
	public function testSanitizeDN($attribute) {
		list($lw, $con, $um, $helper) = $this->getConnectorAndLdapMock();

		$dnFromServer = 'cn=Mixed Cases,ou=Are Sufficient To,ou=Test,dc=example,dc=org';

		$lw->expects($this->any())
			->method('isResource')
			->will($this->returnValue(true));
		$lw->expects($this->any())
			->method('getAttributes')
			->will($this->returnValue(array(
				$attribute => array('count' => 1, $dnFromServer)
			)));

		$access = new Access($con, $lw, $um, $helper);
		$values = $access->readAttribute('uid=whoever,dc=example,dc=org', $attribute);
		$this->assertSame($values[0], strtolower($dnFromServer));
	}

	/**
	 * @expectedException \Exception
	 * @expectedExceptionMessage LDAP password changes are disabled
	 */
	public function testSetPasswordWithDisabledChanges() {
		$this->connection
			->method('__get')
			->willReturn(false);

		$this->access->setPassword('CN=foo', 'MyPassword');
	}

	public function testSetPasswordWithLdapNotAvailable() {
		$this->connection
			->method('__get')
			->willReturn(true);
		$connection = $this->createMock(LDAP::class);
		$this->connection
			->expects($this->once())
			->method('getConnectionResource')
			->willReturn($connection);
		$this->ldap
			->expects($this->once())
			->method('isResource')
			->with($connection)
			->willReturn(false);

		$this->assertFalse($this->access->setPassword('CN=foo', 'MyPassword'));
	}

	/**
	 * @expectedException \OC\HintException
	 * @expectedExceptionMessage Password change rejected.
	 */
	public function testSetPasswordWithRejectedChange() {
		$this->connection
			->method('__get')
			->willReturn(true);
		$connection = $this->createMock(LDAP::class);
		$this->connection
			->expects($this->once())
			->method('getConnectionResource')
			->willReturn($connection);
		$this->ldap
			->expects($this->once())
			->method('isResource')
			->with($connection)
			->willReturn(true);
		$this->ldap
			->expects($this->once())
			->method('modReplace')
			->with($connection, 'CN=foo', 'MyPassword')
			->willThrowException(new ConstraintViolationException());

		$this->access->setPassword('CN=foo', 'MyPassword');
	}

	public function testSetPassword() {
		$this->connection
			->method('__get')
			->willReturn(true);
		$connection = $this->createMock(LDAP::class);
		$this->connection
			->expects($this->once())
			->method('getConnectionResource')
			->willReturn($connection);
		$this->ldap
			->expects($this->once())
			->method('isResource')
			->with($connection)
			->willReturn(true);
		$this->ldap
			->expects($this->once())
			->method('modReplace')
			->with($connection, 'CN=foo', 'MyPassword')
			->willReturn(true);

		$this->assertTrue($this->access->setPassword('CN=foo', 'MyPassword'));
	}
}
