/*!
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

interface DashboardRegisterContext {
	id: string
	title: string
	iconClass: string
	iconUrl: string
	url?: string
}

type DashboardRegisterCallback = (el: HTMLElement, context: DashboardRegisterContext) => void

declare global {
	interface RenderedFilesApp {
		destroy: () => void
	}

	interface Window {
		OCA: {
			Dashboard: {
				register: (appid: string, callback: DashboardRegisterCallback) => void
			}
		}

		// Provided by the Files app once its scripts are loaded (see `OCA\Files\Event\LoadFilesApp`).
		OCP: {
			Files: {
				renderFilesApp: (el: HTMLElement, viewId: string) => RenderedFilesApp
			}
		}
	}
}

export {}
