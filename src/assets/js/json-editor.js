(function () {
    var cfg = window.JE_CONFIG;
    var input = document.getElementById('jeInput');
    var hl = document.getElementById('jeHighlight');
    var gutter = document.getElementById('jeGutter');
    var status = document.getElementById('jeStatus');
    var saveBtn = document.getElementById('jeSave');
    var saved = input.value;
    var timer = null;
    var HIGHLIGHT_LIMIT = 1500000;

    function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    var TOKEN = /("(?:\\.|[^"\\])*")(\s*:)?|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)|\b(true|false)\b|\b(null)\b|([{}\[\],:])/g;

    function highlight(text) {
        if (text.length > HIGHLIGHT_LIMIT) return esc(text);
        var out = '', last = 0, m;
        TOKEN.lastIndex = 0;
        while ((m = TOKEN.exec(text))) {
            out += esc(text.slice(last, m.index));
            if (m[1] !== undefined) {
                out += '<span class="' + (m[2] ? 'je-key' : 'je-str') + '">' + esc(m[1]) + '</span>'
                    + (m[2] ? '<span class="je-punct">' + esc(m[2]) + '</span>' : '');
            } else if (m[3] !== undefined) out += '<span class="je-num">' + m[3] + '</span>';
            else if (m[4] !== undefined) out += '<span class="je-bool">' + m[4] + '</span>';
            else if (m[5] !== undefined) out += '<span class="je-null">' + m[5] + '</span>';
            else out += '<span class="je-punct">' + esc(m[6]) + '</span>';
            last = TOKEN.lastIndex;
        }
        return out + esc(text.slice(last));
    }

    function errorLine(text, e) {
        var m = /line (\d+)/i.exec(e.message);
        if (m) return +m[1];
        m = /position (\d+)/i.exec(e.message);
        if (m) return text.slice(0, +m[1]).split('\n').length;
        return 0;
    }

    function setStatus(msg, cls) { status.textContent = msg; status.className = 'je-status ' + (cls || ''); }

    function render() {
        var text = input.value;
        hl.innerHTML = highlight(text) + '\n';
        var errLine = 0, info;
        try {
            var data = JSON.parse(text);
            var n = data && Array.isArray(data.questions) ? data.questions.length : null;
            info = n === null ? ['Немає масиву "questions"', 'err'] : ['✓ JSON коректний, запитань: ' + n, 'ok'];
        } catch (e) {
            errLine = errorLine(text, e);
            info = ['✗ ' + e.message + (errLine ? ' (рядок ' + errLine + ')' : ''), 'err'];
        }
        var dirty = text !== saved;
        setStatus(info[0] + (dirty ? ' · є незбережені зміни' : ''), info[1]);
        saveBtn.disabled = info[1] === 'err';

        var lines = text.split('\n').length, g = '';
        for (var i = 1; i <= lines; i++) g += (i === errLine ? '<span class="je-errline">' + i + '</span>' : i + '\n');
        gutter.innerHTML = g;
        syncScroll();
    }

    function syncScroll() {
        hl.scrollTop = input.scrollTop;
        hl.scrollLeft = input.scrollLeft;
        gutter.scrollTop = input.scrollTop;
    }

    function schedule() { clearTimeout(timer); timer = setTimeout(render, 120); }

    async function save() {
        if (saveBtn.disabled) return;
        saveBtn.disabled = true;
        setStatus('Збереження…');
        var body = new URLSearchParams({ action: 'save_file', file: cfg.file, csrf_token: cfg.csrf, content: input.value });
        try {
            var res = await fetch('/admin/parser.php', { method: 'POST', credentials: 'same-origin', body: body });
            var r = await res.json();
            if (!r.success) throw new Error(r.error || 'Помилка збереження');
            saved = input.value;
            render();
            setStatus('✓ Збережено, запитань: ' + r.questions, 'ok');
        } catch (e) {
            setStatus('✗ ' + e.message, 'err');
            saveBtn.disabled = false;
        }
    }

    function reformat(indent) {
        try {
            input.value = JSON.stringify(JSON.parse(input.value), null, indent);
            render();
        } catch (e) { render(); }
    }

    input.addEventListener('input', schedule);
    input.addEventListener('scroll', syncScroll);
    input.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); return; }
        if (e.key === 'Tab') {
            e.preventDefault();
            var s = input.selectionStart;
            input.setRangeText('  ', s, input.selectionEnd, 'end');
            schedule();
        }
    });
    document.getElementById('jeFormat').addEventListener('click', function () { reformat(2); });
    document.getElementById('jeMinify').addEventListener('click', function () { reformat(0); });
    saveBtn.addEventListener('click', save);
    window.addEventListener('beforeunload', function (e) {
        if (input.value !== saved) { e.preventDefault(); e.returnValue = ''; }
    });
    render();
})();
