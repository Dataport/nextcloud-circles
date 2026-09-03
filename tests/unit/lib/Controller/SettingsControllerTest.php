<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Circles\Tests\Unit\Controller;

use OCA\Circles\ConfigLexicon;
use OCA\Circles\Controller\SettingsController;
use OCA\Circles\Service\TeamFolderPolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase {
	private SettingsController $controller;
	private TeamFolderPolicy&MockObject $teamFolderPolicy;

	protected function setUp(): void {
		parent::setUp();

		$this->teamFolderPolicy = $this->createMock(TeamFolderPolicy::class);
		$this->controller = new SettingsController(
			'circles',
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->teamFolderPolicy,
		);
	}

	public function testSetValueStoresDefaultQuota(): void {
		$this->teamFolderPolicy->expects($this->once())->method('setDefaultQuota')->with(2147483648);

		$response = $this->controller->setValue(ConfigLexicon::TEAM_FOLDER_DEFAULT_QUOTA, '2147483648');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSetValueRejectsInvalidDefaultQuota(): void {
		$this->teamFolderPolicy->method('setDefaultQuota')
			->willThrowException(new \InvalidArgumentException('default quota must be a non-negative integer'));

		$response = $this->controller->setValue(ConfigLexicon::TEAM_FOLDER_DEFAULT_QUOTA, '-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSetValueRejectsNonNumericDefaultQuota(): void {
		$this->teamFolderPolicy->expects($this->never())->method('setDefaultQuota');

		$response = $this->controller->setValue(ConfigLexicon::TEAM_FOLDER_DEFAULT_QUOTA, 'unlimited');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSetValueRejectsUnsupportedKey(): void {
		$response = $this->controller->setValue('unsupported', '{}');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
