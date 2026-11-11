import './bootstrap';
import { Clerk } from '@clerk/clerk-js';
import { fileUploadHandler } from './upload-progress';
import { pdfDrawSignature, pdfPlacementItem } from './sign-pdf';
import { pdfEditorPreview, computeEffectiveFontSize, isRtlText } from './edit-pdf';

window.fileUploadHandler = fileUploadHandler;
window.pdfDrawSignature = pdfDrawSignature;
window.pdfPlacementItem = pdfPlacementItem;
window.pdfEditorPreview = pdfEditorPreview;
window.computeEffectiveFontSize = computeEffectiveFontSize;
window.isRtlText = isRtlText;

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

        controls?.querySelector('[data-clerk-sign-out]')?.addEventListener('click', async () => {
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

document.addEventListener('alpine:init', () => {
    if (window.Alpine) {
        window.Alpine.data('fileUploadHandler', fileUploadHandler);
        window.Alpine.data('pdfDrawSignature', pdfDrawSignature);
        window.Alpine.data('pdfPlacementItem', pdfPlacementItem);
        window.Alpine.data('pdfEditorPreview', pdfEditorPreview);
    }
});
