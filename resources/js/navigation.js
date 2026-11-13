export function initNavigation() {
    // 1. Quota Popover Toggle
    const quotaButton = document.getElementById('quota-popover-button');
    const quotaPopover = document.getElementById('quota-popover');
    const quotaChevron = document.getElementById('quota-chevron');

    const toggleQuotaPopover = (show) => {
        if (!quotaButton || !quotaPopover) return;
        const shouldShow = typeof show === 'boolean' ? show : quotaPopover.classList.contains('hidden');
        if (shouldShow) {
            quotaPopover.classList.remove('hidden');
            quotaButton.setAttribute('aria-expanded', 'true');
            if (quotaChevron) quotaChevron.classList.add('rotate-180');
        } else {
            quotaPopover.classList.add('hidden');
            quotaButton.setAttribute('aria-expanded', 'false');
            if (quotaChevron) quotaChevron.classList.remove('rotate-180');
        }
    };

    if (quotaButton) {
        quotaButton.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleQuotaPopover();
        });
    }

    // 2. Mobile Drawer Toggle
    const mobileButton = document.getElementById('mobile-menu-button');
    const mobileDrawer = document.getElementById('mobile-nav-drawer');
    const mobileClose = document.getElementById('mobile-drawer-close');
    const mobileBackdrop = document.getElementById('mobile-nav-backdrop');
    const hamburgerIcon = document.getElementById('mobile-hamburger-icon');
    const closeIcon = document.getElementById('mobile-close-icon');

    const toggleMobileDrawer = (open) => {
        if (!mobileDrawer) return;
        const shouldOpen = typeof open === 'boolean' ? open : mobileDrawer.classList.contains('hidden');
        if (shouldOpen) {
            mobileDrawer.classList.remove('hidden');
            if (mobileButton) mobileButton.setAttribute('aria-expanded', 'true');
            if (hamburgerIcon) hamburgerIcon.classList.add('hidden');
            if (closeIcon) closeIcon.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        } else {
            mobileDrawer.classList.add('hidden');
            if (mobileButton) mobileButton.setAttribute('aria-expanded', 'false');
            if (hamburgerIcon) hamburgerIcon.classList.remove('hidden');
            if (closeIcon) closeIcon.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
    };

    if (mobileButton) {
        mobileButton.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleMobileDrawer();
        });
    }

    if (mobileClose) {
        mobileClose.addEventListener('click', () => toggleMobileDrawer(false));
    }

    if (mobileBackdrop) {
        mobileBackdrop.addEventListener('click', () => toggleMobileDrawer(false));
    }

    // Close popover when clicking outside
    document.addEventListener('click', (e) => {
        if (quotaPopover && !quotaPopover.contains(e.target) && !quotaButton?.contains(e.target)) {
            toggleQuotaPopover(false);
        }
    });

    // Close popover and drawer on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            toggleQuotaPopover(false);
            toggleMobileDrawer(false);
        }
    });
}
