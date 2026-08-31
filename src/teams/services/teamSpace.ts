/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ContentsWithRoot, File, Folder } from '@nextcloud/files'
import type { FileStat, ResponseDataDetailed } from 'webdav'

import { getCurrentUser } from '@nextcloud/auth'
import { generateRemoteUrl } from '@nextcloud/router'
import { getClient, getDefaultPropfind, resultToNode } from '@nextcloud/files/dav'

/**
 * Build a `getContents` implementation scoped to a single team folder,
 * rooted at `/groupfolders/{uid}/{mountPoint}` on the WebDAV server (the
 * group folders DAV backend resolves a folder by its mount point name, not
 * its numeric id). Nodes are resolved with the same DAV properties as the
 * main Files view, so the regular file actions (rename, share, delete, ...)
 * work based on their real permissions.
 *
 * @param mountPoint the group folder's mount point name
 */
export function getTeamSpaceContents(mountPoint: string): (path?: string) => Promise<ContentsWithRoot> {
	const filesRoot = `/groupfolders/${getCurrentUser()?.uid}/${mountPoint}`
	const remoteURL = generateRemoteUrl('dav' + filesRoot)
	const client = getClient(remoteURL)

	return async function getContents(path = '/'): Promise<ContentsWithRoot> {
		const contentsResponse = await client.getDirectoryContents(path, {
			details: true,
			data: getDefaultPropfind(),
			includeSelf: true,
		}) as ResponseDataDetailed<FileStat[]>

		const [root, ...contents] = contentsResponse.data

		return {
			folder: resultToNode(root, filesRoot, remoteURL) as Folder,
			contents: contents.map((result) => resultToNode(result, filesRoot, remoteURL)) as File[],
		}
	}
}
