(function () {
    'use strict';

    const appId = 'worktracker';
    const requestToken = OC.requestToken;

    function request(path, method = 'GET', body = null) {
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
        return fetch(OC.generateUrl('/apps/' + appId + '/api/' + path), options)
            .then(async response => {
                const text = await response.text();
                let data = null;
                if (text !== '') {
                    try {
                        data = JSON.parse(text);
                    } catch (error) {
                        const detail = text.replace(/<[^>]*>/g, ' ').replace(/\\s+/g, ' ').trim().slice(0, 240);
                        throw new Error('Ungültige Serverantwort (HTTP ' + response.status + ')' + (detail ? ': ' + detail : '.'));
                    }
                }
                if (!response.ok) {
                    throw new Error((data && data.error) || ('HTTP ' + response.status + ': ' + response.statusText));
                }
                if (data === null) throw new Error('Der Server hat eine leere Antwort gesendet.');
                return data;
            });
    }

    function formatSeconds(seconds) {
        seconds = Math.max(0, Number(seconds) || 0);
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = Math.floor(seconds % 60);
        return [hours, minutes, secs].map(v => String(v).padStart(2, '0')).join(':');
    }

    function button(label, action, className) {
        const el = document.createElement('button');
        el.type = 'button';
        el.className = 'worktracker-button ' + (className || '');
        el.textContent = label;
        el.addEventListener('click', async () => {
            el.disabled = true;
            try {
                await action();
                await render();
            } catch (error) {
                OC.Notification.showTemporary(error.message || t(appId, 'Request failed'));
            } finally {
                el.disabled = false;
            }
        });
        return el;
    }

    async function render(root) {
        const target = root || document.querySelector('.worktracker-widget-content');
        if (!target) return;
        try {
            const [status, projects] = await Promise.all([
                request('status'),
                request('projects'),
            ]);
            target.replaceChildren();
            const work = document.createElement('div');
            work.className = 'worktracker-work';
            const heading = document.createElement('strong');
            heading.textContent = t(appId, 'Work time');
            const clock = document.createElement('div');
            clock.className = 'worktracker-clock';
            clock.textContent = formatSeconds(status.work_elapsed_seconds);
            const rounded = document.createElement('div');
            rounded.className = 'worktracker-rounded';
            rounded.textContent = t(appId, 'Rounded') + ': ' + formatSeconds(status.work_rounded_seconds);
            work.append(heading, clock, rounded);
            if (status.work_running) {
                work.append(button(t(appId, 'Stop work'), () => request('work/stop', 'POST'), 'danger'));
                if (status.pause_running) {
                    work.append(button(t(appId, 'End pause'), () => request('pause/stop', 'POST')));
                } else {
                    work.append(button(t(appId, 'Pause'), () => request('pause/start', 'POST')));
                }
            } else {
                work.append(button(t(appId, 'Start work'), () => request('work/start', 'POST'), 'primary'));
            }
            target.append(work);

            const projectHeading = document.createElement('h3');
            projectHeading.textContent = t(appId, 'Projects');
            target.append(projectHeading);
            projects.forEach(project => {
                const row = document.createElement('div');
                row.className = 'worktracker-project';
                const details = document.createElement('div');
                details.className = 'worktracker-project-details';
                const name = document.createElement('strong');
                name.textContent = project.name;
                const exact = Number((status.project_seconds || {})[project.id] || 0);
                const roundedSeconds = Number((status.project_rounded_seconds || {})[project.id] || 0);
                const duration = document.createElement('small');
                duration.textContent = formatSeconds(exact) + ' / ' + formatSeconds(roundedSeconds);
                duration.title = t(appId, 'Actual / rounded');
                details.append(name, duration);
                row.append(details);
                if (status.active_project_id === project.id) {
                    row.append(button(t(appId, 'Stop'), () => request('projects/stop', 'POST'), 'danger'));
                } else {
                    const start = button(t(appId, 'Start'), () => request('projects/' + project.id + '/start', 'POST'));
                    start.disabled = !status.work_running;
                    row.append(start);
                }
                target.append(row);
            });
        } catch (error) {
            target.textContent = error.message || t(appId, 'Could not load Work Tracker');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (window.OCA && OCA.Dashboard && OCA.Dashboard.register) {
            OCA.Dashboard.register('worktracker', function (el) {
                const root = document.createElement('div');
                root.className = 'worktracker-widget-content';
                el.append(root);
                render(root);
            });
        }
    });
})();
