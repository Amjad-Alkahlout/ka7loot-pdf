import './bootstrap';
import { Clerk } from '@clerk/clerk-js';
import { initNavigation } from './navigation';
import { fileUploadHandler } from './upload-progress';
import { pdfDrawSignature, pdfPlacementItem } from './sign-pdf';
import { pdfEditorPreview, computeEffectiveFontSize, isRtlText } from './edit-pdf';
import { previewUrlManager, getPreviewUrl, revokePreviewUrl, revokeAllPreviewUrls, canPreviewLocally } from './preview-url-manager';
import { registerSortableDirective } from './sortable-directive';
import { pdfProgressivePreview, pdfInspectModal } from './pdf-progressive-preview';

window.fileUploadHandler = fileUploadHandler;
window.pdfDrawSignature = pdfDrawSignature;
window.pdfPlacementItem = pdfPlacementItem;
window.pdfEditorPreview = pdfEditorPreview;
window.computeEffectiveFontSize = computeEffectiveFontSize;
window.isRtlText = isRtlText;
window.previewUrlManager = previewUrlManager;
window.getPreviewUrl = getPreviewUrl;
window.revokePreviewUrl = revokePreviewUrl;
window.revokeAllPreviewUrls = revokeAllPreviewUrls;
window.canPreviewLocally = canPreviewLocally;
window.pdfProgressivePreview = pdfProgressivePreview;
window.pdfInspectModal = pdfInspectModal;


const clerkConfig = window.clerkConfig || {};

const loadClerkUiBundle = (publishableKey) => new Promise((resolve, reject) => {
    const encodedDomain = publishableKey.split('_')[2];

    if (!encodedDomain) {
        reject(new Error('The Clerk publishable key does not include a Frontend API domain.'));
        return;
    }

    let clerkDomain;
    try {
        clerkDomain = atob(encodedDomain).replace(/\$$/, '');
    } catch {
        reject(new Error('The Clerk publishable key contains an invalid Frontend API domain.'));
        return;
    }

    if (!clerkDomain || !/^[a-zA-Z0-9.-]+$/.test(clerkDomain)) {
        reject(new Error('The Clerk publishable key contains an invalid Frontend API domain.'));
        return;
    }

    const script = document.createElement('script');
    script.src = `https://${clerkDomain}/npm/@clerk/ui@1/dist/ui.browser.js`;
    script.async = true;
    script.crossOrigin = 'anonymous';
    script.onload = () => {
        if (window.__internal_ClerkUICtor) {
            resolve(window.__internal_ClerkUICtor);
        } else {
            reject(new Error('The Clerk UI bundle loaded without exposing its UI constructor.'));
        }
    };
    script.onerror = () => reject(new Error('Failed to load the Clerk UI bundle.'));
    document.head.appendChild(script);
});

const nativeFetch = window.fetch.bind(window);
window.fetch = async (input, init = {}) => {
    const requestUrl = typeof input === 'string' ? input : input.url;
    const sameOrigin = new URL(requestUrl, window.location.href).origin === window.location.origin;
    const session = window.clerk?.session;

    if (sameOrigin && session) {
        const token = await session.getToken();
        if (token) {
            const headers = new Headers(init.headers || (input instanceof Request ? input.headers : undefined));
            headers.set('Authorization', `Bearer ${token}`);
            init = { ...init, headers };
        }
    }

    return nativeFetch(input, init);
};

if (clerkConfig.publishableKey) {
    const clerk = new Clerk(clerkConfig.publishableKey);
    window.clerk = clerk;

    Promise.all([
        loadClerkUiBundle(clerkConfig.publishableKey),
    ]).then(([ClerkUI]) => clerk.load({ ui: { ClerkUI } })).then(() => {
        const controls = document.getElementById('clerk-auth-controls');

        document.querySelectorAll('[data-clerk-sign-up]').forEach((button) => {
            button.addEventListener('click', async () => {
                try {
                    await clerk.openSignUp({ afterSignUpUrl: window.location.href });
                } catch (error) {
                    console.error('Clerk sign-up could not be opened.', error);
                }
            });
        });

        document.querySelectorAll('[data-clerk-sign-out]').forEach((button) => {
            button.addEventListener('click', async () => {
                await clerk.signOut();
                await nativeFetch(clerkConfig.logoutUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': clerkConfig.csrfToken,
                        'Accept': 'application/json',
                    },
                    credentials: 'same-origin',
                });
                window.location.reload();
            });
        });

        document.querySelectorAll('[data-delete-account-form]').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();

                if (!window.confirm(form.dataset.confirmMessage || 'Delete account permanently?')) {
                    return;
                }

                const response = await nativeFetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: { Accept: 'text/html' },
                });

                if (response.ok) {
                    await clerk.signOut();
                    window.location.assign(response.url);
                    return;
                }

                window.location.reload();
            });
        });

        const showSyncError = () => {
            const errorMessage = document.getElementById('clerk-auth-error');
            if (errorMessage) {
                errorMessage.textContent = clerkConfig.syncErrorMessage;
                errorMessage.classList.remove('hidden');
            }
        };

        const syncSession = async () => {
            const token = await clerk.session?.getToken();
            if (!token) return null;

            const response = await nativeFetch(clerkConfig.syncUrl, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'X-CSRF-TOKEN': clerkConfig.csrfToken,
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`The local sign-in sync request failed with HTTP ${response.status}.`);
            }

            const result = await response.json();
            return result.authenticated === true;
        };

        let syncInProgress = false;
        clerk.addListener(async ({ user }) => {
            if (!user || !clerk.session || controls?.dataset.authenticated === 'true' || syncInProgress) {
                return;
            }

            syncInProgress = true;
            try {
                const authenticated = await syncSession();
                if (authenticated === true) {
                    window.location.reload();
                } else if (authenticated === false) {
                    showSyncError();
                    console.error('Clerk sign-in could not be synchronized with the application.');
                }
            } catch (error) {
                showSyncError();
                console.error('Clerk sign-in could not be synchronized with the application.', error);
            } finally {
                syncInProgress = false;
            }
        });

        document.querySelectorAll('[data-clerk-sign-in]').forEach((button) => {
            button.addEventListener('click', async () => {
                try {
                    await clerk.openSignIn({ afterSignInUrl: window.location.href });
                } catch (error) {
                    console.error('Clerk sign-in could not be opened.', error);
                }
            });
        });
    }).catch((error) => {
        console.error('Clerk failed to initialize its authentication UI.', error);
    });
}

const initAlpinePlugins = (Alpine) => {
    if (!Alpine || Alpine._sortableRegistered) return;
    Alpine._sortableRegistered = true;
    Alpine.data('fileUploadHandler', fileUploadHandler);
    Alpine.data('pdfDrawSignature', pdfDrawSignature);
    Alpine.data('pdfPlacementItem', pdfPlacementItem);
    Alpine.data('pdfEditorPreview', pdfEditorPreview);
    Alpine.data('previewUrlManager', previewUrlManager);
    Alpine.data('pdfProgressivePreview', pdfProgressivePreview);
    Alpine.data('pdfInspectModal', pdfInspectModal);
    registerSortableDirective(Alpine);

};

document.addEventListener('alpine:init', () => {
    initAlpinePlugins(window.Alpine);
});

if (window.Alpine) {
    initAlpinePlugins(window.Alpine);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNavigation);
} else {
    initNavigation();
}
