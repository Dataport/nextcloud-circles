<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Circles\Service;

use OCA\Circles\ConfigLexicon;
use OCA\Circles\Db\MembershipRequest;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\Membership;
use OCP\AppFramework\Services\IAppConfig;

/**
 * Policy for team folders owned by teams (circles).
 *
 * This class owns the *policy* for team-folder creation:
 *  - the `team_folder_auto_create` app config toggle (occ only, not admin UI),
 *  - the `team_folder_quotas` app config mapping,
 *  - the circle-type eligibility rules (personal/hidden/system/backend circles
 *    are excluded).
 *
 * The *orchestration* (creating, unlinking, removing folders) is owned by the
 * groupfolders app. The circles app keeps no reference to the groupfolders app.
 *
 * The Groupfolders provider owns the durable `team_circle_id` linkage. Circles
 * never persists a Groupfolders identifier.
 */
class TeamFolderPolicy {
	public const DEFAULT_QUOTA = 104857600;
	public const PARAM_CREATE_TEAM_FOLDER = 'createTeamFolder';

	public function __construct(
		private IAppConfig $appConfig,
		private MembershipRequest $membershipRequest,
	) {
	}

	/**
	 * Whether Circles may provision team folders (auto-create on team creation
	 * and Circles UI/API upgrade). Controlled via app config, e.g.:
	 *
	 *   occ config:app:set circles team_folder_auto_create --value="false" --type=boolean
	 *
	 * Defaults to true when unset.
	 */
	public function isTeamFolderProvisioningEnabled(): bool {
		return $this->appConfig->getAppValueBool(ConfigLexicon::TEAM_FOLDER_AUTO_CREATE, true);
	}

	/**
	 * Whether the circle type is eligible for a dedicated team folder.
	 */
	public function isEligibleCircle(Circle $circle): bool {
		if ($circle->isConfig(Circle::CFG_PERSONAL)) {
			return false;
		}

		if ($circle->isConfig(Circle::CFG_HIDDEN)) {
			return false;
		}

		if ($circle->isConfig(Circle::CFG_SYSTEM)) {
			return false;
		}

		if ($circle->isConfig(Circle::CFG_BACKEND)) {
			return false;
		}

		return true;
	}

	public function shouldCreateTeamFolder(Circle $circle): bool {
		if (!$this->isTeamFolderProvisioningEnabled()) {
			return false;
		}

		return $this->isEligibleCircle($circle);
	}

	/**
	 * Get the quota applied when no group-specific override matches.
	 */
	public function getDefaultQuota(): int {
		$quota = $this->appConfig->getAppValueInt(ConfigLexicon::TEAM_FOLDER_DEFAULT_QUOTA, self::DEFAULT_QUOTA);
		return $quota >= 0 ? $quota : self::DEFAULT_QUOTA;
	}

	/**
	 * @throws \InvalidArgumentException when the quota is negative.
	 */
	public function setDefaultQuota(int $quota): void {
		if ($quota < 0) {
			throw new \InvalidArgumentException('default quota must be a non-negative integer');
		}

		$this->appConfig->setAppValueInt(ConfigLexicon::TEAM_FOLDER_DEFAULT_QUOTA, $quota);
	}

	/**
	 * @return array<string, int>
	 */
	public function getQuotas(): array {
		$quotas = $this->appConfig->getAppValueArray(ConfigLexicon::TEAM_FOLDER_QUOTAS, []);
		return array_filter(
			$quotas,
			static fn (mixed $quota, mixed $teamId): bool => is_string($teamId) && $teamId !== '' && is_int($quota) && $quota >= 0,
			ARRAY_FILTER_USE_BOTH,
		);
	}

	/**
	 * @param array<string, mixed> $quotas
	 */
	public function setQuotas(array $quotas): void {
		foreach ($quotas as $teamId => $quota) {
			if (!is_string($teamId) || trim($teamId) === '' || !is_int($quota) || $quota < 0) {
				throw new \InvalidArgumentException('quotas must map non-empty team IDs to non-negative integers');
			}
		}

		$this->appConfig->setAppValueArray(ConfigLexicon::TEAM_FOLDER_QUOTAS, $quotas);
	}

	public function removeTeam(string $teamId): void {
		$quotas = $this->getQuotas();
		if (array_key_exists($teamId, $quotas)) {
			unset($quotas[$teamId]);
			$this->setQuotas($quotas);
		}
	}

	/**
	 * Resolve the highest configured quota for the local team owner.
	 * Unlimited (0) takes precedence over every finite quota.
	 */
	public function getQuotaForCircle(Circle $circle): int {
		$quotas = $this->getQuotas();
		$fallback = $this->getDefaultQuota();
		$owner = $circle->getOwner();
		if (!$owner->isLocal()) {
			return $fallback;
		}

		$teamIds = array_map(
			static fn (Membership $membership): string => $membership->getCircleId(),
			$this->membershipRequest->getMemberships($owner->getSingleId()),
		);
		$matches = array_intersect_key($quotas, array_flip($teamIds));
		if ($matches === []) {
			return $fallback;
		}

		if (in_array(0, $matches, true)) {
			return 0;
		}

		return max($matches);
	}
}
