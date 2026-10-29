/**
 * Filename: permissions-ui.js
 * Purpose: Coordinate permissions ui interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/* Presentation follows server authorization; requests are checked independently. */
(() => 
{
    const ACCESS = window.AT_ACCESS;
    if (!ACCESS) 
    {
        return;
    }
    AT.can = (permission = 'view', module = ACCESS.module) => !!ACCESS.modules[module]?.[permission];
    const SPECIAL = 
    {
        userAdd: 'create',
        saveUserBtn: 'create|edit',
        userResetSave: 'edit',
        orgAdd: 'create',
        orgAddChild: 'create',
        orgEdit: 'edit',
        orgDelete: 'delete',
        orgSave: 'create|edit',
        crScanBtn: 'create',
        crExportXlsx: 'export',
        crExportOds: 'export',
        clientGenerateBtn: 'export',
        linkAdd: 'create',
        ddNewTable: 'create',
        ddImport: 'create',
        ddExport: 'export',
        erdGenerateOpen: 'create',
        erdGenerateSubmit: 'create',
        erdNew: 'create',
        erdDelete: 'delete',
        erdSave: 'create|edit',
        serverEdit: 'edit',
        groupEdit: 'edit',
        serverCredentialsTab: 'credentials',
        logsClear: 'delete',
        logsDownload: 'export'
    };
    function Permission(el) 
    {
        if (el.dataset.permission) 
        {
            return el.dataset.permission;
        }
        if (SPECIAL[el.id]) 
        {
            return SPECIAL[el.id];
        }
        const ATTRS = [...el.attributes].map(a => a.name);
        if (ATTRS.some(a => a.startsWith('data-new') || a.startsWith('data-add'))) 
        {
            return 'create';
        }
        if (ATTRS.some(a => a.startsWith('data-save'))) 
        {
            return 'create|edit';
        }
        if (ATTRS.some(a => a.startsWith('data-export'))) 
        {
            return 'export';
        }
        if (ATTRS.some(a => a.startsWith('data-edit'))) 
        {
            return 'edit';
        }
        if (ATTRS.some(a => a.startsWith('data-delete') || a === 'data-unlink')) 
        {
            return 'delete';
        }
        if (el.hasAttribute('data-user-actions')) 
        {
            return 'edit|delete';
        }
        const OP = el.getAttribute('data-op');
        if (OP) 
        {
            return OP === 'delete' ? 'delete' : ['reset', 'unlock', 'toggle'].includes(OP) ? 'edit' : null;
        }
        const DD = el.getAttribute('data-dd') || '';
        if (/^(new|import)/.test(DD)) 
        {
            return 'create';
        }
        if (/^(edit|save)/.test(DD)) 
        {
            return 'edit';
        }
        if (/^(delete|remove)/.test(DD)) 
        {
            return 'delete';
        }
        if (DD.includes('export')) 
        {
            return 'export';
        }
        if (el.tagName === 'A' && /auth_email_preview\.php/.test(el.getAttribute('href') || '')) 
        {
            return 'support@usermgmt';
        }
        if (el.tagName === 'A' && /(?:export|download)\.php/.test(el.getAttribute('href') || '')) 
        {
            return 'export';
        }
        const CALL = (el.getAttribute('onclick') || '').trim();
        if (/^(?:new|add|import|generate)[A-Z_]/.test(CALL)) 
        {
            return 'create';
        }
        if (/^(?:delete|remove)[A-Z_]/.test(CALL)) 
        {
            return 'delete';
        }
        if (/^edit[A-Z_]/.test(CALL)) 
        {
            return 'edit';
        }
        if (/^save[A-Z_]/.test(CALL)) 
        {
            return 'create|edit';
        }
        if (/^export|^crExport/.test(CALL)) 
        {
            return 'export';
        }
        return null;
    }
    function Apply(root) 
    {
        if (!(root instanceof Element || root instanceof Document)) 
        {
            return;
        }
        const ELEMENTS = [...(root instanceof Element && root.matches('button,a,[data-permission]') ? [root] : []), ...root.querySelectorAll('button,a,[data-permission]')];
        for (const EL of ELEMENTS) 
        {
            const P = Permission(EL);
            if (!P) 
            {
                continue;
            }
            const IS_ALLOWED = P.split('|').some(token => 
            {
                const [ACTION, MODULE] = token.split('@');
                return AT.can(ACTION, MODULE || ACCESS.module);
            });
            if (!IS_ALLOWED) 
            {
                EL.hidden = true;
                EL.setAttribute('aria-hidden', 'true');
                EL.dataset.accessHidden = '1';
            }
        }
    }
    AT.refreshNotificationCount = async () => 
    {
        const BADGE = document.getElementById('notificationBadge');
        if (!BADGE || document.visibilityState === 'hidden') 
        {
            return;
        }
        try 
        {
            const R = await AT.api(window.AT_AUDIT.endpoint.replace('/audit/client.php', '/notifications/api.php?action=count'));
            BADGE.textContent = R.unread > 99 ? '99+' : R.unread;
            BADGE.hidden = !R.unread;
        }
        catch 
        {
            /* The inbox remains available if the count request fails. */
        }
    };
    document.addEventListener('DOMContentLoaded', () => 
    {
        Apply(document);
        document.querySelectorAll('#sidebarNav .nav-section').forEach(section => 
        {
            if (!section.querySelector('a')) 
            {
                section.hidden = true;
            }
        });
        let isScheduled = false;
        const ROOTS = new Set();
        new MutationObserver(records => 
        {
            for (const RECORD of records) 
            {
                for (const NODE of RECORD.addedNodes) 
                {
                    if (NODE.nodeType === 1) 
                    {
                        ROOTS.add(NODE);
                    }
                }
            }
            if (ROOTS.size && !isScheduled) 
            {
                isScheduled = true;
                queueMicrotask(() => 
                {
                    for (const NODE of ROOTS) 
                    {
                        Apply(NODE);
                    }
                    ROOTS.clear();
                    isScheduled = false;
                });
            }
        }).observe(document.body, 
        {
            childList: true,
            subtree: true
        });
        AT.refreshNotificationCount();
        setInterval(AT.refreshNotificationCount, 120000);
    });
})();