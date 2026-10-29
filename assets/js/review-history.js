/**
 * Filename: review-history.js
 * Purpose: Coordinate review history interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

(() => 
{
    const 
    {
        $: ELEMENT,
        esc: ESC
    }
    = WUI;
    const ROOT = ELEMENT('reviewHistoryApp');
    const API = ROOT.dataset.api;
    const BASE = ROOT.dataset.base;
    let page = 1;
    let pages = 1;
    let selected = 0;
    let run = null;
    let findingPage = 1;
    let findingPages = 1;
    let findings = [];
    let editing = null;
    let request = 0;
    let detailRequest = 0;
    const Post = (action, body) => AT.api(API + '?action=' + action, 
    {
        method: 'POST',
        body: 
        {
            review_id: selected,
            ...body
        }
    });
    const GetDate = value => value ? new Date(value).toLocaleString('en-PH', 
    {
        timeZone: 'Asia/Manila'
    }) : 'Not set';
    async function Load() 
    {
        const GENERATION = ++request;
        ELEMENT('reviewHistoryRows').setAttribute('aria-busy', 'true');
        try 
        {
            const RESULT = await AT.api(API + '?action=runs&' + new URLSearchParams(
            {
                page,
                q: ELEMENT('reviewHistorySearch').value,
                engine: ELEMENT('reviewHistoryEngine').value,
                application_id: ELEMENT('reviewHistoryApplication').value,
                mine: ELEMENT('reviewHistoryMine').checked ? '1' : '0'
            }));
            if (GENERATION !== request) 
            {
                return;
            }
            page = RESULT.page;
            pages = RESULT.pages;
            ELEMENT('reviewHistoryRows').innerHTML = RESULT.runs.map(r => '<tr><td><strong>' + ESC(r.title) + '</strong><small>#' + Number(r.review_id) + ' · ' + ESC(r.application_name || 'No application linked') + '</small><small>' + ESC(GetDate(r.created_at)) + '</small></td><td>' + ESC(r.engine) + '</td><td>' + Number(r.open_count) + ' open<small>' + Number(r.finding_count) + ' total · ' + Number(r.file_count) + ' files</small></td><td>' + ESC(r.assignee_name || 'Unassigned') + '<small>' + ESC(r.due_at || 'No due date') + '</small></td><td><span class="ws-tag ' + (r.completed_at ? 'green' : 'amber') + '">' + (r.completed_at ? 'Completed' : 'In review') + '</span></td><td><button class="btn btn-sm" data-open-review="' + Number(r.review_id) + '">Open</button></td></tr>').join('') || '<tr><td colspan="6" class="ws-state"><strong>No saved reviews.</strong>Run a source review to start tracking findings.</td></tr>';
            ELEMENT('reviewHistoryCount').textContent = RESULT.total + ' reviews · page ' + page + ' of ' + pages;
            ELEMENT('reviewHistoryPrevious').disabled = page <= 1;
            ELEMENT('reviewHistoryNext').disabled = page >= pages;
        }
        catch (e) 
        {
            WUI.error('reviewHistoryError', e.message);
        }
        finally 
        {
            if (GENERATION === request) 
            {
                ELEMENT('reviewHistoryRows').setAttribute('aria-busy', 'false');
            }
        }
    }
    async function Detail(id) 
    {
        selected = id;
        const GENERATION = ++detailRequest;
        WUI.error('reviewDetailError', '');
        if (!ELEMENT('reviewDetailDialog').open) 
        {
            WUI.open('reviewDetailDialog');
        }
        try 
        {
            const RESULT = await AT.api(API + '?action=detail&review_id=' + id);
            if (GENERATION !== detailRequest) 
            {
                return;
            }
            run = RESULT;
            ELEMENT('reviewDetailTitle').textContent = run.title;
            ELEMENT('reviewDetailSubtitle').textContent = '#' + run.review_id + ' · ' + run.engine + ' · ' + GetDate(run.created_at);
            ELEMENT('reviewTitle').value = run.title;
            ELEMENT('reviewApplication').value = run.application_id || '';
            ELEMENT('reviewAssignee').value = run.assigned_to || '';
            ELEMENT('reviewDue').value = run.due_at || '';
            ELEMENT('reviewSavedExport').href = BASE + '/modules/codereview/history_export.php?review_id=' + run.review_id;
            const FIELDS = [['Application', run.application_name || 'Not linked'], ['Created by', run.creator_name], ['Reviewer', run.assignee_name || 'Unassigned'], ['Due date', run.due_at || 'Not set'], ['Files / findings', run.file_count + ' / ' + run.finding_count], ['Review state', run.completed_at ? 'Completed ' + GetDate(run.completed_at) : 'In review']];
            ELEMENT('reviewDetailMeta').innerHTML = '<dl class="wf-details">' + FIELDS.map(([k, v]) => '<div><dt>' + ESC(k) + '</dt><dd>' + ESC(v) + '</dd></div>').join('') + '</dl><p class="wf-muted">' + Object.entries(run.statuses).map(([k, v]) => ESC(k) + ': ' + Number(v)).join(' · ') + '</p>' + (run.report_meta.skipped || run.report_meta.findings_limited ? '<p class="ws-note">Scan limits or skipped files reduced coverage. Review the original scope before making a decision.</p>' : '') + (run.report_meta.owasp ? '<p class="ws-note">' + ESC(run.report_meta.owasp.limitation) + '</p>' : '');
            ELEMENT('reviewNotes').innerHTML = '<h4>Decision history</h4>' + run.notes.map(n => '<article class="wf-note"><small>' + ESC(n.author_name) + ' · ' + ESC(GetDate(n.created_at)) + '</small><p>' + ESC(n.note) + '</p></article>').join('') + (!run.notes.length ? '<p class="wf-muted">No reviewer notes.</p>' : '');
            await LoadFindings();
        }
        catch (e) 
        {
            WUI.error('reviewDetailError', e.message);
        }
    }
    async function LoadFindings() 
    {
        const ID = selected;
        try 
        {
            const REPORT = await AT.api(API + '?action=findings&' + new URLSearchParams(
            {
                review_id: ID,
                page: findingPage,
                status: ELEMENT('reviewFindingStatus').value
            }));
            if (ID !== selected) 
            {
                return;
            }
            findingPage = REPORT.page;
            findingPages = REPORT.pages;
            findings = REPORT.findings;
            ELEMENT('reviewFindingRows').innerHTML = findings.map(f => '<tr><td><strong>' + ESC(f.file_path) + '</strong><small>Line ' + Number(f.line_number) + '</small></td><td><strong>' + ESC(f.rule) + '</strong><small>' + ESC(f.severity) + ' · ' + ESC(f.category || 'General') + '</small><p>' + ESC(f.message) + '</p>' + (f.remediation ? '<details><summary>Suggested action</summary><p>' + ESC(f.remediation) + '</p></details>' : '') + '</td><td>' + ESC(f.status) + (f.disposition_note ? '<small>' + ESC(f.disposition_note) + '</small>' : '') + '</td><td><button class="btn btn-sm" data-permission="review" data-finding="' + Number(f.finding_id) + '">Record decision</button></td></tr>').join('') || '<tr><td colspan="4" class="ws-state">No findings match this view. Check scan coverage and manual review requirements.</td></tr>';
            ELEMENT('reviewFindingCount').textContent = REPORT.total + ' findings · page ' + findingPage + ' of ' + findingPages;
            ELEMENT('reviewFindingPrevious').disabled = findingPage <= 1;
            ELEMENT('reviewFindingNext').disabled = findingPage >= findingPages;
        }
        catch (e) 
        {
            WUI.error('reviewDetailError', e.message);
        }
    }
    ELEMENT('reviewHistoryRows').onclick = e => 
    {
        const B = e.target.closest('[data-open-review]');
        if (B) 
        {
            findingPage = 1;
            ELEMENT('reviewFindingStatus').value = '';
            ELEMENT('reviewComparison').replaceChildren();
            Detail(Number(B.dataset.openReview));
        }
    };
    ELEMENT('reviewFindingRows').onclick = e => 
    {
        const B = e.target.closest('[data-finding]');
        if (!B) 
        {
            return;
        }
        editing = findings.find(f => String(f.finding_id) === B.dataset.finding);
        if (!editing) 
        {
            return;
        }
        ELEMENT('findingContext').textContent = editing.file_path + ':' + editing.line_number + ' · ' + editing.rule;
        ELEMENT('findingStatus').value = editing.status;
        ELEMENT('findingNote').value = editing.disposition_note || '';
        WUI.error('findingError', '');
        WUI.open('findingEditor');
    };
    ELEMENT('findingForm').onsubmit = e => 
    {
        e.preventDefault();
        WUI.busy(ELEMENT('findingSave'), 'Saving…', async () => 
        {
            try 
            {
                await Post('findings_update', 
                {
                    finding_id: editing.finding_id,
                    status: ELEMENT('findingStatus').value,
                    note: ELEMENT('findingNote').value,
                    expected_updated_at: editing.updated_at
                });
                WUI.close('findingEditor');
                await Detail(selected);
                Load();
                AT.toast('Finding decision recorded.');
            }
            catch (e) 
            {
                WUI.error('findingError', e.message);
            }
        });
    };
    ELEMENT('reviewDetailsForm').onsubmit = e => 
    {
        e.preventDefault();
        WUI.busy(ELEMENT('reviewDetailsSave'), 'Saving…', async () => 
        {
            try 
            {
                await Post('update_run', 
                {
                    title: ELEMENT('reviewTitle').value,
                    application_id: ELEMENT('reviewApplication').value
                });
                await Detail(selected);
                Load();
                AT.toast('Review details saved.');
            }
            catch (e) 
            {
                WUI.error('reviewDetailError', e.message);
            }
        });
    };
    ELEMENT('reviewAssignForm').onsubmit = e => 
    {
        e.preventDefault();
        WUI.busy(ELEMENT('reviewAssignSave'), 'Saving…', async () => 
        {
            try 
            {
                await Post('assign_review', 
                {
                    assigned_to: ELEMENT('reviewAssignee').value,
                    due_at: ELEMENT('reviewDue').value
                });
                await Detail(selected);
                Load();
                AT.toast('Reviewer assignment saved.');
            }
            catch (e) 
            {
                WUI.error('reviewDetailError', e.message);
            }
        });
    };
    for (const [ID, ACTION] of [['reviewAddNote', 'add_note'], ['reviewComplete', 'complete_review']]) 
    {
        ELEMENT(ID).onclick = () => WUI.busy(ELEMENT(ID), 'Saving…', async () => 
        {
            try 
            {
                await Post(ACTION, 
                {
                    note: ELEMENT('reviewNote').value
                });
                ELEMENT('reviewNote').value = '';
                await Detail(selected);
                Load();
                AT.toast(ACTION === 'complete_review' ? 'Review completed.' : 'Note added.');
            }
            catch (e) 
            {
                WUI.error('reviewDetailError', e.message);
            }
        });
    }
    ELEMENT('reviewCompare').onclick = () => WUI.busy(ELEMENT('reviewCompare'), 'Comparing…', async () => 
    {
        try 
        {
            const R = await AT.api(API + '?action=compare&review_id=' + selected + '&baseline_id=' + encodeURIComponent(ELEMENT('reviewBaseline').value));
            ELEMENT('reviewComparison').innerHTML = '<p class="wf-muted">' + ESC(R.note) + '</p>' + [['New', R.new], ['Continuing', R.continuing], ['No longer detected', R.not_detected]].map(([label, items]) => '<details><summary>' + label + ' · ' + items.length + '</summary>' + items.map(f => '<p class="wf-muted">' + ESC(f.file_path) + ':' + Number(f.line_number) + ' · ' + ESC(f.rule) + '</p>').join('') + '</details>').join('');
        }
        catch (e) 
        {
            WUI.error('reviewDetailError', e.message);
        }
    });
    ELEMENT('reviewFindingStatus').onchange = () => 
    {
        findingPage = 1;
        LoadFindings();
    };
    ELEMENT('reviewFindingPrevious').onclick = () => 
    {
        findingPage--;
        LoadFindings();
    };
    ELEMENT('reviewFindingNext').onclick = () => 
    {
        findingPage++;
        LoadFindings();
    };
    ELEMENT('reviewHistorySearch').oninput = AT.debounce(() => 
    {
        page = 1;
        Load();
    }, 300);
    for (const ID of ['reviewHistoryEngine', 'reviewHistoryApplication', 'reviewHistoryMine']) 
    {
        ELEMENT(ID).onchange = () => 
        {
            page = 1;
            Load();
        };
    }
    ELEMENT('reviewHistoryPrevious').onclick = () => 
    {
        page--;
        Load();
    };
    ELEMENT('reviewHistoryNext').onclick = () => 
    {
        page++;
        Load();
    };
    AT.api(API + '?action=catalog').then(c => 
    {
        for (const ID of ['reviewHistoryApplication', 'reviewApplication']) 
        {
            ELEMENT(ID).replaceChildren(new Option(ID === 'reviewHistoryApplication' ? 'All applications' : 'Not linked', ''), ...c.applications.map(a => new Option(a.application_code + ' · ' + a.application_name, a.application_id)));
        }
        ELEMENT('reviewAssignee').replaceChildren(new Option('Not assigned', ''), ...c.reviewers.map(u => new Option(u.name, u.user_id)));
        Load();
        const ID = Number(new URLSearchParams(location.search).get('review_id'));
        if (ID) 
        {
            Detail(ID);
        }
    }).catch(e => WUI.error('reviewHistoryError', e.message));
})();