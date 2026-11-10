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
                const detail = responseText.replace(/<[^>]*>/g, ' ').replace(/\\s+/g, ' ').trim().slice(0, 240);
                throw new Error('Ungültige Serverantwort (HTTP ' + response.status + ')' + (detail ? ': ' + detail : '.'));
            }
        }

        if (!response.ok) {
            throw new Error((data && data.error) || ('HTTP ' + response.status + ': ' + response.statusText));
        }
        if (data === null) throw new Error('Der Server hat eine leere Antwort gesendet.');
        return data;
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
                await action();
                await render();
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
            workLabel.textContent = translate('Net working time');
            const workValue = document.createElement('strong');
            workValue.textContent = formatSeconds(status.work_elapsed_seconds);
            workMetric.append(workLabel, workValue);
            metrics.append(workMetric);

            const pauseMetric = document.createElement('div');
            pauseMetric.className = 'worktracker-metric';
            const pauseLabel = document.createElement('span');
            pauseLabel.textContent = translate('Break time');
            const pauseValue = document.createElement('strong');
            pauseValue.textContent = formatSeconds(status.pause_elapsed_seconds);
            pauseMetric.append(pauseLabel, pauseValue);
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

            const projectSection = section(translate('Projects'));
            const addProject = makeButton(translate('New project'), () => {
                projectSection.append(projectForm());
                addProject.disabled = true;
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
