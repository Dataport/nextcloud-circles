/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Member, MemberCandidate, Resource, Team, TeamRole } from './types.ts'

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { logger } from '../logger.ts'
import { SHARES_TYPES_MEMBER_MAP } from './team-page/models/constants.ts'
import { getRecommendations, getSuggestions } from './team-page/services/collaborationAutocompletion.js'

/** `SHARES_TYPES_MEMBER_MAP` is built dynamically, so type its shape explicitly. */
const shareTypeToMemberType = SHARES_TYPES_MEMBER_MAP as Record<number, number>

/** Minimal shape of an OCS response envelope. */
interface OcsResponse<T> {
	ocs: { data: T }
}

/** Raw member as returned by the circles API. */
interface RawMember {
	singleId: string
	userId: string | null
	displayName: string
	userType?: number
	type?: number
	level?: number
}

/** Raw resource as returned by the dashboard endpoint. */
interface RawResource {
	id: string | number
	name: string
	type: string
	iconUrl: string
	fallbackIcon: string
	url: string
}

/** Raw circle as returned by the `/circles` endpoint. */
interface RawCircle {
	id: string
	name: string
	displayName: string
	description?: string
	population?: number
	initiator?: { level?: number }
}

/** Raw team as returned by the dashboard widget endpoint. */
interface RawDashboardTeam {
	singleId: string
	members: RawMember[]
	resources: RawResource[]
}

/** Raw sharee suggestion, as returned by the files_sharing autocompletion helper. */
interface RawSuggestion {
	id: string
	label: string
	shareWith: string
	shareType: number
	user?: string | null
}

/**
 * Map a circles member level to a role.
 *
 * @param level - The circles member level (9 owner, 8 admin, 4 moderator, …)
 */
function levelToRole(level: number | undefined): TeamRole {
	if (level === undefined) {
		return 'member'
	}
	if (level >= 9) {
		return 'owner'
	}
	if (level >= 8) {
		return 'admin'
	}
	if (level >= 4) {
		return 'moderator'
	}
	return 'member'
}

/**
 * Map a resource from the dashboard endpoint to our type.
 *
 * @param raw - The raw resource
 */
function mapResource(raw: RawResource): Resource {
	return {
		id: String(raw.id),
		name: raw.name,
		type: raw.type === 'folder' ? 'folder' : 'file',
		iconUrl: raw.iconUrl,
		fallbackIcon: raw.fallbackIcon,
		url: raw.url,
	}
}

/**
 * Map a preview member (from the dashboard endpoint, no level) to our type.
 *
 * @param raw - The raw member
 */
function mapPreviewMember(raw: RawMember): Member {
	return {
		id: raw.singleId,
		userId: raw.userId ?? null,
		displayName: raw.displayName,
		isUser: raw.type === 1,
		role: 'member',
	}
}

/**
 * Map a full member (from the members endpoint, includes level) to our type.
 *
 * @param raw - The raw member
 */
function mapFullMember(raw: RawMember): Member {
	return {
		id: raw.singleId,
		userId: raw.userId ?? null,
		displayName: raw.displayName,
		isUser: raw.userType === 1,
		role: levelToRole(raw.level),
	}
}

/**
 * Fetch all of the current user's teams, merging team metadata (name,
 * description, member count, our role) with the members preview and resources.
 */
export async function fetchTeams(): Promise<Team[]> {
	const [circlesRes, dashRes] = await Promise.allSettled([
		axios.get<OcsResponse<RawCircle[]>>(generateOcsUrl('apps/circles/circles') + '?limit=-1'),
		axios.get<OcsResponse<RawDashboardTeam[]>>(generateOcsUrl('apps/circles/teams/dashboard/widget') + '?limit=200&offset=0'),
	])

	// The team list is required; without it we have nothing to show.
	if (circlesRes.status === 'rejected') {
		throw circlesRes.reason
	}
	const circles = circlesRes.value.data.ocs.data ?? []

	// The dashboard only enriches each team with member/resource previews, so
	// treat a failure there as "no previews" rather than failing the whole page.
	let dashboard: RawDashboardTeam[] = []
	if (dashRes.status === 'fulfilled') {
		dashboard = dashRes.value.data.ocs.data ?? []
	} else {
		logger.warn('Failed to load team dashboard previews', { error: dashRes.reason })
	}
	const dashboardById = new Map(dashboard.map((team) => [team.singleId, team]))

	return circles.map((circle) => {
		const extra = dashboardById.get(circle.id)
		return {
			id: circle.id,
			displayName: circle.displayName || circle.name,
			description: circle.description ?? '',
			memberCount: circle.population ?? extra?.members.length ?? 0,
			myRole: levelToRole(circle.initiator?.level),
			members: (extra?.members ?? []).map(mapPreviewMember),
			resources: (extra?.resources ?? []).map(mapResource),
		}
	})
}

/**
 * Fetch the full member list for a team (includes roles).
 *
 * @param teamId - The team single id
 */
export async function fetchTeamMembers(teamId: string): Promise<Member[]> {
	const res = await axios.get<OcsResponse<RawMember[]>>(generateOcsUrl('apps/circles/circles/{circleId}/members', { circleId: teamId }))
	return (res.data.ocs.data ?? []).map(mapFullMember)
}

/**
 * Create a team and return its single id.
 *
 * @param name - The team name
 */
export async function createTeam(name: string, createTeamFolder = true): Promise<string> {
	const res = await axios.post<OcsResponse<RawCircle>>(
		generateOcsUrl('apps/circles/circles'),
		{ name, createTeamFolder },
	)
	return res.data.ocs.data.id
}

/**
 * Set a team's description.
 *
 * @param teamId - The team single id
 * @param description - The new description
 */
export async function setTeamDescription(teamId: string, description: string): Promise<void> {
	await axios.put(
		generateOcsUrl('apps/circles/circles/{circleId}/description', { circleId: teamId }),
		{ value: description },
	)
}

/**
 * Leave a team.
 *
 * @param teamId - The team single id
 */
export async function leaveTeam(teamId: string): Promise<void> {
	await axios.put(
		generateOcsUrl('apps/circles/circles/{circleId}/leave', { circleId: teamId }),
		{},
	)
}

/**
 * Delete a team.
 *
 * @param teamId - The team single id
 */
export async function deleteTeam(teamId: string): Promise<void> {
	await axios.delete(generateOcsUrl('apps/circles/circles/{circleId}', { circleId: teamId }))
}

/**
 * The team folder linked to a team.
 */
export interface TeamFolder {
	id: number
	mountPoint: string
	quota: number | null
}

/** Team and its linked folder as exposed to administrators. */
export interface AdminTeamFolder {
	teamId: string
	teamName: string
	folder: TeamFolder | null
}

/**
 * Fetch all teams and their optionally linked team folders for the admin settings view.
 */
export async function getAdminTeamFolders(): Promise<AdminTeamFolder[]> {
	const { data } = await axios.get<OcsResponse<AdminTeamFolder[]>>(generateOcsUrl('/apps/circles/admin/teamfolders'))
	return data.ocs.data
}

/**
 * Fetch the team folder linked to a team.
 *
 * The Circles app exposes the team-folder lifecycle through the core Teams
 * contract. The active provider is discovered server-side.
 *
 * @param teamId - The team single id
 * @return The linked team folder, or null if none exists.
 */
export async function getTeamFolder(teamId: string): Promise<TeamFolder | null> {
	try {
		const { data } = await axios.get<OcsResponse<TeamFolder>>(generateOcsUrl('apps/circles/teams/{circleId}/folder', { circleId: teamId }))
		return data.ocs.data
	} catch (error) {
		if (error && typeof error === 'object'
			&& 'response' in error
			&& (error.response as { status?: number })?.status === 404) {
			return null
		}
		throw error
	}
}

/**
 * Create a team folder for a team that predates the auto-create feature.
 *
 * Idempotent: if the team already owns a folder, the existing folder is
 * returned. Requires team owner privileges.
 *
 * @param teamId - The team single id
 * @param name - Optional team folder name
 * @return The created (or existing) team folder.
 */
export async function upgradeTeamFolder(teamId: string, name?: string): Promise<TeamFolder> {
	const { data } = await axios.post<OcsResponse<{ folderId: number, folder: TeamFolder }>>(
		generateOcsUrl('apps/circles/teams/{circleId}/folder', { circleId: teamId }),
		{ name },
	)
	return data.ocs.data.folder
}

/**
 * Fetch team folders that are not yet linked to a team.
 *
 * @param teamId - The team single id
 */
export async function getLinkableTeamFolders(teamId: string): Promise<TeamFolder[]> {
	const { data } = await axios.get<OcsResponse<TeamFolder[]>>(generateOcsUrl('apps/circles/teams/{circleId}/folder/linkable', { circleId: teamId }))
	return data.ocs.data
}

/**
 * Link a team to a team folder.
 *
 * @param teamId - The team single id
 * @param folderId - The id of the existing team folder
 */
export async function linkTeamFolder(teamId: string, folderId: number): Promise<TeamFolder> {
	const { data } = await axios.post<OcsResponse<{ folderId: number, folder: TeamFolder }>>(
		generateOcsUrl('apps/circles/teams/{circleId}/folder/link', { circleId: teamId }),
		{ folderId },
	)
	return data.ocs.data.folder
}

/**
 * Update the storage quota of the team folder linked to a team.
 *
 * @param teamId - The team single id
 * @param quota - Quota in bytes, or zero for unlimited
 */
export async function updateTeamFolderQuota(teamId: string, quota: number): Promise<TeamFolder> {
	const { data } = await axios.put<OcsResponse<{ folderId: number, folder: TeamFolder }>>(
		generateOcsUrl('apps/circles/teams/{circleId}/folder/quota', { circleId: teamId }),
		{ quota },
	)
	return data.ocs.data.folder
}

/**
 * Search for potential new members (users, groups, emails, contacts, other
 * teams…) using the same sharee autocompletion endpoint as file sharing.
 * This restores the legacy "add members while creating a team" feature for
 * the team creation wizard.
 *
 * @param term - The search query. An empty term returns curated recommendations.
 */
export async function searchMemberCandidates(term: string): Promise<MemberCandidate[]> {
	const suggestions: RawSuggestion[] = term.trim()
		? await getSuggestions(term)
		: await getRecommendations()

	return suggestions.map((suggestion) => ({
		key: suggestion.id,
		shareWith: suggestion.shareWith,
		shareType: suggestion.shareType,
		displayName: suggestion.label,
		isUser: suggestion.user !== null && suggestion.user !== undefined,
	}))
}

/**
 * Add a batch of picked candidates as members of a team, typically right
 * after creating it from the wizard.
 *
 * @param teamId - The team single id
 * @param candidates - The candidates picked in the wizard's member step
 * @return The number of candidates that were actually added.
 */
export async function addTeamMembers(teamId: string, candidates: MemberCandidate[]): Promise<number> {
	const members = candidates.map((candidate) => ({
		id: candidate.shareWith,
		type: shareTypeToMemberType[candidate.shareType],
	}))
	const res = await axios.post<OcsResponse<Record<string, unknown>>>(
		generateOcsUrl('apps/circles/circles/{circleId}/members/multi', { circleId: teamId }),
		{ members },
	)
	return Object.keys(res.data.ocs.data ?? {}).length
}
