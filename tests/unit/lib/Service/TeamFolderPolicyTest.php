<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Circles\Tests\Unit\Service;

use OCA\Circles\ConfigLexicon;
use OCA\Circles\Db\MembershipRequest;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\Member;
use OCA\Circles\Model\Membership;
use OCA\Circles\Service\TeamFolderPolicy;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TeamFolderPolicyTest extends TestCase {
	private TeamFolderPolicy $service;
	private IAppConfig&MockObject $appConfig;
	private MembershipRequest&MockObject $membershipRequest;

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getAppValueBool')
			->with(ConfigLexicon::TEAM_FOLDER_AUTO_CREATE, true)
			->willReturn(true);
		$this->membershipRequest = $this->createMock(MembershipRequest::class);

		$this->service = new TeamFolderPolicy(
			$this->appConfig,
			$this->membershipRequest,
		);
	}

	public function testShouldCreateTeamFolderSkipsForPersonalCircle(): void {
		$circle = $this->createCircle(Circle::CFG_PERSONAL);

		$this->assertFalse($this->service->shouldCreateTeamFolder($circle));
	}

	public function testShouldCreateTeamFolderSkipsForHiddenCircle(): void {
		$circle = $this->createCircle(Circle::CFG_HIDDEN);

		$this->assertFalse($this->service->shouldCreateTeamFolder($circle));
	}

	public function testShouldCreateTeamFolderSkipsForSystemCircle(): void {
		$circle = $this->createCircle(Circle::CFG_SYSTEM);

		$this->assertFalse($this->service->shouldCreateTeamFolder($circle));
	}

	public function testShouldCreateTeamFolderSkipsForBackendCircle(): void {
		$circle = $this->createCircle(Circle::CFG_BACKEND);

		$this->assertFalse($this->service->shouldCreateTeamFolder($circle));
	}

	public function testShouldCreateTeamFolderSkipsWhenAppConfigDisabled(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueBool')
			->with(ConfigLexicon::TEAM_FOLDER_AUTO_CREATE, true)
			->willReturn(false);
		$service = new TeamFolderPolicy($appConfig, $this->membershipRequest);

		$this->assertFalse($service->isTeamFolderProvisioningEnabled());
		$this->assertFalse($service->shouldCreateTeamFolder($this->createCircle()));
	}

	public function testShouldCreateTeamFolderEnabledWhenAppConfigTrue(): void {
		$this->assertTrue($this->service->isTeamFolderProvisioningEnabled());
		$this->assertTrue($this->service->shouldCreateTeamFolder($this->createCircle()));
	}

	public function testShouldCreateTeamFolderDefaultsToEnabledWhenUnset(): void {
		$this->assertTrue($this->service->isTeamFolderProvisioningEnabled());
		$this->assertTrue($this->service->shouldCreateTeamFolder($this->createCircle()));
	}

	public function testIsEligibleCircleIndependentOfProvisioningFlag(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueBool')
			->with(ConfigLexicon::TEAM_FOLDER_AUTO_CREATE, true)
			->willReturn(false);
		$service = new TeamFolderPolicy($appConfig, $this->membershipRequest);

		$this->assertTrue($service->isEligibleCircle($this->createCircle()));
		$this->assertFalse($service->isEligibleCircle($this->createCircle(Circle::CFG_PERSONAL)));
	}

	public function testGetQuotasDefaultsToEveryone(): void {
		$this->appConfig->method('getAppValueArray')
			->with(ConfigLexicon::TEAM_FOLDER_QUOTAS, [TeamFolderPolicy::EVERYONE => TeamFolderPolicy::DEFAULT_QUOTA])
			->willReturn([]);

		$this->assertSame([TeamFolderPolicy::EVERYONE => TeamFolderPolicy::DEFAULT_QUOTA], $this->service->getQuotas());
	}

	public function testGetQuotasNormalizesEveryoneToDefaultQuota(): void {
		$this->configureQuotas([TeamFolderPolicy::EVERYONE => 2147483648, 'engineering' => 5368709120]);

		$this->assertSame([
			TeamFolderPolicy::EVERYONE => TeamFolderPolicy::DEFAULT_QUOTA,
			'engineering' => 5368709120,
		], $this->service->getQuotas());
	}

	public function testGetQuotaForCircleUsesEveryoneWithoutMatchingTeam(): void {
		$this->configureQuotas([TeamFolderPolicy::EVERYONE => 104857600, 'marketing' => 2147483648]);
		$circle = $this->createCircleWithOwner('alice');
		$this->configureMemberships('alice', ['support']);

		$this->assertSame(104857600, $this->service->getQuotaForCircle($circle));
	}

	public function testGetQuotaForCircleUsesHighestMatchingQuota(): void {
		$this->configureQuotas([
			TeamFolderPolicy::EVERYONE => 104857600,
			'marketing' => 2147483648,
			'engineering' => 5368709120,
		]);
		$circle = $this->createCircleWithOwner('bob');
		$this->configureMemberships('bob', ['marketing', 'engineering']);

		$this->assertSame(5368709120, $this->service->getQuotaForCircle($circle));
	}

	public function testGetQuotaForCircleTreatsUnlimitedAsHighestQuota(): void {
		$this->configureQuotas([TeamFolderPolicy::EVERYONE => 104857600, 'marketing' => 2147483648, 'engineering' => 0]);
		$circle = $this->createCircleWithOwner('bob');
		$this->configureMemberships('bob', ['marketing', 'engineering']);

		$this->assertSame(0, $this->service->getQuotaForCircle($circle));
	}

	public function testGetQuotaForCircleUsesEveryoneForRemoteOwner(): void {
		$this->configureQuotas([TeamFolderPolicy::EVERYONE => 104857600]);
		$circle = $this->createCircleWithOwner('remote-user', false);
		$this->membershipRequest->expects($this->never())->method('getMemberships');

		$this->assertSame(104857600, $this->service->getQuotaForCircle($circle));
	}

	public function testSetQuotasRequiresEveryone(): void {
		$this->appConfig->expects($this->never())->method('setAppValueArray');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('everyone quota is required');

		$this->service->setQuotas(['marketing' => 2147483648]);
	}

	public function testSetQuotasRejectsInvalidQuota(): void {
		$this->appConfig->expects($this->never())->method('setAppValueArray');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->setQuotas([TeamFolderPolicy::EVERYONE => -1]);
	}

	public function testSetQuotasRejectsChangedEveryoneQuota(): void {
		$this->appConfig->expects($this->never())->method('setAppValueArray');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('everyone quota must be 100 MB');

		$this->service->setQuotas([TeamFolderPolicy::EVERYONE => 2147483648]);
	}

	public function testRemoveTeamKeepsEveryoneAndPersistsRemainingMappings(): void {
		$quotas = [TeamFolderPolicy::EVERYONE => 104857600, 'marketing' => 2147483648, 'engineering' => 5368709120];
		$this->configureQuotas($quotas);
		$this->appConfig->expects($this->once())
			->method('setAppValueArray')
			->with(ConfigLexicon::TEAM_FOLDER_QUOTAS, [TeamFolderPolicy::EVERYONE => 104857600, 'engineering' => 5368709120]);

		$this->service->removeTeam('marketing');
		$this->service->removeTeam(TeamFolderPolicy::EVERYONE);
	}

	/** @param array<string, int> $quotas */
	private function configureQuotas(array $quotas): void {
		$this->appConfig->method('getAppValueArray')->willReturn($quotas);
	}

	/** @param list<string> $teamIds */
	private function configureMemberships(string $ownerSingleId, array $teamIds): void {
		$memberships = array_map(function (string $teamId): Membership&MockObject {
			$membership = $this->createMock(Membership::class);
			$membership->method('getCircleId')->willReturn($teamId);
			return $membership;
		}, $teamIds);
		$this->membershipRequest->method('getMemberships')->with($ownerSingleId)->willReturn($memberships);
	}

	private function createCircleWithOwner(string $userId, bool $local = true): Circle&MockObject {
		$owner = $this->createMock(Member::class);
		$owner->method('isLocal')->willReturn($local);
		$owner->method('getSingleId')->willReturn($userId);
		$circle = $this->createMock(Circle::class);
		$circle->method('getOwner')->willReturn($owner);

		return $circle;
	}

	/**
	 * @param int $config bitwise circle config flags
	 */
	private function createCircle(int $config = Circle::CFG_CIRCLE): Circle&MockObject {
		$circle = $this->createMock(Circle::class);
		$circle->method('isConfig')
			->willReturnCallback(function (int $flag) use ($config): bool {
				return ($config & $flag) === $flag;
			});

		return $circle;
	}
}
