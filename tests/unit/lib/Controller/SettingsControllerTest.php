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
		$this->teamFolderPolicy->method('getQuotas')->willReturn([TeamFolderPolicy::EVERYONE => TeamFolderPolicy::DEFAULT_QUOTA]);
		$this->controller = new SettingsController(
			'circles',
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->teamFolderPolicy,
		);
	}

	public function testSetValueStoresQuotaMapping(): void {
		$quotas = [TeamFolderPolicy::EVERYONE => 104857600, 'engineering' => 5368709120];
		$this->teamFolderPolicy->expects($this->once())->method('setQuotas')->with($quotas);

		$response = $this->controller->setValue(ConfigLexicon::TEAM_FOLDER_QUOTAS, json_encode($quotas, JSON_THROW_ON_ERROR));

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($quotas, $response->getData()[ConfigLexicon::TEAM_FOLDER_QUOTAS]);
	}

	public function testSetValueRejectsMalformedJson(): void {
		$this->teamFolderPolicy->expects($this->never())->method('setQuotas');

		$response = $this->controller->setValue(ConfigLexicon::TEAM_FOLDER_QUOTAS, '{invalid');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSetValueRejectsMissingEveryone(): void {
		$this->teamFolderPolicy->method('setQuotas')->willThrowException(new \InvalidArgumentException('everyone quota is required'));

		$response = $this->controller->setValue(ConfigLexicon::TEAM_FOLDER_QUOTAS, '{"marketing":2147483648}');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('everyone quota is required', $response->getData()['data']['message']);
	}

	public function testSetValueRejectsChangedEveryoneQuota(): void {
		$this->teamFolderPolicy->method('setQuotas')->willThrowException(new \InvalidArgumentException('everyone quota must be 100 MB'));

		$response = $this->controller->setValue(ConfigLexicon::TEAM_FOLDER_QUOTAS, '{"everyone":2147483648}');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('everyone quota must be 100 MB', $response->getData()['data']['message']);
	}

	public function testSetValueRejectsUnsupportedKey(): void {
		$this->teamFolderPolicy->expects($this->never())->method('setQuotas');

		$response = $this->controller->setValue('unsupported', '{}');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
