(function () {
    'use strict';

    const appId = 'worktracker';
    const requestToken = OC.requestToken;
    const root = document.getElementById('worktracker-main-content');
    if (!root) return;

    function translate(text) {
        return window.t ? t(appId, text) : text;
    }

    async function request(path, method = 'GET', body = null) {
        const options = {
            method,
            headers: {
                'Accept': 'application/json',
                'requesttoken': requestToken,
            },
        };
        if (body !== null) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }

        const response = await fetch(OC.generateUrl('/apps/' + appId + '/api/' + path), options);
        const responseText = await response.text();
        let data = null;

        if (responseText !== '') {
            try {
                data = JSON.parse(responseText);
            } catch (error) {
                const detail = responseText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 240);
                throw new Error('Ungültige Serverantwort (HTTP ' + response.status + ')' + (detail ? ': ' + detail : '.'));
            }
        }

        if (!response.ok) {
            throw new Error((data && data.error) || ('HTTP ' + response.status + ': ' + response.statusText));
        }
        if (data === null) throw new Error('Der Server hat eine leere Antwort gesendet.');
        return data;
    }

    function formatDate(date) {
        return date.toLocaleDateString(undefined, { year: 'numeric', month: '2-digit', day: '2-digit' });
    }

    function dateInputValue(date) {
        const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
        return local.toISOString().slice(0, 10);
    }

    function reportDuration(entries, type, from, to, now) {
        return entries.filter(entry => entry.type === type).reduce((sum, entry) => {
            const start = Math.max(from, Number(entry.start_ts));
            const end = Math.min(to, Number(entry.end_ts ?? now));
            return sum + Math.max(0, end - start);
        }, 0);
    }

    function roundQuarterHour(seconds) {
        return seconds <= 0 ? 0 : Math.floor((seconds + 450) / 900) * 900;
    }

    function roundEntriesByDay(entries, type, from, to, now, groupByProject = false) {
        const buckets = new Map();
        entries.filter(entry => entry.type === type).forEach(entry => {
            let cursor = Math.max(from, Number(entry.start_ts));
            const end = Math.min(to, Number(entry.end_ts ?? now));
            while (cursor < end) {
                const date = new Date(cursor * 1000);
                const nextDay = new Date(date.getFullYear(), date.getMonth(), date.getDate() + 1).getTime() / 1000;
                const partEnd = Math.min(end, nextDay);
                const day = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
                const key = (groupByProject ? String(entry.project_id) + '|' : '') + day;
                buckets.set(key, (buckets.get(key) || 0) + partEnd - cursor);
                cursor = partEnd;
            }
        });
        return [...buckets.values()].reduce((sum, seconds) => sum + roundQuarterHour(seconds), 0);
    }

    async function renderReport(container, fromDate, toDate, projects = []) {
        const from = new Date(fromDate + 'T00:00:00').getTime() / 1000;
        const to = new Date(toDate + 'T00:00:00').getTime() / 1000 + 86400;
        if (!Number.isFinite(from) || !Number.isFinite(to) || to <= from) {
            throw new Error(translate('Please select a valid reporting period.'));
        }

        const entries = await request('entries?from=' + Math.floor(from) + '&to=' + Math.floor(to));
        const now = Math.floor(Date.now() / 1000);
        const work = reportDuration(entries, 'work', from, to, now);
        const pause = reportDuration(entries, 'pause', from, to, now);
        const project = reportDuration(entries, 'project', from, to, now);
        const workRounded = roundEntriesByDay(entries, 'work', from, to, now);
        const pauseRounded = entries.filter(entry => entry.type === 'pause').reduce((sum, entry) => {
            const start = Math.max(from, Number(entry.start_ts));
            const end = Math.min(to, Number(entry.end_ts ?? now));
            return sum + (end > start ? roundQuarterHour(end - start) : 0);
        }, 0);
        const projectRounded = roundEntriesByDay(entries, 'project', from, to, now, true);

        container.replaceChildren();
        const totals = document.createElement('div');
        totals.className = 'worktracker-metrics';
        [
            [translate('Gross working time'), work, workRounded],
            [translate('Break time'), pause, pauseRounded],
            [translate('Project time'), project, projectRounded],
        ].forEach(([label, exact, rounded]) => {
            const metric = document.createElement('div');
            metric.className = 'worktracker-metric';
            const caption = document.createElement('span');
            caption.textContent = label;
            const value = document.createElement('strong');
            value.textContent = formatSeconds(exact);
            const roundedValue = document.createElement('small');
            roundedValue.textContent = translate('Rounded') + ': ' + formatSeconds(rounded);
            roundedValue.className = 'worktracker-rounded';
            metric.append(caption, value, roundedValue);
            totals.append(metric);
        });
        container.append(totals);

        const byProject = new Map();
        const byProjectRoundedDaily = new Map();
        entries.filter(entry => entry.type === 'project').forEach(entry => {
            const start = Math.max(from, Number(entry.start_ts));
            const end = Math.min(to, Number(entry.end_ts ?? now));
            const seconds = Math.max(0, end - start);
            const name = entry.project_name || translate('Unknown project');
            byProject.set(name, (byProject.get(name) || 0) + seconds);
            let cursor = start;
            while (cursor < end) {
                const date = new Date(cursor * 1000);
                const nextDay = new Date(date.getFullYear(), date.getMonth(), date.getDate() + 1).getTime() / 1000;
                const partEnd = Math.min(end, nextDay);
                const day = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
                const key = name + '|' + day;
                byProjectRoundedDaily.set(key, (byProjectRoundedDaily.get(key) || 0) + partEnd - cursor);
                cursor = partEnd;
            }
        });
        const projectHeading = document.createElement('h3');
        projectHeading.textContent = translate('Time by project');
        container.append(projectHeading);
        if (byProject.size === 0) {
            const empty = document.createElement('p');
            empty.className = 'worktracker-empty';
            empty.textContent = translate('No project time recorded in this period.');
            container.append(empty);
        } else {
            const list = document.createElement('div');
            list.className = 'worktracker-report-list';
            [...byProject.entries()].sort((a, b) => b[1] - a[1]).forEach(([name, seconds]) => {
                const row = document.createElement('div');
                row.className = 'worktracker-report-row';
                const project = document.createElement('span');
                project.textContent = name;
                const duration = document.createElement('div');
                duration.className = 'worktracker-dual-time';
                const exactValue = document.createElement('strong');
                exactValue.textContent = formatSeconds(seconds);
                const roundedValue = document.createElement('small');
                const rounded = [...byProjectRoundedDaily.entries()]
                    .filter(([key]) => key.startsWith(name + '|'))
                    .reduce((sum, [, value]) => sum + roundQuarterHour(value), 0);
                roundedValue.textContent = translate('Rounded') + ': ' + formatSeconds(rounded);
                duration.append(exactValue, roundedValue);
                row.append(project, duration);
                list.append(row);
            });
            container.append(list);
        }

        const exportHeading = document.createElement('h3');
        exportHeading.textContent = translate('Export');
        container.append(exportHeading);
        const exports = document.createElement('div');
        exports.className = 'worktracker-controls';
        [
            ['CSV', 'csv'],
            ['Excel (.xlsx)', 'xlsx'],
        ].forEach(([label, format]) => {
            const link = document.createElement('a');
            link.className = 'worktracker-button';
            link.textContent = label;
            link.href = OC.generateUrl('/apps/' + appId + '/api/export.' + format
                + '?from=' + Math.floor(from) + '&to=' + Math.floor(to));
            link.setAttribute('download', 'worktracker.' + format);
            exports.append(link);
        });
        container.append(exports);

        const entriesHeading = document.createElement('h3');
        entriesHeading.textContent = translate('Recorded entries');
        container.append(entriesHeading);
        const entryList = document.createElement('div');
        entryList.className = 'worktracker-report-list';
        entries.forEach(entry => {
            const row = document.createElement('div');
            row.className = 'worktracker-report-row worktracker-entry-row';
            const details = document.createElement('div');
            details.className = 'worktracker-entry-details';
            const title = document.createElement('strong');
            const labels = {
                work: translate('Working time'),
                pause: translate('Break'),
                project: translate('Project time'),
            };
            title.textContent = (labels[entry.type] || entry.type)
                + (entry.project_name ? ' · ' + entry.project_name : '');
            const period = document.createElement('span');
            period.textContent = new Date(Number(entry.start_ts) * 1000).toLocaleString()
                + ' – ' + (entry.end_ts ? new Date(Number(entry.end_ts) * 1000).toLocaleString() : translate('Running'));
            details.append(title, period);
            const actions = document.createElement('div');
            actions.className = 'worktracker-controls';
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'worktracker-button';
            edit.textContent = translate('Edit');
            edit.addEventListener('click', () => {
                const editor = document.createElement('form');
                editor.className = 'worktracker-project-form worktracker-entry-editor';
                const heading = document.createElement('h4');
                heading.textContent = translate('Edit time entry');
                editor.append(heading);

                const localValue = timestamp => {
                    const date = new Date(Number(timestamp) * 1000);
                    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
                };
                const addInput = (labelText, name, type, value) => {
                    const wrapper = document.createElement('label');
                    wrapper.className = 'worktracker-field';
                    const caption = document.createElement('span');
                    caption.textContent = labelText;
                    const input = document.createElement('input');
                    input.type = type;
                    input.name = name;
                    input.value = value;
                    input.required = true;
                    wrapper.append(caption, input);
                    editor.append(wrapper);
                    return input;
                };
                const start = addInput(translate('Start'), 'start', 'datetime-local', localValue(entry.start_ts));
                const end = addInput(translate('End'), 'end', 'datetime-local', localValue(entry.end_ts || now));
                if (entry.type === 'project') {
                    const wrapper = document.createElement('label');
                    wrapper.className = 'worktracker-field';
                    const caption = document.createElement('span');
                    caption.textContent = translate('Project');
                    const projectInput = document.createElement('select');
                    projectInput.name = 'project_id';
                    projects.forEach(project => {
                        const option = document.createElement('option');
                        option.value = project.id;
                        option.textContent = project.name;
                        projectInput.append(option);
                    });
                    projectInput.value = String(entry.project_id);
                    wrapper.append(caption, projectInput);
                    editor.append(wrapper);
                }
                const buttons = document.createElement('div');
                buttons.className = 'worktracker-controls';
                const save = document.createElement('button');
                save.type = 'submit';
                save.className = 'worktracker-button';
                save.textContent = translate('Save');
                const cancel = document.createElement('button');
                cancel.type = 'button';
                cancel.className = 'worktracker-button';
                cancel.textContent = translate('Cancel');
                cancel.addEventListener('click', () => editor.remove());
                buttons.append(save, cancel);
                editor.append(buttons);
                row.after(editor);
                edit.disabled = true;

                editor.addEventListener('submit', async event => {
                    event.preventDefault();
                    save.disabled = true;
                    try {
                        const startTs = Math.floor(new Date(start.value).getTime() / 1000);
                        const endTs = Math.floor(new Date(end.value).getTime() / 1000);
                        if (!Number.isFinite(startTs) || !Number.isFinite(endTs) || endTs <= startTs) {
                            throw new Error(translate('End must be after start.'));
                        }
                        const payload = { start_ts: startTs, end_ts: endTs };
                        if (entry.type === 'project') {
                            payload.project_id = Number(editor.querySelector('[name="project_id"]').value);
                        }
                        await request('entries/' + encodeURIComponent(entry.type) + '/' + encodeURIComponent(entry.id), 'PUT', payload);
                        await renderReport(container, fromDate, toDate, projects);
                    } catch (error) {
                        showMessage(error.message || translate('Could not update entry'), true);
                        save.disabled = false;
                    }
                });
            });
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'worktracker-button';
            remove.textContent = translate('Delete');
            remove.addEventListener('click', async () => {
                if (!window.confirm(translate('Delete this time entry permanently?'))) return;
                remove.disabled = true;
                try {
                    await request('entries/' + encodeURIComponent(entry.type) + '/' + encodeURIComponent(entry.id), 'DELETE');
                    await renderReport(container, fromDate, toDate, projects);
                } catch (error) {
                    showMessage(error.message || translate('Could not delete entry'), true);
                    remove.disabled = false;
                }
            });
            if (!entry.end_ts) {
                edit.disabled = true;
                remove.disabled = true;
                edit.title = translate('Stop the running interval before editing it.');
                remove.title = translate('A running interval cannot be deleted here.');
            }
            actions.append(edit, remove);
            row.append(details, actions);
            entryList.append(row);
        });
        if (entries.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'worktracker-empty';
            empty.textContent = translate('No entries in this period.');
            entryList.append(empty);
        }
        container.append(entryList);
    }

    function formatSeconds(value) {
        const seconds = Math.max(0, Number(value) || 0);
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remainder = Math.floor(seconds % 60);
        return [hours, minutes, remainder].map(value => String(value).padStart(2, '0')).join(':');
    }

    function makeButton(label, action, className = '') {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'worktracker-button ' + className;
        button.textContent = label;
        button.addEventListener('click', async () => {
            button.disabled = true;
            clearMessage();
            try {
                const shouldRender = await action();
                if (shouldRender !== false) await render();
            } catch (error) {
                showMessage(error.message || translate('Request failed'), true);
            } finally {
                button.disabled = false;
            }
        });
        return button;
    }

    function showMessage(message, isError = false) {
        let notice = root.querySelector('.worktracker-notice');
        if (!notice) {
            notice = document.createElement('div');
            notice.className = 'worktracker-notice';
            root.prepend(notice);
        }
        notice.textContent = message;
        notice.classList.toggle('error', isError);
    }

    function clearMessage() {
        const notice = root.querySelector('.worktracker-notice');
        if (notice) notice.remove();
    }

    function section(title) {
        const element = document.createElement('section');
        element.className = 'worktracker-section';
        const heading = document.createElement('h2');
        heading.textContent = title;
        element.append(heading);
        return element;
    }

    function field(form, labelText, name, type = 'text', value = '') {
        const wrapper = document.createElement('label');
        wrapper.className = 'worktracker-field';
        const label = document.createElement('span');
        label.textContent = labelText;
        const input = type === 'textarea'
            ? document.createElement('textarea')
            : document.createElement('input');
        if (type !== 'textarea') input.type = type;
        input.name = name;
        input.value = value ?? '';
        if (name === 'name') input.required = true;
        wrapper.append(label, input);
        form.append(wrapper);
        return input;
    }

    function projectForm(project = null) {
        const form = document.createElement('form');
        form.className = 'worktracker-project-form';
        const heading = document.createElement('h3');
        heading.textContent = project ? translate('Edit project') : translate('New project');
        form.append(heading);

        field(form, translate('Project name'), 'name', 'text', project?.name || '');
        field(form, translate('Code'), 'code', 'text', project?.code || '');
        field(form, translate('WBS'), 'wbs', 'text', project?.wbs || '');
        field(form, translate('Description'), 'description', 'textarea', project?.description || '');
        field(form, translate('Valid from'), 'valid_from', 'date', project?.valid_from || '');
        field(form, translate('Valid until'), 'valid_until', 'date', project?.valid_until || '');

        const actions = document.createElement('div');
        actions.className = 'worktracker-controls';
        const save = document.createElement('button');
        save.type = 'submit';
        save.className = 'worktracker-button';
        save.textContent = translate('Save project');
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'worktracker-button';
        cancel.textContent = translate('Cancel');
        cancel.addEventListener('click', () => render());
        actions.append(save, cancel);
        form.append(actions);

        form.addEventListener('submit', async event => {
            event.preventDefault();
            save.disabled = true;
            clearMessage();
            const payload = Object.fromEntries(new FormData(form).entries());
            try {
                if (project) {
                    await request('projects/' + encodeURIComponent(project.id), 'PUT', payload);
                } else {
                    await request('projects', 'POST', payload);
                }
                await render();
            } catch (error) {
                showMessage(error.message || translate('Could not save project'), true);
                save.disabled = false;
            }
        });
        return form;
    }

    async function render() {
        root.replaceChildren();
        const loading = document.createElement('p');
        loading.className = 'worktracker-loading';
        loading.textContent = translate('Loading…');
        root.append(loading);

        try {
            const [status, projects] = await Promise.all([
                request('status'),
                request('projects'),
            ]);

            root.replaceChildren();

            const overview = section(translate('Today'));
            const metrics = document.createElement('div');
            metrics.className = 'worktracker-metrics';

            const workMetric = document.createElement('div');
            workMetric.className = 'worktracker-metric';
            const workLabel = document.createElement('span');
            workLabel.textContent = translate('Gross working time');
            const workValue = document.createElement('strong');
            workValue.textContent = formatSeconds(status.work_elapsed_seconds);
            const workRounded = document.createElement('small');
            workRounded.className = 'worktracker-rounded';
            workRounded.textContent = translate('Rounded') + ': ' + formatSeconds(status.work_rounded_seconds);
            workMetric.append(workLabel, workValue, workRounded);
            metrics.append(workMetric);

            const pauseMetric = document.createElement('div');
            pauseMetric.className = 'worktracker-metric';
            const pauseLabel = document.createElement('span');
            pauseLabel.textContent = translate('Break time');
            const pauseValue = document.createElement('strong');
            pauseValue.textContent = formatSeconds(status.pause_elapsed_seconds);
            const pauseRounded = document.createElement('small');
            pauseRounded.className = 'worktracker-rounded';
            pauseRounded.textContent = translate('Rounded') + ': ' + formatSeconds(status.pause_rounded_seconds);
            pauseMetric.append(pauseLabel, pauseValue, pauseRounded);
            metrics.append(pauseMetric);
            overview.append(metrics);

            const controls = document.createElement('div');
            controls.className = 'worktracker-controls';
            if (status.work_running) {
                controls.append(makeButton(translate('Stop work'), () => request('work/stop', 'POST')));
                if (status.pause_running) {
                    controls.append(makeButton(translate('End pause'), () => request('pause/stop', 'POST')));
                } else {
                    controls.append(makeButton(translate('Start pause'), () => request('pause/start', 'POST')));
                }
            } else {
                controls.append(makeButton(translate('Start work'), () => request('work/start', 'POST')));
            }
            overview.append(controls);
            root.append(overview);

            const projectTimeSection = section(translate('Project times today'));
            const projectTimeList = document.createElement('div');
            projectTimeList.className = 'worktracker-report-list';
            const projectTimeTotals = status.project_seconds || {};
            const projectTimeRounded = status.project_rounded_seconds || {};
            const projectById = new Map(projects.map(project => [String(project.id), project.name]));
            Object.keys(projectTimeTotals).sort((a, b) => Number(projectTimeTotals[b]) - Number(projectTimeTotals[a])).forEach(id => {
                const row = document.createElement('div');
                row.className = 'worktracker-project-time-row';
                const name = document.createElement('span');
                name.textContent = projectById.get(String(id)) || (translate('Project') + ' #' + id);
                const duration = document.createElement('strong');
                duration.textContent = formatSeconds(projectTimeTotals[id]) + ' / ' + formatSeconds(projectTimeRounded[id] || 0);
                duration.title = translate('Actual / rounded');
                row.append(name, duration);
                projectTimeList.append(row);
            });
            if (projectTimeList.children.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'worktracker-empty';
                empty.textContent = translate('No project time recorded today.');
                projectTimeList.append(empty);
            }
            projectTimeSection.append(projectTimeList);
            root.append(projectTimeSection);

            const projectSection = section(translate('Project management'));
            const addProject = makeButton(translate('New project'), () => {
                projectSection.append(projectForm());
                addProject.disabled = true;
                return false;
            });
            projectSection.append(addProject);

            if (!Array.isArray(projects) || projects.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'worktracker-empty';
                empty.textContent = translate('No projects available yet.');
                projectSection.append(empty);
            } else {
                const list = document.createElement('div');
                list.className = 'worktracker-project-list';
                projects.forEach(project => {
                    const row = document.createElement('div');
                    row.className = 'worktracker-project';
                    const details = document.createElement('div');
                    details.className = 'worktracker-project-details';
                    const name = document.createElement('strong');
                    name.textContent = project.name || '';
                    details.append(name);
                    if (project.code) {
                        const code = document.createElement('span');
                        code.textContent = project.code;
                        details.append(code);
                    }
                    if (project.description) {
                        const description = document.createElement('span');
                        description.textContent = project.description;
                        details.append(description);
                    }
                    row.append(details);

                    const actions = document.createElement('div');
                    actions.className = 'worktracker-controls';
                    const active = Number(status.active_project_id) === Number(project.id);
                    if (active) {
                        actions.append(makeButton(translate('Stop project'), () => request('projects/stop', 'POST')));
                    } else {
                        const start = makeButton(translate('Start'), () => request('projects/' + encodeURIComponent(project.id) + '/start', 'POST'));
                        start.disabled = !status.work_running;
                        actions.append(start);
                    }

                    if (project.owner_uid === OC.currentUser) {
                        actions.append(makeButton(translate('Edit'), () => {
                            projectSection.append(projectForm(project));
                            addProject.disabled = true;
                            return false;
                        }));
                        actions.append(makeButton(translate('Archive'), async () => {
                            if (window.confirm(translate('Archive this project? Historical time entries will be kept.'))) {
                                await request('projects/' + encodeURIComponent(project.id), 'DELETE');
                            }
                        }));
                    }
                    row.append(actions);
                    list.append(row);
                });
                projectSection.append(list);
            }
            root.append(projectSection);

            const reportSection = section(translate('Reporting & export'));
            const reportFilters = document.createElement('form');
            reportFilters.className = 'worktracker-report-filters';
            const fromLabel = document.createElement('label');
            fromLabel.className = 'worktracker-field';
            const fromCaption = document.createElement('span');
            fromCaption.textContent = translate('From');
            const fromInput = document.createElement('input');
            fromInput.type = 'date';
            fromInput.value = dateInputValue(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
            fromLabel.append(fromCaption, fromInput);
            const toLabel = document.createElement('label');
            toLabel.className = 'worktracker-field';
            const toCaption = document.createElement('span');
            toCaption.textContent = translate('To');
            const toInput = document.createElement('input');
            toInput.type = 'date';
            toInput.value = dateInputValue(new Date());
            toLabel.append(toCaption, toInput);
            const showReport = document.createElement('button');
            showReport.type = 'submit';
            showReport.className = 'worktracker-button';
            showReport.textContent = translate('Run report');
            reportFilters.append(fromLabel, toLabel, showReport);
            reportSection.append(reportFilters);
            const reportContent = document.createElement('div');
            reportContent.className = 'worktracker-report-content';
            reportSection.append(reportContent);
            reportFilters.addEventListener('submit', async event => {
                event.preventDefault();
                showReport.disabled = true;
                try {
                    await renderReport(reportContent, fromInput.value, toInput.value, projects);
                } catch (error) {
                    showMessage(error.message || translate('Could not load report'), true);
                } finally {
                    showReport.disabled = false;
                }
            });
            root.append(reportSection);
            await renderReport(reportContent, fromInput.value, toInput.value);

            const updated = document.createElement('p');
            updated.className = 'worktracker-updated';
            updated.textContent = translate('Last updated') + ': ' + new Date().toLocaleTimeString();
            root.append(updated);
        } catch (error) {
            root.replaceChildren();
            showMessage(error.message || translate('Could not load Work Tracker'), true);
            const retry = makeButton(translate('Retry'), render);
            root.append(retry);
        }
    }

    render();
})();
