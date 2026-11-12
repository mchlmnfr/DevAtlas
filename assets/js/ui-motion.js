/**
 * Filename: ui-motion.js
 * Purpose: Animate shared interface elements while respecting account and device motion settings.
 * Developer: DevAtlas maintainers
 * Date: 2026-11-12
 * Recent updates: Add personal dashboard layouts and shared motion controls.
 */
/* Decorative motion never delays navigation, form submissions or data loading. */
(() => {
    'use strict';
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    const root = document.documentElement;
    const seen = new WeakSet();
    const running = new Set();
    const selectors = '.card, .ws-card, .ws-hero, .dashboard-welcome, .login-form-wrap, .login-story, .docs-hero';
    let intersection = null;
    let pending = new Set();
    let scheduled = false;
    const mode = () => reduced.matches ? 'off' : (root.dataset.motion || 'standard');
    const allowed = el => !el.closest('dialog, [role="dialog"], .swal2-container, [data-no-motion], template') && el.getClientRects().length;
    function play(el, index = 0, row = false) {
        if (seen.has(el)) return;
        seen.add(el);
        if (mode() === 'off' || typeof el.animate !== 'function' || !allowed(el)) return;
        const subtle = mode() === 'subtle';
        const frames = subtle || row ? [{ opacity: .45 }, { opacity: 1 }]
            : [{ opacity: .35, transform: 'translateY(6px)' }, { opacity: 1, transform: 'translateY(0)' }];
        const animation = el.animate(frames, { duration: subtle ? 130 : row ? 160 : 240,
            delay: Math.min(index, 5) * (subtle ? 8 : 22), easing: 'cubic-bezier(.2,.7,.2,1)' });
        running.add(animation);
        animation.finished.catch(() => {}).finally(() => running.delete(animation));
    }
    function register(node) {
        if (!(node instanceof Element)) return;
        const cards = [ ...(node.matches(selectors) ? [node] : []), ...node.querySelectorAll(selectors) ];
        cards.forEach((el, i) => {
            if (seen.has(el) || !allowed(el)) return;
            if (intersection) intersection.observe(el); else play(el, i);
        });
        // Fade only a few newly rendered rows; large inventories stay responsive.
        if (node.matches('tbody')) [...node.children].slice(0, 8).forEach((el, i) => play(el, i, true));
        else if (node.matches('tr') && node.parentElement?.matches('tbody') && node.sectionRowIndex < 8) play(node, node.sectionRowIndex, true);
    }
    function update() {
        if (mode() === 'off') {
            running.forEach(animation => animation.cancel());
            running.clear();
        }
        document.dispatchEvent(new CustomEvent('devatlas:motion-change', { detail: { mode: mode() } }));
    }
    function ready() {
        document.body.classList.add('ui-motion-ready');
        const frame = document.querySelector('.content, .docs-main, main');
        if (frame && mode() !== 'off' && typeof frame.animate === 'function') {
            // Fade the page frame without transforming it or changing fixed-position descendants.
            const animation = frame.animate([{ opacity: .7 }, { opacity: 1 }], { duration: mode() === 'subtle' ? 110 : 180, easing: 'ease-out' });
            running.add(animation);
            animation.finished.catch(() => {}).finally(() => running.delete(animation));
        }
        if ('IntersectionObserver' in window) intersection = new IntersectionObserver(entries => {
            entries.filter(entry => entry.isIntersecting).forEach((entry, i) => {
                intersection.unobserve(entry.target);
                play(entry.target, i);
            });
        }, { threshold: .08 });
        register(document.body);
        new MutationObserver(records => {
            for (const record of records) for (const node of record.addedNodes)
                if (node instanceof Element && !node.matches('svg, use, path, option, script, style')) pending.add(node);
            if (!pending.size || scheduled) return;
            scheduled = true;
            requestAnimationFrame(() => {
                const nodes = pending; pending = new Set(); scheduled = false;
                nodes.forEach(register);
            });
        }).observe(document.body, { childList: true, subtree: true });
        new MutationObserver(update).observe(root, { attributes: true, attributeFilter: ['data-motion'] });
        if (reduced.addEventListener) reduced.addEventListener('change', update);
        update();
    }
    window.DevAtlasMotion = { mode, set(value) {
        if (['standard', 'subtle', 'off'].includes(value)) { root.dataset.motion = value; update(); }
    } };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready, { once: true }); else ready();
})();
