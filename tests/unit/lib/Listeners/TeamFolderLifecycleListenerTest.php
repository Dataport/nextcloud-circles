<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Circles\Tests\Unit\Listeners;

use OCA\Circles\Events\CreatingCircleEvent;
use OCA\Circles\Events\DestroyingCircleEvent;
use OCA\Circles\Listeners\TeamFolderLifecycleListener;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\Federated\FederatedEvent;
use OCA\Circles\Service\TeamFolderPolicy;
use OCP\Teams\ITeamFolderProvider;
use OCP\Teams\ITeamManager;
use OCP\Teams\Team;
use OCP\Teams\TeamFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TeamFolderLifecycleListenerTest extends TestCase {
	public function testCreatingCircleUsesResolvedOwnerQuota(): void {
		$circle = $this->createMock(Circle::class);
		$circle->method('getSingleId')->willReturn('team-1');
		$circle->method('getDisplayName')->willReturn('Engineering');

		$federatedEvent = $this->createMock(FederatedEvent::class);
		$federatedEvent->method('getCircle')->willReturn($circle);
		$event = new CreatingCircleEvent($federatedEvent);

		$policy = $this->createMock(TeamFolderPolicy::class);
		$policy->method('shouldCreateTeamFolder')->with($circle)->willReturn(true);
		$policy->method('getQuotaForCircle')->with($circle)->willReturn(5368709120);

		$provider = $this->createMock(ITeamFolderProvider::class);
		$provider->expects($this->once())
			->method('createTeamFolder')
			->with(
				$this->callback(static fn (Team $team): bool => $team->getId() === 'team-1' && $team->getDisplayName() === 'Engineering'),
				5368709120,
			)
			->willReturn($this->createMock(TeamFolder::class));
		$teamManager = $this->createMock(ITeamManager::class);
		$teamManager->method('getTeamFolderProvider')->willReturn($provider);

		$listener = new TeamFolderLifecycleListener($teamManager, $policy, $this->createMock(LoggerInterface::class));
		$listener->handle($event);
	}

	public function testDestroyingCircleRemovesQuotaMapping(): void {
		$circle = $this->createMock(Circle::class);
		$circle->method('getSingleId')->willReturn('team-1');
		$federatedEvent = $this->createMock(FederatedEvent::class);
		$federatedEvent->method('getCircle')->willReturn($circle);
		$policy = $this->createMock(TeamFolderPolicy::class);
		$policy->expects($this->once())->method('removeTeam')->with('team-1');
		$teamManager = $this->createMock(ITeamManager::class);
		$teamManager->method('getTeamFolderProvider')->willReturn(null);

		$listener = new TeamFolderLifecycleListener($teamManager, $policy, $this->createMock(LoggerInterface::class));
		$listener->handle(new DestroyingCircleEvent($federatedEvent));
	}
}
