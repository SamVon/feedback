let exporting = false;
let pendingCommits = 0;
let hooksRegistered = false;

function registerLivewireHooks() {
    if (hooksRegistered || !window.Livewire) return;
    hooksRegistered = true;
    window.Livewire.hook('commit', ({ succeed, fail }) => {
        pendingCommits++;
        let finished = false;
        const finish = () => {
            if (finished) return;
            finished = true;
            pendingCommits--;
        };
        succeed(finish);
        fail(finish);
    });
}

registerLivewireHooks();
document.addEventListener('livewire:init', registerLivewireHooks, { once: true });

const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function waitForDashboard() {
    const deadline = Date.now() + 15000;
    while (Date.now() < deadline) {
        const root = document.querySelector('[data-dashboard-capture]');
        if (!root) throw new Error('Dashboard is unavailable');
        const charts = [...root.querySelectorAll('[data-chart-type]')];
        const ready = charts.every((frame) => window.Alpine?.$data?.(frame)?.getChart?.());
        if (pendingCommits === 0 && ready) {
            await delay(150);
            if (pendingCommits === 0 && root.isConnected) {
                for (const frame of charts) {
                    const chart = window.Alpine.$data(frame).getChart();
                    chart.stop();
                    chart.update('none');
                }
                return root;
            }
        }
        await delay(100);
    }
    throw new Error('Dashboard is still loading');
}

function withTimeout(promise, milliseconds) {
    let timer;
    return Promise.race([
        promise,
        new Promise((_, reject) => {
            timer = setTimeout(() => reject(new Error('Image capture timed out')), milliseconds);
        }),
    ]).finally(() => clearTimeout(timer));
}

async function renderPng(svgUrl, width, height, pixelRatio) {
    // Livewire and Alpine attributes are valid HTML but can make the exported SVG invalid XML.
    const template = document.createElement('template');
    template.innerHTML = decodeURIComponent(svgUrl.slice(svgUrl.indexOf(',') + 1));
    const svg = template.content.querySelector('svg');
    for (const node of [svg, ...svg.querySelectorAll('*')]) {
        for (const attribute of [...node.attributes]) {
            if (/^(wire:|x-|@|:)/.test(attribute.name)) {
                node.removeAttributeNode(attribute);
            }
        }
    }

    const image = new Image();
    image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(new XMLSerializer().serializeToString(svg))}`;
    await image.decode();
    const canvas = document.createElement('canvas');
    canvas.width = Math.floor(width * pixelRatio);
    canvas.height = Math.floor(height * pixelRatio);
    const context = canvas.getContext('2d');
    context.fillStyle = '#f6f6f6';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(image, 0, 0, canvas.width, canvas.height);
    return new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
}

window.addEventListener('dashboard-export-png', async () => {
    if (exporting) return;
    const button = document.querySelector('[data-dashboard-export-button]');
    const status = document.querySelector('[data-dashboard-export-status]');
    if (!button || !status) return;
    exporting = true;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    status.hidden = false;
    status.removeAttribute('data-error');
    status.textContent = button.dataset.exportWorking;
    let root;
    let wasInert = false;
    const controller = new AbortController();

    try {
        const [{ toSvg }] = await Promise.all([
            import('html-to-image'),
            withTimeout(document.fonts.ready, 15000),
        ]);
        root = await waitForDashboard();
        wasInert = root.inert;
        root.inert = true;
        const width = root.scrollWidth;
        const height = root.scrollHeight;
        const pixelRatio = Math.min(2, 16000 / width, 16000 / height, Math.sqrt(24000000 / (width * height)));
        const blob = await withTimeout(toSvg(root, {
            width,
            height,
            pixelRatio,
            backgroundColor: '#f6f6f6',
            preferredFontFormat: 'woff2',
            fetchRequestInit: { signal: controller.signal },
            filter: (node) => node.tagName !== 'SCRIPT' && !node.hasAttribute?.('data-dashboard-export-exclude'),
        }).then((svg) => renderPng(svg, width, height, pixelRatio)), 25000);
        if (!blob) throw new Error('Empty dashboard image');
        const timestamp = new Intl.DateTimeFormat('sv-SE', {
            timeZone: 'Asia/Vientiane', year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
        }).format(new Date()).replace(/[ :]/g, '-');
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `easy-gold-dashboard-${timestamp}.png`;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 30000);
        status.textContent = button.dataset.exportSuccess;
    } catch (error) {
        status.textContent = button.dataset.exportError;
        status.setAttribute('data-error', '');
        console.error('Dashboard PNG export failed', error);
    } finally {
        controller.abort();
        if (root) root.inert = wasInert;
        button.disabled = false;
        button.removeAttribute('aria-busy');
        exporting = false;
    }
});
