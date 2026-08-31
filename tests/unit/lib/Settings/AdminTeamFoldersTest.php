<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Circles\Tests\Unit\Settings;

use OCA\Circles\Service\TeamFolderPolicy;
use OCA\Circles\Settings\AdminTeamFolders;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminTeamFoldersTest extends TestCase {
	private IInitialState&MockObject $initialState;
	private TeamFolderPolicy&MockObject $teamFolderPolicy;

	protected function setUp(): void {
		parent::setUp();

		$this->initialState = $this->createMock(IInitialState::class);
		$this->teamFolderPolicy = $this->createMock(TeamFolderPolicy::class);
	}

	public function testGetFormProvidesAutoCreateState(): void {
		$quotas = [TeamFolderPolicy::EVERYONE => TeamFolderPolicy::DEFAULT_QUOTA];
		$providedState = [];
		$this->teamFolderPolicy->expects($this->once())->method('getQuotas')->willReturn($quotas);
		$this->teamFolderPolicy->expects($this->once())->method('isTeamFolderProvisioningEnabled')->willReturn(false);
		$this->initialState->expects($this->exactly(2))
			->method('provideInitialState')
			->willReturnCallback(function (string $key, mixed $value) use (&$providedState): void {
				$providedState[$key] = $value;
			});

		$settings = new AdminTeamFolders(
			$this->createMock(IL10N::class),
			$this->initialState,
			$this->teamFolderPolicy,
		);

		$settings->getForm();

		$this->assertSame([
			'teamFolderQuotas' => $quotas,
			'teamFolderAutoCreateEnabled' => false,
		], $providedState);
	}
}