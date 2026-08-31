<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<script setup lang="ts">
import type { OCSResponse } from '@nextcloud/typings/ocs'

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { formatFileSize, parseFileSize } from '@nextcloud/files'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateOcsUrl } from '@nextcloud/router'
import { computed, onMounted, ref } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import IconDeleteOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { logger } from '../logger.ts'

interface QuotaOption {
	id: string
	label: string
}

interface TeamOption {
	id: string
	label: string
}

interface RawTeam {
	id: string
	name: string
	displayName: string
}

interface QuotaRow extends TeamOption {
	quota: QuotaOption
}

const everyone = 'everyone'

const unlimitedQuota: QuotaOption = {
	id: '0',
	label: t('circles', 'Unlimited'),
}

const quotaPreset: QuotaOption[] = [
	{ id: '1 GB', label: '1 GB' },
	{ id: '5 GB', label: '5 GB' },
	{ id: '10 GB', label: '10 GB' },
]

const initialQuotas = loadState<Record<string, number>>('circles', 'teamFolderQuotas', { [everyone]: 104857600 })
const rows = ref<QuotaRow[]>(Object.entries(initialQuotas)
	.map(([teamId, quota]) => ({ id: teamId, label: teamId, quota: quotaOption(teamId === everyone ? 104857600 : quota) }))
	.sort((left, right) => left.id === everyone ? -1 : right.id === everyone ? 1 : left.label.localeCompare(right.label)))
const teams = ref<TeamOption[]>([])
const selectedTeam = ref<TeamOption | null>(null)
const loadingTeams = ref(false)
const saving = ref(false)

const availableTeams = computed<TeamOption[]>(() => {
	const mappedIds = new Set(rows.value.map((row) => row.id))
	return teams.value.filter((team) => !mappedIds.has(team.id))
})

const quotaOptions = computed<QuotaOption[]>(() => {
	const options = [unlimitedQuota, ...quotaPreset]
	for (const row of rows.value) {
		if (!options.some((option) => option.id === row.quota.id)) {
			options.push(row.quota)
		}
	}
	return options
})

/**
 * Convert a byte value into a selectable quota option.
 *
 * @param bytes - Quota in bytes
 */
function quotaOption(bytes: number): QuotaOption {
	if (bytes === 0) {
		return unlimitedQuota
	}
	const label = formatFileSize(bytes)
	return { id: label, label }
}

/**
 * Normalize a user-entered quota string into a quota option.
 *
 * @param quota - Raw quota string entered by the user (e.g. "4 GB")
 * @return Normalized quota option
 */
function validateQuota(quota: string): QuotaOption {
	const parsed = parseFileSize(quota, true)
	if (parsed !== null && parsed >= 0) {
		const label = formatFileSize(parsed)
		return { id: label, label }
	}
	return unlimitedQuota
}

/**
 * Update app configuration
 *
 * @param key - The config key
 * @param value - The config value
 */
async function updateAppConfig(key: string, value: string): Promise<boolean> {
	try {
		await confirmPassword()

		const url = generateOcsUrl('/apps/circles/settings/{key}', {
			appId: 'circles',
			key,
		})
		const { data } = await axios.post<OCSResponse>(url, {
			value,
		})
		if (data.ocs.meta.status !== 'ok') {
			if (data.ocs.meta.message) {
				showError(t('circles', 'Unable to update team space config'))
				logger.error('Error while updating team folder config', { error: data.ocs })
				return false
			} else {
				throw new Error(`${data.ocs.meta.statuscode}`)
			}
		}
		return true
	} catch (error) {
		showError(t('circles', 'Unable to update team space config'))
		logger.error('Error while updating team folder config', { error })
		return false
	}
}

/**
 * Load all teams of the current user.
 */
async function loadTeams() {
	loadingTeams.value = true
	try {
		const { data } = await axios.get<OCSResponse<RawTeam[]>>(generateOcsUrl('/apps/circles/circles') + '?limit=-1')
		teams.value = (data.ocs.data ?? [])
			.map((team) => ({ id: team.id, label: team.displayName || team.name || team.id }))
			.sort((left, right) => left.label.localeCompare(right.label))

		const labels = new Map(teams.value.map((team) => [team.id, team.label]))
		rows.value = rows.value.map((row) => ({ ...row, label: labels.get(row.id) ?? row.label }))
	} catch (error) {
		logger.error('Unable to load teams', { error })
		showError(t('circles', 'Unable to load teams'))
	} finally {
		loadingTeams.value = false
	}
}

/** Add the selected team with the default quota. */
function addTeam() {
	if (selectedTeam.value === null) {
		return
	}
	rows.value.push({ ...selectedTeam.value, quota: quotaOption(104857600) })
	selectedTeam.value = null
}

/**
 * Remove a team quota mapping.
 *
 * @param teamId - Team ID to remove
 */
function removeTeam(teamId: string) {
	rows.value = rows.value.filter((row) => row.id !== teamId || row.id === everyone)
}

/** Save all team quota mappings. */
async function onSaveQuotas() {
	const quotas: Record<string, number> = { [everyone]: 104857600 }
	for (const row of rows.value) {
		if (row.id === everyone) {
			continue
		}
		const bytes = row.quota.id === unlimitedQuota.id ? 0 : parseFileSize(row.quota.id, true)
		if (bytes === null || bytes < 0) {
			showError(t('circles', 'Quota must be a non-negative number.'))
			return
		}
		quotas[row.id] = Math.round(bytes)
	}

	saving.value = true
	if (await updateAppConfig('team_folder_quotas', JSON.stringify(quotas))) {
		showSuccess(t('circles', 'Changed default team folder quotas'))
	}
	saving.value = false
}

onMounted(loadTeams)
</script>

<template>
	<NcSettingsSection
		:name="t('circles', 'Teams')"
		:description="t('circles', 'Configure default storage quotas for new team folders based on the team owner’s team memberships.')">
		<div class="team-folders__add-row">
			<NcSelect
				v-model="selectedTeam"
				:loading="loadingTeams"
				:options="availableTeams"
				:placeholder="t('circles', 'Select a team')"
				class="team-folders__team-select" />
			<NcButton
				:disabled="selectedTeam === null"
				@click="addTeam">
				{{ t('circles', 'Add') }}
			</NcButton>
		</div>

		<div
			class="team-folders__table"
			role="table"
			:aria-label="t('circles', 'Default team folder quotas')">
			<div class="team-folders__header" role="row">
				<span role="columnheader">{{ t('circles', 'Team') }}</span>
				<span role="columnheader">{{ t('circles', 'Default quota') }}</span>
				<span role="columnheader">{{ t('circles', 'Options') }}</span>
			</div>
			<div
				v-for="row in rows"
				:key="row.id"
				class="team-folders__row"
				role="row">
				<div class="team-folders__team" role="cell">
					<strong>{{ row.id === everyone ? t('circles', 'Everyone') : row.label }}</strong>
					<small v-if="row.id !== everyone && row.label !== row.id">{{ row.id }}</small>
				</div>
				<span v-if="row.id === everyone" class="team-folders__fixed-quota" role="cell">
					{{ row.quota.label }}
				</span>
				<NcSelect
					v-else
					v-model="row.quota"
					:aria-label="t('circles', 'Default quota for {team}', { team: row.label })"
					:clearable="false"
					:createOption="validateQuota"
					:options="quotaOptions"
					taggable
					role="cell" />
				<div class="team-folders__options" role="cell">
					<NcActions v-if="row.id !== everyone" :aria-label="t('circles', 'Quota mapping actions')">
						<NcActionButton closeAfterClick @click="removeTeam(row.id)">
							<template #icon>
								<IconDeleteOutline :size="20" />
							</template>
							{{ t('circles', 'Delete') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>
		</div>

		<NcButton variant="primary" :disabled="saving" @click="onSaveQuotas">
			{{ saving ? t('circles', 'Saving …') : t('circles', 'Save') }}
		</NcButton>
	</NcSettingsSection>
</template>

<style scoped>
.team-folders__add-row {
	display: flex;
	gap: 8px;
	align-items: center;
	max-width: 500px;
	margin-bottom: 20px;
}

.team-folders__team-select {
	flex: 1;
}

.team-folders__table {
	max-width: 720px;
	margin-bottom: 16px;
}

.team-folders__header,
.team-folders__row {
	display: grid;
	grid-template-columns: minmax(160px, 1fr) minmax(180px, 240px) 44px;
	gap: 12px;
	align-items: center;
	min-height: 52px;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.team-folders__header {
	min-height: 36px;
	color: var(--color-text-maxcontrast);
	font-weight: bold;
}

.team-folders__team {
	display: flex;
	min-width: 0;
	flex-direction: column;
}

.team-folders__team strong,
.team-folders__team small {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.team-folders__team small {
	color: var(--color-text-maxcontrast);
}

.team-folders__fixed-quota {
	padding: 0 12px;
}

.team-folders__options {
	display: flex;
	justify-content: flex-end;
}

@media (max-width: 600px) {
	.team-folders__header {
		display: none;
	}

	.team-folders__row {
		grid-template-columns: minmax(0, 1fr) 44px;
	}

	.team-folders__row > :nth-child(2) {
		grid-column: 1 / -1;
		grid-row: 2;
	}

	.team-folders__options {
		grid-column: 2;
		grid-row: 1;
	}
}
</style>
